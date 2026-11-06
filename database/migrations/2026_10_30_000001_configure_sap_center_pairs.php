<?php

use App\Models\SportsComplex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_complexes', fn (Blueprint $table) => $table->json('sap_center_options')->nullable());
        DB::transaction(function () {
            $pairs = ['CW' => ['21070001', '2107001'], 'GB' => ['21040001', '2104001'], 'SZ' => ['21030002', '2103002'], 'IG' => ['21050001', '2105001'], 'CS' => ['21090001', '2109001'], 'MP' => ['21080001', '2108001'], 'TP' => ['21060001', '2106001'], 'PA' => ['21100001', '2110001'], 'HS' => ['22010001', '2201001']];
            foreach ($pairs as $code => [$cost, $profit]) {
                SportsComplex::where('code', $code)->first()?->update(['cost_center' => $cost, 'profit_center' => $profit]);
            }
            $options = [];
            for ($i = 1; $i <= 4; $i++) {
                $options[] = ['id' => 'JT-'.$i, 'label' => 'Johar Town center '.$i, 'cost_center' => str_pad('2101000'.$i, 10, '0', STR_PAD_LEFT), 'profit_center' => str_pad('210100'.$i, 9, '0', STR_PAD_LEFT)];
            }
            SportsComplex::where('code', 'JT')->first()?->update(['cost_center' => null, 'profit_center' => null, 'sap_center_options' => $options]);
        });
    }

    public function down(): void
    {
        Schema::table('sports_complexes', fn (Blueprint $table) => $table->dropColumn('sap_center_options'));
        // Retain approved center values; restore prior configuration from the release backup if needed.
    }
};
