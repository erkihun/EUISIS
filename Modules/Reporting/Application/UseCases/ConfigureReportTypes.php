<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateSection;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateVersion;

/**
 * Report types and their templates. A template version holds the narrative
 * sections, the attachments it requires and the sequential review steps; once
 * published it never changes, and every cycle is pinned to one version.
 */
final readonly class ConfigureReportTypes
{
    /** The standard narrative outline offered for a new report type. */
    public const DEFAULT_SECTIONS = [
        ['key' => 'executive_summary', 'title_en' => 'Executive summary', 'title_am' => 'አጠቃላይ ማጠቃለያ', 'is_required' => true],
        ['key' => 'period_and_scope', 'title_en' => 'Reporting period and scope', 'title_am' => 'የሪፖርቱ ጊዜና ወሰን', 'is_required' => true],
        ['key' => 'planned_activities', 'title_en' => 'Planned activities and targets', 'title_am' => 'የታቀዱ ተግባራትና ግቦች', 'is_required' => true],
        ['key' => 'achievements', 'title_en' => 'Achievements', 'title_am' => 'የተከናወኑ ተግባራት', 'is_required' => true],
        ['key' => 'plan_versus_actual', 'title_en' => 'Planned versus actual results', 'title_am' => 'የዕቅድና የክንውን ንጽጽር', 'is_required' => true],
        ['key' => 'uncompleted', 'title_en' => 'Uncompleted activities and reasons', 'title_am' => 'ያልተከናወኑ ተግባራትና ምክንያቶቻቸው', 'is_required' => false],
        ['key' => 'challenges', 'title_en' => 'Challenges and risks', 'title_am' => 'ያጋጠሙ ችግሮችና ሥጋቶች', 'is_required' => true],
        ['key' => 'corrective_measures', 'title_en' => 'Corrective measures', 'title_am' => 'የተወሰዱ የማስተካከያ እርምጃዎች', 'is_required' => false],
        ['key' => 'support_requested', 'title_en' => 'Support or decisions requested', 'title_am' => 'የሚጠየቅ ድጋፍ ወይም ውሳኔ', 'is_required' => false],
        ['key' => 'next_period_plan', 'title_en' => 'Next-period plan', 'title_am' => 'የቀጣይ ጊዜ ዕቅድ', 'is_required' => true],
        ['key' => 'evidence', 'title_en' => 'Evidence and annexes', 'title_am' => 'ማስረጃዎችና አባሪዎች', 'is_required' => false],
        ['key' => 'signoff', 'title_en' => 'Prepared, reviewed and approved by', 'title_am' => 'ያዘጋጀው፣ የገመገመውና ያጸደቀው', 'is_required' => false],
    ];

    public const DEFAULT_WORKFLOW = ['steps' => [
        ['name_en' => 'Review', 'name_am' => 'ግምገማ', 'permission' => ReportingRoles::REVIEW],
        ['name_en' => 'Approval', 'name_am' => 'ማጽደቅ', 'permission' => ReportingRoles::APPROVE],
    ]];

    public function __construct(
        private OrganizationDirectory $organizations,
        private AuditRecorder $audit,
        private ReportingSettings $settings,
    ) {}

    /** @param array<string, mixed> $data validated type fields */
    public function saveType(ActorContext $actor, ?string $typeId, array $data): ReportType
    {
        ReportingGuard::require($actor, ReportingRoles::MANAGE_TEMPLATES);
        $this->settings->assertWritable();
        if (($data['receiving_organization_id'] ?? null) !== null && $this->organizations->find((string) $data['receiving_organization_id']) === null) {
            throw new WorkflowViolation('unknown_organization');
        }

        return DB::transaction(function () use ($actor, $typeId, $data): ReportType {
            $type = $typeId === null ? new ReportType(['created_by' => $actor->userId]) : ReportType::query()->lockForUpdate()->findOrFail($typeId);
            $type->fill([...$data, 'updated_by' => $actor->userId])->save();
            if ($typeId === null) {
                $this->replaceDraft($type, $actor, self::DEFAULT_SECTIONS, self::DEFAULT_WORKFLOW['steps'], []);
            }
            $this->audit->record('type_saved', $actor, 'reporting.report_type', $type->id, null, ['code' => $type->code, 'is_active' => $type->is_active]);

            return $type;
        });
    }

    /**
     * Saves the working (unpublished) version. If the latest version is
     * published, a new draft version is started; published versions stay as they are.
     *
     * @param  list<array{key: string, title_en: string, title_am: string, guidance_en?: ?string, guidance_am?: ?string, is_required?: bool}>  $sections
     * @param  list<array{name_en: string, name_am: string, permission: string}>  $steps
     * @param  list<array{key: string, label_en: string, label_am: string}>  $requiredAttachments
     */
    public function saveTemplateDraft(ActorContext $actor, string $typeId, array $sections, array $steps, array $requiredAttachments): TemplateVersion
    {
        ReportingGuard::require($actor, ReportingRoles::MANAGE_TEMPLATES);
        $this->settings->assertWritable();

        return DB::transaction(fn (): TemplateVersion => $this->replaceDraft(ReportType::query()->lockForUpdate()->findOrFail($typeId), $actor, $sections, $steps, $requiredAttachments));
    }

    public function publishTemplate(ActorContext $actor, string $typeId): TemplateVersion
    {
        ReportingGuard::require($actor, ReportingRoles::MANAGE_TEMPLATES);
        $this->settings->assertWritable();

        return DB::transaction(function () use ($actor, $typeId): TemplateVersion {
            ReportType::query()->lockForUpdate()->findOrFail($typeId);
            $draft = TemplateVersion::query()->where('report_type_id', $typeId)->where('status', 'draft')->lockForUpdate()->first();
            if ($draft === null) {
                throw new WorkflowViolation('no_draft_template');
            }
            if ($draft->sections()->count() === 0 || ($draft->workflow['steps'] ?? []) === []) {
                throw new WorkflowViolation('template_incomplete');
            }
            $draft->forceFill(['status' => 'published', 'published_at' => CarbonImmutable::now('UTC'), 'published_by' => $actor->userId])->save();
            $this->audit->record('template_published', $actor, 'reporting.template_version', $draft->id, null, ['report_type_id' => $typeId, 'version' => $draft->version]);

            return $draft;
        });
    }

    /** The version new cycles are pinned to: the latest published one. */
    public function publishedVersion(string $typeId): ?TemplateVersion
    {
        return TemplateVersion::query()->where('report_type_id', $typeId)->where('status', 'published')->orderByDesc('version')->first();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $steps
     * @param  list<array<string, mixed>>  $requiredAttachments
     */
    private function replaceDraft(ReportType $type, ActorContext $actor, array $sections, array $steps, array $requiredAttachments): TemplateVersion
    {
        $keys = array_column($sections, 'key');
        if ($sections === [] || count($keys) !== count(array_unique($keys))) {
            throw new WorkflowViolation('template_sections_invalid');
        }
        foreach ($steps as $step) {
            if (! in_array($step['permission'] ?? '', [ReportingRoles::REVIEW, ReportingRoles::APPROVE], true)) {
                throw new WorkflowViolation('template_step_invalid');
            }
        }
        if ($steps === []) {
            throw new WorkflowViolation('template_step_invalid');
        }

        $draft = TemplateVersion::query()->where('report_type_id', $type->id)->where('status', 'draft')->lockForUpdate()->first()
            ?? TemplateVersion::query()->create([
                'report_type_id' => $type->id,
                'version' => (int) TemplateVersion::query()->where('report_type_id', $type->id)->max('version') + 1,
                'status' => 'draft', 'workflow' => ['steps' => []], 'created_by' => $actor->userId,
            ]);
        $draft->forceFill(['workflow' => ['steps' => array_values($steps)], 'required_attachments' => array_values($requiredAttachments)])->save();

        TemplateSection::query()->where('template_version_id', $draft->id)->delete();
        foreach (array_values($sections) as $position => $section) {
            TemplateSection::query()->create([
                'template_version_id' => $draft->id, 'key' => $section['key'], 'position' => $position + 1,
                'title_en' => $section['title_en'], 'title_am' => $section['title_am'],
                'guidance_en' => $section['guidance_en'] ?? null, 'guidance_am' => $section['guidance_am'] ?? null,
                'is_required' => (bool) ($section['is_required'] ?? true),
            ]);
        }

        return $draft;
    }
}
