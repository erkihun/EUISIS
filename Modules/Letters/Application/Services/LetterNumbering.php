<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Models\Organization;
use App\Support\EthiopianCalendar;
use Carbon\CarbonImmutable;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\ResetPolicy;
use Modules\Letters\Domain\Models\LetterNumberingRule;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterSequence;

/**
 * Draws registry numbers (ADR 0037 §5). Inside one institution, Amharic and
 * English letters are numbered separately: every sequence is per institution,
 * direction, language and reset period. The rule is looked up from the most
 * specific to the least: the institution's rule for the language, the
 * institution's rule for both languages, the platform's rule for the
 * language, the platform's rule for both, then the built-in default
 * `{ORG}/{SEQ}/{EC_YEAR}` reset every Ethiopian year. The sequence row is read
 * and bumped under a row lock inside the caller's transaction, so two
 * registrars never draw the same number.
 */
class LetterNumbering
{
    public function ruleFor(?string $organizationId, LetterDirection $direction, string $language): LetterNumberingRule
    {
        $language = self::language($language);
        $candidates = LetterNumberingRule::query()->where('direction', $direction->value)
            ->where(fn ($q) => $q->whereNull('language')->orWhere('language', $language))
            ->where(fn ($q) => $organizationId === null ? $q->whereNull('organization_id') : $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->get();

        foreach ([
            fn (LetterNumberingRule $r): bool => $r->organization_id !== null && $r->language === $language,
            fn (LetterNumberingRule $r): bool => $r->organization_id !== null && $r->language === null,
            fn (LetterNumberingRule $r): bool => $r->organization_id === null && $r->language === $language,
            fn (LetterNumberingRule $r): bool => $r->organization_id === null && $r->language === null,
        ] as $match) {
            $rule = $candidates->first($match);
            if ($rule !== null) {
                return $rule;
            }
        }

        return LetterNumberingRule::fallback($direction, $language);
    }

    /** Assigns the next number to an unnumbered registry entry. Call inside a transaction. */
    public function assign(LetterRegistration $registration, ?CarbonImmutable $on = null): LetterRegistration
    {
        if ($registration->registry_number !== null) {
            return $registration;
        }
        $on ??= CarbonImmutable::now();
        $language = self::language((string) ($registration->letter?->language ?? 'am'));
        $rule = $this->ruleFor($registration->organization_id, $registration->direction, $language);
        $periodKey = $this->periodKey($rule->reset_policy, $on);

        $sequence = LetterSequence::query()->lockForUpdate()
            ->where('organization_id', $registration->organization_id)
            ->where('direction', $registration->direction->value)
            ->where('language', $language)
            ->where('period_key', $periodKey)
            ->first();
        if ($sequence === null) {
            $sequence = LetterSequence::query()->create([
                'organization_id' => $registration->organization_id, 'direction' => $registration->direction->value, 'language' => $language, 'period_key' => $periodKey, 'last_number' => 0,
            ]);
            // Re-read under the lock so a concurrent creator and we agree on one row.
            $sequence = LetterSequence::query()->lockForUpdate()->whereKey($sequence->id)->firstOrFail();
        }
        $next = $sequence->last_number + 1;
        $sequence->update(['last_number' => $next]);

        $organization = Organization::query()->find($registration->organization_id);
        $registration->forceFill([
            'registry_number' => $this->format($rule, (string) ($organization?->code ?? ''), $next, $on, $language),
            'sequence_number' => $next,
            'period_key' => $periodKey,
        ])->save();

        return $registration;
    }

    public function format(LetterNumberingRule $rule, string $organizationCode, int $number, CarbonImmutable $on, string $language = 'am'): string
    {
        $ethiopian = EthiopianCalendar::toEthiopian($on);
        $replacements = [
            '{ORG}' => $organizationCode,
            '{SEQ}' => str_pad((string) $number, max(1, (int) $rule->padding), '0', STR_PAD_LEFT),
            '{EC_YEAR}' => (string) $ethiopian['year'],
            '{GC_YEAR}' => $on->format('Y'),
            '{DIR}' => (string) ($rule->direction_code ?? ''),
            '{LANG}' => (string) ($rule->language_code ?? strtoupper(self::language($language))),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $rule->pattern);
    }

    /** Preview a rule's next number for an institution and language without drawing it. */
    public function preview(LetterNumberingRule $rule, string $organizationId, string $language = 'am', ?CarbonImmutable $on = null): string
    {
        $on ??= CarbonImmutable::now();
        $language = self::language($language);
        $periodKey = $this->periodKey($rule->reset_policy, $on);
        $last = (int) (LetterSequence::query()->where('organization_id', $organizationId)->where('direction', $rule->direction->value)
            ->where('language', $language)->where('period_key', $periodKey)->value('last_number') ?? 0);
        $code = (string) (Organization::query()->whereKey($organizationId)->value('code') ?? '');

        return $this->format($rule, $code, $last + 1, $on, $language);
    }

    public function periodKey(ResetPolicy $policy, CarbonImmutable $on): string
    {
        return match ($policy) {
            ResetPolicy::EthiopianYear => (string) EthiopianCalendar::toEthiopian($on)['year'],
            ResetPolicy::GregorianYear => $on->format('Y'),
            ResetPolicy::Never => 'all',
        };
    }

    /** The two letter languages; anything else counts as Amharic. */
    public static function language(?string $language): string
    {
        return $language === 'en' ? 'en' : 'am';
    }
}
