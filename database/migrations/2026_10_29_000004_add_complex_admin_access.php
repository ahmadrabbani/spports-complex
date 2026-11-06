<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_complex_admin')->default(false)->index();
        });
        DB::table('users')->where('role', 'admin')->whereNotNull('sports_complex_id')->update(['is_complex_admin' => true]);
        Schema::create('staff_access_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 40);
            $t->json('before')->nullable();
            $t->json('after');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_access_audits');
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['is_complex_admin']);
            $t->dropColumn('is_complex_admin');
        });
    }
};
