# Transport — Device Security

* A scanner is registered by the city (`transport-devices.create`) for one provider, optionally bound to one vehicle. Registration issues a credential `tdev_…` shown **once**; only `sha256(credential)` and a 12-character prefix are stored.
* The device API (`/api/v1/transport/*`) authenticates with `Authorization: Bearer tdev_…` through `AuthenticateTransportDevice`: module flag on, credential resolves, device `active`, provider active and offering transport. `last_seen_at`, `last_ip` and `software_version` are touched on each call; `last_sync_at` on heartbeat/sync.
* Rate limit `transport-device` per device; refused credentials are throttled per IP.
* Rotation (`transport-devices.update`) issues a new credential and invalidates the old one at once. Revocation (`transport-devices.revoke`, or the provider's *Report lost*) is immediate and permanent (`revoked_at`, reason, audit `transport_device.revoked`).
* A bound device may only board trips on its own vehicle (`device_trip_mismatch` + exception); any device may only act inside its provider.
* Devices never carry employee lists, tariffs or settings secrets; the `GET device` answer carries capabilities (offline, nfc, gps flags, duplicate and capacity policies, inactivity timeout) only.
* Silent devices (no contact past `device_inactivity_timeout_minutes`) show on the device lists, in the Overview and in system health (`transport` component).
* `transport_devices.credential_hash` is unique; the plain credential is never logged (`TransportDeviceService` masks it).
