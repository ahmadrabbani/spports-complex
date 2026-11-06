<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('challan_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('bop_mpgs');
            $table->string('environment', 20)->default('test');
            $table->string('order_id', 40)->unique();
            $table->string('session_id', 120)->nullable();
            $table->string('success_indicator', 120)->nullable();
            $table->string('bank_transaction_reference', 120)->nullable()->index();
            $table->string('gateway_result', 40)->nullable();
            $table->string('gateway_order_status', 50)->nullable()->index();
            $table->enum('status', ['created', 'session_created', 'pending', 'verified', 'failed', 'expired', 'reversed', 'verification_required'])->default('created')->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PKR');
            $table->uuid('initiation_key')->unique();
            $table->unsignedInteger('verification_attempts')->default(0);
            $table->timestamp('session_created_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('last_response')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'status']);
            $table->index(['challan_id', 'status']);
        });

        Schema::create('bank_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('correlation_id')->unique();
            $table->string('provider', 30)->default('bop_mpgs');
            $table->string('environment', 20)->default('test');
            $table->string('operation', 60)->index();
            $table->string('http_method', 10);
            $table->string('endpoint', 255);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_api_logs');
        Schema::dropIfExists('bank_transactions');
    }
};
