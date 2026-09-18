<?php

use App\Services\Nfc\UnavailableSecureCardAdapter;

return [
    // Bind an audited hardware adapter in the container before enabling secure cards.
    'adapter' => UnavailableSecureCardAdapter::class,
    'challenge_ttl_seconds' => 60,
];
