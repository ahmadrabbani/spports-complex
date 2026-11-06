<?php

namespace App\Http\Controllers;

use App\Models\Challan;
use App\Models\Facility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class PortalAccountController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load(['member.sportsComplex:id,name,code', 'member.memberships.plan']);

        return [
            'user' => $user->only(['id', 'name', 'email', 'cnic', 'phone', 'must_change_password']),
            'member' => $user->member,
        ];
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['required', 'regex:/^03\d{9}$/'],
        ]);
        $user->update($data);

        return ['user' => $user->fresh()];
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()->mixedCase()],
        ]);
        $request->user()->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ])->save();

        $request->user()->tokens()->delete();

        return ['message' => 'Password updated.', 'user' => $request->user()->fresh()];
    }

    public function facilities(Request $request)
    {
        $member = $request->user()->member;
        if (! $member || ($member->status !== 'active' || $member->reconciliation_account === '260111')) {
            return ['membership' => null, 'sports_complex' => null, 'facilities' => [], 'maintenance_notifications' => []];
        }

        $sportsComplex = $member->sportsComplex()->first(['sports_complexes.id', 'name', 'code']);

        $memberships = $member->memberships()->with('plan')
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', today())
            ->where(fn ($query) => $query->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()))
            ->get();
        if ($memberships->isEmpty()) {
            return ['membership' => null, 'sports_complex' => $sportsComplex, 'facilities' => [], 'maintenance_notifications' => []];
        }

        $facilities = Facility::query()
            ->where('sports_complex_id', $member->sports_complex_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return [
            'membership' => $memberships,
            'sports_complex' => $sportsComplex,
            'facilities' => $facilities,
            'maintenance_notifications' => $facilities->where('operational_status', 'maintenance')->map(fn (Facility $facility) => [
                'facility_id' => $facility->id,
                'title' => $facility->name.' is under maintenance',
                'message' => $facility->maintenance_notice ?: 'This facility is temporarily unavailable.',
                'starts_at' => $facility->maintenance_starts_at?->toISOString(),
                'ends_at' => $facility->maintenance_ends_at?->toISOString(),
            ])->values(),
        ];
    }

    public function printChallan(Request $request, Challan $challan)
    {
        abort_unless($request->user()->member_id === $challan->member_id, 403);

        return view('challans.print', ['challan' => $challan->load(['member', 'sportsComplex', 'creator', 'items'])]);
    }
}
