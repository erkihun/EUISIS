<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\BreakGlassGrant;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\BreakGlassAccess;
use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use App\Support\CorePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Break-glass access (ADR 0010).
 *
 *  - A person with break_glass.request asks for named permissions, over one
 *    organization (and everything under it) or the whole city, for a number
 *    of minutes up to security.break_glass_max_minutes, with a reason.
 *  - Someone else with break_glass.approve approves (it starts at once) or
 *    rejects it. Nobody approves their own.
 *  - It ends by itself when its time runs out (and core:break-glass-expire
 *    records that), or earlier by its holder or a break_glass.revoke holder.
 *  - Never granted this way: managing roles, accounts, settings, integration
 *    secrets, the audit seal, and break-glass itself.
 *  - Every audit row written while it is in force names it.
 */
final readonly class ManageBreakGlass
{
    public const EXCLUDED = [
        'roles.manage', 'users.create', 'users.update', 'users.restore', 'system-settings.update', 'integrations.manage',
        'audit.integrity.verify', 'break_glass.request', 'break_glass.approve', 'break_glass.revoke',
    ];

    public function __construct(private AuditWriter $audit, private CoreSettings $settings) {}

    /** @return list<string> */
    public static function grantable(): array
    {
        return array_values(array_diff(CorePermissions::all(), self::EXCLUDED));
    }

    /** @param array<string, mixed> $input */
    public function request(array $input, User $actor): BreakGlassGrant
    {
        abort_unless($actor->is_active && $actor->can('break_glass.request'), 403);
        $max = $this->settings->int('security.break_glass_max_minutes');
        $data = Validator::make($input, [
            'permissions' => ['required', 'array', 'min:1', 'max:30'],
            'permissions.*' => ['string', Rule::in(self::grantable())],
            'scope_type' => ['required', Rule::in(['organization', User::SCOPE_CITYWIDE])],
            'organization_id' => ['required_if:scope_type,organization', 'nullable', 'uuid', Rule::exists('organizations', 'id')],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:'.$max],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
        ])->validate();
        if (BreakGlassGrant::query()->where('user_id', $actor->getKey())->whereIn('status', [BreakGlassGrant::REQUESTED, BreakGlassGrant::ACTIVE])->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
            throw ValidationException::withMessages(['permissions' => 'You already have an emergency access request or grant open.']);
        }

        return DB::transaction(function () use ($data, $actor): BreakGlassGrant {
            $grant = BreakGlassGrant::query()->create([
                'user_id' => $actor->getKey(), 'permissions' => array_values(array_unique($data['permissions'])), 'scope_type' => $data['scope_type'],
                'organization_id' => $data['scope_type'] === 'organization' ? $data['organization_id'] : null, 'reason' => $data['reason'],
                'duration_minutes' => (int) $data['duration_minutes'], 'status' => BreakGlassGrant::REQUESTED, 'requested_at' => now(),
            ]);
            $this->audit->write('break_glass.requested', $actor, $grant, $grant->organization_id, null, $grant->only(['permissions', 'scope_type', 'organization_id', 'duration_minutes']), $data['reason']);

            return $grant;
        });
    }

    public function decide(BreakGlassGrant $grant, bool $approve, ?string $reason, User $actor): BreakGlassGrant
    {
        abort_unless($actor->is_active && $actor->can('break_glass.approve'), 403);
        if (! $approve && mb_strlen(trim((string) $reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give the reason for refusing (at least 5 characters).']);
        }

        return DB::transaction(function () use ($grant, $approve, $reason, $actor): BreakGlassGrant {
            $grant = BreakGlassGrant::query()->lockForUpdate()->findOrFail($grant->id);
            if ($grant->status !== BreakGlassGrant::REQUESTED) {
                throw ValidationException::withMessages(['grant' => 'This request has already been decided.']);
            }
            if ((int) $grant->user_id === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['grant' => 'Someone other than the requester decides an emergency access request.']);
            }
            $grant->update($approve
                ? ['status' => BreakGlassGrant::ACTIVE, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_reason' => $reason,
                    'starts_at' => now(), 'expires_at' => now()->addMinutes($grant->duration_minutes)]
                : ['status' => BreakGlassGrant::REJECTED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_reason' => $reason]);
            $this->audit->write($approve ? 'break_glass.approved' : 'break_glass.rejected', $actor, $grant, $grant->organization_id, null,
                $grant->only(['status', 'starts_at', 'expires_at']), $reason);
            $this->forget();

            return $grant;
        });
    }

    public function end(BreakGlassGrant $grant, string $reason, User $actor): BreakGlassGrant
    {
        $own = (int) $grant->user_id === (int) $actor->getKey();
        abort_unless($actor->is_active && ($own || $actor->can('break_glass.revoke')), 403);
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give the reason (at least 5 characters).']);
        }

        return DB::transaction(function () use ($grant, $reason, $actor): BreakGlassGrant {
            $grant = BreakGlassGrant::query()->lockForUpdate()->findOrFail($grant->id);
            if (! in_array($grant->status, [BreakGlassGrant::ACTIVE, BreakGlassGrant::REQUESTED], true)) {
                throw ValidationException::withMessages(['grant' => 'This emergency access has already ended.']);
            }
            $grant->update(['status' => BreakGlassGrant::REVOKED, 'ended_by' => $actor->getKey(), 'ended_at' => now(), 'end_reason' => $reason]);
            $this->audit->write('break_glass.revoked', $actor, $grant, $grant->organization_id, null, ['status' => BreakGlassGrant::REVOKED], $reason);
            $this->forget();

            return $grant;
        });
    }

    /** @return int grants whose time ran out, now recorded as expired */
    public function expire(): int
    {
        $count = 0;
        BreakGlassGrant::query()->where('status', BreakGlassGrant::ACTIVE)->where('expires_at', '<=', now())->orderBy('id')->each(function (BreakGlassGrant $grant) use (&$count): void {
            DB::transaction(function () use ($grant, &$count): void {
                $locked = BreakGlassGrant::query()->lockForUpdate()->find($grant->id);
                if ($locked?->status === BreakGlassGrant::ACTIVE && $locked->expires_at?->lte(now())) {
                    $locked->update(['status' => BreakGlassGrant::EXPIRED, 'ended_at' => $locked->expires_at]);
                    $this->audit->write('break_glass.expired', null, $locked, $locked->organization_id, null, ['status' => BreakGlassGrant::EXPIRED]);
                    $count++;
                }
            });
        });

        return $count;
    }

    /** A decision changes who holds what at once, in this request too. */
    private function forget(): void
    {
        app(BreakGlassAccess::class)->forget();
        app(OrganizationScope::class)->clearCache();
    }

    public static function organizationName(?string $id): ?string
    {
        return $id === null ? null : Organization::query()->whereKey($id)->value('name_en');
    }
}
