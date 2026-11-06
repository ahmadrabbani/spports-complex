<?php

namespace App\Services;

use App\Models\BankApiLog;
use App\Models\BankTransaction;
use App\Models\Challan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BopMpgsService
{
    public function __construct(private SportsComplexChallanService $challanService) {}

    public function configuration(): array
    {
        $merchant = (string) config('services.bop_mpgs.merchant_id');

        return [
            'enabled' => (bool) config('services.bop_mpgs.enabled'),
            'configured' => $this->configured(),
            'environment' => (string) config('services.bop_mpgs.environment', 'test'),
            'base_url' => (string) config('services.bop_mpgs.base_url'),
            'api_version' => (int) config('services.bop_mpgs.api_version', 73),
            'merchant' => $merchant ? str_repeat('*', max(0, strlen($merchant) - 4)).substr($merchant, -4) : null,
            'api_password_configured' => filled(config('services.bop_mpgs.api_password')),
            'webhook_key_configured' => filled(config('services.bop_mpgs.webhook_key')),
            'webhook_supported' => false,
            'verification_method' => 'MPGS RETRIEVE_ORDER inquiry',
        ];
    }

    public function initiate(Challan $challan, User $user, string $initiationKey, bool $mobile = false): BankTransaction
    {
        abort_if($challan->environment === 'mock', 409, 'Mock challans cannot be sent to a bank.');
        $this->assertConfigured();
        abort_unless(in_array($challan->status, ['generated', 'issued', 'unpaid'], true), 409, 'Only an unpaid generated challan can be sent to the bank.');

        if ($existing = BankTransaction::where('initiation_key', $initiationKey)->first()) {
            abort_unless($existing->challan_id === $challan->id, 409, 'This initiation key belongs to another challan.');

            return $existing->load(['challan', 'member']);
        }
        if ($existing = $challan->bankTransactions()->whereIn('status', ['session_created', 'pending', 'verified'])->latest()->first()) {
            return $existing->load(['challan', 'member']);
        }

        $transaction = DB::transaction(function () use ($challan, $user, $initiationKey) {
            $locked = Challan::lockForUpdate()->findOrFail($challan->id);

            return BankTransaction::create([
                'public_id' => (string) Str::uuid(),
                'challan_id' => $locked->id,
                'member_id' => $locked->member_id,
                'environment' => config('services.bop_mpgs.environment', 'test'),
                'order_id' => 'LDA-'.now()->format('ymd').'-'.$locked->id.'-'.substr($initiationKey, 0, 8),
                'status' => 'created',
                'amount' => $locked->amount,
                'currency' => 'PKR',
                'initiation_key' => $initiationKey,
                'created_by' => $user->id,
            ]);
        });

        $payload = [
            'apiOperation' => 'INITIATE_CHECKOUT',
            'apiPassword' => config('services.bop_mpgs.api_password'),
            'apiUsername' => 'merchant.'.config('services.bop_mpgs.merchant_id'),
            'merchant' => config('services.bop_mpgs.merchant_id'),
            'interaction.operation' => 'PURCHASE',
            'interaction.merchant.name' => config('services.bop_mpgs.merchant_name'),
            'interaction.returnUrl' => $mobile ? route('mobile.bank.return') : route('bank.return', $transaction->public_id),
            'order.id' => $transaction->order_id,
            'order.amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'order.currency' => $transaction->currency,
            'order.description' => Str::limit('LDA Sports Complex '.$challan->challan_no, 127, ''),
            'correlationId' => (string) Str::uuid(),
        ];
        $result = $this->postNvp('INITIATE_CHECKOUT', $payload, $transaction);
        $sessionId = data_get($result, 'session.id');
        if (($result['result'] ?? null) !== 'SUCCESS' || ! is_string($sessionId) || $sessionId === '') {
            $transaction->update([
                'status' => 'failed',
                'gateway_result' => $result['result'] ?? null,
                'failure_message' => $this->gatewayError($result, 'The bank did not create a checkout session.'),
                'last_response' => $this->sanitize($result),
                'failed_at' => now(),
            ]);

            return $transaction->fresh(['challan', 'member']);
        }

        $transaction->update([
            'session_id' => $sessionId,
            'success_indicator' => $result['successIndicator'] ?? null,
            'gateway_result' => $result['result'],
            'status' => 'session_created',
            'last_response' => $this->sanitize($result),
            'session_created_at' => now(),
            'failure_message' => null,
        ]);

        return $transaction->fresh(['challan', 'member']);
    }

    public function verify(BankTransaction $transaction): BankTransaction
    {
        abort_if($transaction->provider === 'mock' || $transaction->challan->environment === 'mock', 409, 'Mock transactions cannot be verified by a bank.');
        $this->assertConfigured();
        if ($transaction->status === 'verified') {
            return $transaction->load(['challan', 'member']);
        }

        $payload = [
            'apiOperation' => 'RETRIEVE_ORDER',
            'apiPassword' => config('services.bop_mpgs.api_password'),
            'apiUsername' => 'merchant.'.config('services.bop_mpgs.merchant_id'),
            'merchant' => config('services.bop_mpgs.merchant_id'),
            'order.id' => $transaction->order_id,
            'correlationId' => (string) Str::uuid(),
        ];

        try {
            $result = $this->postNvp('RETRIEVE_ORDER', $payload, $transaction);
        } catch (ConnectionException $exception) {
            $transaction->increment('verification_attempts');
            $transaction->update([
                'status' => 'verification_required',
                'last_verified_at' => now(),
                'failure_message' => 'The bank inquiry did not complete. No payment status was changed.',
            ]);
            throw $exception;
        }

        $gatewayStatus = $result['status'] ?? $result['order.status'] ?? null;
        $gatewayResult = $result['result'] ?? null;
        $reference = $this->reference($result);
        $status = $this->mapStatus($gatewayResult, $gatewayStatus);
        $transaction->increment('verification_attempts');
        $transaction->update([
            'gateway_result' => $gatewayResult,
            'gateway_order_status' => $gatewayStatus,
            'bank_transaction_reference' => $reference,
            'status' => $status,
            'last_response' => $this->sanitize($result),
            'last_verified_at' => now(),
            'failed_at' => $status === 'failed' ? now() : null,
            'failure_message' => $status === 'failed' ? $this->gatewayError($result, 'The bank reports that the payment failed.') : null,
        ]);

        if ($status === 'verified') {
            $this->recordVerifiedPayment($transaction->fresh(), $result);
        }

        return $transaction->fresh(['challan', 'member']);
    }

    public function testConnection(): array
    {
        $this->assertConfigured();
        $orderId = 'LDA-DIAG-'.now()->format('ymdHis');
        $payload = [
            'apiOperation' => 'INITIATE_CHECKOUT',
            'apiPassword' => config('services.bop_mpgs.api_password'),
            'apiUsername' => 'merchant.'.config('services.bop_mpgs.merchant_id'),
            'merchant' => config('services.bop_mpgs.merchant_id'),
            'interaction.operation' => 'PURCHASE',
            'interaction.merchant.name' => config('services.bop_mpgs.merchant_name'),
            'order.id' => $orderId,
            'order.amount' => '1.00',
            'order.currency' => 'PKR',
            'order.description' => 'LDA MPGS sandbox connectivity diagnostic',
            'correlationId' => (string) Str::uuid(),
        ];
        $result = $this->postNvp('DIAGNOSTIC_INITIATE_CHECKOUT', $payload);

        return [
            'connected' => ($result['result'] ?? null) === 'SUCCESS' && filled(data_get($result, 'session.id')),
            'result' => $result['result'] ?? null,
            'session_created' => filled(data_get($result, 'session.id')),
            'order_id' => $orderId,
            'message' => ($result['result'] ?? null) === 'SUCCESS'
                ? 'The MPGS sandbox authenticated the request and created a checkout session.'
                : $this->gatewayError($result, 'The MPGS sandbox did not accept the diagnostic request.'),
        ];
    }

    private function recordVerifiedPayment(BankTransaction $transaction, array $result): void
    {
        $amount = (float) ($result['amount'] ?? $result['order.amount'] ?? 0);
        $currency = (string) ($result['currency'] ?? $result['order.currency'] ?? '');
        $orderId = (string) ($result['id'] ?? $result['order.id'] ?? '');
        if (abs($amount - (float) $transaction->amount) > 0.001 || $currency !== $transaction->currency || $orderId !== $transaction->order_id) {
            $transaction->update([
                'status' => 'verification_required',
                'failure_message' => 'Bank inquiry values do not match the stored order, amount, or currency. No payment was recorded.',
            ]);

            return;
        }

        DB::transaction(function () use ($transaction) {
            $locked = BankTransaction::with('challan.items')->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->verified_at) {
                return;
            }
            $reference = $locked->bank_transaction_reference ?: $locked->order_id;
            Payment::firstOrCreate(
                ['receipt_no' => 'BOP-'.$locked->order_id],
                [
                    'member_id' => $locked->member_id,
                    'membership_id' => $locked->challan->membership_id,
                    'challan_id' => $locked->challan_id,
                    'amount' => $locked->amount,
                    'paid_on' => now()->toDateString(),
                    'method' => 'online',
                    'status' => 'paid',
                    'reference' => $reference,
                    'notes' => 'Verified server-side through BOP MPGS RETRIEVE_ORDER.',
                ]
            );
            $this->challanService->markPaid($locked->challan);
            $locked->update(['status' => 'verified', 'verified_at' => now(), 'failure_message' => null]);
        });
    }

    private function postNvp(string $operation, array $payload, ?BankTransaction $transaction = null): array
    {
        $endpoint = rtrim((string) config('services.bop_mpgs.base_url'), '/').'/api/nvp/version/'.(int) config('services.bop_mpgs.api_version', 73);
        $started = microtime(true);
        $correlationId = (string) ($payload['correlationId'] ?? Str::uuid());
        $log = BankApiLog::create([
            'bank_transaction_id' => $transaction?->id,
            'correlation_id' => $correlationId,
            'environment' => config('services.bop_mpgs.environment', 'test'),
            'operation' => $operation,
            'http_method' => 'POST',
            'endpoint' => parse_url($endpoint, PHP_URL_PATH),
            'request_payload' => $this->sanitize($payload),
            'requested_at' => now(),
        ]);

        try {
            $response = Http::asForm()->accept('text/plain')->timeout((int) config('services.bop_mpgs.timeout', 30))->post($endpoint, $payload);
            $parsed = $this->parseNvp($response);
            $log->update([
                'http_status' => $response->status(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'response_payload' => $this->sanitize($parsed),
                'responded_at' => now(),
                'error_message' => $response->successful() ? null : 'MPGS returned HTTP '.$response->status().'.',
            ]);
            if (! $response->successful()) {
                throw new \RuntimeException('The bank gateway returned HTTP '.$response->status().'.');
            }

            return $parsed;
        } catch (Throwable $exception) {
            $log->update([
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'responded_at' => now(),
                'error_message' => Str::limit($exception->getMessage(), 1000),
            ]);
            throw $exception;
        }
    }

    private function parseNvp(Response $response): array
    {
        $result = [];
        foreach (explode('&', trim($response->body())) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $value = urldecode($value);
            $result[$key] = $value;
            data_set($result, $key, $value);
        }

        return $result;
    }

    private function sanitize(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            if (preg_match('/password|secret|key|card|account|token/i', (string) $key)) {
                $clean[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } else {
                $clean[$key] = is_string($value) ? Str::limit($value, 2000) : $value;
            }
        }

        return $clean;
    }

    private function mapStatus(?string $result, ?string $orderStatus): string
    {
        if ($result === 'SUCCESS' && $orderStatus === 'CAPTURED') {
            return 'verified';
        }
        if (in_array($orderStatus, ['REFUNDED', 'PARTIALLY_REFUNDED', 'EXCESSIVELY_REFUNDED', 'CHARGEBACK_PROCESSED'], true)) {
            return 'reversed';
        }
        if ($result === 'FAILURE' || in_array($orderStatus, ['FAILED', 'CANCELLED'], true)) {
            return 'failed';
        }

        return 'pending';
    }

    private function reference(array $result): ?string
    {
        foreach (['transaction[0].transaction.acquirer.transactionId', 'transaction[0].transaction.reference', 'transaction[0].id', 'transaction.id'] as $key) {
            if (filled($result[$key] ?? null)) {
                return Str::limit((string) $result[$key], 120, '');
            }
        }

        return null;
    }

    private function gatewayError(array $result, string $fallback): string
    {
        foreach (['error.explanation', 'error.cause', 'response.gatewayCode'] as $key) {
            if (filled($result[$key] ?? null)) {
                return Str::limit((string) $result[$key], 1000, '');
            }
        }

        return $fallback;
    }

    private function configured(): bool
    {
        return filled(config('services.bop_mpgs.base_url'))
            && filled(config('services.bop_mpgs.merchant_id'))
            && filled(config('services.bop_mpgs.api_password'));
    }

    private function assertConfigured(): void
    {
        if (! config('services.bop_mpgs.enabled') || ! $this->configured()) {
            throw ValidationException::withMessages(['bank' => 'BOP MPGS test integration is not enabled or configured.']);
        }
        if (config('services.bop_mpgs.environment') !== 'test') {
            throw ValidationException::withMessages(['bank' => 'This build permits the documented MPGS test environment only.']);
        }
    }
}
