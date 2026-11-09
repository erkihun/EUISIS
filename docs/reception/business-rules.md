# Reception business rules

- One visit belongs to one active desk and one institution.
- One visit can have at most one queue ticket.
- Queue numbers restart per desk and local calendar date; allocation occurs inside a transaction with a locked sequence row.
- Allowed queue transitions are waiting → called/cancelled; called → recalled/in-service/no-show/waiting transfer; in-service → completed/waiting transfer.
- Priority is explicit `normal` or `approved_priority`; it is never inferred from personal data.
- Repeat visitors are matched only by normalized phone digest. Anonymous inquiries store no phone and require no national identifier.
- Appointments must be future times on a configured working day that is not a public holiday. A desk has one booking per timestamp.
- Referrals append history and never overwrite earlier referrals. Cross-organization referral requires scope at both organizations and grants no new record access.
