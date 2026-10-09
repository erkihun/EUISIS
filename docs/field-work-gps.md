# Field Work GPS Capture

GPS capture is an explicit employee action available only during an approved Field Work request. The browser asks for a current high-accuracy location (`enableHighAccuracy: true`, no cached reading); EUISIS records separate immutable `FIELD_CHECK_IN` and `FIELD_CHECK_OUT` events. It never starts background or continuous tracking.

The server derives the employee and participant from the authenticated account, validates coordinate ranges and measurements, requires check-in before check-out, prevents duplicates with both participant/event and idempotency keys, and uses server receipt time as the authoritative time. Browser-supplied coordinates and frontend distance calculations are never authoritative.

When destination coordinates and a radius are configured, the server uses the Haversine formula to persist distance and `within_expected_area`, `outside_expected_area`, or `cannot_validate`. The System Settings GPS policy determines whether a low-accuracy or outside-geofence observation is recorded only, marked for supervisor review, or recorded and blocked from satisfying the action. The effective policy snapshot is retained on the immutable event. GPS can be mocked by a device/browser; this implementation does not claim anti-spoof certainty.

No map provider or PostGIS extension exists in the project, so neither is added. No offline sensitive-GPS queue exists; connectivity is required to record an event. All policy values start as `not_configured` or disabled until an authorized administrator sets an approved policy.
