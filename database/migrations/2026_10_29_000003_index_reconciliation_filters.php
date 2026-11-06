<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', fn (Blueprint $t) => $t->index('membership_category_id'));
        Schema::table('challans', function (Blueprint $t) {
            $t->index('membership_category_id');
            $t->index(['reconciliation_account', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('members', fn (Blueprint $t) => $t->dropIndex(['membership_category_id']));
        Schema::table('challans', function (Blueprint $t) {
            $t->dropIndex(['membership_category_id']);
            $t->dropIndex(['reconciliation_account', 'status']);
        });
    }
};
