<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\OrganizationScopeType;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\UseCases\ConfigureReportTypes;
use Modules\Reporting\Application\UseCases\ManageSchedules;
use Modules\Reporting\Application\UseCases\PrepareReport;
use Modules\Reporting\Application\UseCases\PublishCycles;
use Modules\Reporting\Application\UseCases\ReviewReports;
use Modules\Reporting\Application\UseCases\SubmitReport;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Enums\ReviewDecision;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;
use Modules\Reporting\Infrastructure\Persistence\Models\Schedule;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A small city for Reporting tests: a receiving bureau (HQ), three reporting
 * institutions (A, B, C) and an unrelated one (X). Users get scope records,
 * as the platform's scoped roles require (fail closed).
 *
 * The clock starts on 2026-11-20 09:00 EAT; the monthly Ethiopian schedule
 * publishes Tikimt 2019 (11 October – 9 November 2026), due on 14 November
 * 17:30 EAT, unless a test moves the clock first.
 */
final class ReportingScenario
{
    /** @var array<string, Organization> */
    public array $org = [];

    public ReportType $type;

    public Schedule $schedule;

    public User $admin;

    private int $users = 0;

    public static function build(): self
    {
        $s = new self;
        $type = OrganizationType::query()->firstOrCreate(['code' => 'RPT-BUREAU'], ['name_en' => 'Bureau', 'level_order' => 2, 'category' => 'functional']);
        foreach (['HQ' => ['Public Service Bureau', 'ፐብሊክ ሰርቪስ ቢሮ'], 'A' => ['Trade Bureau', 'ንግድ ቢሮ'], 'B' => ['Health Bureau', 'ጤና ቢሮ'], 'C' => ['Education Bureau', 'ትምህርት ቢሮ'], 'X' => ['Culture Bureau', 'ባህል ቢሮ']] as $key => [$en, $am]) {
            $s->org[$key] = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'RPT-'.$key, 'name_en' => $en, 'name_am' => $am, 'status' => 'active']);
        }
        $s->admin = $s->user([...ReportingRoles::ADMINISTRATOR, ...ReportingRoles::OVERSIGHT], [], true);

        return $s;
    }

    /**
     * A user holding $permissions, scoped to the given institutions or city-wide.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $orgKeys
     */
    public function user(array $permissions, array $orgKeys = [], bool $citywide = false): User
    {
        $this->users++;
        $role = Role::findOrCreate('RPT test role '.$this->users, 'web');
        $role->syncPermissions(array_map(fn (string $p): Permission => Permission::findOrCreate($p, 'web'), array_values(array_unique($permissions))));
        $user = User::factory()->create([
            'status' => 'active', 'name' => 'Reporting User '.$this->users, 'email' => 'rpt'.$this->users.'-'.Str::random(6).'@example.test',
            'default_organization_id' => $orgKeys === [] ? null : $this->org[$orgKeys[0]]->id,
        ]);
        $user->assignRole($role);
        if ($citywide) {
            UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => null, 'scope_type' => OrganizationScopeType::Citywide->value, 'is_active' => true]);
        }
        foreach ($orgKeys as $key) {
            UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $this->org[$key]->id, 'scope_type' => OrganizationScopeType::Self->value, 'is_active' => true]);
        }
        $user->forgetCachedPermissions();
        app(OrganizationScopeService::class)->clearCache();
        app()->forgetScopedInstances();

        return $user->fresh();
    }

    public function preparer(string $key): User
    {
        return $this->user(ReportingRoles::PREPARER, [$key]);
    }

    public function officer(string $key): User
    {
        return $this->user(ReportingRoles::OFFICER, [$key]);
    }

    public function reviewer(string $key = 'HQ'): User
    {
        return $this->user(ReportingRoles::REVIEWER, [$key]);
    }

    public function approver(string $key = 'HQ'): User
    {
        return $this->user([...ReportingRoles::APPROVER, ReportingRoles::GRANT_EXTENSIONS], [$key]);
    }

    public function actor(User $user): ActorContext
    {
        app()->forgetScopedInstances();

        return app(ReportingAccessResolver::class)->contextFor((int) $user->id);
    }

    public static function clock(string $localTime = '2026-11-20 09:00'): CarbonImmutable
    {
        $now = CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa')->utc();
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);

        return $now;
    }

    /**
     * A report type with a published template, and a monthly schedule on the
     * Ethiopian calendar for A, B and C reporting to HQ.
     *
     * @param  array<string, mixed>  $typeOverrides
     * @param  array<string, mixed>  $scheduleOverrides
     * @param  list<string>  $targets
     */
    public function configure(array $typeOverrides = [], array $scheduleOverrides = [], array $targets = ['A', 'B', 'C']): self
    {
        $admin = $this->actor($this->admin);
        $configure = app(ConfigureReportTypes::class);
        $this->type = $configure->saveType($admin, null, [
            'code' => 'MONTHLY-'.Str::upper(Str::random(4)), 'name_en' => 'Monthly performance report', 'name_am' => 'ወርሃዊ የሥራ አፈጻጸም ሪፖርት',
            'frequency' => 'monthly', 'receiving_organization_id' => $this->org['HQ']->id, 'submission_mode' => 'either',
            'confidentiality' => 'internal', 'allow_nil_report' => true, 'allow_self_approval' => false, 'is_active' => true, ...$typeOverrides,
        ]);
        // Two required sections keep the test narratives short.
        $configure->saveTemplateDraft($admin, $this->type->id, [
            ['key' => 'executive_summary', 'title_en' => 'Executive summary', 'title_am' => 'አጠቃላይ ማጠቃለያ', 'is_required' => true],
            ['key' => 'achievements', 'title_en' => 'Achievements', 'title_am' => 'የተከናወኑ ተግባራት', 'is_required' => true],
            ['key' => 'challenges', 'title_en' => 'Challenges and risks', 'title_am' => 'ያጋጠሙ ችግሮችና ሥጋቶች', 'is_required' => false],
        ], ConfigureReportTypes::DEFAULT_WORKFLOW['steps'], []);
        $configure->publishTemplate($admin, $this->type->id);

        $this->schedule = app(ManageSchedules::class)->save($admin, null, [
            'report_type_id' => $this->type->id, 'name_en' => 'Monthly report', 'name_am' => 'ወርሃዊ ሪፖርት',
            'frequency' => 'monthly', 'calendar_basis' => 'ethiopian', 'fiscal_year_start_month' => 11,
            'opens_offset_days' => -7, 'due_offset_days' => 5, 'due_time' => '17:30', 'timezone' => 'Africa/Addis_Ababa',
            'holiday_adjustment' => 'none', 'review_sla_working_days' => 5, 'revision_days' => 5, 'late_policy' => 'accept',
            'receiving_organization_id' => $this->org['HQ']->id, 'starts_on' => '2026-10-11', 'is_active' => true, ...$scheduleOverrides,
        ], array_map(fn (string $key): array => ['organization_id' => $this->org[$key]->id], $targets));

        return $this;
    }

    /** Publishes the period that contains the date (Tikimt 2019 by default). */
    public function publish(string $dateInPeriod = '2026-10-20'): Cycle
    {
        return app(PublishCycles::class)->publishPeriod($this->actor($this->admin), $this->schedule->id, $dateInPeriod);
    }

    public function obligation(Cycle $cycle, string $key): Obligation
    {
        return Obligation::query()->where('cycle_id', $cycle->id)->where('organization_id', $this->org[$key]->id)->firstOrFail();
    }

    /** Saves a complete structured narrative as the user. */
    public function write(Obligation $obligation, User $user, string $text = 'Text'): void
    {
        $prepare = app(PrepareReport::class);
        $draft = $prepare->draft($obligation->fresh());
        $prepare->save($this->actor($user), $obligation->id, [
            'mode' => 'structured',
            'content' => ['executive_summary' => "<p>{$text}</p>", 'achievements' => '<p>የተከናወኑ ተግባራት</p><ul><li>One</li></ul>'],
        ], $draft->lock_version);
    }

    public function submit(Obligation $obligation, User $user, ?string $lateReason = null): Submission
    {
        $draft = app(PrepareReport::class)->draft($obligation->fresh());

        return app(SubmitReport::class)->submit($this->actor($user), $obligation->id, $draft->lock_version, $lateReason);
    }

    public function writeAndSubmit(Obligation $obligation, User $user): Submission
    {
        $this->write($obligation, $user);

        return $this->submit($obligation, $user);
    }

    public function decide(Submission $submission, User $user, ReviewDecision $decision, ?string $reason = null): Submission
    {
        $step = ReviewStep::query()->where('submission_id', $submission->id)->where('status', 'pending')->firstOrFail();

        return app(ReviewReports::class)->decide($this->actor($user), $submission->id, $decision, $reason, $step->step_no);
    }

    /** Review and approval by two different people of the receiving bureau. */
    public function approve(Submission $submission): Submission
    {
        $this->decide($submission, $this->reviewer(), ReviewDecision::Approve);

        return $this->decide($submission, $this->approver(), ReviewDecision::Approve);
    }
}
