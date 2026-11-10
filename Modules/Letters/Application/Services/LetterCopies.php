<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterRecipient;

/** Keeps a draft's copy recipients (ግልባጭ) in step with the composer's list. */
class LetterCopies
{
    /**
     * @param  list<array{organization_id?: string|null, external_name?: string|null, external_address?: string|null}>|null  $copies
     */
    public function sync(Letter $letter, ?array $copies): void
    {
        LetterRecipient::query()->where('letter_id', $letter->id)->where('kind', LetterRecipient::KIND_CC)->delete();
        $position = 0;
        $seen = [];
        foreach ($copies ?? [] as $copy) {
            if (! is_array($copy)) {
                continue;
            }
            $organizationId = isset($copy['organization_id']) && is_string($copy['organization_id']) && $copy['organization_id'] !== '' ? $copy['organization_id'] : null;
            $name = isset($copy['external_name']) ? trim((string) $copy['external_name']) : '';
            if ($organizationId === null && $name === '') {
                continue;
            }
            if ($organizationId !== null && ($organizationId === $letter->organization_id || $organizationId === $letter->recipient_organization_id || isset($seen[$organizationId]))) {
                continue;
            }
            if ($organizationId !== null) {
                $seen[$organizationId] = true;
            }
            LetterRecipient::query()->create([
                'letter_id' => $letter->id,
                'kind' => LetterRecipient::KIND_CC,
                'organization_id' => $organizationId,
                'external_name' => $organizationId === null ? mb_substr($name, 0, 255) : null,
                'external_address' => $organizationId === null && isset($copy['external_address']) && trim((string) $copy['external_address']) !== '' ? mb_substr(trim((string) $copy['external_address']), 0, 500) : null,
                'position' => $position++,
            ]);
        }
    }
}
