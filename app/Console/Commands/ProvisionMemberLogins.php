<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProvisionMemberLogins extends Command
{
    protected $signature = 'members:provision-logins
        {--complex=* : Sports-complex code(s) to provision}
        {--customer=* : Specific SAP customer number(s) to provision}
        {--file=* : Recovery XLSX file(s); only Account-column members are provisioned}
        {--password= : Temporary password; otherwise MEMBER_DEFAULT_PASSWORD is used}
        {--dry-run : Validate and report without creating accounts}';

    protected $description = 'Create CNIC-based member portal accounts with a mandatory first-login password change';

    public function handle(): int
    {
        $password = (string) ($this->option('password') ?: config('member_portal.default_password'));
        if (strlen($password) < 8) {
            $this->error('Set MEMBER_DEFAULT_PASSWORD or pass --password with at least 8 characters.');

            return self::FAILURE;
        }
        if (! $this->option('complex') && ! $this->option('customer') && ! $this->option('file')) {
            $this->error('Select at least one --complex code, --customer number, or recovery --file.');

            return self::FAILURE;
        }

        $query = Member::with('sportsComplex:id,code')->where('status', 'active');
        if ($codes = $this->option('complex')) {
            $query->whereHas('sportsComplex', fn ($q) => $q->whereIn('code', $codes));
        }
        $customers = collect($this->option('customer'));
        foreach ($this->option('file') as $file) {
            if (! is_file($file)) {
                $this->error("Missing recovery file: {$file}");

                return self::FAILURE;
            }
            $customers = $customers->merge($this->accountsFromWorkbook($file));
        }
        if ($customers->isNotEmpty()) {
            $customers = $customers->unique()->values();
            $query->whereIn('customer_no', $customers);
        }

        $selectedAccounts = $customers->count();
        $matchedActive = (clone $query)->count();
        $created = $existing = $invalid = $duplicates = 0;
        foreach ($query->orderBy('id')->cursor() as $member) {
            $cnic = preg_replace('/\D+/', '', (string) $member->cnic);
            if (strlen($cnic) !== 13) {
                $invalid++;

                continue;
            }
            if (User::where('cnic', $cnic)->orWhere('member_id', $member->id)->exists()) {
                $existing++;

                continue;
            }
            if (Member::where('cnic', $member->cnic)->whereKeyNot($member->id)->exists()) {
                $duplicates++;

                continue;
            }
            if (! $this->option('dry-run')) {
                User::create([
                    'name' => $member->full_name,
                    'email' => 'member-'.$member->customer_no.'@portal.invalid',
                    'cnic' => $cnic,
                    'phone' => $this->phone($member->phone),
                    'password' => Hash::make($password),
                    'role' => 'member',
                    'member_id' => $member->id,
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
            }
            $created++;
        }

        $notActive = $selectedAccounts ? max(0, $selectedAccounts - $matchedActive) : 0;
        $this->table(
            ['Ready', 'Existing account', 'Invalid CNIC', 'Duplicate CNIC', 'Inactive/not matched'],
            [[$created, $existing, $invalid, $duplicates, $notActive]],
        );
        $this->info($this->option('dry-run') ? 'Dry run only; no accounts were created.' : 'Member accounts created. Users must change the temporary password after login.');

        return self::SUCCESS;
    }

    private function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (str_starts_with($digits, '92') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match('/^03\d{9}$/', $digits) ? $digits : null;
    }

    private function accountsFromWorkbook(string $file): array
    {
        $accounts = [];
        $workbook = IOFactory::load($file);
        foreach ($workbook->getWorksheetIterator() as $sheet) {
            $rows = $sheet->toArray(null, true, true, false);
            $accountColumn = null;
            $headerRow = null;
            foreach (array_slice($rows, 0, 10, true) as $rowIndex => $row) {
                foreach ($row as $columnIndex => $value) {
                    if (strcasecmp(trim((string) $value), 'Account') === 0) {
                        $accountColumn = $columnIndex;
                        $headerRow = $rowIndex;
                        break 2;
                    }
                }
            }
            if ($accountColumn === null) {
                continue;
            }
            foreach (array_slice($rows, $headerRow + 1) as $row) {
                $account = trim((string) ($row[$accountColumn] ?? ''));
                if (preg_match('/^\d{10}$/', $account)) {
                    $accounts[] = $account;
                }
            }
        }
        $workbook->disconnectWorksheets();

        return array_values(array_unique($accounts));
    }
}
