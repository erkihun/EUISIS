<?php

declare(strict_types=1);

namespace App\Console\Commands\Core;

use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Contracts\CredentialVerification;
use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Contracts\OrganizationDirectory;
use App\Backbone\Contracts\OrganizationHierarchy;
use App\Backbone\Contracts\PositionDirectory;
use App\Backbone\Contracts\ServiceEligibility;
use App\Backbone\Contracts\UnitDirectory;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\MasterDataWriteMode;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Integration\IntegrationEvent;
use App\Models\Organization;
use App\Support\EthiopianCalendar;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Post-deployment smoke test of Core: the contracts resolve, a real
 * organization, employee and card can be read through them, an unknown
 * credential is refused, the Core and API routes are registered, both
 * languages and the Ethiopian calendar work.
 *
 * Read-only: it runs no write, records no verification and prints no personal
 * data (every line is a pass or a fail).
 */
class CoreSmoke extends Command
{
    protected $signature = 'core:smoke';

    protected $description = 'Read-only post-deployment smoke test of the Core platform';

    private const CONTRACTS = [
        OrganizationDirectory::class, OrganizationHierarchy::class, UnitDirectory::class, PositionDirectory::class,
        EmployeeDirectory::class, AssignmentContextReader::class, CredentialVerification::class, ServiceEligibility::class,
    ];

    private const ROUTES = [
        'login', 'core.dashboard', 'core.login', 'core.assignments', 'core.integration', 'core.audit', 'core.settings',
        'core.employees', 'core.positions', 'core.id-cards',
        'organizations.index', 'positions.index', 'employees.index', 'id-cards.index', 'id-cards.suspend', 'public.verify',
        'api.v1.organizations.show', 'api.v1.employees.assignment', 'api.v1.id-cards.verify', 'api.v1.cards.verify',
        'api.v1.employees.service-eligibility', 'api.v1.changes.index',
    ];

    private const TABLES = ['organizations', 'organization_units', 'positions', 'employees', 'employee_assignments', 'id_cards', 'audit_logs', 'integration_events'];

    private int $failures = 0;

    public function handle(): int
    {
        $this->check('Golden Path tables exist', fn (): bool => collect(self::TABLES)->every(fn (string $table): bool => Schema::hasTable($table)));

        foreach (self::CONTRACTS as $contract) {
            $this->check('Contract '.class_basename($contract).' resolves', fn (): bool => app($contract) instanceof $contract);
        }

        $this->check('An active organization reads back through OrganizationDirectory', function (): bool {
            $organization = Organization::query()->where('status', 'active')->orderBy('id')->first(['id']);

            return $organization !== null
                && app(OrganizationDirectory::class)->find((string) $organization->getKey())?->organizationUuid === (string) $organization->getKey();
        });

        $this->check('An active employee reads back with a current assignment', function (): bool {
            $employee = Employee::query()->where('status', EmployeeStatus::Active->value)->whereNotNull('current_assignment_id')->orderBy('id')->first(['id']);

            return $employee !== null
                && app(EmployeeDirectory::class)->findByUuid((string) $employee->getKey()) !== null
                && app(AssignmentContextReader::class)->currentFor((string) $employee->getKey()) !== null;
        });

        $this->check('A current active card verifies as valid', function (): bool {
            $card = IdCard::query()->where('status', CardStatus::Active->value)->where('is_current', true)->whereNull('revoked_at')
                ->whereNotNull('public_card_uuid')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('id')->first(['id', 'public_card_uuid']);

            return $card !== null && app(CredentialVerification::class)->verifyCardUuid((string) $card->public_card_uuid)->valid;
        });
        $this->check('An unknown credential is refused', fn (): bool => ! app(CredentialVerification::class)->verify('core-smoke-not-a-card')->valid);

        foreach (self::ROUTES as $route) {
            $this->check('Route '.$route, fn (): bool => Route::has($route));
        }

        $this->check('English and Amharic messages load', fn (): bool => __('backbone.master_data_frozen', [], 'en') !== 'backbone.master_data_frozen'
            && __('backbone.master_data_frozen', [], 'am') !== 'backbone.master_data_frozen'
            && __('backbone.master_data_frozen', [], 'am') !== __('backbone.master_data_frozen', [], 'en'));
        $this->check('Ethiopian calendar converts (11 Sep 2026 = 1/1/2019)', fn (): bool => EthiopianCalendar::toEthiopian(new DateTimeImmutable('2026-09-11')) === ['year' => 2019, 'month' => 1, 'day' => 1]);
        $this->check('Master-data writes are open (mode: '.MasterDataWriteMode::current()->value.')', fn (): bool => MasterDataWriteMode::current()->allowsWrites());
        // Post-go-live smoke (Core Stage 7): events reach partners, so the outbox must drain.
        $this->check('No integration event has waited longer than 15 minutes', fn (): bool => ! IntegrationEvent::query()
            ->where('status', IntegrationEvent::STATUS_PENDING)
            ->where('created_at', '<', now()->subMinutes(15))
            ->exists());

        if ($this->failures > 0) {
            $this->components->error($this->failures.' smoke check(s) failed.');

            return self::FAILURE;
        }
        $this->components->info('Every Core smoke check passed.');

        return self::SUCCESS;
    }

    /** @param callable(): bool $probe */
    private function check(string $label, callable $probe): void
    {
        try {
            $passed = $probe();
        } catch (Throwable $e) {
            $passed = false;
            $label .= ' ('.class_basename($e).')';
        }
        $this->failures += $passed ? 0 : 1;
        $this->components->twoColumnDetail($label, $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>');
    }
}
