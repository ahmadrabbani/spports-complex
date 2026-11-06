<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challans', function (Blueprint $t) {
            $t->string('environment', 20)->default('live')->index();
            $t->timestamp('payment_verified_at')->nullable();
        });
        Schema::create('payment_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('challan_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('provider');
            $t->string('verification_result')->index();
            $t->unsignedSmallInteger('response_code')->nullable();
            $t->text('response_payload')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_verifications');
        Schema::table('challans', fn (Blueprint $t) => $t->dropColumn(['environment', 'payment_verified_at']));
    }
};
