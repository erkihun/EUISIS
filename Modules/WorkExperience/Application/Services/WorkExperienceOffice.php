<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Modules\WorkExperience\Domain\Models\WorkExperienceOfficeSetting;

/**
 * An institution's settings for the work experience certificate: who signs
 * it, and what it carries. With no row an institution has no signatory yet
 * (no certificate can be prepared), the salary is written and service in
 * other institutions is left out.
 */
class WorkExperienceOffice
{
    public const FIELDS = ['signatory_name_am', 'signatory_name_en', 'signatory_title_am', 'signatory_title_en', 'include_salary', 'include_external'];

    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @return array{signatory_name_am: ?string, signatory_name_en: ?string, signatory_title_am: ?string, signatory_title_en: ?string, include_salary: bool, include_external: bool, configured: bool} */
    public function settingsOf(?string $organizationId): array
    {
        $row = $organizationId === null ? null : WorkExperienceOfficeSetting::query()->where('organization_id', $organizationId)->first();

        return $this->present($row);
    }

    /** @return array{signatory_name_am: ?string, signatory_name_en: ?string, signatory_title_am: ?string, signatory_title_en: ?string, include_salary: bool, include_external: bool, configured: bool} */
    public function present(?WorkExperienceOfficeSetting $row): array
    {
        $name = $row?->signatory_name_am ?: $row?->signatory_name_en;
        $title = $row?->signatory_title_am ?: $row?->signatory_title_en;

        return [
            'signatory_name_am' => $row?->signatory_name_am,
            'signatory_name_en' => $row?->signatory_name_en,
            'signatory_title_am' => $row?->signatory_title_am,
            'signatory_title_en' => $row?->signatory_title_en,
            'include_salary' => $row === null ? true : $row->include_salary,
            'include_external' => $row === null ? false : $row->include_external,
            // A certificate needs a name and a title to be signed under.
            'configured' => filled($name) && filled($title),
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, string $organizationId, array $data): WorkExperienceOfficeSetting
    {
        $row = WorkExperienceOfficeSetting::query()->firstOrNew(['organization_id' => $organizationId]);
        $before = $row->exists ? $row->only(self::FIELDS) : null;
        $row->fill([
            'signatory_name_am' => $this->text($data['signatory_name_am'] ?? null),
            'signatory_name_en' => $this->text($data['signatory_name_en'] ?? null),
            'signatory_title_am' => $this->text($data['signatory_title_am'] ?? null),
            'signatory_title_en' => $this->text($data['signatory_title_en'] ?? null),
            'include_salary' => (bool) ($data['include_salary'] ?? true),
            'include_external' => (bool) ($data['include_external'] ?? false),
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->audit->execute(AuditEventType::WorkExperienceSettingsChanged, $actor, $row, $organizationId, $before, $row->only(self::FIELDS));

        return $row;
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
