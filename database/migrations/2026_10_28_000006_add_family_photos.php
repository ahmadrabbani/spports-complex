<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_members', function (Blueprint $t) {
            $t->string('profile_image_path')->nullable();
            $t->timestamp('photo_updated_at')->nullable();
            $t->foreignId('photo_updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('family_photo_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_member_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('action');
            $t->string('old_path')->nullable();
            $t->string('new_path');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_photo_audits');
        Schema::table('family_members', function (Blueprint $t) {
            $t->dropForeign(['photo_updated_by']);
            $t->dropColumn(['profile_image_path', 'photo_updated_at', 'photo_updated_by']);
        });
    }
};
