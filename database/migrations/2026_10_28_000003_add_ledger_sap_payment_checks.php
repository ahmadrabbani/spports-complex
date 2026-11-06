<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_transactions', function (Blueprint $t) {
            $t->string('sap_payment_status', 30)->nullable()->index();
            $t->string('sap_payment_document', 40)->nullable();
            $t->timestamp('sap_payment_checked_at')->nullable();
        });
        Schema::create('sap_ledger_payment_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_transaction_id')->constrained()->restrictOnDelete();
            $t->uuid('run_id')->index();
            $t->string('challan', 18);
            $t->string('result', 30)->index();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->text('response_payload');
            $t->timestamps();
            $t->unique(['run_id', 'member_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_ledger_payment_checks');
        Schema::table('member_transactions', fn (Blueprint $t) => $t->dropColumn(['sap_payment_status', 'sap_payment_document', 'sap_payment_checked_at']));
    }
};
