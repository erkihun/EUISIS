<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Models\LetterRegistration;

/** The registry listing's query, shared by the page and the CSV export so both answer the same filters. */
class RegistryQuery
{
    public function __construct(private readonly LettersAccess $access) {}

    /**
     * @param  array<string, string>  $filters  direction, status, organization_id, q, from, to
     * @return Builder<LetterRegistration>
     */
    public function build(User $user, array $filters, bool $inboxOnly = false): Builder
    {
        $query = $this->access->scopeRegistrations(LetterRegistration::query(), $user)
            ->with(['letter.organization', 'letter.senderOrganization', 'letter.recipientOrganization', 'letter.unit', 'letter.fileClass', 'letter.routings', 'organization', 'routings']);

        if ($inboxOnly) {
            $query->where('status', RegistrationStatus::PendingReceipt->value);
        } else {
            $query->whereNotNull('registered_at');
        }
        if (($filters['direction'] ?? '') !== '') {
            $query->where('direction', $filters['direction']);
        }
        if (($filters['status'] ?? '') !== '' && ! $inboxOnly) {
            $query->where('status', $filters['status']);
        }
        if (($filters['organization_id'] ?? '') !== '') {
            $query->where('organization_id', $filters['organization_id']);
        }
        if (($filters['file_class_id'] ?? '') !== '') {
            $query->whereHas('letter', fn (Builder $l) => $l->where('file_class_id', $filters['file_class_id']));
        }
        if (($filters['from'] ?? '') !== '') {
            $query->whereDate('registered_at', '>=', $filters['from']);
        }
        if (($filters['to'] ?? '') !== '') {
            $query->whereDate('registered_at', '<=', $filters['to']);
        }
        if (($filters['q'] ?? '') !== '') {
            $q = $filters['q'];
            $query->where(fn (Builder $w) => $w->where('registry_number', 'like', "%{$q}%")
                ->orWhereHas('letter', fn (Builder $l) => $l->where('subject', 'like', "%{$q}%")->orWhere('external_party_name', 'like', "%{$q}%")
                    ->orWhere('external_reference', 'like', "%{$q}%")->orWhere('file_code', 'like', "%{$q}%")));
        }

        return $query->orderByDesc($inboxOnly ? 'created_at' : 'registered_at');
    }
}
