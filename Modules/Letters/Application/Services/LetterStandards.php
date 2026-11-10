<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use Carbon\CarbonImmutable;
use Modules\Letters\Domain\Enums\LetterPriority;
use Modules\Letters\Domain\Models\LetterFileClass;
use Modules\Letters\Domain\Models\LetterStandard;

/**
 * The reply-time standard and the file scheme that apply to an institution:
 * its own settings, else the platform default, else the built-in defaults
 * (10 / 3 / 1 working days by priority, interim reply 2 days before the date,
 * escalation 2 days after it). Working days are Monday to Friday.
 */
class LetterStandards
{
    /** @return array<string, int> */
    public function for(?string $organizationId): array
    {
        $own = $organizationId !== null ? LetterStandard::query()->where('organization_id', $organizationId)->first() : null;
        $platform = LetterStandard::query()->whereNull('organization_id')->first();
        $source = $own ?? $platform;

        return [
            ...LetterStandard::DEFAULTS,
            ...($source !== null ? $source->only(array_keys(LetterStandard::DEFAULTS)) : []),
            'source' => $own !== null ? 'institution' : ($platform !== null ? 'platform' : 'default'),
        ];
    }

    /** The due date a letter of this priority gets when the registrar sets none. */
    public function dueDate(?string $organizationId, LetterPriority $priority, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $settings = $this->for($organizationId);
        $days = match ($priority) {
            LetterPriority::VeryUrgent => $settings['reply_days_very_urgent'],
            LetterPriority::Urgent => $settings['reply_days_urgent'],
            LetterPriority::Normal => $settings['reply_days_normal'],
        };

        return self::addWorkingDays($from ?? CarbonImmutable::now(), (int) $days);
    }

    public static function addWorkingDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $date = $from->startOfDay();
        while ($days > 0) {
            $date = $date->addDay();
            if (! $date->isWeekend()) {
                $days--;
            }
        }

        return $date;
    }

    /**
     * The file classes an institution may pick from: its own scheme, else the platform scheme.
     *
     * @return list<array{id: string, code: string, title_am: string|null, title_en: string|null, parent_id: string|null}>
     */
    public function fileClassesFor(?string $organizationId): array
    {
        $own = $organizationId !== null ? LetterFileClass::query()->where('organization_id', $organizationId)->where('active', true)->orderBy('position')->orderBy('code')->get() : collect();
        $rows = $own->isNotEmpty() ? $own : LetterFileClass::query()->whereNull('organization_id')->where('active', true)->orderBy('position')->orderBy('code')->get();

        return $rows->map(fn (LetterFileClass $c): array => ['id' => $c->id, 'code' => $c->code, 'title_am' => $c->title_am, 'title_en' => $c->title_en, 'parent_id' => $c->parent_id])->all();
    }
}
