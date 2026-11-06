<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->foreignId('family_member_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('category', 40)->index();
            $t->string('title', 150);
            $t->string('original_name');
            $t->string('path');
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size');
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['member_id', 'family_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_documents');
    }
};
