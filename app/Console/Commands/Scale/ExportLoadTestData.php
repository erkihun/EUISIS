<?php

declare(strict_types=1);

namespace App\Console\Commands\Scale;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes the k6 input file (loadtest/k6/euisis.js) from the synthetic scale
 * database: load-test account emails split by role, a sample of public card
 * UUIDs for the ID checker, and search terms. Contains no real personal data.
 */
class ExportLoadTestData extends Command
{
    protected $signature = 'scale:export-loadtest-data {path : Where to write the JSON file}';

    protected $description = 'Export k6 input data from the synthetic scale database (never production).';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Never against production.');

            return self::FAILURE;
        }

        $accounts = DB::table('users')
            ->join('model_has_roles', fn ($j) => $j->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', 'App\\Models\\User'))
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('users.email', 'like', '%@scale.invalid')
            ->get(['users.email', 'roles.name']);

        $data = [
            'employees' => $accounts->where('name', 'Employee')->pluck('email')->values()->all(),
            'hr' => $accounts->where('name', 'HR Officer')->pluck('email')->values()->all(),
            'publicCardUuids' => DB::table('id_cards')->where('card_number', 'like', 'SC-C-%')
                ->whereNotNull('public_card_uuid')->inRandomOrder()->limit(2000)->pluck('public_card_uuid')->all(),
            'searchTerms' => ['Abebe', 'Almaz Dawit', 'Hana', 'SC-1', 'SC-42', 'Meron', 'Tesfaye Kebede', 'SC-150'],
        ];

        if ($data['employees'] === [] || $data['hr'] === []) {
            $this->error('No load-test accounts found; run scale:generate with --login-users first.');

            return self::FAILURE;
        }

        file_put_contents($this->argument('path'), json_encode($data, JSON_PRETTY_PRINT));
        $this->info(sprintf('Wrote %d employee, %d HR accounts and %d card UUIDs.', count($data['employees']), count($data['hr']), count($data['publicCardUuids'])));

        return self::SUCCESS;
    }
}
