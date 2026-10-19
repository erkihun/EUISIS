# Addis One Assistant — system diagnostics through the assistant

## Who

`intelligence.system-health.view` (+ `intelligence.view`) and the
diagnostics switch → `system_health`; `intelligence.incidents.view` adds
references, links and runbook links (`intelligence.runbooks.view`). Everyone
else gets, on a support question only, a generic notice when a verified
incident touches the page's area.

## What "Check system status" returns

- `status` block: overall state (unhealthy > degraded > healthy; UNKNOWN
  when no component was sampled), each component with its state label,
  the latest sample time, and the UNKNOWN reason when applicable.
- Active incident cards (top 3 by severity): title, severity, status,
  impact, component, last seen; reference and "View incident" only with the
  incidents grant.
- An `observed` label line with the incident count — the figure is a fact
  from `intelligence_incidents`, not a model statement.

Everything comes from `ComponentHealth::states()` and the incidents table;
if telemetry is off or stale the answer says UNKNOWN. Nothing is invented.

## Page correlation (support questions)

`incident_lookup` searches active incidents whose `endpoint` or `module`
equals the page's route group (`RouteGroups::of`) or whose component is
`application`/`database`; `other` never matches. A route name alone is not
evidence.

## Example

> Why is PDF export not working?

With a verified incident: *A verified incident is active on the part of the
platform this page belongs to.* → incident card (Repeated error: …,
high, open, impact) → *Recommended runbook: Deployment regression* →
troubleshooting steps → ticket offer with the incident reference attached
(priority becomes high when the incident is critical/high).

Without one: *No verified system incident was found from available
telemetry for this area.* → steps → ticket offer.

## Narrative

When AI is on and `structured_signals` may go outbound, the composer asks
the model for a short narrative over the compact data (labels, states,
counts). Figures in the blocks are always the deterministic ones; the
narrative is marked AI and carries the disclaimer. The model never executes
or recommends commands; runbooks are links to read-only guidance.
