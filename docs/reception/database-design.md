# Reception database design

Tables: `reception_desks`, `reception_counters`, `reception_desk_staff`, `reception_desk_service`, `reception_visitors`, `reception_visits`, `reception_queue_sequences`, `reception_queue_tickets`, `reception_appointments`, and `reception_referrals`.

All owned entities use UUIDv7 keys. Foreign keys point to authoritative organizations, units, users, employees and services. Composite unique constraints protect desk counter codes, desk/service and desk/staff assignments, daily queue numbers, sequence rows and appointment slots. Operational indexes cover institution/date/status, desk/date/status, counter/status and referral destination/status.

Rollback drops only Reception-owned tables in reverse dependency order. It does not alter Core records or delete the reusable code-rule history.
