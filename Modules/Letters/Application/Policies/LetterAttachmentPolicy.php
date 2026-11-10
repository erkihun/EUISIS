<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Policies;

use App\Models\User;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Models\LetterAttachment;

/** Whoever may open the letter may download its files. */
final class LetterAttachmentPolicy
{
    public function __construct(private readonly LettersAccess $access) {}

    public function download(User $user, LetterAttachment $attachment): bool
    {
        $letter = $attachment->letter;
        if ($letter === null) {
            return false;
        }
        $letter->loadMissing(['registrations', 'routings']);

        return $this->access->canViewContents($user, $letter);
    }
}
