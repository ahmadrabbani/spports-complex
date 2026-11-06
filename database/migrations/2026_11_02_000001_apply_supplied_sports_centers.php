<?php

use App\Models\SportsComplex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $centers = ['CW' => '21070001', 'GB' => '21040001', 'SZ' => '21030002', 'IG' => '21050001', 'CS' => '21090001', 'MP' => '21080001', 'TP' => '21060001', 'PA' => '21100001', 'HS' => '22010001'];
            foreach ($centers as $code => $center) {
                SportsComplex::where('code', $code)->first()?->update(['cost_center' => $center, 'profit_center' => $center]);
            }
            $options = [];
            for ($i = 1; $i <= 4; $i++) {
                $center = '2101000'.$i;
                $options[] = ['id' => 'JT-'.$i, 'label' => 'Johar Town center '.$i, 'cost_center' => str_pad($center, 10, '0', STR_PAD_LEFT), 'profit_center' => str_pad($center, 9, '0', STR_PAD_LEFT)];
            }
            SportsComplex::where('code', 'JT')->first()?->update(['cost_center' => null, 'profit_center' => null, 'sap_center_options' => $options]);
        });
    }

    public function down(): void
    {
        // Restore the audited configuration backup rather than guessing previous SAP identifiers.
    }
};
