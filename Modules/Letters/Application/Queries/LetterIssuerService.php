<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Queries;

use App\Models\User;
use InvalidArgumentException;
use Modules\Letters\Application\Actions\ComposeLetter;
use Modules\Letters\Contracts\Data\IssueLetterRequest;
use Modules\Letters\Contracts\Data\LetterSummary;
use Modules\Letters\Contracts\LetterIssuer;
use Modules\Letters\Contracts\LetterLinks;
use Modules\Letters\Domain\Enums\TemplateStatus;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterTemplate;

/** The public contract's implementation (ADR 0037 §2): drafts from templates for other modules' records, and the links back. */
final class LetterIssuerService implements LetterIssuer, LetterLinks
{
    public function __construct(private readonly ComposeLetter $compose) {}

    public function issue(IssueLetterRequest $request): LetterSummary
    {
        $template = LetterTemplate::query()->where('code', strtoupper($request->templateCode))
            ->where('status', TemplateStatus::Published->value)
            ->where(fn ($q) => $q->whereNull('owner_organization_id')->orWhere('owner_organization_id', $request->organizationId))
            ->orderByRaw('owner_organization_id is null')
            ->first();
        $version = $template?->currentVersion();
        if ($template === null || $version === null || ! $template->isOfferedTo($request->organizationId)) {
            throw new InvalidArgumentException("Letter template [{$request->templateCode}] is not offered to organization [{$request->organizationId}].");
        }
        if (! in_array($request->direction, ['outgoing', 'internal'], true)) {
            throw new InvalidArgumentException("Letters issued through the contract are outgoing or internal, [{$request->direction}] given.");
        }

        $actor = $request->actorId !== null ? User::query()->find($request->actorId) : null;
        $letter = $this->compose->execute([
            'direction' => $request->direction,
            'organization_id' => $request->organizationId,
            'recipient_organization_id' => $request->recipientOrganizationId,
            'external_party_name' => $request->externalPartyName,
            'external_party_address' => $request->externalPartyAddress,
            'signatory_user_id' => $request->signatoryUserId,
            'template_version_id' => $version->id,
            'template_context' => $request->context,
            'language' => $request->language ?? $template->language,
            'letter_date' => now()->toDateString(),
        ], $actor, $request->regardingType, $request->regardingId);

        return $this->summary($letter);
    }

    public function forRecord(string $regardingType, string $regardingId): array
    {
        return Letter::query()->with('registrations')
            ->where('regarding_type', $regardingType)->where('regarding_id', $regardingId)
            ->orderByDesc('created_at')->get()
            ->map(fn (Letter $letter): LetterSummary => $this->summary($letter))->all();
    }

    private function summary(Letter $letter): LetterSummary
    {
        $letter->loadMissing('registrations');

        return new LetterSummary(
            id: $letter->id,
            direction: $letter->direction->value,
            status: $letter->status->value,
            subject: (string) $letter->subject,
            referenceNumber: $letter->referenceNumber(),
            letterDate: (string) $letter->letter_date?->toDateString(),
            url: route('letters.show', $letter, false),
        );
    }
}
