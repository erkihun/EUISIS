<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | ID Card QR Code
    |--------------------------------------------------------------------------
    |
    | The QR printed on the physical ID card.
    |
    | model: QR Code Model 2 (ISO/IEC 18004). The generator library implements
    |        Model 2 exclusively, so this records the contract and is asserted
    |        at render time rather than selected.
    |
    | default_error_correction: level Q (25% recovery), for a card that gets
    |        handled, scratched and photocopied. Never lowered automatically to
    |        make a payload fit — the symbol grows instead.
    |
    | minimum_version / maximum_version: version 6 is the floor, not the
    |        ceiling. A payload that outgrows the floor upgrades to the smallest
    |        version that holds it; one that outgrows the ceiling is an error,
    |        because an unbounded symbol would eventually stop scanning from a
    |        card-sized print.
    |
    | base_url: the public verification origin. A dedicated short domain keeps
    |        the payload small, which keeps the symbol version low.
    |
    */

    'qr' => [
        'model' => 2,
        'default_error_correction' => 'Q',
        'minimum_version' => 6,
        'maximum_version' => 12,
        'auto_upgrade_version' => true,

        // Defaults to the existing /id-checker path so nothing changes until a
        // short domain is deliberately configured.
        'base_url' => env('ID_CARD_QR_BASE_URL'),
        'short_url_enabled' => env('ID_CARD_QR_SHORT_URL_ENABLED', false),

        // Path segment used when the short URL is enabled: /c/{uuid}.
        'short_path' => 'c',
    ],

];
