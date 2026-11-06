<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_transactions', function (Blueprint $table) {
            $table->string('source_date', 10)->nullable()->after('document_date');
            $table->boolean('date_is_anomalous')->default(false)->after('source_date')->index();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("UPDATE member_transactions SET source_date = substr(document_date, 9, 2) || '.' || substr(document_date, 6, 2) || '.' || substr(document_date, 1, 4)");
        } else {
            DB::statement("UPDATE member_transactions SET source_date = DATE_FORMAT(document_date, '%d.%m.%Y')");
        }
        DB::table('member_transactions')
            ->whereDate('document_date', '<', '2017-01-01')
            ->orWhereDate('document_date', '>', '2029-12-31')
            ->update(['date_is_anomalous' => true]);
    }

    public function down(): void
    {
        Schema::table('member_transactions', function (Blueprint $table) {
            $table->dropColumn(['source_date', 'date_is_anomalous']);
        });
    }
};
