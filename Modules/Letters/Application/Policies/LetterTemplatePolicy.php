<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Policies;

use App\Models\User;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Enums\TemplateStatus;
use Modules\Letters\Domain\Models\LetterTemplate;

/** Templates: platform ones are the steward's (city-wide); an institution's are its administrator's. */
final class LetterTemplatePolicy
{
    public function __construct(private readonly LettersAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canViewTemplates($user);
    }

    public function view(User $user, LetterTemplate $template): bool
    {
        return $this->access->canViewTemplate($user, $template);
    }

    public function create(User $user): bool
    {
        return $user->can('letters.templates.manage') || ($user->can('letters.templates.manage_platform') && $this->access->isCitywide($user));
    }

    public function update(User $user, LetterTemplate $template): bool
    {
        return $template->status !== TemplateStatus::Archived && $this->access->canManageTemplate($user, $template->owner_organization_id);
    }

    public function publish(User $user, LetterTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    public function archive(User $user, LetterTemplate $template): bool
    {
        return $template->status !== TemplateStatus::Archived && $this->access->canManageTemplate($user, $template->owner_organization_id);
    }
}
