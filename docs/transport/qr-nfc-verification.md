# Transport — QR and NFC Verification

Both credentials resolve to the same card and go through the same engine; the method is recorded on the boarding (`verification_method`: `qr`, `nfc`, `manual_exception`).

## QR
The card's printed QR carries the stable public card URL (`CardQrPayloadService::buildStableQrUrl`). The scan value is resolved to `public_card_uuid` (`resolvePublicUuidFromScanValue`), the card and employee are loaded, and only a **hash** of the value (`qr_reference_hash`) is stored — never the value.

## NFC
Behind `transport_nfc` and `nfc_enabled`. The tag reference is resolved through `NfcCredentialService::resolveForAttendedScan` (status: active / expired / revoked / suspended / pending / replaced → `nfc_credential_*` reasons). No PII lives on the tag.

## The decision pipeline (`TransportBoardingService::board`)
1. Replay: same idempotency key / scan nonce for the provider → the first decision, no new row.
2. Provider offers transport; device (API) is active, of the provider, bound to the trip's vehicle when bound.
3. Credential → card → employee (`card_not_found`, `id_card_*`, `employee_inactive`, `employee_assignment_missing`).
4. Trip authority: trip exists, is the provider's, is today's, is boardable, matches the route (`trip_required`, `trip_not_found`, `trip_not_today`, `trip_not_boardable`, `trip_route_mismatch`); stop on route.
5. Eligibility (pass / grants / policies) with snapshot.
6. Per-employee lock (PostgreSQL advisory) → duplicate check (per trip; per direction per day by policy) → capacity (block or warn).
7. Write the row (accepted or refused with `result_code`), update `boarded_count`, record exceptions, audit manual boardings.

Refusals are evidence too: every refused scan is a `rejected` row with its reason.

## The terminal UX (`TransportScanPanel`)
Continuous camera scanning (auto-restart after each result), keyboard-wedge readers typing straight into the page, audio + vibration per outcome (accepted / duplicate / refused), a three-second auto-reset, the session counter, the trip's live occupancy, one-tap trip activation for dispatchers, the stop selector, and the manual-exception form (employee number lookup limited to id, number and name; reason required). Admin and portal share the component.

## Result codes
`lang/{en,am}/transport.php → reasons` and `resources/js/i18n/{en,am}/transport.ts → reasons` carry the same keys; the operator sees them in their language.
