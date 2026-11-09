<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationFunction;
use App\Models\PositionCompetencyRequirement;
use App\Models\PositionProfile;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\UnitResponsibility;
use App\Models\User;
use App\Services\OrganizationDesign\JobDescriptionService;
use App\Services\OrganizationDesign\MappingWorkspaceService;
use App\Services\OrganizationDesign\OrganizationDesignGapService;
use App\Services\OrganizationDesign\TraceabilityService;
use App\Support\Demo\DemoDataset;
use Database\Seeders\Demo\DemoOrganizationDesignSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * The organization-design demo chain (docs/organization-design.md): seeded
 * through the domain services for ORG-1 by a designer and a separate
 * approver, complete from mandate to a job description in force, and
 * unchanged by a second run.
 */

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

function designCounts(): array
{
    return [
        'mandates' => InstitutionMandate::query()->count(), 'functions' => OrganizationFunction::query()->count(), 'services' => Service::query()->count(),
        'processes' => Process::query()->count(), 'tasks' => ProcessTask::query()->count(), 'standards' => PositionServiceTaskStandard::query()->count(),
        'responsibilities' => UnitResponsibility::query()->count(), 'studies' => OrganizationDesignStudy::query()->count(), 'profiles' => PositionProfile::query()->count(),
        'job_descriptions' => JobDescription::query()->count(), 'work_links' => PositionWorkLink::query()->count(), 'position_competencies' => PositionCompetencyRequirement::query()->count(),
        'users' => User::query()->count(),
    ];
}

test('the demo design chain is complete from mandate to a job description in force, by a designer and a separate approver, and a second run adds nothing', function (): void {
    $this->seed(DemoDataSeeder::class);
    $organization = DemoDataset::requireOrganization(DemoOrganizationDesignSeeder::ORGANIZATION);
    $designer = DemoDataset::requireUser(DemoOrganizationDesignSeeder::DESIGNER_EMAIL);
    $approver = DemoDataset::requireUser(DemoOrganizationDesignSeeder::APPROVER_EMAIL);
    expect($designer->hasRole('Organization Designer'))->toBeTrue()->and($approver->hasRole('Organization Design Approver'))->toBeTrue();

    $mandate = InstitutionMandate::query()->where('organization_id', $organization->id)->where('code', DemoOrganizationDesignSeeder::MANDATE_CODE)->firstOrFail();
    expect($mandate->status)->toBe(OrganizationDesignStatus::Published)
        ->and((int) $mandate->created_by)->toBe($designer->id)->and((int) $mandate->approved_by)->toBe($approver->id)
        ->and(OrganizationFunction::query()->where('mandate_id', $mandate->id)->count())->toBe(2)
        ->and(Service::query()->where('organization_id', $organization->id)->where('status', OrganizationDesignStatus::Published)->count())->toBe(2)
        ->and(Process::query()->where('organization_id', $organization->id)->where('status', OrganizationDesignStatus::Published)->count())->toBe(2)
        ->and(ProcessTask::query()->where('organization_id', $organization->id)->count())->toBe(4);
    $task = ProcessTask::query()->where('organization_id', $organization->id)->where('code', 'T-01')->firstOrFail();
    expect(PositionServiceTaskStandard::query()->inForceOn(now()->toDateString())->where('master_task_id', $task->id)->exists())->toBeTrue();

    $study = OrganizationDesignStudy::query()->where('organization_id', $organization->id)->where('title_en', DemoOrganizationDesignSeeder::STUDY_TITLE)->firstOrFail();
    expect($study->status)->toBe(OrganizationDesignStatus::Approved)->and($study->proposals()->count())->toBe(1);

    $hr = DemoDataset::position($organization, 'P-HR');
    $clerk = DemoDataset::position($organization, 'P-CLERK');
    expect(PositionWorkLink::query()->where('position_id', $hr->id)->count())->toBe(3)
        ->and(PositionCompetencyRequirement::query()->where('position_id', $hr->id)->where('source', 'derived')->count())->toBeGreaterThanOrEqual(1)
        ->and(PositionProfile::query()->where('position_id', $hr->id)->value('status'))->toBe(OrganizationDesignStatus::Published)
        ->and(PositionProfile::query()->where('position_id', $clerk->id)->value('status'))->toBe(OrganizationDesignStatus::Draft)
        ->and(app(JobDescriptionService::class)->inForceFor($hr)?->reference_number)->toStartWith('JD-')
        ->and(JobDescription::query()->where('position_id', $clerk->id)->exists())->toBeFalse();

    // The chain is traceable end to end and the gap report sees no error.
    $trace = app(TraceabilityService::class)->trace('task', $task->id);
    expect(collect($trace['upstream'])->pluck('type')->all())->toBe(['mandate', 'function', 'service', 'process', 'activity'])
        ->and(collect($trace['downstream'])->pluck('type')->all())->toContain('position', 'job_description');
    expect(app(OrganizationDesignGapService::class)->gaps($organization->id)['ok'])->toBeTrue()
        ->and(collect(app(MappingWorkspaceService::class)->unmapped($organization->id)['positions'])->pluck('id')->all())->not->toContain($hr->id);

    // Second run: nothing added, nothing re-versioned.
    $first = designCounts();
    $this->seed(DemoDataSeeder::class);
    expect(designCounts())->toBe($first)
        ->and($mandate->fresh()->status)->toBe(OrganizationDesignStatus::Published)
        ->and(JobDescription::query()->where('position_id', $hr->id)->count())->toBe(1);
});
