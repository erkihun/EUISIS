<?php

declare(strict_types=1);

namespace Modules\Communication\Contracts;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * What the shared layout shows of Communication on every page (ADR 0017):
 * the top-bar Messages button. Nothing else of Communication travels with
 * every page.
 */
interface CommunicationTopBar
{
    /**
     * Null when Communication is off or the person may not use it; otherwise
     * enabled, the workspace navigation (on Communication's own pages only),
     * the unread total and whether realtime is configured.
     *
     * @return array{enabled: bool, nav: mixed, unread_count: int, realtime: bool}|null
     */
    public function props(?User $user, Request $request): ?array;
}
