<?php

use App\Models\Challan;
use App\Services\SapPaymentVerificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:verify-pending {--limit=10}', function () {
    $count = 0;
    Challan::where('environment', 'live')->whereIn('status', ['generated', 'issued', 'unpaid'])
        ->whereNotNull('sap_document_no')->orderBy('id')->limit(min(50, max(1, (int) $this->option('limit'))))
        ->each(function ($challan) use (&$count) {
            if (preg_match('/^\d{6,30}$/D', (string) $challan->sap_document_no)) {
                app(SapPaymentVerificationService::class)->verify($challan);
                $count++;
            }
        });
    $this->info("Checked {$count} SAP challans; unknown responses never authorize payment.");
});
Schedule::command('payments:verify-pending --limit=10')->hourly()->withoutOverlapping();
