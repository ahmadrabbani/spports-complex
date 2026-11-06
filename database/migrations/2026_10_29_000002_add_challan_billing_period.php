<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_charges', function (Blueprint $t) {
            $t->string('billing_period', 7)->nullable();
            $t->unique(['member_id', 'fee_type_id', 'billing_period', 'source_type'], 'member_fee_period_environment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('member_charges', function (Blueprint $t) {
            $t->dropUnique('member_fee_period_environment_unique');
            $t->dropColumn('billing_period');
        });
    }
};
