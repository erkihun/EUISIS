<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Policies;

use App\Models\User;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Models\Letter;

/** Letters: every rule depends on the record's state and institution, so every check goes through LettersAccess. */
final class LetterPolicy
{
    public function __construct(private readonly LettersAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('letters.view') || $user->can('letters.handle');
    }

    public function view(User $user, Letter $letter): bool
    {
        $letter->loadMissing(['registrations', 'routings']);

        return $this->access->canView($user, $letter);
    }

    public function update(User $user, Letter $letter): bool
    {
        return $this->access->canEdit($user, $letter);
    }

    public function submit(User $user, Letter $letter): bool
    {
        return $this->access->canSubmit($user, $letter);
    }

    public function approve(User $user, Letter $letter): bool
    {
        return $this->access->canApprove($user, $letter);
    }

    public function return(User $user, Letter $letter): bool
    {
        return $this->access->canReturn($user, $letter);
    }

    public function dispatch(User $user, Letter $letter): bool
    {
        return $this->access->canDispatch($user, $letter);
    }

    public function cancel(User $user, Letter $letter): bool
    {
        return $this->access->canCancel($user, $letter);
    }

    public function reply(User $user, Letter $letter): bool
    {
        $letter->loadMissing('registrations');

        return $this->access->canReply($user, $letter);
    }

    public function addAttachment(User $user, Letter $letter): bool
    {
        return $this->access->canAddAttachment($user, $letter);
    }

    public function viewContents(User $user, Letter $letter): bool
    {
        $letter->loadMissing(['registrations', 'routings']);

        return $this->access->canViewContents($user, $letter);
    }

    public function pdf(User $user, Letter $letter): bool
    {
        return $this->viewContents($user, $letter) && $letter->isComposed();
    }
}
