<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_complex_images', function (Blueprint $table) {
            $table->string('title')->nullable()->after('alt_text');
            $table->text('caption')->nullable()->after('title');
            $table->string('facility_category')->default('main_exterior')->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('sports_complex_images', function (Blueprint $table) {
            $table->dropColumn(['title', 'caption', 'facility_category']);
        });
    }
};
