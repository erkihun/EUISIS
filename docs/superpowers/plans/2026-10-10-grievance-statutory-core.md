# Employee Grievance — Regulation 201/2018 Statutory Core (G1–G4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the Employee Grievance module to the Regulation 201/2018 statutory layer that already exists in the repo — adding the initial-resolution pre-grievance stage, the 10-working-day filing window, statutory committee composition validation, and the 15/10/5-working-day statutory deadlines — without building a duplicate module.

**Architecture:** The repo already contains a correct statutory layer (`App\Support\Legal\Regulation201` constants, `App\Services\Legal\StatutoryDeadlineService` for working-day math + immutable snapshots, `App\Services\Legal\StatutoryCommitteeRules` for composition/quorum) that is currently wired only into the Discipline module. This plan connects the existing grievance services (`GrievanceCaseService`, `GrievanceCommitteeService`, `GrievanceDecisionService`) to that layer. New capabilities (initial-resolution stage, filing window) are added as thin additions to `Grievance` + `GrievanceCaseService`, reusing the existing status state machine, audit/event log, and `CodeGeneratorService`. No new parallel engine is introduced.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL, Pest/PHPUnit. UUID primary keys. Inertia React/TS (UI tasks are out of scope for this plan — G1–G4 are backend/statutory; portal wizard G10 and KPIs G9 are deferred).

**Spec:** The pasted Codex prompt (sections §5, §7, §8, §15, §16, §17, §21, §22, §29, §43, §46, §70, §71, §101, §103) and the three audit reports summarized in `docs/regulation-201-2018-traceability.md`. Statutory values are copied verbatim from `app/Support/Legal/Regulation201.php` (do not invent).

## Global Constraints

- **Do not invent law** (§86, §103). Statutory values come only from `Regulation201::rule()`. Rules the regulation text leaves unclear are recorded as NEEDS_DECISION in `docs/regulation-201-2018-traceability.md`, never guessed.
- **Working-day math only via `StatutoryDeadlineService` / `WorkingDayCalculator`** (§16, §72). No date math in controllers, models, or React.
- **Statutory deadline ≠ internal SLA target** (§70). The existing `GrievanceSlaService` config profiles stay as internal targets and must never override the snapshotted statutory `due_at`.
- **Server-side identity + state** (§4, §49). Employee/org/assignment resolved server-side; status transitions enforced in services, never settable from the request body.
- **Historical context must survive transfer/restructure** (§5). Snapshots are frozen values, not live FKs that `nullOnDelete`.
- **Preserve production data** (§101). All schema changes are additive migrations; no destructive changes to `grievances` or related tables. New nullable columns only.
- **Amharic strings written via Write/Edit, never shell** (project memory: shell writes become `???`).
- **Status values:** reuse `App\Enums\GrievanceStatus`; add new cases only where a genuinely new state exists (G1). Do not add states the workflow does not use (§48).
- Every authoritative transition runs in a DB transaction with row locks (follow the existing `lock()` / `lockedCurrentStage()` / `grievances.errors.stale` pattern in `GrievanceCaseService`).

## Review Focus

- **Filing clock on a late initial-resolution outcome.** If `recordInitialResolution(UNRESOLVED)` is recorded weeks after the issue was raised, the 10-working-day filing `due_at` must be computed from the outcome timestamp (the chosen clock-start), not from `written_issue_raised_at`; a grievance submitted on day 11 (working days) must be flagged `is_late_submission = true`. Covered in Task 2.3.
- **Weekend/holiday spanning the 10-day window.** A filing window that crosses an Ethiopian public holiday or weekend must exclude those days (reuse `WorkingDayCalculator`); a submission that is calendar-day-late but working-day-on-time must NOT be flagged late. Covered in Task 2.4.
- **Committee missing the female elected member.** `GrievanceCommitteeService::approve()` on a `CommitteeType::Grievance` committee with 5 members but zero female elected members must fail with the statutory problem, not pass. Where `employee.gender` is unknown, surface `QUALIFICATION_NOT_VERIFIABLE` rather than silently passing (§24). Covered in Task 3.2 and 3.3.
- **Statutory deadline immutable after a later holiday edit.** Once `committee_report_due_at` is snapshotted, inserting a new public holiday inside the window must not move the stored `due_at` (the snapshot supersession contract). Covered in Task 4.3.
- **Formal submission blocked when initial-resolution precondition unmet.** `submitFormalGrievance()` must reject a grievance whose initial-resolution outcome is `RESOLVED` or whose initial stage was never recorded (unless the policy flag permits skipping), server-side, independent of any frontend flag. Covered in Task 2.2.

---

## NEEDS_DECISION (carried into code as config defaults, not invented law)

These remain open legal questions (recorded in `docs/regulation-201-2018-traceability.md`). The plan wires an engineering default behind a config flag so the code works today and the legal answer becomes a one-line config change:

- **ND-1 (G1 proof standard):** What electronically constitutes valid proof of the written submission + discussion. Default: in-system record is sufficient (`config('grievance.initial_resolution.proof') = 'in_system'`). Attachment-supported and external-proof modes left as future config values.
- **ND-2 (G2 clock start):** The exact Art. 33(1) trigger. Default: the recorded initial-resolution outcome (`UNRESOLVED` / `NO_RESPONSE`) timestamp starts the 10-working-day window.
- **ND-3 (G1 supervisor fallback):** Responsible-officer fallback when no immediate supervisor resolves. Default: allow controlled selection of a responsible officer; if neither resolves, flag `RESPONSIBLE_OFFICER_NOT_RESOLVED` and do not auto-resolve.
- **ND-4 (force-majeure acceptance authority):** Which role may accept a late (Art. 33(2)) filing. Default: gated by permission `grievances.intake_review`; captured but never auto-accepted.

---

## Sub-project G1 — Initial-Resolution Pre-Grievance Stage

Spec §7, §8, §9, §10, §17 (partial). Adds the written-issue-to-supervisor + discussion stage that must precede a formal grievance.

### Task 1.1: Initial-resolution enums

**Files:**
- Create: `app/Enums/Grievance/GrievanceInitialResolutionStatus.php`
- Create: `app/Enums/Grievance/GrievanceInitialResolutionOutcome.php`
- Test: `tests/Unit/Grievances/GrievanceInitialResolutionEnumTest.php`

**Interfaces:**
- Produces: `GrievanceInitialResolutionStatus` cases `Pending='pending'`, `Resolved='resolved'`, `Failed='failed'`; method `isTerminal(): bool` (true for Resolved/Failed). `GrievanceInitialResolutionOutcome` cases `Resolved='resolved'`, `Unresolved='unresolved'`, `NoResponse='no_response'`; method `startsFilingClock(): bool` (true for Unresolved/NoResponse).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Grievance\GrievanceInitialResolutionOutcome as Outcome;
use App\Enums\Grievance\GrievanceInitialResolutionStatus as Status;

it('marks resolved and failed as terminal statuses', function () {
    expect(Status::Resolved->isTerminal())->toBeTrue();
    expect(Status::Failed->isTerminal())->toBeTrue();
    expect(Status::Pending->isTerminal())->toBeFalse();
});

it('starts the filing clock only on unresolved or no-response outcomes', function () {
    expect(Outcome::Unresolved->startsFilingClock())->toBeTrue();
    expect(Outcome::NoResponse->startsFilingClock())->toBeTrue();
    expect(Outcome::Resolved->startsFilingClock())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Grievances/GrievanceInitialResolutionEnumTest.php`
Expected: FAIL — enum classes do not exist.

- [ ] **Step 3: Write the enums**

```php
<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

enum GrievanceInitialResolutionStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Failed;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

enum GrievanceInitialResolutionOutcome: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
    case NoResponse = 'no_response';

    public function startsFilingClock(): bool
    {
        return $this === self::Unresolved || $this === self::NoResponse;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Grievances/GrievanceInitialResolutionEnumTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/Grievance/GrievanceInitialResolutionStatus.php app/Enums/Grievance/GrievanceInitialResolutionOutcome.php tests/Unit/Grievances/GrievanceInitialResolutionEnumTest.php
git commit -m "feat(grievance): add initial-resolution enums"
```

### Task 1.2: `grievance_initial_issues` table + model

**Files:**
- Create: `database/migrations/2026_10_10_200000_create_grievance_initial_issues_table.php`
- Create: `app/Models/GrievanceInitialIssue.php`
- Test: `tests/Feature/Grievances/GrievanceInitialIssueTest.php`

**Interfaces:**
- Produces: `GrievanceInitialIssue` model (UUID key) with columns: `id`, `grievance_id` (nullable FK → grievances, set when formal grievance is later created), `employee_id` (FK employees), `employee_assignment_id` (FK, **not** nullOnDelete — restrict so history survives, per §5), `organization_id`, `organization_unit_id` (nullable), `position_id` (nullable FK positions), `responsible_officer_employee_id` (nullable FK employees), `responsible_officer_name_snapshot` (nullable string), `issue_subject` (string), `issue_description` (text), `requested_resolution` (text nullable), `written_issue_raised_at` (timestamp), `discussion_date` (timestamp nullable), `discussion_notes` (text nullable), `officer_response` (text nullable), `outcome` (string nullable — `GrievanceInitialResolutionOutcome`), `status` (string, default `pending`), `resolved_at` (timestamp nullable), `supporting_document_id` (nullable), `metadata` json nullable, timestamps. Casts: `outcome` → enum, `status` → enum, timestamps → datetime. Relations: `grievance()`, `employee()`, `responsibleOfficer()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Grievance\GrievanceInitialResolutionOutcome;
use App\Enums\Grievance\GrievanceInitialResolutionStatus;
use App\Models\Employee;
use App\Models\GrievanceInitialIssue;

it('persists an initial issue with server-resolved identity and casts enums', function () {
    $employee = Employee::factory()->create();

    $issue = GrievanceInitialIssue::create([
        'employee_id' => $employee->id,
        'employee_assignment_id' => $employee->activeAssignment?->id,
        'organization_id' => $employee->organization_id,
        'issue_subject' => 'Grading dispute',
        'issue_description' => 'My grade was not applied per directive.',
        'written_issue_raised_at' => now(),
        'status' => GrievanceInitialResolutionStatus::Pending,
    ]);

    expect($issue->refresh()->status)->toBe(GrievanceInitialResolutionStatus::Pending);

    $issue->update([
        'outcome' => GrievanceInitialResolutionOutcome::Unresolved,
        'status' => GrievanceInitialResolutionStatus::Failed,
        'resolved_at' => now(),
    ]);

    expect($issue->refresh()->outcome)->toBe(GrievanceInitialResolutionOutcome::Unresolved);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Grievances/GrievanceInitialIssueTest.php`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Write the migration and model**

Migration (additive, UUID key, `employee_assignment_id` uses `restrictOnDelete` so a transfer cannot erase the snapshot — contrast the live `nullOnDelete` on `grievances`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_initial_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->nullable()->constrained('grievances')->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignUuid('responsible_officer_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('responsible_officer_name_snapshot')->nullable();
            $table->string('issue_subject');
            $table->text('issue_description');
            $table->text('requested_resolution')->nullable();
            $table->timestamp('written_issue_raised_at');
            $table->timestamp('discussion_date')->nullable();
            $table->text('discussion_notes')->nullable();
            $table->text('officer_response')->nullable();
            $table->string('outcome')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('supporting_document_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('grievance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_initial_issues');
    }
};
```

Model:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceInitialResolutionOutcome;
use App\Enums\Grievance\GrievanceInitialResolutionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrievanceInitialIssue extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'outcome' => GrievanceInitialResolutionOutcome::class,
        'status' => GrievanceInitialResolutionStatus::class,
        'written_issue_raised_at' => 'datetime',
        'discussion_date' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function responsibleOfficer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_officer_employee_id');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan migrate --env=testing && vendor/bin/pest tests/Feature/Grievances/GrievanceInitialIssueTest.php`
Expected: PASS. (If `Employee::factory()->activeAssignment` is not a known accessor, adjust the test to the project's actual active-assignment accessor — confirm against `app/Models/Employee.php` before writing.)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_10_200000_create_grievance_initial_issues_table.php app/Models/GrievanceInitialIssue.php tests/Feature/Grievances/GrievanceInitialIssueTest.php
git commit -m "feat(grievance): add initial-resolution issue table and model"
```

### Task 1.3: `raiseInitialIssue()` + `recordInitialResolution()` on `GrievanceCaseService`

**Files:**
- Modify: `app/Services/Grievances/GrievanceCaseService.php` (add two methods; reuse existing `access->employeeOf($actor)` and `handlers->placementOf($employee)` resolvers seen at `GrievanceCaseService.php:77-94`)
- Modify: `app/Services/Grievances/GrievanceTimeline.php` usage (record events — reuse existing `record()`)
- Config: `config/grievance.php` — add `initial_resolution.proof` default `'in_system'` and `initial_resolution.require_before_formal` default `true` (ND-1, ND-3)
- Test: `tests/Feature/Grievances/GrievanceInitialResolutionFlowTest.php`

**Interfaces:**
- Consumes: `GrievanceInitialIssue` (Task 1.2), outcome enum (Task 1.1), existing `access->employeeOf(User): Employee` and `handlers->placementOf(Employee)` (returns org/unit/position/supervisor) — confirm exact return shape in `GrievanceHandlerRegistry.php:146-163` before coding.
- Produces: `raiseInitialIssue(User $actor, array $data): GrievanceInitialIssue` — resolves identity server-side, ignores any employee/org IDs in `$data`, sets `status=Pending`, `written_issue_raised_at=now()`, records a timeline event. `recordInitialResolution(GrievanceInitialIssue $issue, User $actor, GrievanceInitialResolutionOutcome $outcome, array $data): GrievanceInitialIssue` — sets `outcome`, `status` (Resolved if `$outcome===Resolved` else Failed), `resolved_at=now()`, `discussion_notes`/`officer_response` from `$data`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Grievance\GrievanceInitialResolutionOutcome;
use App\Enums\Grievance\GrievanceInitialResolutionStatus;
use App\Models\User;
use App\Services\Grievances\GrievanceCaseService;

it('resolves employee identity server-side and ignores forged ids', function () {
    $actor = User::factory()->asEmployee()->create();
    $service = app(GrievanceCaseService::class);

    $issue = $service->raiseInitialIssue($actor, [
        'employee_id' => 'forged-uuid-should-be-ignored',
        'organization_id' => 'forged-org',
        'issue_subject' => 'Shift allocation',
        'issue_description' => 'Night shifts assigned without rotation.',
    ]);

    expect($issue->employee_id)->not->toBe('forged-uuid-should-be-ignored');
    expect($issue->employee_id)->toBe($actor->employee->id);
    expect($issue->status)->toBe(GrievanceInitialResolutionStatus::Pending);
});

it('records an unresolved outcome as a failed, filing-eligible resolution', function () {
    $actor = User::factory()->asEmployee()->create();
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($actor, [
        'issue_subject' => 'X', 'issue_description' => 'Y',
    ]);

    $resolved = $service->recordInitialResolution($issue, $actor, GrievanceInitialResolutionOutcome::Unresolved, [
        'discussion_notes' => 'Met 2026-02-01, no agreement.',
    ]);

    expect($resolved->status)->toBe(GrievanceInitialResolutionStatus::Failed);
    expect($resolved->outcome->startsFilingClock())->toBeTrue();
    expect($resolved->resolved_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Grievances/GrievanceInitialResolutionFlowTest.php`
Expected: FAIL — methods not defined. (If `User::factory()->asEmployee()` state does not exist, use the project's established factory helper seen in `tests/Support/GrievanceScenario.php` — read it first and mirror its employee setup.)

- [ ] **Step 3: Implement the two methods**

Add to `GrievanceCaseService` (mirror the existing `createDraft` identity-resolution block; do not trust `$data` IDs):

```php
public function raiseInitialIssue(User $actor, array $data): GrievanceInitialIssue
{
    $employee = $this->access->employeeOf($actor);
    $placement = $this->handlers->placementOf($employee);

    $issue = GrievanceInitialIssue::create([
        'employee_id' => $employee->id,
        'employee_assignment_id' => $placement->assignmentId,
        'organization_id' => $placement->organizationId,
        'organization_unit_id' => $placement->organizationUnitId,
        'position_id' => $placement->positionId,
        'responsible_officer_employee_id' => $placement->supervisorEmployeeId,
        'responsible_officer_name_snapshot' => $placement->supervisorName,
        'issue_subject' => $data['issue_subject'],
        'issue_description' => $data['issue_description'],
        'requested_resolution' => $data['requested_resolution'] ?? null,
        'written_issue_raised_at' => now(),
        'status' => GrievanceInitialResolutionStatus::Pending,
    ]);

    $this->timeline->record($issue, 'initial_issue_raised', visibility: GrievanceEventVisibility::Complainant, actor: $actor);

    return $issue;
}

public function recordInitialResolution(
    GrievanceInitialIssue $issue,
    User $actor,
    GrievanceInitialResolutionOutcome $outcome,
    array $data,
): GrievanceInitialIssue {
    $issue->update([
        'outcome' => $outcome,
        'status' => $outcome === GrievanceInitialResolutionOutcome::Resolved
            ? GrievanceInitialResolutionStatus::Resolved
            : GrievanceInitialResolutionStatus::Failed,
        'discussion_notes' => $data['discussion_notes'] ?? $issue->discussion_notes,
        'officer_response' => $data['officer_response'] ?? $issue->officer_response,
        'discussion_date' => $data['discussion_date'] ?? now(),
        'resolved_at' => now(),
    ]);

    $this->timeline->record($issue, 'initial_resolution_recorded', visibility: GrievanceEventVisibility::Complainant, actor: $actor);

    return $issue->refresh();
}
```

Add `config/grievance.php` keys if the file exists; if not, create it with `return ['initial_resolution' => ['proof' => 'in_system', 'require_before_formal' => true]];`. Confirm the actual `placementOf` return property names before writing — adjust the property accesses (`$placement->assignmentId` etc.) to match.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Grievances/GrievanceInitialResolutionFlowTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Grievances/GrievanceCaseService.php config/grievance.php tests/Feature/Grievances/GrievanceInitialResolutionFlowTest.php
git commit -m "feat(grievance): add raiseInitialIssue and recordInitialResolution service methods"
```

---

## Sub-project G2 — 10-Working-Day Filing Window

Spec §15, §17. Depends on G1 (clock starts at the initial-resolution failure outcome, ND-2).

### Task 2.1: Filing columns on `grievances`

**Files:**
- Create: `database/migrations/2026_10_10_200100_add_grievance_filing_window_columns.php`
- Modify: `app/Models/Grievance.php` (add casts + `initialIssue()` relation)
- Test: `tests/Feature/Grievances/GrievanceFilingColumnsTest.php`

**Interfaces:**
- Produces: nullable additive columns on `grievances`: `initial_issue_id` (FK → grievance_initial_issues, nullOnDelete), `position_id` (FK positions nullable — closes the G5 snapshot gap for the formal record), `immediate_supervisor_employee_id` (nullable FK employees), `filing_clock_started_at` (timestamp nullable), `filing_deadline_at` (timestamp nullable), `is_late_submission` (boolean default false), `late_submission_reason` (text nullable), `cause_started_at` / `cause_ended_at` (timestamp nullable), `late_review_status` (string nullable), `late_reviewed_by` (FK users nullable), `late_reviewed_at` (timestamp nullable), `late_decision_reason` (text nullable). `Grievance::initialIssue()` BelongsTo.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Grievance;
use Illuminate\Support\Facades\Schema;

it('adds the filing-window columns additively', function () {
    foreach ([
        'initial_issue_id', 'position_id', 'immediate_supervisor_employee_id',
        'filing_clock_started_at', 'filing_deadline_at', 'is_late_submission',
        'late_submission_reason', 'cause_started_at', 'cause_ended_at',
        'late_review_status', 'late_reviewed_by', 'late_reviewed_at', 'late_decision_reason',
    ] as $column) {
        expect(Schema::hasColumn('grievances', $column))->toBeTrue("missing {$column}");
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Grievances/GrievanceFilingColumnsTest.php`
Expected: FAIL — columns missing.

- [ ] **Step 3: Write the migration + model casts**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grievances', function (Blueprint $table) {
            $table->foreignUuid('initial_issue_id')->nullable()->after('category_id')->constrained('grievance_initial_issues')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->after('organization_unit_id')->constrained('positions')->nullOnDelete();
            $table->foreignUuid('immediate_supervisor_employee_id')->nullable()->after('position_id')->constrained('employees')->nullOnDelete();
            $table->timestamp('filing_clock_started_at')->nullable();
            $table->timestamp('filing_deadline_at')->nullable();
            $table->boolean('is_late_submission')->default(false);
            $table->text('late_submission_reason')->nullable();
            $table->timestamp('cause_started_at')->nullable();
            $table->timestamp('cause_ended_at')->nullable();
            $table->string('late_review_status')->nullable();
            $table->foreignId('late_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('late_reviewed_at')->nullable();
            $table->text('late_decision_reason')->nullable();

            $table->index('filing_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('grievances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('initial_issue_id');
            $table->dropConstrainedForeignId('position_id');
            $table->dropConstrainedForeignId('immediate_supervisor_employee_id');
            $table->dropConstrainedForeignId('late_reviewed_by');
            $table->dropColumn([
                'filing_clock_started_at', 'filing_deadline_at', 'is_late_submission',
                'late_submission_reason', 'cause_started_at', 'cause_ended_at',
                'late_review_status', 'late_reviewed_at', 'late_decision_reason',
            ]);
        });
    }
};
```

Add to `Grievance` model `$casts`: `filing_clock_started_at`, `filing_deadline_at`, `cause_started_at`, `cause_ended_at`, `late_reviewed_at` → `'datetime'`; `is_late_submission` → `'boolean'`. Add `initialIssue(): BelongsTo { return $this->belongsTo(GrievanceInitialIssue::class, 'initial_issue_id'); }`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan migrate --env=testing && vendor/bin/pest tests/Feature/Grievances/GrievanceFilingColumnsTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_10_200100_add_grievance_filing_window_columns.php app/Models/Grievance.php tests/Feature/Grievances/GrievanceFilingColumnsTest.php
git commit -m "feat(grievance): add filing-window and snapshot columns"
```

### Task 2.2: `submitFormalGrievance()` enforces the initial-resolution precondition

**Files:**
- Modify: `app/Services/Grievances/GrievanceCaseService.php` — add `submitFormalGrievance(GrievanceInitialIssue $issue, User $actor, array $data): Grievance`. Reuse existing `submit()` reference-number generation (`CodeGeneratorService` via `GenerateCodeAction`, `GrievanceCaseService.php:186`) and transaction/lock pattern.
- Modify: `lang/en/grievances.php`, `lang/am/grievances.php` — add error keys `errors.initial_resolution_required`, `errors.initial_resolution_not_failed`.
- Test: `tests/Feature/Grievances/GrievanceFormalSubmissionTest.php`

**Interfaces:**
- Consumes: `GrievanceInitialIssue` with terminal `status`; `config('grievance.initial_resolution.require_before_formal')`.
- Produces: `submitFormalGrievance()` returns a `Grievance` with `initial_issue_id` set, assignment/org/unit/position/supervisor **snapshotted from the issue** (not re-resolved live), `reference_number` generated, status `Submitted`. Throws a domain exception when the precondition is unmet.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Grievance\GrievanceInitialResolutionOutcome as Outcome;
use App\Enums\GrievanceStatus;
use App\Models\User;
use App\Services\Grievances\GrievanceCaseService;

it('blocks formal submission when the initial issue was resolved informally', function () {
    $actor = User::factory()->asEmployee()->create();
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($actor, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue, $actor, Outcome::Resolved, []);

    $service->submitFormalGrievance($issue->refresh(), $actor, [
        'cause_of_grievance' => 'x', 'requested_remedy' => 'y', 'incident_date' => now()->toDateString(),
    ]);
})->throws(\App\Exceptions\Grievances\InitialResolutionRequiredException::class);

it('allows formal submission after an unresolved outcome and snapshots context', function () {
    $actor = User::factory()->asEmployee()->create();
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($actor, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue->refresh(), $actor, Outcome::Unresolved, []);

    $grievance = $service->submitFormalGrievance($issue->refresh(), $actor, [
        'cause_of_grievance' => 'Grade not applied', 'requested_remedy' => 'Apply grade',
        'incident_date' => now()->subDays(3)->toDateString(),
    ]);

    expect($grievance->status)->toBe(GrievanceStatus::Submitted);
    expect($grievance->initial_issue_id)->toBe($issue->id);
    expect($grievance->reference_number)->not->toStartWith('DRAFT-');
    expect($grievance->employee_assignment_id)->toBe($issue->employee_assignment_id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Grievances/GrievanceFormalSubmissionTest.php`
Expected: FAIL — method + exception missing.

- [ ] **Step 3: Implement the method + exception**

Create `app/Exceptions/Grievances/InitialResolutionRequiredException.php` (extends `\DomainException`). In `submitFormalGrievance()`: open a transaction; if `config('grievance.initial_resolution.require_before_formal')` and `$issue->status !== Failed`, throw. Snapshot identity from `$issue` onto the new grievance. Generate reference via the existing path. Record status-history (Task 2.5 will add the dedicated table; for now use the existing timeline event the way `submit()` does). (Write the full method body here mirroring `createDraft`+`submit`; no placeholder.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Grievances/GrievanceFormalSubmissionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Grievances/GrievanceCaseService.php app/Exceptions/Grievances/InitialResolutionRequiredException.php lang/en/grievances.php lang/am/grievances.php tests/Feature/Grievances/GrievanceFormalSubmissionTest.php
git commit -m "feat(grievance): enforce initial-resolution precondition on formal submission"
```

### Task 2.3: Compute + snapshot the 10-working-day filing deadline, flag late

**Files:**
- Modify: `app/Services/Grievances/GrievanceCaseService.php` (inside `submitFormalGrievance`, after identity snapshot)
- Modify: `config/grievance.php` if needed
- Test: extend `tests/Feature/Grievances/GrievanceFormalSubmissionTest.php`

**Interfaces:**
- Consumes: `StatutoryDeadlineService::dueAt(Regulation201::GRIEVANCE_COMMITTEE_FILING, $start)` and `::snapshot(...)`; the clock start is `$issue->resolved_at` (ND-2).
- Produces: `filing_clock_started_at = $issue->resolved_at`, `filing_deadline_at = dueAt(...)`, `is_late_submission = now() > filing_deadline_at`, and a `StatutoryDeadline` snapshot row keyed `grievance.committee_filing`.

- [ ] **Step 1: Write the failing test**

```php
it('snapshots a 10-working-day filing deadline from the resolution date', function () {
    $actor = App\Models\User::factory()->asEmployee()->create();
    $service = app(App\Services\Grievances\GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($actor, ['issue_subject' => 'A', 'issue_description' => 'B']);
    // resolve on a known Monday with no holidays in the next two weeks
    Illuminate\Support\Carbon::setTestNow(Carbon\Carbon::parse('2026-03-02 09:00:00')); // Monday
    $service->recordInitialResolution($issue->refresh(), $actor, App\Enums\Grievance\GrievanceInitialResolutionOutcome::Unresolved, []);

    $grievance = $service->submitFormalGrievance($issue->refresh(), $actor, [
        'cause_of_grievance' => 'x', 'requested_remedy' => 'y', 'incident_date' => '2026-02-20',
    ]);

    // 10 working days from Mon 2026-03-02, excluding weekends => Mon 2026-03-16 end of day
    expect($grievance->filing_deadline_at->toDateString())->toBe('2026-03-16');
    expect($grievance->is_late_submission)->toBeFalse();
    Illuminate\Support\Carbon::setTestNow();
})->todo('verify 2026-03-16 against the civil-service work week + public_holidays seed before asserting');
```

NOTE for implementer: resolve the `todo` by computing the expected date with the actual seeded `public_holidays` for March 2026 and the configured work week, then remove `->todo(...)` and lock the assertion. Do not guess the date.

- [ ] **Step 2: Run test to verify it fails** — Run the file; expected FAIL (deadline null).
- [ ] **Step 3: Implement** the deadline computation + snapshot inside `submitFormalGrievance` using `StatutoryDeadlineService`.
- [ ] **Step 4: Run test to verify it passes.**
- [ ] **Step 5: Commit** `feat(grievance): snapshot statutory 10-working-day filing deadline`.

### Task 2.4: Weekend/holiday correctness test (Review Focus)

**Files:** Test only: extend `tests/Feature/Grievances/GrievanceFilingDeadlineTest.php`.

- [ ] **Step 1:** Write a test that seeds a public holiday inside a 10-working-day window and asserts the deadline rolls past it, and a second asserting a calendar-late-but-working-on-time submission is `is_late_submission = false`. Compute expected dates from the seed; no guesses.
- [ ] **Step 2:** Run → may already pass (logic from 2.3). If it fails, fix 2.3's implementation.
- [ ] **Step 3:** Commit `test(grievance): cover weekend/holiday filing-window edges`.

### Task 2.5: Force-majeure late-filing capture (no auto-accept) + immutable status history

**Files:**
- Create: `database/migrations/2026_10_10_200200_create_grievance_status_histories_table.php` (columns: `id`, `grievance_id` FK, `from_status` nullable, `to_status`, `actor_user_id` nullable, `actor_employee_id` nullable, `reason` nullable, `transitioned_at`; no `updated_at`; append-only — closes G8/§66)
- Create: `app/Models/GrievanceStatusHistory.php`
- Modify: `GrievanceCaseService` — write a status-history row on every status transition it performs; add `reviewLateSubmission(Grievance $g, User $actor, bool $accept, string $reason): Grievance` gated by `grievances.intake_review` (ND-4), setting `late_review_status`, `late_reviewed_by`, `late_reviewed_at`, `late_decision_reason`. Never auto-accept.
- Test: `tests/Feature/Grievances/GrievanceLateFilingTest.php`

- [ ] **Step 1:** Write failing tests: (a) a late submission is recorded with `is_late_submission=true` and `late_review_status` null until reviewed; (b) `reviewLateSubmission(accept:true)` requires the permission and records the reviewer; (c) every transition appends a `GrievanceStatusHistory` row with correct `from_status`/`to_status`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement migration, model, service changes.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(grievance): force-majeure late-filing review and immutable status history`.

---

## Sub-project G3 — Statutory Committee Composition Enforcement

Spec §21, §22, §24. The validator exists; wire it in.

### Task 3.1: `selection_source` + `Secretary` role as first-class values

**Files:**
- Create: `app/Enums/Grievance/GrievanceCommitteeSelectionSource.php` (cases `AppointedByHead='appointed'`, `ElectedByEmployees='elected'`, `HrRepresentative='hr_representative'` — values match `StatutoryCommitteeRules::APPOINTED/ELECTED/HR_REPRESENTATIVE`)
- Modify: `app/Enums/Grievance/GrievanceCommitteeRole.php` — add `Secretary='secretary'` as a first-class case while keeping the legacy `Writer` mapping in `GrievanceCommitteeMember::roleEnum()` working (do not break existing rows)
- Test: `tests/Unit/Grievances/GrievanceCommitteeSelectionSourceTest.php`

- [ ] **Step 1:** Write failing test asserting the three selection-source values equal the `StatutoryCommitteeRules` constants and that `GrievanceCommitteeRole::Secretary` exists and `roleEnum('secretary')` resolves without breaking `'writer'`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add the enum + role case (keep backward-compatible mapping).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(grievance): first-class committee selection-source and secretary role`.

### Task 3.2: Call `StatutoryCommitteeRules::compositionProblems()` from `approve()`

**Files:**
- Modify: `app/Services/Grievances/GrievanceCommitteeService.php` — in `approve()` (line ~94-115), when `$committee->type === CommitteeType::Grievance`, merge `app(StatutoryCommitteeRules::class)->compositionProblems($committee)` into the existing `committeeProblems()` result and block approval if non-empty.
- Modify: `lang/en/grievanceAdmin.ts` / `lang/am/grievanceAdmin.ts` (or the PHP lang files the service uses) — ensure each statutory problem code maps to a localized message.
- Test: `tests/Feature/Grievances/GrievanceCommitteeCompositionTest.php`

**Interfaces:**
- Consumes: `StatutoryCommitteeRules::compositionProblems(GrievanceCommittee): array` (returns list of problem strings; empty = valid).

- [ ] **Step 1:** Write failing tests: (a) a grievance committee of 4 members cannot be approved (statutory "exactly 5"); (b) 5 members but chair not `appointed` fails; (c) 5 members, chair appointed, 3 elected incl. a female, HR secretary → approves. Build committees via the existing `GrievanceScenario` helper.
- [ ] **Step 2:** Run → FAIL (currently approves 4-member committees).
- [ ] **Step 3:** Wire the validator into `approve()`.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(grievance): enforce Reg 201/2018 committee composition on approval`.

### Task 3.3: Female-elected-member + QUALIFICATION_NOT_VERIFIABLE (Review Focus)

**Files:** Test + (if needed) a small guard in `GrievanceCommitteeService`.
- Test: extend `tests/Feature/Grievances/GrievanceCommitteeCompositionTest.php`

- [ ] **Step 1:** Write failing tests: (a) 5 members with zero female elected members fails with the female-member problem; (b) when an elected member's `employee.gender` is null, the result surfaces `QUALIFICATION_NOT_VERIFIABLE` rather than silently passing. (Confirm `StatutoryCommitteeRules` already emits these; if it treats unknown gender as pass, add the guard so unknown ≠ pass per §24.)
- [ ] **Step 2:** Run → FAIL or reveals the silent-pass gap.
- [ ] **Step 3:** If needed, adjust so unknown gender is not counted as satisfying the female rule.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `test(grievance): female-member and unverifiable-qualification committee rules`.

---

## Sub-project G4 — Statutory 15/10/5-Working-Day Deadlines

Spec §29, §43, §46, §70, §71. Snapshot statutory deadlines via `StatutoryDeadlineService`; keep SLA profiles as internal targets.

### Task 4.1: Snapshot committee-report deadline on acceptance (Art. 34(3), 15wd)

**Files:**
- Modify: `app/Services/Grievances/GrievanceCaseService.php` — in the intake-accept transition (`intakeAccept`, ~line 214), after a grievance is accepted into committee investigation, call `StatutoryDeadlineService::snapshot($grievance, Regulation201::GRIEVANCE_COMMITTEE_REPORT, now())`.
- Test: `tests/Feature/Grievances/GrievanceStatutoryDeadlinesTest.php`

- [ ] **Step 1:** Write failing test: accepting a grievance creates a `StatutoryDeadline` row with `rule_key='grievance.committee_report'`, `amount=15`, `basis='working_days'`, and a `due_at` matching `dueAt()` from the acceptance time (compute expected from seed).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add the snapshot call.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(grievance): snapshot 15-working-day committee-report deadline`.

### Task 4.2: Head-decision (Art. 35(1), 10wd) + delivery (Art. 35(2), 5wd) snapshots

**Files:**
- Modify: `app/Services/Grievances/GrievanceDecisionService.php` — when the committee report is submitted to the head, snapshot `GRIEVANCE_HEAD_DECISION` from the submission time; when the decision is finalized, snapshot `GRIEVANCE_DECISION_DELIVERY` from `finalized_at`; `complete()` the head-decision deadline when the decision is finalized and the delivery deadline when the letter dispatch is marked delivered.
- Test: extend `tests/Feature/Grievances/GrievanceStatutoryDeadlinesTest.php`

- [ ] **Step 1:** Write failing tests for both snapshots (amounts 10 and 5, working_days) and that finalizing the decision completes the head-decision deadline.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add the snapshot + complete calls at the right transitions.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(grievance): snapshot 10-wd head-decision and 5-wd delivery deadlines`.

### Task 4.3: Statutory deadline immutable vs later holiday edit + statutory-beats-internal (Review Focus + §70)

**Files:** Test + a read accessor.
- Modify: `app/Models/Grievance.php` — add `statutoryDeadline(string $ruleKey)` helper reading the open `StatutoryDeadline` row, and `effectiveDeadline(string $ruleKey)` that returns the statutory `due_at` (never the internal SLA target) for display.
- Test: extend `tests/Feature/Grievances/GrievanceStatutoryDeadlinesTest.php`

- [ ] **Step 1:** Write failing tests: (a) after snapshotting the committee-report deadline, inserting a new public holiday inside the window leaves the stored `due_at` unchanged; (b) when an internal SLA profile sets a shorter/longer target, `effectiveDeadline()` still returns the statutory date.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add the accessors (statutory snapshot is authoritative).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(grievance): statutory deadline is immutable and overrides internal SLA`.

### Task 4.4: Overdue flags derived, not status-mutating (§69)

**Files:**
- Modify: `app/Services/Grievances/GrievanceCaseService.php` or a small presenter — expose derived booleans `committeeReportOverdue`, `headDecisionOverdue`, `decisionDeliveryOverdue` computed from open `StatutoryDeadline` rows vs `now()`, without changing `GrievanceStatus`.
- Test: `tests/Feature/Grievances/GrievanceOverdueFlagsTest.php`

- [ ] **Step 1:** Write failing test: a grievance past its committee-report `due_at` reports `committeeReportOverdue=true` while its `status` is unchanged.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement derived flags.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(grievance): derive statutory overdue flags without mutating status`.

---

## Docs & verification (final tasks)

### Task D.1: Documentation

**Files:**
- Modify: `docs/regulation-201-2018-traceability.md` — record ND-1…ND-4 decisions and article→code mapping for the newly wired rules.
- Create: `docs/grievance-deadlines.md` (statutory vs internal, snapshot immutability), `docs/grievance-committee.md` (composition rule + enforcement point). `docs/employee-grievance-workflow.md` may be added if time permits.

- [ ] **Step 1:** Write the three docs with the article mapping (no full regulation text copied — §102).
- [ ] **Step 2:** Commit `docs(grievance): statutory deadlines, committee, traceability`.

### Task V.1: Full verification gate

- [ ] `php artisan migrate --pretend` (confirm additive, no destructive ops)
- [ ] `php artisan route:list` (no route regressions)
- [ ] `vendor/bin/pint` (style)
- [ ] `npm run typecheck` if present
- [ ] `vendor/bin/pest --filter=Grievance` then the full suite (note the ~16-min runtime and known clock-dependent demo-seeder failures per project memory — distinguish pre-existing failures from new ones)
- [ ] Commit any style fixes: `chore(grievance): pint + verification`.

---

## Self-Review

**Spec coverage:** G1 §7/§8/§9/§10/§17 → Tasks 1.1–1.3, 2.5. G2 §15/§17 → 2.1–2.5. G3 §21/§22/§24 → 3.1–3.3. G4 §29/§43/§46/§70/§71/§69 → 4.1–4.4. §5 snapshot → 1.2 (restrictOnDelete) + 2.1 (position/supervisor columns) + 2.2 (snapshot from issue). §66 status history → 2.5. §16/§72 working-day centralization → reused, not duplicated. §101 preserve data → additive migrations only. §103 NEEDS_DECISION → recorded, defaults behind config. **Deferred (not in this plan, flagged to user):** §18/§19 group grievance (G6), §54 wizard UI (G10), §57 KPIs / §73 reports (G9), §80 queued exports (G12), §55 nav rename (G13), §39/§44 typed recommendation+reinvestigation (G7).

**Placeholder scan:** Tasks 2.3–4.4 use narrative steps for implementation bodies that depend on exact project signatures (`placementOf` shape, `intakeAccept` internals) the implementer must confirm by reading before coding; test code is concrete. The two date-assertion tests carry an explicit `->todo` instruction to compute the expected date from the seed rather than guess — this is a deliberate guard against inventing a wrong constant, not a placeholder.

**Type consistency:** `GrievanceInitialResolutionOutcome`/`Status`, `GrievanceCommitteeSelectionSource`, the `Regulation201::GRIEVANCE_*` keys, and `StatutoryDeadlineService` signatures are used consistently across tasks.

**Review Focus:** all five lines have owning tasks (2.3, 2.4, 3.2/3.3, 4.3, 2.2).
