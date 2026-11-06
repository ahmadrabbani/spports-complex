<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('operator')->after('password');
            $table->foreignId('sports_complex_id')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('sports_complex_id');
        });

        Schema::create('sports_complexes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->unique();
            $table->string('account_group', 20)->nullable()->index();
            $table->string('city')->default('Lahore');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('sports_complex_id')->references('id')->on('sports_complexes')->nullOnDelete();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_complex_id')->constrained()->restrictOnDelete();
            $table->string('customer_no', 20)->unique();
            $table->string('membership_no', 80)->nullable();
            $table->string('full_name');
            $table->string('title', 30)->nullable();
            $table->string('cnic', 20)->nullable()->index();
            $table->string('phone', 30)->nullable()->index();
            $table->text('address')->nullable();
            $table->string('postal_code', 15)->nullable();
            $table->string('country', 2)->default('PK');
            $table->string('region', 20)->nullable();
            $table->string('reconciliation_account', 20)->nullable()->index();
            $table->string('source_address_id', 30)->nullable();
            $table->string('source_created_by', 40)->nullable();
            $table->date('source_created_at')->nullable();
            $table->dateTime('source_changed_at')->nullable();
            $table->string('confirmation_status', 20)->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->index();
            $table->json('source_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['sports_complex_id', 'full_name']);
        });

        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_complex_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('duration_months')->default(12);
            $table->decimal('joining_fee', 12, 2)->default(0);
            $table->decimal('recurring_fee', 12, 2)->default(0);
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'yearly', 'one_time'])->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on');
            $table->date('expires_on')->nullable()->index();
            $table->enum('status', ['pending', 'active', 'expired', 'cancelled', 'suspended'])->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_no')->unique();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on')->index();
            $table->enum('method', ['cash', 'card', 'bank', 'online'])->default('cash');
            $table->enum('status', ['paid', 'pending', 'void'])->default('paid');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_complex_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->decimal('hourly_rate', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->enum('status', ['reserved', 'confirmed', 'completed', 'cancelled'])->default('reserved');
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('checksum', 64)->index();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_plans');
        Schema::dropIfExists('members');
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['sports_complex_id']));
        Schema::dropIfExists('sports_complexes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'sports_complex_id', 'is_active']));
    }
};
