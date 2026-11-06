<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_charges', function (Blueprint $table) {
            $table->json('source_payload')->nullable()->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('member_charges', function (Blueprint $table) {
            $table->dropColumn('source_payload');
        });
    }
};
