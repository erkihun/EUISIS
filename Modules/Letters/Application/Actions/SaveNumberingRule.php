<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Models\LetterNumberingRule;

/** Creates or replaces one institution's (or the platform's) numbering rule for a direction and a language (or both languages). */
final class SaveNumberingRule
{
    public function __construct(private readonly LettersAudit $audit) {}

    /** @param array<string, mixed> $data validated SaveNumberingRuleRequest data */
    public function execute(array $data, User $actor): LetterNumberingRule
    {
        $pattern = trim((string) $data['pattern']);
        if (! str_contains($pattern, '{SEQ}')) {
            throw ValidationException::withMessages(['pattern' => __('letters.validation.pattern_needs_seq')]);
        }
        $unknown = preg_match_all('/\{[A-Z_]+\}/', $pattern, $m) ? array_diff(array_unique($m[0]), LetterNumberingRule::TOKENS) : [];
        if ($unknown !== []) {
            throw ValidationException::withMessages(['pattern' => __('letters.validation.pattern_unknown_tokens', ['tokens' => implode(', ', $unknown)])]);
        }

        $owner = $data['organization_id'] ?? null;
        $language = isset($data['language']) && in_array($data['language'], ['am', 'en'], true) ? $data['language'] : null;
        // One pattern for both languages must tell them apart, or an Amharic and an English letter would draw the same number.
        if ($language === null && ! str_contains($pattern, '{LANG}')) {
            throw ValidationException::withMessages(['pattern' => __('letters.validation.pattern_needs_lang')]);
        }
        $rule = LetterNumberingRule::query()
            ->where('direction', $data['direction'])
            ->where(fn ($q) => $owner === null ? $q->whereNull('organization_id') : $q->where('organization_id', $owner))
            ->where(fn ($q) => $language === null ? $q->whereNull('language') : $q->where('language', $language))
            ->first();
        $old = $rule?->only(['pattern', 'reset_policy', 'padding', 'direction_code', 'language_code']);
        $attributes = [
            'organization_id' => $owner,
            'direction' => $data['direction'],
            'language' => $language,
            'pattern' => $pattern,
            'reset_policy' => $data['reset_policy'] ?? 'ethiopian_year',
            'padding' => (int) ($data['padding'] ?? 4),
            'direction_code' => isset($data['direction_code']) && trim((string) $data['direction_code']) !== '' ? trim((string) $data['direction_code']) : null,
            'language_code' => isset($data['language_code']) && trim((string) $data['language_code']) !== '' ? trim((string) $data['language_code']) : null,
            'updated_by' => $actor->id,
        ];
        if ($rule === null) {
            $rule = LetterNumberingRule::query()->create($attributes);
        } else {
            $rule->fill($attributes)->save();
        }

        $this->audit->record(AuditEventType::LetterNumberingRuleSaved, $actor, $rule, $owner, ['direction' => $data['direction'], 'language' => $language, 'pattern' => $pattern], null, $old);

        return $rule;
    }
}
