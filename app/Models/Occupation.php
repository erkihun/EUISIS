<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Occupation extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'isco_code',
        'isco_group_id',
        'name_en',
        'name_am',
        'skill_specialization',
        'skill_specialization_am',
        'description',
        'description_en',
        'description_am',
        'is_active',
        'isco_major_group_code',
        'isco_sub_major_group_code',
        'isco_minor_group_code',
        'isco_unit_group_code',
        'skill_level',
    ];

    /**
     * The ISCO-08 skill level of a code. Each major group has one level, except
     * Managers and Armed Forces, whose level is set by the sub-major group.
     */
    public static function iscoSkillLevel(string $code): ?string
    {
        $bySubMajor = ['01' => '4', '02' => '2', '03' => '1', '11' => '4', '12' => '4', '13' => '4', '14' => '3'];
        $byMajor = ['2' => '4', '3' => '3', '4' => '2', '5' => '2', '6' => '2', '7' => '2', '8' => '2', '9' => '1'];

        return $bySubMajor[substr($code, 0, 2)] ?? $byMajor[substr($code, 0, 1)] ?? null;
    }

    /**
     * The hierarchy codes a recorded ISCO code implies and the reference row it cites.
     *
     * @return array<string, string|null>
     */
    public static function iscoAttributesFor(string $code): array
    {
        $length = strlen($code);

        return [
            'isco_major_group_code' => substr($code, 0, 1),
            'isco_sub_major_group_code' => $length >= 2 ? substr($code, 0, 2) : null,
            'isco_minor_group_code' => $length >= 3 ? substr($code, 0, 3) : null,
            'isco_unit_group_code' => $length === 4 ? $code : null,
            'isco_group_id' => IscoGroup::query()->where('code', $code)->value('id'),
        ];
    }

    public function setIscoCodeAttribute(?string $value): void
    {
        $this->attributes['isco_code'] = $value === null ? null : trim($value);
    }

    /** The ISCO reference row this occupation cites; the recorded codes stay on the occupation. */
    public function iscoGroup(): BelongsTo
    {
        return $this->belongsTo(IscoGroup::class, 'isco_group_id');
    }

    public function professions(): HasMany
    {
        return $this->hasMany(Profession::class)->orderBy('sort_order')->orderBy('code');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
