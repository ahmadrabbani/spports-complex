<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('sports_complex_id')->constrained()->restrictOnDelete();
            $table->string('customer_no', 20)->index();
            $table->string('company_code', 10)->nullable();
            $table->string('assignment', 40)->nullable();
            $table->string('document_no', 40)->index();
            $table->string('document_type', 10)->index();
            $table->date('document_date')->index();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('PKR');
            $table->string('clearing_document', 40)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('source_file');
            $table->unsignedInteger('source_page')->nullable();
            $table->char('source_hash', 64)->unique();
            $table->timestamp('imported_at');
            $table->timestamps();
            $table->index(['sports_complex_id', 'document_date']);
            $table->index(['member_id', 'document_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_transactions');
    }
};
