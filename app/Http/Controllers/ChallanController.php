<?php

namespace App\Http\Controllers;

use App\Models\Challan;
use App\Models\Member;
use App\Services\ChallanAdjustmentService;
use App\Services\SportsComplexChallanService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChallanController extends Controller
{
    public function __construct(private SportsComplexChallanService $service) {}

    public function index(Request $request)
    {
        $query = Challan::with([
            'member:id,full_name,customer_no,cnic,membership_no',
            'sportsComplex:id,name,code,cost_center,profit_center',
            'creator:id,name',
            'items', 'adjustments.actor:id,name',
        ])->latest('id');
        if ($request->user()->sports_complex_id) {
            $query->where('sports_complex_id', $request->user()->sports_complex_id);
        }
        if ($request->filled('reconciliation_account')) {
            $query->where('reconciliation_account', $request->string('reconciliation_account'));
        }
        if ($request->filled('membership_category_id')) {
            $query->where('membership_category_id', $request->integer('membership_category_id'));
        }
        if ($request->boolean('frozen')) {
            $query->where('member_status_snapshot', 'frozen');
        }
        if ($request->boolean('pending')) {
            $query->whereIn('status', ['generated', 'issued', 'unpaid', 'processing']);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('q')).'%';
            $query->where(fn ($q) => $q->where('challan_no', 'like', $term)
                ->orWhere('sap_document_no', 'like', $term)
                ->orWhereHas('member', fn ($members) => $members->search((string) $request->input('q'))));
        }

        $scope = Challan::query()->when($request->user()->sports_complex_id, fn ($q, $id) => $q->where('sports_complex_id', $id));
        $metrics = [];
        foreach (['mock', 'live'] as $environment) {
            $q = (clone $scope)->where('environment', $environment);
            $metrics[$environment] = ['total' => (clone $q)->count(), 'pending' => (clone $q)->whereIn('status', ['generated', 'issued', 'unpaid'])->count(), 'paid' => (clone $q)->where('status', 'paid')->count(), 'generation_errors' => (clone $q)->where('status', 'failed')->count(), 'outstanding' => (clone $q)->whereIn('status', ['generated', 'issued', 'unpaid'])->sum('amount'), 'verified_collections' => (clone $q)->whereNotNull('payment_verified_at')->where('status', 'paid')->sum('amount')];
        }

        return response()->json([...$query->paginate(min($request->integer('per_page', 20), 100))->toArray(), 'metrics' => $metrics, 'payment_mode' => config('services.payment_mode')]);
    }

    public function preview(Request $request, Member $member)
    {
        $this->authorizeComplex($request, $member->sports_complex_id);
        $data = $this->validateGeneration($request);

        return $this->service->preview($member, $data['charges'], $data['sap_center_option'] ?? null);
    }

    public function store(Request $request)
    {
        $data = $this->validateGeneration($request, true);
        $member = Member::findOrFail($data['member_id']);
        $this->authorizeComplex($request, $member->sports_complex_id);
        $challan = $this->service->generate(
            $member,
            $data['charges'],
            $data['idempotency_key'],
            $data['due_on'],
            $request->user(),
            $data['notes'] ?? null,
            $data['sap_center_option'] ?? null,
        );

        if (! in_array($challan->sap_status, ['posted', 'mock'], true)) {
            return response()->json([
                'message' => $challan->failure_message ?: 'Unable to generate SAP challan.',
                'challan' => $challan,
            ], $challan->sap_status === 'verification_required' ? 409 : 502);
        }

        return response()->json($challan, 201);
    }

    public function adjustments(Request $request, Challan $challan)
    {
        $this->authorizeComplex($request, $challan->sports_complex_id);

        return $challan->adjustments()->with('actor:id,name')->get();
    }

    public function adjust(Request $request, Challan $challan, ChallanAdjustmentService $adjustments)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $this->authorizeComplex($request, $challan->sports_complex_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'proposed_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99'], 'items' => ['nullable', 'array', 'max:20'], 'items.*.id' => ['required', 'integer', 'distinct'], 'items.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99']]);

        return response()->json($adjustments->adjust($challan, $data, $request->user()), 201);
    }

    public function retry(Request $request, Challan $challan)
    {
        $this->authorizeComplex($request, $challan->sports_complex_id);
        $retried = $this->service->retry($challan);

        return response()->json($retried, $retried->sap_status === 'posted' ? 200 : 502);
    }

    public function update(Request $request, Challan $challan)
    {
        $this->authorizeComplex($request, $challan->sports_complex_id);
        $data = $request->validate(['status' => ['required', Rule::in(['paid', 'cancelled'])]]);
        if ($data['status'] === 'paid') {
            abort(409, 'Use authoritative payment verification or the isolated mock simulator.');
        } else {
            abort_unless($request->user()->role === 'admin', 403);
            $this->service->cancel($challan);
        }

        return $challan->fresh(['member', 'sportsComplex', 'items', 'creator']);
    }

    public function print(Request $request, Challan $challan)
    {
        $this->authorizeComplex($request, $challan->sports_complex_id);

        return view('challans.print', ['challan' => $challan->load(['member', 'sportsComplex', 'creator', 'items'])]);
    }

    private function validateGeneration(Request $request, bool $store = false): array
    {
        $rules = [
            'sap_center_option' => ['nullable', 'string', 'max:50'],
            'charges' => ['required', 'array', 'min:1', 'max:20'],
            'charges.*.fee_type_id' => ['required', 'integer', 'exists:fee_types,id'],
            'charges.*.freeze_fee_approval_id' => ['nullable', 'integer', 'exists:freeze_fee_approvals,id'],
            'charges.*.membership_id' => ['nullable', 'integer', 'exists:memberships,id'],
            'charges.*.description' => ['nullable', 'string', 'max:255'],
            'charges.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
        ];
        if ($store) {
            $rules += [
                'member_id' => ['required', 'integer', 'exists:members,id'],
                'idempotency_key' => ['required', 'uuid'],
                'due_on' => ['required', 'date', 'after_or_equal:today'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ];
        }

        return $request->validate($rules);
    }

    private function authorizeComplex(Request $request, int $complexId): void
    {
        if ($request->user()->sports_complex_id && $request->user()->sports_complex_id !== $complexId) {
            abort(403);
        }
    }
}
