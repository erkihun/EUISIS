<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Models\User;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterEvent;
use Modules\Letters\Domain\Models\LetterRegistration;

/** Appends to a letter's timeline. */
class LetterEvents
{
    /** @param array<string, mixed> $payload */
    public function record(Letter $letter, LetterEventType $type, ?User $actor, array $payload = [], ?LetterRegistration $registration = null): LetterEvent
    {
        return LetterEvent::query()->create([
            'letter_id' => $letter->id,
            'registration_id' => $registration?->id,
            'type' => $type->value,
            'actor_id' => $actor?->id,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
