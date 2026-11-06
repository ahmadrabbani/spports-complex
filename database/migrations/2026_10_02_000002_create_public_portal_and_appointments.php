<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cnic', 13)->nullable()->unique()->after('email');
            $table->string('phone', 15)->nullable()->after('cnic');
            $table->foreignId('member_id')->nullable()->after('sports_complex_id')->constrained()->nullOnDelete();
        });

        Schema::table('sports_complexes', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
            $table->text('description')->nullable()->after('city');
            $table->string('opening_hours')->nullable()->after('description');
            $table->string('image_url', 1000)->nullable()->after('address');
        });
        foreach (DB::table('sports_complexes')->select('id', 'name')->get() as $complex) {
            DB::table('sports_complexes')->where('id', $complex->id)->update(['slug' => Str::slug($complex->name).'-'.$complex->id]);
        }

        Schema::table('facilities', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->string('icon', 40)->nullable()->after('slug');
            $table->text('description')->nullable()->after('icon');
            $table->string('image_url', 1000)->nullable()->after('description');
        });

        Schema::create('appointment_purposes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('icon', 40)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('appointment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_complex_id')->unique()->constrained()->cascadeOnDelete();
            $table->time('opening_time')->default('09:00');
            $table->time('closing_time')->default('17:00');
            $table->unsignedSmallInteger('slot_duration_minutes')->default(30);
            $table->unsignedSmallInteger('max_appointments_per_slot')->default(1);
            $table->json('working_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('appointment_blocked_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_complex_id')->constrained()->cascadeOnDelete();
            $table->date('blocked_on');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['sports_complex_id', 'blocked_on']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('appointment_no', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('sports_complex_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_purpose_id')->constrained()->restrictOnDelete();
            $table->date('appointment_date')->index();
            $table->time('appointment_time');
            $table->string('status', 30)->default('pending')->index();
            $table->boolean('documents_verified')->default(false);
            $table->boolean('file_entry_completed')->default(false);
            $table->string('file_entry_reference')->nullable()->index();
            $table->text('file_entry_remarks')->nullable();
            $table->timestamp('file_entry_completed_at')->nullable();
            $table->foreignId('file_entry_completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('form_number', 80)->nullable()->unique();
            $table->timestamp('form_number_assigned_at')->nullable();
            $table->foreignId('form_number_assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['sports_complex_id', 'appointment_date', 'appointment_time']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('appointment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('appointment_events');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('appointment_blocked_dates');
        Schema::dropIfExists('appointment_schedules');
        Schema::dropIfExists('appointment_purposes');
        Schema::table('facilities', fn (Blueprint $table) => $table->dropColumn(['slug', 'icon', 'description', 'image_url']));
        Schema::table('sports_complexes', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'description', 'opening_hours', 'image_url']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
            $table->dropUnique(['cnic']);
            $table->dropColumn(['cnic', 'phone']);
        });
    }
};
