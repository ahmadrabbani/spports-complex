<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('profile_image_path')->nullable()->after('address');
            $table->timestamp('photo_updated_at')->nullable()->after('profile_image_path');
            $table->foreignId('photo_updated_by')->nullable()->after('photo_updated_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_image_path')->nullable()->after('member_id');
        });

        Schema::create('member_photo_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('applicant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->string('source', 30);
            $table->string('old_path')->nullable();
            $table->string('new_path');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_photo_audits');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('profile_image_path'));
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('photo_updated_by');
            $table->dropColumn(['profile_image_path', 'photo_updated_at']);
        });
    }
};
