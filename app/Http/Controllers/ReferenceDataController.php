<?php

namespace App\Http\Controllers;

use App\Models\AppointmentPurpose;
use App\Models\FeeType;
use App\Models\MembershipCategory;
use App\Models\MembershipPlan;
use App\Models\SportsComplex;
use Illuminate\Http\Request;

class ReferenceDataController extends Controller
{
    public function __invoke(Request $request)
    {
        return [
            'complexes' => SportsComplex::when($request->user()->sports_complex_id, fn ($q, $id) => $q->whereKey($id))->with(['images', 'facilities' => fn ($query) => $query->with('membershipPlans:id,name')->orderBy('name')])->orderBy('name')->get(),
            'membership_categories' => MembershipCategory::with('account')->where('active', true)->where('is_freeze', false)->orderBy('name')->get(),
            'plans' => MembershipPlan::where('is_active', true)->when($request->user()->sports_complex_id, fn ($q, $id) => $q->where(fn ($p) => $p->whereNull('sports_complex_id')->orWhere('sports_complex_id', $id)))->orderBy('name')->get(),
            'fee_types' => FeeType::where('active', true)->orderBy('name')->get(),
            'appointment_purposes' => AppointmentPurpose::where('is_active', true)->orderBy('name')->get(),
        ];
    }
}
