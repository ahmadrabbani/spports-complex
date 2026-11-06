<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challans', function (Blueprint $table) {
            $table->id();
            $table->string('challan_no', 40)->nullable()->unique();
            $table->foreignId('sports_complex_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_code', 10)->default('2000');
            $table->string('reconciliation_account', 20)->nullable();
            $table->string('purpose');
            $table->decimal('amount', 12, 2);
            $table->date('issued_on')->index();
            $table->date('due_on')->index();
            $table->enum('status', ['draft', 'issued', 'paid', 'cancelled'])->default('issued')->index();
            $table->enum('sap_status', ['not_posted', 'queued', 'posted', 'failed'])->default('not_posted')->index();
            $table->string('sap_document_no', 40)->nullable()->index();
            $table->timestamp('sap_posted_at')->nullable();
            $table->json('sap_payload')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sports_complex_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challans');
    }
};
