<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_complexes', function (Blueprint $table) {
            $table->string('cost_center', 30)->nullable()->after('account_group');
            $table->string('profit_center', 30)->nullable()->after('cost_center');
        });

        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('short_text', 50)->nullable();
            $table->string('gl_account', 20)->nullable();
            $table->string('reconciliation_account', 20)->nullable()->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('member_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->string('gl_account', 20);
            $table->string('reconciliation_account', 20);
            $table->enum('status', ['outstanding', 'paid', 'cancelled', 'reversed'])->default('outstanding')->index();
            $table->date('due_on')->nullable()->index();
            $table->string('source_type', 40)->default('manual');
            $table->string('source_id', 100)->nullable();
            $table->uuid('generation_key')->nullable();
            $table->unsignedSmallInteger('generation_line')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['generation_key', 'generation_line']);
            $table->index(['member_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('challans', function (Blueprint $table) {
            $table->string('status', 30)->default('processing')->change();
            $table->string('sap_status', 30)->default('processing')->change();
            $table->uuid('idempotency_key')->nullable()->unique()->after('membership_id');
            $table->foreignId('regenerates_challan_id')->nullable()->after('idempotency_key')->constrained('challans')->nullOnDelete();
            $table->decimal('previous_outstanding', 12, 2)->default(0)->after('purpose');
            $table->decimal('current_amount', 12, 2)->default(0)->after('previous_outstanding');
            $table->string('sap_reference', 80)->nullable()->after('sap_document_no');
            $table->text('request_url')->nullable()->after('sap_payload');
            $table->json('response_payload')->nullable()->after('request_url');
            $table->text('failure_message')->nullable()->after('response_payload');
            $table->timestamp('generated_at')->nullable()->after('failure_message');
            $table->timestamp('failed_at')->nullable()->after('generated_at');
        });

        Schema::create('challan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_charge_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->string('gl_account', 20);
            $table->string('cost_center', 30);
            $table->string('profit_center', 30);
            $table->string('reconciliation_account', 20);
            $table->boolean('is_previous_outstanding')->default(false);
            $table->timestamps();
            $table->unique(['challan_id', 'member_charge_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('challan_id')->nullable()->after('membership_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('challan_id'));
        Schema::dropIfExists('challan_items');
        Schema::table('challans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('regenerates_challan_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'previous_outstanding', 'current_amount', 'sap_reference', 'request_url', 'response_payload', 'failure_message', 'generated_at', 'failed_at']);
        });
        Schema::dropIfExists('member_charges');
        Schema::dropIfExists('fee_types');
        Schema::table('sports_complexes', fn (Blueprint $table) => $table->dropColumn(['cost_center', 'profit_center']));
    }
};
