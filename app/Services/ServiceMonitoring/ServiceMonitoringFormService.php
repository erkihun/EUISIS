<?php

declare(strict_types=1);

namespace App\Services\ServiceMonitoring;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\ServiceMonitoring\FormStatus;
use App\Models\ServiceMonitoringForm;
use App\Models\User;
use App\Support\ServiceMonitoring\FormSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of monitoring forms (docs/service-monitoring.md §Versions).
 *
 * draft → published → superseded (by the next published version) → archived.
 * Only a draft's content changes; a published version is immutable because
 * reports point at it. Changing a published form means drafting a new
 * version of the same code.
 */
final readonly class ServiceMonitoringFormService
{
    public function __construct(private WriteAuditLogAction $audit) {}

    /**
     * @param  array{code: string, name_am: string, name_en?: string|null, description?: string|null, schema: array<string, mixed>, organization_type_ids?: list<string>}  $attributes
     */
    public function create(User $actor, array $attributes): ServiceMonitoringForm
    {
        return DB::transaction(function () use ($actor, $attributes): ServiceMonitoringForm {
            $form = ServiceMonitoringForm::query()->create([
                'code' => $attributes['code'],
                'version' => 1,
                'name_am' => $attributes['name_am'],
                'name_en' => $attributes['name_en'] ?? null,
                'description' => $attributes['description'] ?? null,
                'status' => FormStatus::Draft,
                'schema' => $attributes['schema'],
                'created_by' => $actor->getKey(),
            ]);
            $form->organizationTypes()->sync($attributes['organization_type_ids'] ?? []);
            $this->log(AuditEventType::ServiceMonitoringFormSaved, $actor, $form, null);

            return $form;
        });
    }

    /**
     * @param  array{name_am: string, name_en?: string|null, description?: string|null, schema: array<string, mixed>, organization_type_ids?: list<string>}  $attributes
     */
    public function update(User $actor, ServiceMonitoringForm $form, array $attributes): ServiceMonitoringForm
    {
        return DB::transaction(function () use ($actor, $form, $attributes): ServiceMonitoringForm {
            $form = ServiceMonitoringForm::query()->lockForUpdate()->findOrFail($form->getKey());
            if (! $form->status->isEditable()) {
                throw ValidationException::withMessages(['form' => __('service-monitoring.errors.form_not_editable')]);
            }
            $before = $form->only(['name_am', 'name_en', 'description']);
            $form->update([
                'name_am' => $attributes['name_am'],
                'name_en' => $attributes['name_en'] ?? null,
                'description' => $attributes['description'] ?? null,
                'schema' => $attributes['schema'],
            ]);
            $form->organizationTypes()->sync($attributes['organization_type_ids'] ?? []);
            $this->log(AuditEventType::ServiceMonitoringFormSaved, $actor, $form, $before);

            return $form;
        });
    }

    /** Validate and publish a draft; the previously published version is superseded. */
    public function publish(User $actor, ServiceMonitoringForm $form): ServiceMonitoringForm
    {
        return DB::transaction(function () use ($actor, $form): ServiceMonitoringForm {
            $form = ServiceMonitoringForm::query()->lockForUpdate()->findOrFail($form->getKey());
            if ($form->status !== FormStatus::Draft) {
                throw ValidationException::withMessages(['form' => __('service-monitoring.errors.only_draft_publishable')]);
            }
            $problems = FormSchema::from($form->schema)->problems(forPublish: true);
            if ($form->organizationTypes()->doesntExist()) {
                $problems['organization_type_ids'] = __('service-monitoring.errors.no_organization_types');
            }
            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }

            ServiceMonitoringForm::query()
                ->where('code', $form->code)
                ->where('status', FormStatus::Published->value)
                ->update(['status' => FormStatus::Superseded->value, 'updated_at' => now()]);

            $form->update([
                'status' => FormStatus::Published,
                'published_by' => $actor->getKey(),
                'published_at' => now(),
            ]);
            $this->log(AuditEventType::ServiceMonitoringFormPublished, $actor, $form, null);

            return $form;
        });
    }

    /** Copy a version into the next draft of the same form. One draft per code. */
    public function newVersion(User $actor, ServiceMonitoringForm $source): ServiceMonitoringForm
    {
        return DB::transaction(function () use ($actor, $source): ServiceMonitoringForm {
            $versions = ServiceMonitoringForm::query()->where('code', $source->code)->lockForUpdate()->get();
            if ($versions->contains(static fn (ServiceMonitoringForm $v): bool => $v->status === FormStatus::Draft)) {
                throw ValidationException::withMessages(['form' => __('service-monitoring.errors.draft_exists')]);
            }

            return $this->copy($actor, $source, $source->code, (int) $versions->max('version') + 1, $source->name_am);
        });
    }

    /** An independent form (new code, version 1) starting from another form's content. */
    public function duplicate(User $actor, ServiceMonitoringForm $source, string $code, string $nameAm): ServiceMonitoringForm
    {
        return DB::transaction(fn (): ServiceMonitoringForm => $this->copy($actor, $source, $code, 1, $nameAm));
    }

    public function archive(User $actor, ServiceMonitoringForm $form): ServiceMonitoringForm
    {
        return DB::transaction(function () use ($actor, $form): ServiceMonitoringForm {
            $form = ServiceMonitoringForm::query()->lockForUpdate()->findOrFail($form->getKey());
            if ($form->status === FormStatus::Draft) {
                throw ValidationException::withMessages(['form' => __('service-monitoring.errors.draft_not_archivable')]);
            }
            $form->update(['status' => FormStatus::Archived]);
            $this->log(AuditEventType::ServiceMonitoringFormArchived, $actor, $form, null);

            return $form;
        });
    }

    /** A draft no report points at can be deleted outright. */
    public function discard(User $actor, ServiceMonitoringForm $form): void
    {
        DB::transaction(function () use ($actor, $form): void {
            $form = ServiceMonitoringForm::query()->lockForUpdate()->findOrFail($form->getKey());
            if ($form->status !== FormStatus::Draft || $form->submissions()->exists()) {
                throw ValidationException::withMessages(['form' => __('service-monitoring.errors.draft_in_use')]);
            }
            $this->log(AuditEventType::ServiceMonitoringFormArchived, $actor, $form, ['status' => 'draft', 'discarded' => true]);
            $form->organizationTypes()->detach();
            $form->delete();
        });
    }

    private function copy(User $actor, ServiceMonitoringForm $source, string $code, int $version, string $nameAm): ServiceMonitoringForm
    {
        $copy = ServiceMonitoringForm::query()->create([
            'code' => $code,
            'version' => $version,
            'name_am' => $nameAm,
            'name_en' => $source->name_en,
            'description' => $source->description,
            'status' => FormStatus::Draft,
            'schema' => $source->schema,
            'created_by' => $actor->getKey(),
        ]);
        $copy->organizationTypes()->sync($source->organizationTypes()->pluck('organization_types.id')->all());
        $this->log(AuditEventType::ServiceMonitoringFormSaved, $actor, $copy, ['copied_from' => $source->getKey()]);

        return $copy;
    }

    /** @param array<string, mixed>|null $before */
    private function log(AuditEventType $event, User $actor, ServiceMonitoringForm $form, ?array $before): void
    {
        $this->audit->execute($event, $actor, $form, null, $before, [
            'code' => $form->code,
            'version' => $form->version,
            'status' => $form->status->value,
        ], request: request());
    }
}
