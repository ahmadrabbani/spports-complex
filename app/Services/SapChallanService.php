<?php

namespace App\Services;

use App\Models\Challan;
use App\Models\FeeType;
use App\Models\FreezeFeeApproval;
use App\Models\Member;
use App\Models\MemberCharge;
use App\Models\User;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class SapChallanService
{
    public function preview(Member $member, array $currentCharges, ?string $centerOption = null): array
    {
        $member = $this->selectSapCenters($member, $centerOption);
        $this->validateApplicantAndAccounting($member);
        $current = $this->normalizeCurrentCharges($member, $currentCharges);
        $previous = $member->status === 'frozen' ? collect() : $this->getOutstandingCharges($member->id);
        $items = $this->prepareItems($member, $previous, $current);

        return [
            'applicant' => [
                'name' => $member->full_name,
                'cnic' => $this->digits($member->cnic),
                'phone' => $this->digits($member->phone),
                'address' => $member->address,
                'file_no' => $this->fileNumber($member),
                'sports_complex' => $member->sportsComplex->name,
            ],
            'items' => $items->values()->all(),
            'previous_outstanding' => $this->sum($items->where('is_previous_outstanding', true)),
            'current_amount' => $this->sum($items->where('is_previous_outstanding', false)),
            'total' => $this->sum($items),
            'cost_center' => $member->sportsComplex->cost_center,
            'profit_center' => $member->sportsComplex->profit_center,
            'reconciliation_account' => $items->first()['reconciliation_account'],
            'request_url' => rtrim((string) config('services.sap.challan_url'), '?').'?'.http_build_query($this->buildSapParameters($member, $items->map(fn (array $item) => (object) $item)), '', '&', PHP_QUERY_RFC3986),
        ];
    }

    public function generate(Member $member, array $currentCharges, string $idempotencyKey, string $dueOn, User $user, ?string $notes = null, ?string $centerOption = null): Challan
    {
        $lock = Cache::lock('sap-generation-member:'.$member->id, max(120, (int) config('services.sap.timeout', 30) + 60));
        abort_unless($lock->get(), 409, 'Another challan request is in progress for this member.');
        try {
            return $this->generateLocked($member->fresh(), $currentCharges, $idempotencyKey, $dueOn, $user, $notes, $centerOption);
        } finally {
            $lock->release();
        }
    }

    private function generateLocked(Member $member, array $currentCharges, string $idempotencyKey, string $dueOn, User $user, ?string $notes, ?string $centerOption): Challan
    {
        if ($existing = Challan::where('idempotency_key', $idempotencyKey)->first()) {
            abort_unless($existing->member_id === $member->id, 409, 'This submission key belongs to another challan.');

            return $this->loadChallan($existing);
        }

        $this->preview($member, $currentCharges, $centerOption);

        $challan = DB::transaction(function () use ($member, $currentCharges, $idempotencyKey, $dueOn, $user, $notes, $centerOption) {
            $lockedMember = Member::with('sportsComplex')->lockForUpdate()->findOrFail($member->id);
            if ($existing = Challan::where('idempotency_key', $idempotencyKey)->first()) {
                abort_unless($existing->member_id === $lockedMember->id, 409, 'This submission key belongs to another member.');

                return $existing;
            }

            abort_if(Challan::where('member_id', $lockedMember->id)->whereIn('sap_status', ['processing', 'verification_required'])->exists(), 409, 'Resolve the in-progress or uncertain SAP request before generating another challan.');
            $lockedMember = $this->selectSapCenters($lockedMember, $centerOption);
            $this->validateApplicantAndAccounting($lockedMember);
            $current = $this->createCurrentCharges($lockedMember, $currentCharges, $idempotencyKey, $dueOn, $user);
            $previous = ($lockedMember->status === 'frozen' ? collect() : $this->getOutstandingCharges($lockedMember->id, true))
                ->reject(fn (MemberCharge $charge) => $current->contains('id', $charge->id));
            $items = $this->prepareItems($lockedMember, $previous, $current);
            $previousChallan = Challan::where('member_id', $lockedMember->id)->where('environment', 'mock')
                ->whereIn('status', ['generated', 'issued', 'unpaid', 'expired'])
                ->latest('id')->first();

            $challan = Challan::create([
                'sports_complex_id' => $lockedMember->sports_complex_id,
                'member_id' => $lockedMember->id,
                'membership_id' => $current->pluck('membership_id')->filter()->first(),
                'idempotency_key' => $idempotencyKey,
                'regenerates_challan_id' => $previousChallan?->id,
                'environment' => config('services.payment_mode') === 'mock' ? 'mock' : 'live',
                'membership_category_id' => $lockedMember->membership_category_id,
                'membership_category_name' => $lockedMember->category?->name,
                'member_status_snapshot' => $lockedMember->status,
                'company_code' => $lockedMember->source_data['company_code'] ?? '2000',
                'reconciliation_account' => $items->first()['reconciliation_account'],
                'purpose' => $current->pluck('description')->join(', '),
                'previous_outstanding' => $this->sum($items->where('is_previous_outstanding', true)),
                'current_amount' => $this->sum($items->where('is_previous_outstanding', false)),
                'amount' => $this->sum($items),
                'issued_on' => now()->toDateString(),
                'due_on' => $dueOn,
                'status' => 'processing',
                'sap_status' => 'processing',
                'notes' => $notes,
                'created_by' => $user->id,
            ]);
            $challan->challan_no = sprintf('CH-%s-%s-%06d', now()->format('Y'), $lockedMember->sportsComplex->code, $challan->id);
            foreach ($items as $item) {
                $challan->items()->create($item);
            }
            $params = $this->buildSapParameters($lockedMember, $challan->items()->get());
            $challan->sap_payload = $params;
            $challan->request_url = rtrim((string) config('services.sap.challan_url'), '?').'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $challan->save();
            foreach ($currentCharges as $line) {
                if (! empty($line['freeze_fee_approval_id'])) {
                    FreezeFeeApproval::whereKey($line['freeze_fee_approval_id'])->whereNull('challan_id')->update(['challan_id' => $challan->id]);
                }
            }

            return $challan;
        });

        if ($challan->sap_status !== 'processing') {
            return $this->loadChallan($challan);
        }

        return $this->sendToSap($challan);
    }

    public function retry(Challan $challan): Challan
    {
        // SAP does not expose an idempotency key or documented no-post guarantee.
        abort(409, 'Automatic SAP retries are disabled. Reconcile the original request in SAP before any replacement.');
    }

    public function getOutstandingCharges(int $memberId, bool $lock = false): Collection
    {
        $query = MemberCharge::with('feeType')->where('member_id', $memberId)->where('status', 'outstanding')->where('source_type', config('services.payment_mode') === 'mock' ? '=' : '!=', 'mock_generation')->oldest('id');
        // Never rebill charges already attached to a live SAP request (including uncertain/failed requests).
        $query->whereDoesntHave('challanItems.challan', fn ($q) => $q->where('environment', 'live')->whereNotIn('sap_status', ['draft', 'not_sent']));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function buildSapParameters(Member $member, Collection $items): array
    {
        if ($items->isEmpty() || $items->contains(fn ($item) => blank($item->description) || str_contains($item->description, ',') || blank($item->gl_account) || ! is_numeric($item->amount) || (float) $item->amount <= 0)) {
            throw ValidationException::withMessages(['charges' => 'Every fee requires a positive amount, description without commas, and a GL code.']);
        }
        $amounts = $items->map(fn ($item) => number_format((float) $item->amount, 2, '.', ''))->values();
        $descriptions = $items->pluck('description')->values();
        $glAccounts = $items->pluck('gl_account')->values();
        if ($amounts->count() !== $descriptions->count() || $amounts->count() !== $glAccounts->count()) {
            throw ValidationException::withMessages(['charges' => 'Every amount must have one description and one GL Account.']);
        }
        $reconciliationAccounts = $items->pluck('reconciliation_account')->unique()->values();
        if ($reconciliationAccounts->count() !== 1) {
            throw ValidationException::withMessages(['charges' => 'All charges on one SAP challan must use the same reconciliation account.']);
        }
        [$firstName, $lastName] = $this->splitName($member->full_name);

        return [
            'fname' => $firstName,
            'lname' => $lastName,
            'address' => $member->address,
            'phone' => $this->digits($member->phone),
            'cnic' => $this->digits($member->cnic),
            'amount' => $amounts->implode(','),
            'desc' => $descriptions->implode(','),
            'area' => (string) config('services.sap.area', '1'),
            'type' => (string) config('services.sap.type', '1'),
            'glSAP' => $glAccounts->implode(','),
            'reacon' => $reconciliationAccounts->first(),
            'center' => $member->sportsComplex->cost_center,
            'pcenter' => $member->sportsComplex->profit_center,
            'file' => $this->fileNumber($member),
        ];
    }

    public function markPaid(Challan $challan): void
    {
        DB::transaction(function () use ($challan) {
            $locked = Challan::with('items')->lockForUpdate()->findOrFail($challan->id);
            abort_if(in_array($locked->status, ['cancelled', 'superseded', 'failed'], true), 409, 'This challan cannot be marked paid.');
            $locked->update(['status' => 'paid']);
            MemberCharge::whereIn('id', $locked->items->pluck('member_charge_id'))->where('status', 'outstanding')
                ->update(['status' => 'paid', 'paid_at' => now()]);
        });
    }

    public function cancel(Challan $challan): void
    {
        DB::transaction(function () use ($challan) {
            $locked = Challan::with('items')->lockForUpdate()->findOrFail($challan->id);
            abort_if(in_array($locked->status, ['paid', 'superseded'], true), 409, 'This challan cannot be cancelled.');
            abort_if($locked->environment === 'live' && ($locked->sap_document_no || ! in_array($locked->sap_status, ['draft', 'not_sent'], true)), 409, 'SAP cancellation is not supported. Use Adjust Challan for manual SAP reconciliation.');
            $locked->update(['status' => 'cancelled']);
            MemberCharge::whereIn('id', $locked->items->where('is_previous_outstanding', false)->pluck('member_charge_id'))->where('status', 'outstanding')->update(['status' => 'cancelled']);
        });
    }

    private function normalizeCurrentCharges(Member $member, array $charges): Collection
    {
        $approvalIds = collect($charges)->pluck('freeze_fee_approval_id')->filter();
        if ($approvalIds->count() !== $approvalIds->unique()->count()) {
            throw ValidationException::withMessages(['charges' => 'A freeze fee approval may be used only once.']);
        }
        $account = app(MembershipReconciliationService::class)->resolve($member);
        $types = FeeType::whereIn('id', collect($charges)->pluck('fee_type_id'))->get()->keyBy('id');

        return collect($charges)->map(function (array $charge, int $index) use ($member, $types, $account) {
            $type = $types->get((int) $charge['fee_type_id']);
            if (! $type || ! $type->active) {
                throw ValidationException::withMessages(["charges.{$index}.fee_type_id" => 'Select an active fee type.']);
            }
            if (blank($type->gl_account)) {
                throw ValidationException::withMessages(["charges.{$index}.fee_type_id" => "GL Account is not configured for {$type->name}."]);
            }
            $reconciliation = $account;
            if ($member->status === 'frozen') {
                $approval = FreezeFeeApproval::with('freeze')->find($charge['freeze_fee_approval_id'] ?? 0);
                if (! $type->is_freeze_fee || ! $approval || $approval->challan_id || $approval->fee_type_id !== $type->id || $approval->freeze->member_id !== $member->id || $approval->freeze->unfrozen_at || (int) round((float) $approval->amount * 100) !== (int) round((float) $charge['amount'] * 100)) {
                    throw ValidationException::withMessages(['charges' => 'Frozen members require an unused administrator-approved configured freeze fee. Standard fees are blocked.']);
                }
            } elseif ($type->is_freeze_fee || ! empty($charge['freeze_fee_approval_id'])) {
                throw ValidationException::withMessages(['charges' => 'Freeze fees require a frozen membership.']);
            }
            if (! empty($charge['membership_id']) && ! $member->memberships()->whereKey($charge['membership_id'])->exists()) {
                throw ValidationException::withMessages(["charges.{$index}.membership_id" => 'The membership does not belong to this member.']);
            }

            if (! empty($charge['membership_id'])) {
                $membership = $member->memberships()->with('plan')->find($charge['membership_id']);
                if ($membership?->plan?->membership_category_id && (int) $membership->plan->membership_category_id !== (int) $member->membership_category_id) {
                    throw ValidationException::withMessages(['membership_category_id' => 'The selected membership plan conflicts with the member category. Review the mapping first.']);
                }
            }

            return [
                'fee_type' => $type,
                'membership_id' => $charge['membership_id'] ?? ($member->memberships()->where('status', 'active')->count() === 1 ? $member->memberships()->where('status', 'active')->value('id') : null),
                'description' => trim($charge['description'] ?? '') ?: $type->name,
                'amount' => number_format((float) $charge['amount'], 2, '.', ''),
                'gl_account' => $type->gl_account,
                'reconciliation_account' => $reconciliation,
            ];
        });
    }

    private function createCurrentCharges(Member $member, array $charges, string $key, string $dueOn, User $user): Collection
    {
        $normalized = $this->normalizeCurrentCharges($member, $charges);

        return $normalized->map(function (array $charge, int $index) use ($member, $key, $dueOn, $user) {
            $source = config('services.payment_mode') === 'mock' ? 'mock_generation' : 'challan_generation';
            $period = $charge['fee_type']->code === 'MONTHLY_FEE' ? substr($dueOn, 0, 7) : null;
            if ($period && MemberCharge::where('member_id', $member->id)->where('fee_type_id', $charge['fee_type']->id)->where('billing_period', $period)->where('source_type', $source)->exists()) {
                throw ValidationException::withMessages(['charges' => 'Monthly fee already exists for this member and billing period. Adjust the original instead.']);
            }

            return MemberCharge::firstOrCreate(['generation_key' => $key, 'generation_line' => $index + 1], [
                'member_id' => $member->id, 'membership_id' => $charge['membership_id'], 'fee_type_id' => $charge['fee_type']->id,
                'description' => $charge['description'], 'amount' => $charge['amount'], 'gl_account' => $charge['gl_account'],
                'reconciliation_account' => $charge['reconciliation_account'], 'status' => 'outstanding', 'due_on' => $dueOn,
                'source_type' => $source, 'source_id' => $key.':'.($index + 1), 'billing_period' => $period, 'created_by' => $user->id,
            ]);
        });
    }

    private function prepareItems(Member $member, Collection $previous, Collection $current): Collection
    {
        $items = collect();
        foreach ($previous as $charge) {
            if (! $charge->feeType?->active || $charge->feeType->gl_account !== $charge->gl_account) {
                throw ValidationException::withMessages(['charges' => 'Historical outstanding fee GL requires manual accounting review before rebilling.']);
            }
            $items->push($this->item($member, $charge, true));
        }
        foreach ($current as $charge) {
            if ($charge instanceof MemberCharge) {
                $items->push($this->item($member, $charge, false));
            } else {
                $items->push([
                    'member_charge_id' => null,
                    'fee_type_id' => $charge['fee_type']->id,
                    'description' => $charge['description'],
                    'amount' => $charge['amount'],
                    'gl_account' => $charge['gl_account'],
                    'cost_center' => $member->sportsComplex->cost_center,
                    'profit_center' => $member->sportsComplex->profit_center,
                    'reconciliation_account' => $charge['reconciliation_account'],
                    'is_previous_outstanding' => false,
                ]);
            }
        }
        $accounts = $items->pluck('reconciliation_account')->unique();
        if ($accounts->contains(fn ($code) => (string) $code !== app(MembershipReconciliationService::class)->resolve($member))) {
            throw ValidationException::withMessages(['charges' => 'Historical outstanding uses a different REACON. Review it separately before generating a new challan.']);
        }
        if ($accounts->count() !== 1) {
            throw ValidationException::withMessages(['charges' => 'Outstanding and current charges use different reconciliation accounts and require separate challans.']);
        }

        return $items;
    }

    private function item(Member $member, MemberCharge $charge, bool $previous): array
    {
        return [
            'member_charge_id' => $charge->id,
            'fee_type_id' => $charge->fee_type_id,
            'description' => $previous ? 'Previous Outstanding - '.$charge->description : $charge->description,
            'amount' => $charge->amount,
            'gl_account' => $charge->gl_account,
            'cost_center' => $member->sportsComplex->cost_center,
            'profit_center' => $member->sportsComplex->profit_center,
            'reconciliation_account' => $charge->reconciliation_account,
            'is_previous_outstanding' => $previous,
        ];
    }

    private function sendToSap(Challan $challan): Challan
    {
        if (config('services.payment_mode') === 'mock') {
            $challan->update(['environment' => 'mock', 'status' => 'generated', 'sap_status' => 'mock', 'sap_document_no' => 'MOCK-'.$challan->id, 'generated_at' => now()]);

            return $this->loadChallan($challan);
        }
        try {
            $response = $this->requestSapResponse($challan);
            $storedResponse = $this->responseForAudit($response);
            $document = $response->successful() ? $this->extractDocumentNumber($response) : null;
            if (! $response->successful() || ! $document) {
                $message = $response->successful()
                    ? 'SAP service did not return a valid challan number.'
                    : 'SAP service rejected the challan request.';
                $challan->update(['status' => 'failed', 'sap_status' => $response->successful() || $response->serverError() ? 'verification_required' : 'failed', 'response_payload' => $storedResponse, 'failure_message' => $message, 'failed_at' => now()]);
                Log::warning('SAP challan generation failed', ['challan_id' => $challan->id, 'http_status' => $response->status()]);

                return $this->loadChallan($challan);
            }

            DB::transaction(function () use ($challan, $document, $storedResponse) {
                $challan->update([
                    'status' => 'generated',
                    'sap_status' => 'posted',
                    'sap_document_no' => $document,
                    'sap_reference' => $this->extractReference($storedResponse),
                    'response_payload' => $storedResponse,
                    'sap_posted_at' => now(),
                    'generated_at' => now(),
                ]);
                $chargeIds = $challan->items()->where('is_previous_outstanding', true)->pluck('member_charge_id');
                if ($chargeIds->isNotEmpty()) {
                    Challan::where('member_id', $challan->member_id)->where('environment', 'mock')
                        ->where('id', '<>', $challan->id)
                        ->whereIn('status', ['generated', 'issued', 'unpaid', 'expired'])
                        ->whereHas('items', fn ($query) => $query->whereIn('member_charge_id', $chargeIds))
                        ->update(['status' => 'superseded']);
                }
            });
        } catch (ConnectionException $e) {
            $challan->update([
                'status' => 'failed',
                'sap_status' => 'verification_required',
                'failure_message' => 'SAP did not confirm whether the challan was generated. Verify in SAP before retrying.',
                'failed_at' => now(),
            ]);
            Log::error('SAP challan connection failure', ['challan_id' => $challan->id, 'error' => 'connection_failure']);
        } catch (Throwable $e) {
            $challan->update(['status' => 'failed', 'sap_status' => 'verification_required', 'failure_message' => 'Unable to process the SAP response. Reconcile in SAP before any replacement.', 'failed_at' => now()]);
            Log::error('SAP challan processing failure', ['challan_id' => $challan->id]);
        }

        return $this->loadChallan($challan);
    }

    private function requestSapResponse(Challan $challan): Response
    {
        $primary = (string) config('services.sap.challan_url');
        $fallback = (string) config('services.sap.challan_fallback_url');
        $send = fn (string $url) => Http::connectTimeout((int) config('services.sap.connect_timeout', 10))
            ->timeout((int) config('services.sap.timeout', 30))->withoutRedirecting()->get($url, $challan->sap_payload);
        try {
            return $send($primary);
        } catch (ConnectionException $exception) {
            $transport = $exception->getPrevious();
            $context = $transport instanceof GuzzleConnectException ? $transport->getHandlerContext() : [];
            // Only proven DNS/connect failures before any HTTP bytes were sent can fail over.
            // A timeout, an HTTP response or missing transport evidence may conceal a posted challan.
            $notSent = in_array($context['errno'] ?? null, [6, 7], true)
                && array_key_exists('request_size', $context) && (int) $context['request_size'] === 0;
            if (! $notSent || $fallback === '' || $fallback === $primary) {
                throw $exception;
            }
            $challan->update(['request_url' => rtrim($fallback, '?').'?'.http_build_query($challan->sap_payload, '', '&', PHP_QUERY_RFC3986)]);
            Log::warning('SAP creation primary connection unavailable; using configured fallback', ['challan_id' => $challan->id]);

            return $send($fallback);
        }
    }

    private function selectSapCenters(Member $member, ?string $centerOption): Member
    {
        $member->loadMissing('sportsComplex');
        $options = $member->sportsComplex->sap_center_options ?? [];
        if (! $options) {
            if ($centerOption !== null && $centerOption !== '') {
                throw ValidationException::withMessages(['sap_center_option' => 'This complex does not have selectable SAP centers.']);
            }

            return $member;
        }
        $selected = collect($options)->firstWhere('id', $centerOption);
        if (! $centerOption || ! $selected) {
            throw ValidationException::withMessages(['sap_center_option' => 'Select a configured cost and profit center pair for this sports complex.']);
        }
        // Apply the selected pair only to this request, never to the complex default.
        $copy = clone $member;
        $complex = clone $member->sportsComplex;
        $complex->fill(['cost_center' => $selected['cost_center'], 'profit_center' => $selected['profit_center']]);
        $copy->setRelation('sportsComplex', $complex);

        return $copy;
    }

    private function validateApplicantAndAccounting(Member $member): void
    {
        $errors = [];
        if (blank($member->full_name)) {
            $errors['member'] = 'Applicant name is required.';
        }
        if (strlen($this->digits($member->cnic)) !== 13) {
            $errors['cnic'] = 'A valid 13-digit CNIC is required.';
        }
        if (strlen($this->digits($member->phone)) < 10) {
            $errors['phone'] = 'A valid phone number is required.';
        }
        if (blank($member->address)) {
            $errors['address'] = 'Applicant address is required.';
        }
        if (blank($this->fileNumber($member))) {
            $errors['file'] = 'File/Application number is required.';
        }
        if (! $member->sportsComplex->hasSapCenters()) {
            $errors['sports_complex'] = 'An exact Cost Center and Profit Center must be configured for this sports complex.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (blank(config('services.sap.challan_url'))) {
            throw ValidationException::withMessages(['sap' => 'SAP challan endpoint is not configured.']);
        }
    }

    private function extractDocumentNumber(Response $response): ?string
    {
        // The public PHP proxy returns only the SAP document number on success.
        $plain = trim($response->body());
        if (preg_match('/^[0-9]{10}(?:[0-9]{8})?$/D', $plain)) {
            return $plain;
        }
        $json = $response->json();
        if (is_array($json) && (($json['success'] ?? true) === false || in_array(strtolower((string) ($json['status'] ?? '')), ['error', 'failed', 'failure'], true))) {
            return null;
        }
        foreach (['challan_no', 'challanNo', 'ChallanNo', 'document_no', 'documentNumber', 'sap_document_no'] as $key) {
            $value = data_get($json, $key) ?? data_get($json, 'data.'.$key) ?? data_get($json, 'result.'.$key);
            if (is_scalar($value) && preg_match('/[A-Za-z0-9][A-Za-z0-9\/-]{2,39}/', (string) $value)) {
                return (string) $value;
            }
        }
        if (! is_array($json) && preg_match('/(?:challan|document)(?:\s+(?:no|number))?\s*[:#-]\s*([A-Za-z0-9][A-Za-z0-9\/-]{2,39})/i', $response->body(), $match)) {
            return $match[1];
        }

        return null;
    }

    private function responseForAudit(Response $response): array
    {
        $json = $response->json();

        return ['http_status' => $response->status(), 'body' => is_array($json) ? $json : mb_substr($response->body(), 0, 20000)];
    }

    private function extractReference(array $response): ?string
    {
        foreach (['reference', 'reference_no', 'sap_reference'] as $key) {
            $value = data_get($response, 'body.'.$key) ?? data_get($response, 'body.data.'.$key);
            if (is_scalar($value)) {
                return mb_substr((string) $value, 0, 80);
            }
        }

        return null;
    }

    private function loadChallan(Challan $challan): Challan
    {
        return $challan->fresh(['member', 'sportsComplex', 'items', 'creator']);
    }

    private function sum(Collection $items): string
    {
        $cents = $items->sum(fn ($item) => (int) round((float) data_get($item, 'amount') * 100));

        return number_format($cents / 100, 2, '.', '');
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    private function fileNumber(Member $member): string
    {
        return trim((string) ($member->membership_no ?: $member->customer_no));
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
