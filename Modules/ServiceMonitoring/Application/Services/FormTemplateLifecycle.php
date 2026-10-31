<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Domain\Enums\FormTemplateStatus;
use Modules\ServiceMonitoring\Domain\Models\FormTemplate;

/**
 * Versions of form templates (docs/service-monitoring.md §Form templates):
 * draft → published → superseded (by the next published version) → archived.
 * Only a draft changes, because reports point at published versions. An
 * organization type belongs to one published template at a time, so every
 * participant has exactly one form.
 */
final readonly class FormTemplateLifecycle
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    /** @param array{code: string, name_am: string, name_en?: ?string, description?: ?string, content: array<string, mixed>, organization_type_ids: list<string>} $attributes */
    public function create(User $actor, array $attributes): FormTemplate
    {
        return DB::transaction(function () use ($actor, $attributes): FormTemplate {
            $template = FormTemplate::query()->create([
                'code' => $attributes['code'],
                'version' => 1,
                'name_am' => $attributes['name_am'],
                'name_en' => $attributes['name_en'] ?? null,
                'description' => $attributes['description'] ?? null,
                'status' => FormTemplateStatus::Draft,
                'content' => $attributes['content'],
                'created_by' => $actor->id,
            ]);
            $template->organizationTypes()->sync($attributes['organization_type_ids']);
            $this->log(AuditEventType::ServiceMonitoringFormTemplateSaved, $actor, $template);

            return $template;
        });
    }

    /** @param array{name_am: string, name_en?: ?string, description?: ?string, content: array<string, mixed>, organization_type_ids: list<string>} $attributes */
    public function update(User $actor, FormTemplate $template, array $attributes): FormTemplate
    {
        return DB::transaction(function () use ($actor, $template, $attributes): FormTemplate {
            $template = FormTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $this->assertDraft($template);
            $template->update([
                'name_am' => $attributes['name_am'],
                'name_en' => $attributes['name_en'] ?? null,
                'description' => $attributes['description'] ?? null,
                'content' => $attributes['content'],
            ]);
            $template->organizationTypes()->sync($attributes['organization_type_ids']);
            $this->log(AuditEventType::ServiceMonitoringFormTemplateSaved, $actor, $template);

            return $template;
        });
    }

    public function publish(User $actor, FormTemplate $template): FormTemplate
    {
        return DB::transaction(function () use ($actor, $template): FormTemplate {
            $template = FormTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $this->assertDraft($template);

            $problems = $template->contentRules()->problems(forPublish: true);
            $typeIds = $template->organizationTypes()->pluck('organization_types.id')->all();
            if ($typeIds === []) {
                $problems['organization_type_ids'] = __('service-monitoring.form_templates.errors.no_types');
            }
            $taken = FormTemplate::query()->published()->where('code', '!=', $template->code)
                ->whereHas('organizationTypes', static fn ($q) => $q->whereIn('organization_types.id', $typeIds))
                ->pluck('code');
            if ($taken->isNotEmpty()) {
                $problems['organization_type_ids'] = __('service-monitoring.form_templates.errors.types_taken', ['codes' => $taken->implode(', ')]);
            }
            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }

            FormTemplate::query()->where('code', $template->code)->where('status', FormTemplateStatus::Published->value)
                ->update(['status' => FormTemplateStatus::Superseded->value, 'updated_at' => now()]);
            $template->update(['status' => FormTemplateStatus::Published, 'published_by' => $actor->id, 'published_at' => now()]);
            $this->log(AuditEventType::ServiceMonitoringFormTemplatePublished, $actor, $template);

            return $template;
        });
    }

    /** The next draft of the same template, copied from $source. One draft per code. */
    public function newVersion(User $actor, FormTemplate $source): FormTemplate
    {
        return DB::transaction(function () use ($actor, $source): FormTemplate {
            $versions = FormTemplate::query()->where('code', $source->code)->lockForUpdate()->get();
            if ($versions->contains(static fn (FormTemplate $v): bool => $v->status === FormTemplateStatus::Draft)) {
                throw ValidationException::withMessages(['template' => __('service-monitoring.form_templates.errors.draft_exists')]);
            }

            return $this->copy($actor, $source, $source->code, (int) $versions->max('version') + 1, $source->name_am);
        });
    }

    /** An independent template (new code, version 1) starting from another one's content. */
    public function duplicate(User $actor, FormTemplate $source, string $code, string $nameAm): FormTemplate
    {
        return DB::transaction(fn (): FormTemplate => $this->copy($actor, $source, $code, 1, $nameAm));
    }

    public function archive(User $actor, FormTemplate $template): FormTemplate
    {
        return DB::transaction(function () use ($actor, $template): FormTemplate {
            $template = FormTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($template->status === FormTemplateStatus::Draft) {
                throw ValidationException::withMessages(['template' => __('service-monitoring.form_templates.errors.draft_not_archivable')]);
            }
            $template->update(['status' => FormTemplateStatus::Archived]);
            $this->log(AuditEventType::ServiceMonitoringFormTemplateArchived, $actor, $template);

            return $template;
        });
    }

    /** A draft no report started on can be deleted. */
    public function discard(User $actor, FormTemplate $template): void
    {
        DB::transaction(function () use ($actor, $template): void {
            $template = FormTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($template->status !== FormTemplateStatus::Draft || $template->submissions()->exists()) {
                throw ValidationException::withMessages(['template' => __('service-monitoring.form_templates.errors.draft_in_use')]);
            }
            $this->log(AuditEventType::ServiceMonitoringFormTemplateArchived, $actor, $template, ['discarded' => true]);
            $template->organizationTypes()->detach();
            $template->delete();
        });
    }

    private function copy(User $actor, FormTemplate $source, string $code, int $version, string $nameAm): FormTemplate
    {
        $copy = FormTemplate::query()->create([
            'code' => $code,
            'version' => $version,
            'name_am' => $nameAm,
            'name_en' => $source->name_en,
            'description' => $source->description,
            'status' => FormTemplateStatus::Draft,
            'content' => $source->content,
            'created_by' => $actor->id,
        ]);
        $copy->organizationTypes()->sync($source->organizationTypes()->pluck('organization_types.id')->all());
        $this->log(AuditEventType::ServiceMonitoringFormTemplateSaved, $actor, $copy, ['copied_from' => $source->id]);

        return $copy;
    }

    private function assertDraft(FormTemplate $template): void
    {
        if (! $template->status->isEditable()) {
            throw ValidationException::withMessages(['template' => __('service-monitoring.form_templates.errors.not_draft')]);
        }
    }

    /** @param array<string, mixed> $extra */
    private function log(AuditEventType $event, User $actor, FormTemplate $template, array $extra = []): void
    {
        $this->audit->record($event, $actor, $template, null, ['code' => $template->code, 'version' => $template->version, 'status' => $template->status->value, ...$extra]);
    }
}
