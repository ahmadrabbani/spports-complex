<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });

        Schema::table('facilities', function (Blueprint $table) {
            $table->string('operational_status', 20)->default('available')->after('is_active')->index();
            $table->string('maintenance_notice')->nullable()->after('operational_status');
            $table->dateTime('maintenance_starts_at')->nullable()->after('maintenance_notice');
            $table->dateTime('maintenance_ends_at')->nullable()->after('maintenance_starts_at');
        });

        Schema::create('facility_membership_plan', function (Blueprint $table) {
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained()->cascadeOnDelete();
            $table->primary(['facility_id', 'membership_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_membership_plan');
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropIndex(['operational_status']);
            $table->dropColumn(['operational_status', 'maintenance_notice', 'maintenance_starts_at', 'maintenance_ends_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('must_change_password'));
    }
};
