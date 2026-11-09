<?php

declare(strict_types=1);

/*
 * Classes outside a module that still use its internals, frozen when the module
 * moved (ADR 0017). ModulePublicApiTest ignores exactly these for that module and
 * fails when one no longer needs it, so the list can only shrink: replace a use
 * with the module's Contracts and delete the line.
 *
 * Providers and Entitlements (2026-10-30): both lists emptied the same day; other
 * modules hold their records through the published Contracts\ReadModels and write
 * through their commands. The file stays for the next module that moves.
 */
return [
];
