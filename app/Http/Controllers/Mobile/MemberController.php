<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PortalAccountController;
use App\Http\Controllers\PortalAppointmentController;
use App\Models\Appointment;
use App\Models\Challan;
use App\Models\Facility;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function dashboard(Request $request, PortalAppointmentController $portal)
    {
        $data = $portal->dashboard($request);
        $data['user'] = $request->user()->only(['id', 'name', 'email', 'cnic', 'phone', 'member_id', 'must_change_password']);

        return $data;
    }

    public function appointment(Request $request, Appointment $appointment)
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);

        return $appointment->load(['sportsComplex:id,name,slug', 'purpose:id,name', 'events'])->makeHidden(['user']);
    }

    public function account(Request $request, PortalAccountController $accounts)
    {
        $data = $accounts->show($request);
        if ($data['member']) {
            $member = $data['member'];
            $data['member'] = $member->makeHidden(['source_data'])->toArray();
            $data['member']['profile_image_url'] = $member->profile_image_path ? url('/api/mobile/v1/me/photo') : null;
        }

        return $data;
    }

    public function update(Request $request, PortalAccountController $accounts)
    {
        $accounts->update($request);

        return $this->account($request, $accounts);
    }

    public function ledger(Request $request, PortalAppointmentController $portal)
    {
        $data = $portal->ledger($request);
        // Dedicated challan and bank endpoints avoid returning browser session URLs.
        unset($data['challans']);
        $page = max(1, $request->integer('page', 1));
        $size = max(1, min(100, $request->integer('per_page', 25)));
        $rows = collect($data['transactions']);
        $data['transactions'] = $rows->forPage($page, $size)->values();
        $data['meta'] = ['current_page' => $page, 'per_page' => $size, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $size))];

        return $data;
    }

    public function challans(Request $request)
    {
        $member = $request->user()->member;

        return ['data' => $member ? $member->challans()->latest('id')->get([
            'id', 'challan_no', 'purpose', 'amount', 'issued_on', 'due_on', 'status', 'sap_status', 'sap_document_no',
        ]) : []];
    }

    public function challan(Request $request, Challan $challan)
    {
        abort_unless($request->user()->member_id === $challan->member_id, 403);

        return $challan->only(['id', 'challan_no', 'purpose', 'amount', 'previous_outstanding', 'current_amount', 'issued_on', 'due_on', 'status', 'sap_status', 'sap_document_no']) + [
            'items' => $challan->items()->get(['id', 'description', 'amount', 'gl_account']),
            'print_url' => url('/api/mobile/v1/challans/'.$challan->id.'/print'),
        ];
    }

    public function facilities(Request $request)
    {
        $member = $request->user()->member;
        $plans = $member && $member->status === 'active' && $member->reconciliation_account !== '260111' ? $member->memberships()->where('status', 'active')->whereDate('starts_on', '<=', today())
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()))->pluck('membership_plan_id') : collect();
        $facilities = $plans->isEmpty() ? collect() : Facility::where('sports_complex_id', $member->sports_complex_id)->where('is_active', true)
            ->where(fn ($q) => $q->whereDoesntHave('membershipPlans')->orWhereHas('membershipPlans', fn ($p) => $p->whereIn('membership_plans.id', $plans)))
            ->orderBy('name')->get();

        return ['data' => $facilities];
    }

    public function notifications(Request $request)
    {
        return $request->user()->portalNotifications()->paginate(max(1, min(100, $request->integer('per_page', 25))));
    }

    public function readNotification(Request $request, UserNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return $notification;
    }
}
