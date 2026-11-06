<?php

namespace Database\Seeders;

use App\Models\MembershipCategory;
use App\Models\ReconciliationAccount;
use Illuminate\Database\Seeder;

class ReconciliationAccountSeeder extends Seeder
{
    public const ACCOUNTS = [
        '260108' => 'General Public Family Membership',
        '260110' => 'General Public Individual Membership',
        '260111' => 'Freeze Customers',
        '260112' => 'Govt Officers Family Membership',
        '260114' => "Member's Children Membership",
        '260115' => 'Govt Officer Individual Membership',
        '260116' => 'Femergy Membership',
        '260117' => 'Platinum Membership',
        '260118' => 'Student Membership',
        '260119' => 'Guest User',
        '260120' => 'LDA Employee',
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $code => $name) {
            $account = ReconciliationAccount::firstOrCreate(['code' => (string) $code], ['long_description' => $name, 'short_description' => $name, 'active' => true]);
            MembershipCategory::firstOrCreate(['name' => $name], ['reconciliation_account_id' => $account->id, 'is_freeze' => (string) $code === '260111', 'active' => true]);
        }
    }
}
