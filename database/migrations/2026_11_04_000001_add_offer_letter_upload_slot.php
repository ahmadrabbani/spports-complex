<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', fn (Blueprint $t) => $t->unsignedBigInteger('offer_letter_slot')->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('member_documents', fn (Blueprint $t) => $t->dropColumn('offer_letter_slot'));
    }
};
