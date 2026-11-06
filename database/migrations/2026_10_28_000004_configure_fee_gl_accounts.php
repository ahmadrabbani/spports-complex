<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('fee_types')->whereIn('code', ['FM_GENERAL_PUBLIC', 'IM_GENERAL_PUBLIC', 'FREEZE_CUSTOMERS', 'FM_GOVT_OFFICERS', 'MEMBERS_CHILDREN', 'IM_GOVT_OFFICERS', 'FEMERGY_MEMBERSHIP', 'PLATINUM_MEMBERSHIP', 'STUDENT_MEMBERSHIP', 'GUEST_USER', 'LDA_EMPLOYEE'])->update(['active' => false, 'updated_at' => now()]);
            foreach ([
                ['MONTHLY_FEE', 'Monthly Fee', '610400'],
                ['ARREARS_MONTHLY_FEE', 'Arrears Monthly Fee', '610403'],
                ['FINE_MONTHLY_FEE', 'Fine Monthly Fee', '610402'],
                ['FORM_FEE', 'Form Fee', '610404'],
                ['CARD_FEE', 'Card Fee', '610401'],
                ['MEMBERSHIP_REGISTRATION_FEE', 'Membership Registration Fee', '610357'],
            ] as [$code, $name, $gl]) {
                $existing = DB::table('fee_types')->where('code', $code)->first();
                DB::table('fee_types')->updateOrInsert(['code' => $code], ['name' => $name, 'short_text' => $name, 'gl_account' => $gl, 'active' => true, 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Accounting configuration rollback must use the audited pre-change backup.
    }
};
