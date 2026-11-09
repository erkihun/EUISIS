# Webhook security

Audience: partner engineers receiving Addis One webhooks, and Addis One operators.

## Request

```
POST {your callback URL}
Content-Type: application/json
User-Agent: AddisOne-Webhooks/1
Addis-Event-Id: 019a3c12-0000-7000-8000-000000000004
Addis-Event-Type: id_card.revoked
Addis-Timestamp: 1792400000
Addis-Delivery-Id: 8f1c…            (changes on every delivery generation)
Addis-Signature: t=1792400000,v1=5f2c…[,v1=…]

{ event envelope — see event-catalog.md }
```

## Verifying (do all four)

1. Parse `Addis-Signature`: `t` and one or more `v1`.
2. Reject if `|now − t| > 300` seconds (replay window).
3. Compute `hex(HMAC-SHA256(secret, t + "." + Addis-Event-Id + "." + raw_body))`
   over the **raw bytes** of the body, before any JSON parsing.
4. Accept if any `v1` equals it (constant-time compare). Two `v1` values appear during
   a secret rotation; one of them matches the secret you hold.

Then de-duplicate on `event_id` (store processed ids), answer **2xx quickly** (within a
few seconds) and process asynchronously. Reference implementation:
`App\Services\Integration\Webhooks\WebhookSigner::verify()`.

## Delivery semantics

- **At-least-once.** Retries and replays resend the same `event_id`. Exactly-once is
  not offered.
- **Retries:** network errors, timeouts, 408, 425, 429 and 5xx are retried with
  exponential backoff and jitter (base 30 s, cap 1 h, at most 8 attempts by default).
  Then the delivery is **dead**; the event stays replayable.
- **No retry** for 3xx (redirects are never followed), 400, 401, 403, 404, 410, 422 and
  other 4xx: the delivery is dead at once and needs your intervention.
- **Order:** for one subscription and one aggregate (same employee, card, …), a later
  event waits while an earlier one is still being retried. A dead earlier delivery no
  longer blocks; use `sequence` to detect the gap and the change feed to fill it.
- **Timeouts:** 3 s to connect, 10 s total. Slow endpoints are treated as failures.

## Secrets

- 32 random bytes, `whsec_…`, shown **once** when the subscription is created or
  the secret rotated; stored encrypted; never displayed again, never logged.
- **Rotation:** an administrator rotates; the new secret is shown once and the old one
  keeps signing alongside it for 24 h (configurable), so you can deploy the new
  secret without downtime.
- If a secret leaks, rotate immediately; consider disabling the subscription until
  the partner has the new one.

## Callback URL policy (SSRF)

Checked when a subscription is saved and again before every delivery:

- HTTPS only (plain HTTP only where `INTEGRATION_WEBHOOK_ALLOW_HTTP` is set for local
  development); no credentials in the URL; ports 443/80 or ≥ 1024.
- Every resolved address must be public. Refused: loopback (127/8, ::1), private
  (10/8, 172.16/12, 192.168/16, fc00::/7), link-local and cloud metadata
  (169.254/16 incl. 169.254.169.254, fe80::/10), carrier-grade NAT (100.64/10),
  multicast, reserved, documentation ranges, IPv4-mapped IPv6 forms of all of these,
  `localhost`, `*.local`, `*.internal`.
- Internal destinations only through the explicit allowlist
  `INTEGRATION_WEBHOOK_INTERNAL_HOSTS`.
- **DNS rebinding:** the connection is pinned to the vetted IP (`CURLOPT_RESOLVE`), so
  an answer that changes between check and connect cannot redirect the request.
- **Redirects** are never followed. **TLS** certificates are always verified.

Remaining infrastructure controls (outside the application): an egress firewall that
also blocks private ranges from the worker hosts, and a dedicated `integrations`
queue worker pool.

## What is logged

Per attempt: outcome, HTTP status, a safe error category, latency, timestamps. Never
request or response bodies, never the secret, never tokens. Payloads can be inspected
only by holders of `integrations.payload.view`, and each view is audited.
