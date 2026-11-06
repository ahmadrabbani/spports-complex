<?php

namespace App\Console\Commands;

use App\Services\BopMpgsService;
use Illuminate\Console\Command;

class TestBopMpgsConnection extends Command
{
    protected $signature = 'bank:mpgs-test-connection';

    protected $description = 'Make a real PKR 1.00 checkout-session request to the configured BOP MPGS test gateway';

    public function handle(BopMpgsService $bank): int
    {
        try {
            $result = $bank->testConnection();
            $this->table(['Environment', 'Connected', 'Session created', 'Diagnostic order'], [[
                config('services.bop_mpgs.environment'),
                $result['connected'] ? 'yes' : 'no',
                $result['session_created'] ? 'yes' : 'no',
                $result['order_id'],
            ]]);
            $this->line($result['message']);

            return $result['connected'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
