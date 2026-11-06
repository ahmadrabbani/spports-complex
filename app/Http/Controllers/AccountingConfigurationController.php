<?php

namespace App\Http\Controllers;

use App\Models\FeeType;
use App\Models\SportsComplex;
use Illuminate\Http\Request;

class AccountingConfigurationController extends Controller
{
    public function updateFeeType(Request $request, FeeType $feeType)
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_if($request->user()->sports_complex_id, 403, 'Global fee configuration requires an unscoped administrator.');
        $feeType->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'short_text' => ['nullable', 'string', 'max:50'],
            'gl_account' => ['nullable', 'string', 'max:20', 'regex:/^\d+$/'],
            'reconciliation_account' => ['prohibited'],
            'active' => ['sometimes', 'boolean'],
            'is_freeze_fee' => ['sometimes', 'boolean'],
            'freeze_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
        ]));

        return $feeType->fresh();
    }

    public function updateComplex(Request $request, SportsComplex $sportsComplex)
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_unless($request->user()->isSuperAdmin(), 403, 'SAP configuration requires the central administrator.');
        $sportsComplex->update($request->validate([
            'cost_center' => ['required', 'string', 'max:30', 'regex:/^\d+$/'],
            'profit_center' => ['required', 'string', 'max:30', 'regex:/^\d+$/'],
        ]));

        return $sportsComplex->fresh();
    }
}
