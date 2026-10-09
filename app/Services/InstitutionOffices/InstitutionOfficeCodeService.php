<?php

declare(strict_types=1);

namespace App\Services\InstitutionOffices;

use App\Enums\InstitutionOfficeLevel;
use App\Models\InstitutionOffice;
use App\Models\Organization;
use Illuminate\Support\Str;

class InstitutionOfficeCodeService
{
    /**
     * Generate a unique office code for the given institution and level.
     */
    public function generateCode(
        Organization $institution,
        InstitutionOfficeLevel $level,
        ?InstitutionOffice $parentOffice = null,
    ): string {
        $prefix = Str::upper(Str::substr($institution->code ?? Str::slug($institution->name_en ?? 'ORG'), 0, 4));
        $levelCode = Str::upper(Str::substr($level->value, 0, 3));
        $sequence = $this->nextSequence($institution->id, $level);

        $base = $prefix.'-'.$levelCode.'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);

        // Ensure uniqueness
        $candidate = $base;
        $attempts = 0;

        while (InstitutionOffice::query()->where('office_code', $candidate)->exists()) {
            $attempts++;
            $candidate = $base.'-'.$attempts;
        }

        return $candidate;
    }

    private function nextSequence(string $institutionId, InstitutionOfficeLevel $level): int
    {
        $count = InstitutionOffice::query()
            ->withTrashed()
            ->where('institution_id', $institutionId)
            ->where('office_level', $level->value)
            ->count();

        return $count + 1;
    }
}
