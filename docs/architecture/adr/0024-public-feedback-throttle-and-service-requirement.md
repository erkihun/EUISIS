# ADR 0024: Public feedback throttle counts stored submissions; a post without services is still ratable

- Status: accepted (2026-11-02)
- Context: an end-to-end check of the public Feedback / Tip-off / Complaint flow before go-live,
  with a busy day in mind — many submissions from citizens and from staff at every level of an
  institution, often behind one shared address. Two things would have interrupted genuine
  submissions: the submit throttle counted every POST, so a citizen refused twice for a missing
  name or an unreadable phone number was locked out of that QR code for ten minutes before anything
  was stored; and the employee Feedback form required the service received even when the
  officer's post had no services configured, which made six of the nine live employee QR codes on
  the development data impossible to rate.

## Decision

1. **The configured ceilings are spent by stored submissions only.** `ThrottlePublicSubmissions`
   replaces the framework `throttle` middleware on the five public submit routes. It reads the
   same `service-feedback-submit` limiter (per QR code and address, per address; thresholds from
   the public feedback policy) and hits those keys only when the request ends with the
   `feedback_submitted` flash the controller sets after the commit. A validation refusal, a closed
   link or a revoked token costs the citizen nothing.
2. **A flood is still stopped.** Every POST, stored or refused, counts against a second key per
   ceiling with four times the configured limit (`ATTEMPT_MULTIPLIER`), so a script cannot probe a
   form indefinitely. Both refusals answer 429 with `Retry-After`; the page already turns that into
   a readable popup.
3. **Defaults rise to 10 per QR code per address and 30 per address in ten minutes** (were 3 and
   8). Whole service halls and the districts behind a mobile carrier's shared address sit on one
   client address; the per-address default must hold a busy day of genuine submissions. Operators
   can still lower both in Public Feedback › Settings; nothing stored changes. Without
   `APP_TRUSTED_PROXIES` behind a balancer every visitor is one address (ADR 0008 / go-live
   checklist): that remains an operator setting, not something the throttle can guess.
4. **An officer whose post has no services is still ratable.** `position_service_id` is required
   only when the resolved post has services to choose from (checked in the controller, which knows
   the token); the form hides the service field entirely when the list is empty instead of
   showing a warning the visitor cannot act on. A named service is still checked against the post.
5. **A file PHP dropped for size says "too large".** `PublicEvidenceFile` distinguishes
   `UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE` from an unreadable file, instead of reporting a
   wrong type.
6. **One-time form tokens survive a cache clear.** `PublicFeedbackFormToken` no longer stores a
   token when it is issued; a token is 64 random bits, its issue minute and a truncated HMAC under
   the application key (still 32 hex characters), valid for two hours. Only the CONSUMED marker is
   cached, written with an atomic add, so once-only still holds across nodes, while a deploy-time
   `cache:clear`, a cache restart or an eviction no longer turns every open form into "already
   submitted". The forms also re-read the fresh token the server sends back with a refusal
   (`useFreshFormToken`, and the same effect on the organization form), so a visitor whose Send
   was refused as a replay can press Send again without reopening the page and losing what they
   typed.

## Consequences

- Tests that assumed the old defaults set the thresholds explicitly (`PublicFeedbackAbuseTest`)
  or loop to the constant (`ServiceFeedbackPublicTest`); new tests cover refusals not spending
  the ceiling, the attempt flood ceiling, and a rating without a configured service.
- `docs/public-feedback.md` and `docs/public-feedback-load-testing.md` state the new defaults.
- The k6 script's `pf_throttled` counter keeps its meaning; a run that pushes one address past 30
  stored submissions in ten minutes sees 429s as before.
