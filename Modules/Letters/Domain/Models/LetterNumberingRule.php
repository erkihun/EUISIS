<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\ResetPolicy;

/**
 * How one institution numbers one direction of letters, for one language or
 * for both (`language` null). A rule with a null organization is the platform
 * default. Tokens: {ORG} the institution's code, {SEQ} the padded sequence,
 * {EC_YEAR}, {GC_YEAR}, {DIR} the rule's direction code, {LANG} the rule's
 * language code (or AM / EN).
 */
class LetterNumberingRule extends Model
{
    use HasUuidPrimaryKey;

    /** Amharic letters: the registry number as the offices write it. */
    public const DEFAULT_PATTERN = '{ORG}/{SEQ}/{EC_YEAR}';

    /** English letters: marked and dated in the Gregorian calendar, so the two series never collide. */
    public const DEFAULT_PATTERN_EN = '{ORG}/{LANG}/{SEQ}/{GC_YEAR}';

    public const TOKENS = ['{ORG}', '{SEQ}', '{EC_YEAR}', '{GC_YEAR}', '{DIR}', '{LANG}'];

    protected $table = 'letter_numbering_rules';

    protected $fillable = ['organization_id', 'direction', 'language', 'pattern', 'reset_policy', 'padding', 'direction_code', 'language_code', 'updated_by'];

    protected function casts(): array
    {
        return ['direction' => LetterDirection::class, 'reset_policy' => ResetPolicy::class, 'padding' => 'int'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The built-in default when neither the institution nor the platform configured one. */
    public static function fallback(LetterDirection $direction, string $language = 'am'): self
    {
        $rule = $language === 'en'
            ? new self(['direction' => $direction->value, 'language' => 'en', 'pattern' => self::DEFAULT_PATTERN_EN, 'reset_policy' => ResetPolicy::GregorianYear->value, 'padding' => 4])
            : new self(['direction' => $direction->value, 'language' => 'am', 'pattern' => self::DEFAULT_PATTERN, 'reset_policy' => ResetPolicy::EthiopianYear->value, 'padding' => 4]);
        $rule->exists = false;

        return $rule;
    }
}
