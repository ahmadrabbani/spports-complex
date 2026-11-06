<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_complex_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_complex_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->string('storage_path', 1000)->nullable();
            $table->string('alt_text')->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sports_complex_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_complex_images');
    }
};
