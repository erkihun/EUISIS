<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BreakGlassGrant;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * The break-glass grant in force for a person right now (ADR 0010), read at
 * most once per request. Its time is checked here, so an expired grant gives
 * nothing even before core:break-glass-expire records it.
 */
final class BreakGlassAccess
{
    /** @var array<int, ?BreakGlassGrant> */
    private array $grants = [];

    public static function for(User $user): ?BreakGlassGrant
    {
        return app(self::class)->grant($user);
    }

    public function grant(User $user): ?BreakGlassGrant
    {
        $id = (int) $user->getKey();
        if (! array_key_exists($id, $this->grants)) {
            try {
                $this->grants[$id] = BreakGlassGrant::query()->inForce()->where('user_id', $id)->first();
            } catch (QueryException) {
                $this->grants[$id] = null; // before the table exists (mid-migration)
            }
        }

        return $this->grants[$id];
    }

    public function forget(): void
    {
        $this->grants = [];
    }
}
