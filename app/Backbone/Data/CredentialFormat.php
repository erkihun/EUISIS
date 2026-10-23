<?php

declare(strict_types=1);

namespace App\Backbone\Data;

/** The scan values a service may accept for an ID card credential. */
enum CredentialFormat: string
{
    /** The printed QR: the stable card URL or its bare public card UUID. */
    case PublicReference = 'public_reference';

    /** "<card record uuid>|<raw token>", checked against the stored token hash. */
    case Token = 'token';

    /** A legacy URL ending in the card record UUID; accepted only while id_cards.accept_legacy_qr is on (decision D-3). */
    case LegacyCardId = 'legacy_card_id';
}
