<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Queries;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Communication\Application\Services\CommunicationInbox;
use Modules\Communication\Application\Services\CommunicationNavigation;
use Modules\Communication\Application\Services\CommunicationSettings;
use Modules\Communication\Contracts\CommunicationTopBar;

/** The top-bar Messages button (moved here from the shared Inertia middleware). */
final class CommunicationTopBarQuery implements CommunicationTopBar
{
    public function __construct(
        private readonly CommunicationSettings $settings,
        private readonly CommunicationNavigation $navigation,
        private readonly CommunicationInbox $inbox,
    ) {}

    public function props(?User $user, Request $request): ?array
    {
        if ($user === null || ! $this->settings->enabled() || ! $user->can('communication.access')) {
            return null;
        }

        return [
            'enabled' => true,
            // The workspace's own navigation (Communication has no sidebar entry), only on its pages.
            'nav' => $request->routeIs('communication.*') ? $this->navigation->for($user) : null,
            // Cached briefly by CommunicationInbox.
            'unread_count' => $this->inbox->totals($user)['total'],
            'realtime' => ! in_array(config('broadcasting.default'), [null, 'null', 'log'], true),
        ];
    }
}
