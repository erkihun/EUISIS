<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Feedback\Domain\Enums\OrganizationFeedbackQrStatus;
use Modules\Feedback\Domain\Models\OrganizationFeedbackQrCode;
use RuntimeException;

/**
 * Mints, pauses, rotates and revokes an organization's public feedback QR.
 *
 * The token is stable by default: nothing but a deliberate, audited rotation
 * or revocation by an authorized person changes it (brief §10–12). Resolution
 * for the public page is one indexed lookup that also requires the
 * organization itself to be active (brief §75, 98).
 */
class OrganizationFeedbackQrService
{
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly WriteAuditLogAction $audit) {}

    public function activeFor(Organization $organization): ?OrganizationFeedbackQrCode
    {
        return OrganizationFeedbackQrCode::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', OrganizationFeedbackQrStatus::Active->value)
            ->first();
    }

    /** Create the organization's code if it has none; otherwise return the one it has. */
    public function ensureActive(Organization $organization, ?User $actor = null, ?Request $request = null): OrganizationFeedbackQrCode
    {
        return DB::transaction(function () use ($organization, $actor, $request): OrganizationFeedbackQrCode {
            // Serialise concurrent "generate" clicks on the organization row.
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

            $active = $this->activeFor($organization);

            if ($active !== null) {
                return $active;
            }

            $qr = $this->mint($organization, $actor, null);

            $this->audit->execute(
                eventType: AuditEventType::OrganizationFeedbackQrGenerated,
                actor: $actor,
                auditable: $qr,
                organizationId: (string) $organization->getKey(),
                reason: 'Organization feedback QR generated',
                request: $request,
            );

            return $qr;
        });
    }

    public function disable(OrganizationFeedbackQrCode $qr, ?User $actor = null, ?Request $request = null): OrganizationFeedbackQrCode
    {
        if ($qr->status !== OrganizationFeedbackQrStatus::Active) {
            throw new RuntimeException('Only an active feedback QR can be disabled.');
        }

        $qr->forceFill([
            'status' => OrganizationFeedbackQrStatus::Disabled,
            'disabled_at' => now(),
            'updated_by' => $actor?->getKey(),
        ])->save();

        $this->audit->execute(
            eventType: AuditEventType::OrganizationFeedbackQrDisabled,
            actor: $actor,
            auditable: $qr,
            organizationId: (string) $qr->organization_id,
            reason: 'Organization feedback QR disabled',
            request: $request,
        );

        return $qr;
    }

    public function enable(OrganizationFeedbackQrCode $qr, ?User $actor = null, ?Request $request = null): OrganizationFeedbackQrCode
    {
        if ($qr->status !== OrganizationFeedbackQrStatus::Disabled) {
            throw new RuntimeException('Only a disabled feedback QR can be enabled; a revoked one needs a new code.');
        }

        $qr->forceFill([
            'status' => OrganizationFeedbackQrStatus::Active,
            'disabled_at' => null,
            'enabled_at' => now(),
            'updated_by' => $actor?->getKey(),
        ])->save();

        $this->audit->execute(
            eventType: AuditEventType::OrganizationFeedbackQrEnabled,
            actor: $actor,
            auditable: $qr,
            organizationId: (string) $qr->organization_id,
            reason: 'Organization feedback QR enabled',
            request: $request,
        );

        return $qr;
    }

    /**
     * Revoke the current code and issue a new one in one transaction. The old
     * row stays, with its reason and the person who rotated it, so every
     * submission it ever produced still names the identity it came through.
     */
    public function rotate(OrganizationFeedbackQrCode $qr, string $reason, User $actor, ?Request $request = null): OrganizationFeedbackQrCode
    {
        return DB::transaction(function () use ($qr, $reason, $actor, $request): OrganizationFeedbackQrCode {
            $this->markRevoked($qr, $reason, $actor);

            $next = $this->mint($qr->organization, $actor, $qr);

            $this->audit->execute(
                eventType: AuditEventType::OrganizationFeedbackQrRotated,
                actor: $actor,
                auditable: $next,
                organizationId: (string) $qr->organization_id,
                reason: 'Organization feedback QR rotated: '.$reason,
                request: $request,
            );

            return $next;
        });
    }

    public function revoke(OrganizationFeedbackQrCode $qr, string $reason, User $actor, ?Request $request = null): OrganizationFeedbackQrCode
    {
        $this->markRevoked($qr, $reason, $actor);

        $this->audit->execute(
            eventType: AuditEventType::OrganizationFeedbackQrRevoked,
            actor: $actor,
            auditable: $qr,
            organizationId: (string) $qr->organization_id,
            reason: 'Organization feedback QR revoked: '.$reason,
            request: $request,
        );

        return $qr;
    }

    public function recordExport(OrganizationFeedbackQrCode $qr, string $format, ?User $actor = null, ?Request $request = null): void
    {
        $this->audit->execute(
            eventType: AuditEventType::OrganizationFeedbackQrExported,
            actor: $actor,
            auditable: $qr,
            organizationId: (string) $qr->organization_id,
            reason: 'Organization feedback QR exported as '.$format,
            request: $request,
        );
    }

    /**
     * The code a scanned token names, only while both the code and its
     * organization are active. Everything else resolves to nothing — the
     * public page cannot tell a revoked code from an unknown one.
     */
    public function resolveForPublic(string $token): ?OrganizationFeedbackQrCode
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        return OrganizationFeedbackQrCode::query()
            ->with('organization:id,code,name_en,name_am,logo_path,status,public_contact_phone')
            ->where('public_token', $token)
            ->where('status', OrganizationFeedbackQrStatus::Active->value)
            ->whereHas('organization', fn ($query) => $query->where('status', OrganizationStatus::Active->value))
            ->first();
    }

    public function recordScan(OrganizationFeedbackQrCode $qr): void
    {
        $this->recordScanById((string) $qr->getKey());
    }

    /** Counts a scan of an already-resolved code without resolving it again. */
    public function recordScanById(string $codeId): void
    {
        OrganizationFeedbackQrCode::query()->whereKey($codeId)->update([
            'last_scanned_at' => now(),
            'scan_count' => DB::raw('scan_count + 1'),
        ]);
    }

    private function mint(Organization $organization, ?User $actor, ?OrganizationFeedbackQrCode $previous): OrganizationFeedbackQrCode
    {
        return OrganizationFeedbackQrCode::query()->create([
            'organization_id' => $organization->getKey(),
            'public_token' => $this->generateToken(),
            'status' => OrganizationFeedbackQrStatus::Active,
            'enabled_at' => now(),
            'rotated_from_id' => $previous?->getKey(),
            'created_by' => $actor?->getKey(),
            'updated_by' => $actor?->getKey(),
        ]);
    }

    private function markRevoked(OrganizationFeedbackQrCode $qr, string $reason, User $actor): void
    {
        if ($qr->status === OrganizationFeedbackQrStatus::Revoked) {
            throw new RuntimeException('This feedback QR is already revoked.');
        }

        $qr->forceFill([
            'status' => OrganizationFeedbackQrStatus::Revoked,
            'revoked_at' => now(),
            'revoked_by' => $actor->getKey(),
            'revocation_reason' => $reason,
            'updated_by' => $actor->getKey(),
        ])->save();
    }

    /** 256 random bits, hex; checked against every existing row so no token is ever reused. */
    private function generateToken(): string
    {
        do {
            $candidate = bin2hex(random_bytes(self::TOKEN_BYTES));
        } while (OrganizationFeedbackQrCode::query()->where('public_token', $candidate)->exists());

        return $candidate;
    }
}
