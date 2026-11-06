<?php

use Database\Seeders\ReconciliationAccountSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('long_description');
            $t->string('short_description', 80);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('membership_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->foreignId('reconciliation_account_id')->constrained()->restrictOnDelete();
            $t->boolean('is_freeze')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('members', function (Blueprint $t) {
            $t->string('status', 30)->default('active')->change();
            $t->foreignId('membership_category_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('original_reconciliation_account', 20)->nullable();
        });
        Schema::table('membership_plans', function (Blueprint $t) {
            $t->foreignId('membership_category_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('memberships', function (Blueprint $t) {
            $t->string('status', 30)->default('pending')->change();
        });
        Schema::create('membership_account_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->string('old_account', 20)->nullable();
            $t->string('new_account', 20)->nullable();
            $t->unsignedBigInteger('old_category_id')->nullable();
            $t->unsignedBigInteger('new_category_id')->nullable();
            $t->string('old_status', 30)->nullable();
            $t->string('new_status', 30)->nullable();
            $t->string('reason');
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('membership_freezes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->foreignId('original_category_id')->nullable()->constrained('membership_categories')->restrictOnDelete();
            $t->string('original_account', 20)->nullable();
            $t->string('original_status', 30);
            $t->json('membership_states');
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->text('reason');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('unfrozen_at')->nullable();
            $t->foreignId('unfrozen_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('unfreeze_reason')->nullable();
            $t->timestamps();
            $t->index(['member_id', 'unfrozen_at']);
        });
        Schema::table('fee_types', function (Blueprint $t) {
            $t->boolean('is_freeze_fee')->default(false);
            $t->decimal('freeze_amount', 12, 2)->nullable();
        });
        Schema::table('challans', function (Blueprint $t) {
            $t->foreignId('membership_category_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('membership_category_name')->nullable();
            $t->string('member_status_snapshot', 30)->nullable()->index();
            $t->string('adjustment_status', 40)->nullable()->index();
        });
        Schema::create('freeze_fee_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('membership_freeze_id')->constrained()->restrictOnDelete();
            $t->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->text('reason');
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('challan_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('challan_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('challan_id')->constrained()->restrictOnDelete();
            $t->foreignId('replacement_challan_id')->nullable()->constrained('challans')->restrictOnDelete();
            $t->string('old_account', 20)->nullable();
            $t->string('new_account', 20)->nullable();
            $t->decimal('old_amount', 12, 2);
            $t->decimal('new_amount', 12, 2);
            $t->text('reason');
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 40);
            $t->string('sap_status', 40);
            $t->json('original_snapshot');
            $t->json('proposed_snapshot');
            $t->timestamps();
        });
        (new ReconciliationAccountSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('challan_adjustments');
        Schema::dropIfExists('freeze_fee_approvals');
        Schema::table('challans', function (Blueprint $t) {
            $t->dropConstrainedForeignId('membership_category_id');
            $t->dropIndex(['member_status_snapshot']);
            $t->dropIndex(['adjustment_status']);
            $t->dropColumn(['membership_category_name', 'member_status_snapshot', 'adjustment_status']);
        });
        Schema::table('fee_types', fn (Blueprint $t) => $t->dropColumn(['is_freeze_fee', 'freeze_amount']));
        Schema::dropIfExists('membership_freezes');
        Schema::dropIfExists('membership_account_audits');
        Schema::table('membership_plans', fn (Blueprint $t) => $t->dropConstrainedForeignId('membership_category_id'));
        Schema::table('members', function (Blueprint $t) {
            $t->dropConstrainedForeignId('membership_category_id');
            $t->dropColumn('original_reconciliation_account');
        });
        Schema::dropIfExists('membership_categories');
        Schema::dropIfExists('reconciliation_accounts');
        // Keep widened status columns: narrowing them would destroy frozen history.
    }
};
