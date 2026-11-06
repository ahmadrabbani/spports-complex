<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $t) {
            $t->string('extraction_status', 30)->nullable()->index();
            $t->text('extraction_message')->nullable();
            $t->text('extraction_payload')->nullable();
            $t->timestamp('extracted_at')->nullable();
            $t->foreignId('extraction_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('family_members', function (Blueprint $t) {
            $t->date('membership_valid_until')->nullable();
            $t->string('membership_validity', 40)->nullable();
            $t->foreignId('offer_letter_document_id')->nullable()->constrained('member_documents')->restrictOnDelete();
            $t->string('offer_row_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('family_members', function (Blueprint $t) {
            $t->dropForeign(['offer_letter_document_id']);
            $t->dropColumn(['membership_valid_until', 'membership_validity', 'offer_letter_document_id', 'offer_row_key']);
        });
        Schema::table('member_documents', function (Blueprint $t) {
            $t->dropForeign(['extraction_reviewed_by']);
            $t->dropColumn(['extraction_status', 'extraction_message', 'extraction_payload', 'extracted_at', 'extraction_reviewed_by']);
        });
    }
};
