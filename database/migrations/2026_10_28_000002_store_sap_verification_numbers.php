<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['member_transactions', 'challans'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('sap_verification_number', 18)->nullable()->index());
        }
        // Preserve original documents and source hashes. Only source-backed 2026 company-2000 debit documents qualify.
        DB::table('member_transactions')->where('document_type', 'DR')->where('company_code', '2000')
            ->whereBetween('document_date', ['2026-01-01', '2026-12-31'])->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    if (preg_match('/^\d{10}$/D', $row->document_no)) {
                        DB::table('member_transactions')->where('id', $row->id)->update(['sap_verification_number' => $row->document_no.'20002026']);
                    }
                }
            });
        DB::table('challans')->where('company_code', '2000')->whereBetween('issued_on', ['2026-01-01', '2026-12-31'])
            ->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $document = (string) $row->sap_document_no;
                    if (preg_match('/^\d{10}$/D', $document)) {
                        DB::table('challans')->where('id', $row->id)->update(['sap_verification_number' => $document.'20002026']);
                    } elseif (preg_match('/^\d{10}20002026$/D', $document)) {
                        DB::table('challans')->where('id', $row->id)->update(['sap_verification_number' => $document]);
                    }
                }
            });
    }

    public function down(): void
    {
        foreach (['member_transactions', 'challans'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('sap_verification_number'));
        }
    }
};
