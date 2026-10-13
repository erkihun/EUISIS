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

        // Public feedback scenarios (loadtest/k6/public-feedback.js); empty until feedback:scale-seed has run.
        $handlers = DB::table('users')->where('email', 'like', 'fb-handler-%@scale.invalid')->orderBy('id')->get(['id', 'email']);
        $handlerByOrganization = DB::table('user_organization_scopes')
            ->whereIn('user_id', $handlers->pluck('id'))->pluck('user_id', 'organization_id');
        $data['feedbackAdmins'] = DB::table('users')->where('email', 'sc-admin@scale.invalid')->exists() ? ['sc-admin@scale.invalid'] : [];
        $data['feedbackHandlers'] = $handlers->map(fn ($u): array => ['id' => (int) $u->id, 'email' => $u->email])->values()->all();
        $data['employeeFeedback'] = DB::table('employee_feedback_tokens as t')
            ->join('employees as e', 'e.id', '=', 't.employee_id')
            ->join('employee_assignments as a', 'a.id', '=', 'e.current_assignment_id')
            ->join('position_services as ps', 'ps.position_id', '=', 'a.position_id')
            ->where('t.status', 'active')->where('e.employee_number', 'like', 'SC-%')->whereNull('ps.deleted_at')
            ->inRandomOrder()->limit(2000)->get(['t.token', 'ps.id as service_id'])
            ->map(fn ($r): array => ['token' => $r->token, 'serviceId' => $r->service_id])->values()->all();
        $data['organizationTokens'] = DB::table('organization_feedback_qr_codes')->where('status', 'active')->pluck('public_token')->all();
        $data['categories'] = DB::table('public_feedback_categories')->whereNull('organization_id')->where('is_active', true)->pluck('id')->all();
        $data['openSubmissions'] = DB::table('employee_service_feedback')
            ->whereIn('type', ['suggestion', 'complaint'])->whereIn('status', ['assigned', 'under_review', 'unrouted'])
            ->where('comment', 'like', 'Synthetic scale submission%')->inRandomOrder()->limit(2000)->get(['id', 'type', 'organization_id'])
            ->filter(fn ($r): bool => isset($handlerByOrganization[$r->organization_id]))
            ->map(fn ($r): array => ['id' => $r->id, 'type' => $r->type, 'handlerId' => (int) $handlerByOrganization[$r->organization_id]])->values()->all();

        if ($data['employees'] === [] || $data['hr'] === []) {
            $this->error('No load-test accounts found; run scale:generate with --login-users first.');

            return self::FAILURE;
        }

        file_put_contents($this->argument('path'), json_encode($data, JSON_PRETTY_PRINT));
        $this->info(sprintf('Wrote %d employee, %d HR accounts and %d card UUIDs.', count($data['employees']), count($data['hr']), count($data['publicCardUuids'])));
        $this->info(sprintf('Public feedback: %d employee tokens, %d institution tokens, %d handlers, %d open submissions.', count($data['employeeFeedback']), count($data['organizationTokens']), count($data['feedbackHandlers']), count($data['openSubmissions'])));

        return self::SUCCESS;
    }
}
