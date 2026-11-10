<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\OrganizationScopeType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Str;
use Modules\Letters\Application\Actions\ComposeLetter;
use Modules\Letters\Application\Actions\PublishTemplate;
use Modules\Letters\Application\Actions\RegisterIncomingLetter;
use Modules\Letters\Application\Actions\SaveTemplate;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Contracts\Data\LettersRoles;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterTemplate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A small city for Letters tests: bureau A (with one unit), sub-city B and an
 * unrelated institution C. Users get scope records, as the platform's scoped
 * roles require (fail closed).
 */
final class LettersScenario
{
    /** @var array<string, Organization> */
    public array $org = [];

    public OrganizationUnit $unitA;

    private int $users = 0;

    public static function build(): self
    {
        $s = new self;
        $type = OrganizationType::query()->firstOrCreate(['code' => 'LT-BUREAU'], ['name_en' => 'Bureau', 'level_order' => 2, 'category' => 'functional']);
        foreach (['A' => ['Trade Bureau', 'ንግድ ቢሮ'], 'B' => ['Bole Sub-city', 'ቦሌ ክ/ከተማ'], 'C' => ['Health Bureau', 'ጤና ቢሮ']] as $key => [$en, $am]) {
            $s->org[$key] = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'LT-'.$key, 'name_en' => $en, 'name_am' => $am, 'status' => 'active']);
        }
        $s->unitA = OrganizationUnit::query()->create([
            'organization_id' => $s->org['A']->id, 'unit_type' => 'directorate', 'code' => 'LT-A-HR', 'name_en' => 'HR Directorate', 'name_am' => 'የሰው ሀብት ዳይሬክቶሬት', 'status' => 'active',
        ]);

        return $s;
    }

    /**
     * A user holding $permissions, scoped to the given institutions (or city-wide), with an employee in the first one.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $orgKeys
     */
    public function user(array $permissions, array $orgKeys = [], bool $citywide = false, string $role = ''): User
    {
        $this->users++;
        $roleModel = Role::findOrCreate($role !== '' ? $role : 'LT test role '.$this->users, 'web');
        $roleModel->syncPermissions(array_map(fn (string $p): Permission => Permission::findOrCreate($p, 'web'), $permissions));
        $user = User::factory()->create(['status' => 'active', 'name' => 'Letters User '.$this->users, 'email' => 'lt'.$this->users.'-'.Str::random(6).'@example.test']);
        $user->assignRole($roleModel);
        if ($citywide) {
            UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => null, 'scope_type' => OrganizationScopeType::Citywide->value, 'is_active' => true]);
        }
        foreach ($orgKeys as $key) {
            UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $this->org[$key]->id, 'scope_type' => OrganizationScopeType::Self->value, 'is_active' => true]);
        }
        if ($orgKeys !== []) {
            $employee = Employee::query()->create(['employee_number' => 'LT-'.Str::upper(Str::random(8)), 'first_name' => 'Test', 'last_name' => (string) $this->users, 'full_name' => 'Test '.$this->users, 'status' => 'active']);
            $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $this->org[$orgKeys[0]]->id, 'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true]);
            $employee->update(['current_assignment_id' => $assignment->id]);
            $user->forceFill(['employee_id' => $employee->id])->save();
        }
        $user->forgetCachedPermissions();
        $this->forget();

        return $user->fresh();
    }

    public function registrar(string $key): User
    {
        return $this->user(LettersRoles::REGISTRAR_PERMISSIONS, [$key]);
    }

    public function officer(string $key): User
    {
        return $this->user(LettersRoles::OFFICER_PERMISSIONS, [$key]);
    }

    public function signatory(string $key): User
    {
        return $this->user(LettersRoles::SIGNATORY_PERMISSIONS, [$key]);
    }

    public function administrator(string $key): User
    {
        return $this->user(LettersRoles::ADMINISTRATOR_PERMISSIONS, [$key]);
    }

    public function steward(): User
    {
        return $this->user(LettersRoles::TEMPLATE_STEWARD_PERMISSIONS, [], true);
    }

    /** An account with letters.handle only and no scope: it sees only what is forwarded to it. */
    public function handler(string $key): User
    {
        return $this->user(['letters.handle'], [$key]);
    }

    /** @param array<string, mixed> $overrides */
    public function incoming(string $key, ?User $actor = null, array $overrides = []): Letter
    {
        return app(RegisterIncomingLetter::class)->execute([
            'organization_id' => $this->org[$key]->id,
            'external_party_name' => 'Federal Civil Service Commission',
            'external_reference' => 'FCSC/12/2019',
            'subject' => 'Request for the quarterly staffing return',
            'letter_date' => '2026-11-01',
            'received_on' => '2026-11-03',
            'due_on' => '2026-11-20',
            'priority' => 'urgent',
            ...$overrides,
        ], $actor ?? $this->registrar($key));
    }

    /** @param array<string, mixed> $overrides */
    public function outgoing(string $fromKey, ?string $toKey = 'B', ?User $actor = null, array $overrides = []): Letter
    {
        return app(ComposeLetter::class)->execute([
            'direction' => 'outgoing',
            'organization_id' => $this->org[$fromKey]->id,
            'recipient_organization_id' => $toKey !== null ? $this->org[$toKey]->id : null,
            'external_party_name' => $toKey === null ? 'Ministry of Finance' : null,
            'subject' => 'Transmittal of the staffing return',
            'body' => '<p>Dear {{ recipient.name }}, please find the return attached. Ref {{ letter.number }}.</p>',
            'letter_date' => '2026-11-05',
            ...$overrides,
        ], $actor ?? $this->officer($fromKey));
    }

    /** A published platform template with one custom placeholder. */
    public function platformTemplate(?User $steward = null): LetterTemplate
    {
        $steward ??= $this->steward();
        $template = app(SaveTemplate::class)->execute(null, [
            'owner_organization_id' => null, 'code' => 'TRANSMITTAL', 'title_en' => 'Transmittal letter', 'title_am' => 'የማስተላለፊያ ደብዳቤ', 'category' => 'transmittal', 'language' => 'en',
            'subject' => 'Transmittal: {{ document }}',
            'body' => '<p>To {{ recipient.name }},</p><p>We transmit {{ document }} under our reference {{ letter.number }} dated {{ letter.date_ec }}.</p><p>{{ signatory.name }}</p>',
            'placeholders' => [['key' => 'document', 'label_en' => 'Document transmitted', 'label_am' => 'የተላለፈው ሰነድ', 'required' => true]],
        ], $steward);
        app(PublishTemplate::class)->execute($template, 'First version', $steward);

        return $template->fresh();
    }

    public function forget(): void
    {
        app()->forgetInstance(OrganizationScopeService::class);
        app()->forgetInstance(LettersAccess::class);
    }
}
