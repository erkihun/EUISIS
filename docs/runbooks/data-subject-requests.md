# Data subject requests and breach notification

Operator runbook for the two duties under Proclamation No. 1321/2024 that are
process rather than code (docs/architecture/adr/0027-personal-data-protection-compliance.md).
The data steward of the domain (docs/governance/roles-register.md) owns each
request; the Super Admin role is not needed.

**Draft.** The periods here (30 days, 72 hours, one working day) are proposed
working targets, not figures checked against the proclamation. Legal review is
needed before this runbook is used.

## 1. A person asks about their data

Requests arrive through My Portal › My requests (employees), the public
feedback channel (members of the public) or a letter. Whatever the channel:

1. **Record it the same day** as a support ticket titled `DSR: <type> – <reference>`,
   with the type (access, rectification, erasure, objection), the date received
   and the identity check performed. The 30-day clock starts on receipt.
2. **Verify identity** before disclosing anything: an employee through their
   signed-in portal session or ID card; a member of the public through the
   reference number and the contact they gave when submitting.
3. **Act by type:**

   | Type | What to do | Where |
   |---|---|---|
   | Access | Export the person's record: employee profile, assignments, ID card status, grievances, discipline, court cases, feedback they submitted. | Employees › record › Export; Public feedback › submission › Export. |
   | Rectification | Employees correct their own contact data in My Portal; identity fields go through an employee correction request, approved by HR. | Identity › Employee corrections. |
   | Erasure | Grant for data the law does not require: public feedback contact details and evidence after the case closes, portal preferences, uploaded documents no longer needed for a case. Refuse, with the ground below, for employment history, disciplinary, grievance and court records. Deletion goes through the recycle bin so it is audited. | Record › Delete; Administration › Recycle bin. |
   | Objection | Stop the optional processing (notifications, feedback QR on the profile). Mandatory processing (employment record) continues; say why. | System Settings; Employee › feedback QR. |

4. **Answer in writing within 30 days**, in the language the request came in.
   A refusal names the legal ground: the civil service proclamations,
   Regulation 201/2018 and the retention matrix (docs/governance/retention-matrix.md)
   require the administration to keep employment, disciplinary, grievance and
   court records for the periods listed there.
5. **Close the ticket** with the answer attached. The ticket is the evidence
   that the duty was met.

## 2. A suspected data breach

A breach is any unauthorised access to, loss of, or disclosure of personal data:
a lost export file, a wrong recipient on a letter, a compromised account, a
server intrusion.

1. **Contain first** (docs/runbooks/disaster-recovery.md and
   docs/governance/security-governance.md): revoke the account or token,
   rotate the secret, take the node out of rotation.
2. **Register it within one working day** as an Intelligence incident titled
   `Data breach: <one line>`, whether or not it is later confirmed. Record what
   data, how many people, how it happened, and who knows.
3. **Assess within 72 hours** with the data steward and the security officer:
   is harm to the persons likely? Record the assessment in the incident notes.
4. **Notify:**
   - the data protection authority, through the channel the implementing
     directive names (ADR 0027 lists this as pending management approval);
   - the affected persons, when harm is likely, in plain language, with what
     they can do (change a password, watch for misuse of their ID number);
   - the institution heads whose employees are affected.
   Every notification and its timestamp goes into the incident notes.
5. **Close** with the root cause and the change that prevents a repeat. The
   incident stays in the list; it is the breach register.

## 3. Checks that keep this honest

- Monthly: no `DSR:` ticket older than 30 days without an answer.
- Quarterly: the incident list filtered by `Data breach:` is reviewed with the
  System Owner, including the ones that turned out to be false alarms.
- `php artisan production:readiness` fails when the audit retention is below
  the matrix's floor, which is what makes the "what happened" questions
  answerable at all.
