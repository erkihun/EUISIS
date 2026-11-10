<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Models\User;
use Modules\Letters\Domain\Models\Letter;

/**
 * Starts the answer to a received letter: a draft outgoing letter from the
 * institution that received it, addressed back to the sender (in the system
 * or outside it), referencing the original.
 */
final class CreateReply
{
    public function __construct(private readonly ComposeLetter $compose) {}

    public function execute(Letter $original, string $organizationId, User $actor): Letter
    {
        $backToOrganization = $original->direction->value === 'incoming' ? $original->sender_organization_id : $original->organization_id;
        $external = $original->direction->value === 'incoming' && $original->sender_organization_id === null;

        return $this->compose->execute([
            'direction' => 'outgoing',
            'organization_id' => $organizationId,
            'recipient_organization_id' => $external ? null : $backToOrganization,
            'external_party_name' => $external ? $original->external_party_name : null,
            'external_party_address' => $external ? $original->external_party_address : null,
            'subject' => __('letters.reply_prefix').$original->subject,
            'language' => $original->language,
            'priority' => $original->priority->value,
            'letter_date' => now()->toDateString(),
            'in_reply_to_letter_id' => $original->id,
            'body' => '',
        ], $actor);
    }
}
