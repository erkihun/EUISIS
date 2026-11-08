# ADR 0036: Service feedback, Tip-offs and complaints share nothing

- Status: accepted
- Date: 2026-11-08
- Owner rule (repeated): the three are separate modules; nothing is shared —
  "check deeply end to end, make each page guarded and separate".

## Decision

1. **Addresses and route names per module.** Service feedback under
   `/feedback-admin` (`service-feedback.*`), Tip-offs under `/tip-off-admin`
   (`tip-offs.*`), complaints under `/complaint-admin` (`public-complaints.*`).
   Tip-offs and complaints each have `dashboard`, `cases.index`, `unrouted`,
   `cases.show` and the case actions (`transition`, `assign`, `escalate`,
   `subjects.*`, `referrals.*`, `attachments.download`), plus their own
   `reports.*`, `units.*`, `routing-rules.*` and `settings.*`. The shared
   controllers take the module from a route default, never from the address.
2. **No cross-type pages.** The all-types list (`/service-submissions`), the
   all-types report (`/service-submissions/reports`) and the shared
   `/service-submissions/{type}/{id}` routes are removed; the feedback
   dashboard no longer shows figures across the three. The old GET addresses
   (`/service-complaints`, `/service-suggestions`, their dashboards,
   `/service-submissions/unrouted`, `/service-submissions/{type}/{id}` and its
   attachments) redirect to the module's own page, for bookmarks and earlier
   notifications.
3. **Own grants.** Tip-offs were read and reviewed under the service feedback
   grants. They now have `service_feedback.tip_offs.view` and
   `service_feedback.tip_offs.review` (complaints already had
   `service_feedback.complaints.*`). Migration 2026_11_08_210000 grants them
   to every role and user that held `service_feedback.view` / `.review`, so
   nobody lost access on the day; administrators can now separate them. The
   default role catalog grants them alongside the feedback grants.

## Consequences

- The public intake (QR form `/service-feedback/{token}/{type}`, `/track`)
  is the public side and is unchanged.
- Shared configuration still serving all three (assign, reassign, resolve,
  close, routing management, settings management) is action-level and
  scoped by each module's own page.
