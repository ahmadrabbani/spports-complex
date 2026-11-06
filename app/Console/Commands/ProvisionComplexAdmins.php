<?php

namespace App\Console\Commands;

use App\Models\SportsComplex;
use App\Models\User;
use App\Services\StaffAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProvisionComplexAdmins extends Command
{
    protected $signature = 'staff:provision-complex-admins {--apply : Create missing accounts} {--credentials= : New private CSV output path (must not exist)}';

    protected $description = 'Provision one scoped admin per active complex without changing existing accounts or passwords';

    public function handle(StaffAccessService $service): int
    {
        $missing = SportsComplex::where('is_active', true)->whereDoesntHave('staffUsers', fn ($q) => $q->where('role', 'admin'))->orderBy('id')->get();
        $this->line('Missing complex admin accounts: '.$missing->count());
        if (! $this->option('apply') || $missing->isEmpty()) {
            return self::SUCCESS;
        }
        $path = $this->option('credentials') ?: storage_path('app/private/complex-admin-credentials-'.now()->format('Ymd-His').'.csv');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $oldMask = umask(0077);
        $file = fopen($path, 'x');
        umask($oldMask);
        if (! $file) {
            $this->error('Use a new private credentials file; no users were changed.');

            return self::FAILURE;
        }
        chmod($path, 0600);
        try {
            $count = DB::transaction(function () use ($missing, $service, $file) {
                fputcsv($file, ['sports_complex', 'code', 'login_email', 'temporary_password', 'must_change_password']);
                $count = 0;
                foreach ($missing as $complex) {
                    $locked = SportsComplex::lockForUpdate()->findOrFail($complex->id);
                    if (! $locked->is_active || $locked->staffUsers()->where('role', 'admin')->exists()) {
                        continue;
                    }
                    $email = strtolower($locked->code).'.admin@sports.local';
                    if (User::where('email', $email)->exists()) {
                        throw new \RuntimeException('A generated login is already assigned. Resolve the account collision before provisioning.');
                    }
                    $result = $service->createComplexAdmin(['name' => $locked->name.' Administrator', 'email' => $email, 'sports_complex_id' => $locked->id]);
                    if (fputcsv($file, [$locked->name, $locked->code, $email, $result['temporary_password'], 'yes']) === false) {
                        throw new \RuntimeException('Credentials output failed.');
                    }
                    $count++;
                }
                if (! fflush($file)) {
                    throw new \RuntimeException('Credentials output failed.');
                }

                return $count;
            });
            fclose($file);
            $this->info($count.' accounts created. Private credentials: '.$path);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            fclose($file);
            unlink($path);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
