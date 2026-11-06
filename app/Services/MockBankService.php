<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\Challan;
use App\Models\MemberCharge;
use App\Models\PaymentVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MockBankService
{
    public const SCENARIOS = ['success', 'failed', 'pending', 'cancelled', 'timeout', 'duplicate_callback', 'amount_mismatch', 'invalid_transaction', 'delayed'];

    public function run(Challan $challan, User $user, string $scenario, string $key): BankTransaction
    {
        abort_unless(config('services.payment_mode') === 'mock' && $challan->environment === 'mock', 409, 'Mock payments require an isolated mock challan.');
        abort_unless(in_array($scenario, self::SCENARIOS, true), 422);

        return DB::transaction(function () use ($challan, $user, $scenario, $key) {
            $locked = Challan::lockForUpdate()->findOrFail($challan->id);
            if ($existing = BankTransaction::where('initiation_key', $key)->first()) {
                abort_unless($existing->challan_id === $locked->id && $existing->provider === 'mock', 409);

                return $existing;
            }
            abort_unless($locked->status === 'generated', 409, 'Only an unpaid generated mock challan can be simulated.');
            $status = match ($scenario) {
                'success','duplicate_callback' => 'verified','pending','delayed' => 'pending','timeout','amount_mismatch','invalid_transaction' => 'verification_required',default => 'failed'
            };
            $tx = BankTransaction::create(['public_id' => (string) Str::uuid(), 'challan_id' => $locked->id, 'member_id' => $locked->member_id, 'provider' => 'mock', 'environment' => 'mock', 'order_id' => 'MOCK-'.Str::uuid(), 'bank_transaction_reference' => 'MOCK-'.Str::uuid(), 'status' => $status, 'amount' => $locked->amount, 'currency' => 'PKR', 'initiation_key' => $key, 'created_by' => $user->id, 'last_response' => ['scenario' => $scenario, 'label' => 'SIMULATION ONLY'], 'verified_at' => $status === 'verified' ? now() : null]);
            PaymentVerification::create(['challan_id' => $locked->id, 'bank_transaction_id' => $tx->id, 'provider' => 'mock', 'verification_result' => $status, 'response_payload' => ['scenario' => $scenario], 'created_by' => $user->id]);
            if ($status === 'verified') {
                $locked->update(['status' => 'paid', 'payment_verified_at' => now()]);
                $this->settleMockCharges($locked);
            }

            return $tx;
        });
    }

    private function settleMockCharges(Challan $c): void
    {
        MemberCharge::whereIn('id', $c->items()->pluck('member_charge_id'))->where('source_type', 'mock_generation')->update(['status' => 'paid', 'paid_at' => now()]);
    }

    public function confirm(BankTransaction $tx, User $user): BankTransaction
    {
        abort_unless(config('services.payment_mode') === 'mock' && $tx->provider === 'mock' && $tx->environment === 'mock', 409);

        return DB::transaction(function () use ($tx, $user) {
            $c = Challan::lockForUpdate()->findOrFail($tx->challan_id);
            $tx = BankTransaction::lockForUpdate()->findOrFail($tx->id);
            abort_unless($c->environment === 'mock', 409);
            if ($tx->status === 'verified') {
                return $tx;
            }
            abort_unless($tx->status === 'pending' && $c->status === 'generated' && $tx->amount === $c->amount, 409);
            $tx->update(['status' => 'verified', 'verified_at' => now()]);
            $c->update(['status' => 'paid', 'payment_verified_at' => now()]);
            $this->settleMockCharges($c);
            PaymentVerification::create(['challan_id' => $c->id, 'bank_transaction_id' => $tx->id, 'provider' => 'mock', 'verification_result' => 'verified', 'created_by' => $user->id]);

            return $tx;
        });
    }
}
