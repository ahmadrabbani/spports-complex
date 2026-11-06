<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Facility;
use App\Services\AppointmentService;
use App\Services\MemberLedgerService;
use Illuminate\Http\Request;

class PortalAppointmentController extends Controller
{
    public function __construct(private AppointmentService $service) {}

    public function dashboard(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'member', 403);
        $appointments = $user->appointments()->with(['sportsComplex:id,name,slug', 'purpose:id,name'])->latest('appointment_date')->get();

        $member = $user->member;
        $sportsComplex = $member?->sportsComplex()->first(['sports_complexes.id', 'name', 'code']);
        $unpaid = $member ? $member->challans()->whereIn('status', ['generated', 'issued', 'unpaid', 'expired']) : null;
        $sapTransactions = $member ? $member->transactions() : null;
        $sapTransactionCount = $sapTransactions ? (clone $sapTransactions)->count() : 0;
        $sapDebits = $sapTransactionCount ? (float) (clone $sapTransactions)->where('amount', '>', 0)->sum('amount') : 0.0;
        $sapCredits = $sapTransactionCount ? abs((float) (clone $sapTransactions)->where('amount', '<', 0)->sum('amount')) : 0.0;
        $maintenance = collect();
        if ($member) {
            $hasActiveMembership = $member->memberships()->where('status', 'active')
                ->whereDate('starts_on', '<=', today())
                ->where(fn ($query) => $query->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()))
                ->exists();
            if ($hasActiveMembership) {
                $maintenance = Facility::where('sports_complex_id', $member->sports_complex_id)
                    ->where('is_active', true)->where('operational_status', 'maintenance')
                    ->get()->map(fn (Facility $facility) => [
                        'id' => 'maintenance-'.$facility->id,
                        'type' => 'facility_maintenance',
                        'title' => $facility->name.' is under maintenance',
                        'message' => $facility->maintenance_notice ?: 'This facility is temporarily unavailable.',
                        'action_url' => '/my-facilities',
                    ]);
            }
        }

        return [
            'user' => $user,
            'sports_complex' => $sportsComplex,
            'upcoming' => $appointments->first(fn ($appointment) => $appointment->appointment_date->isFuture() && ! in_array($appointment->status, ['cancelled', 'completed', 'no_show'])),
            'appointments' => $appointments,
            'notifications' => $maintenance->concat($user->portalNotifications()->limit(10)->get())->values(),
            'unpaid_challans' => [
                'count' => $unpaid ? (clone $unpaid)->count() : 0,
                'amount' => number_format($unpaid ? (float) (clone $unpaid)->sum('amount') : 0, 2, '.', ''),
            ],
            'account_summary' => [
                'source' => $sapTransactionCount ? 'sap_customer_ledger' : 'application_challans',
                'transaction_count' => $sapTransactionCount,
                'total_charged' => number_format($sapDebits, 2, '.', ''),
                'total_paid' => number_format($sapCredits, 2, '.', ''),
                'balance' => number_format($sapDebits - $sapCredits, 2, '.', ''),
            ],
        ];
    }

    public function ledger(Request $request)
    {
        abort_unless($request->user()->role === 'member', 403);

        return app(MemberLedgerService::class)->forMember($request->user()->member);
    }

    public function slots(Request $request)
    {
        $data = $request->validate(['sports_complex_id' => ['required', 'exists:sports_complexes,id'], 'date' => ['required', 'date', 'after:today']]);

        return ['slots' => $this->service->availableSlots((int) $data['sports_complex_id'], $data['date'])];
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'member', 403);
        $data = $request->validate([
            'sports_complex_id' => ['required', 'exists:sports_complexes,id'],
            'appointment_purpose_id' => ['required', 'exists:appointment_purposes,id'],
            'appointment_date' => ['required', 'date', 'after:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
        ]);

        return response()->json($this->service->book($request->user(), $data), 201);
    }

    public function show(Request $request, Appointment $appointment)
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);

        return $appointment->load(['user:id,name,email,cnic,phone', 'sportsComplex', 'purpose', 'events.actor']);
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);
        abort_unless(in_array($appointment->status, ['pending', 'confirmed'], true), 409, 'This appointment can no longer be cancelled online.');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->service->transition($appointment, $request->user(), 'cancelled', $data['reason'] ?? 'Cancelled by applicant.');
    }
}
