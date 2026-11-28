# ADR 0040: Work experience and its certificate

- Status: accepted; built (Modules/WorkExperience/README.md)
- Date: 2026-11-27
- Owner request: "How should an employee who wants their work experience in
  writing be handled?" The owner then gave the design himself and ordered the
  build, finished and production ready, without waiting for confirmation.
- Pilot scope freeze (2026-11-04): overridden for this module by that order,
  as it was for ADR 0037 and ADR 0039.

## The owner's design

1. Inside the employee-management menu there is a "Work experience" page.
   The office that manages employees records each employee's experience
   there.
2. The employee submits a request. The request does not have to be a
   letter.
3. The request is looked at; once it is accepted the system tells the
   employee.
4. The certificate is printed, the necessary seal and signature are put on
   it, and it is sent to the employee.

Decisions the owner took on 2026-11-27 when asked:

| Question | Decision |
|---|---|
| How does the certificate reach the employee? | It is printed and sent as paper. The system tells the employee that it was sent. Nothing is scanned or downloaded. |
| Who signs? | Managed in settings. |
| Is the salary written? | Yes. |
| An employee who has left has no account. | The office (HR) may enter the request for them. |

## What was built

**The record.** An employee's experience is read from two sources together
(`WorkExperienceTimeline`):

- the assignments the system already holds (institution, position, unit,
  dates) come by themselves, with no typing;
- the office's own records (`work_experience_records`) add what the system
  cannot know: the salary of an assignment and a corrected title or date
  (a record linked to the assignment), service before the system existed,
  and service in another institution, with the evidence it was taken from.

The system holds no salary for employees, so the salary on a certificate is
what the office recorded on each line. A line with no salary prints a dash.

**The request** (`work_experience_requests`). A short form: who the
certificate is addressed to (empty means "To whom it may concern"), the
purpose, and the language (Amharic or English). States:

```
submitted ──accept──▶ accepted ──print, sign, stamp, send──▶ sent
    │  └──send back (reason required)──▶ returned
    └──withdrawn by the employee──▶ cancelled
```

- The employee asks signed in with their own account. The one exception is
  an employee who has left (status transferred, retired, terminated or
  deceased): the office enters the request, and it is marked as entered by
  the office.
- The office is notified of a new request; the employee is notified when it
  is accepted, when it is sent back, and when the certificate is sent.
- Nobody records their own experience or decides their own request.

**The certificate.** Accepting a request numbers the certificate
(`WE/0001/2019`: a sequence per institution and Ethiopian year, protected by
a unique index), gives it a ten-character verification code, and freezes
what it says in `work_experience_requests.certificate`. A later change to
the record never changes a paper that was already signed; while the request
is accepted and not yet sent the office can write the certificate again
from the record. The printed page (`Infrastructure/Resources/views/certificate.blade.php`)
is an A4 page in the request's language. An Amharic certificate is dated in
the Ethiopian calendar, an English one in the Gregorian. Each opening of
the print page is counted and audited. "Sent" can be recorded only after
the certificate was printed.

**Settings** (`work_experience_office_settings`, one row per institution):
the signatory's name and title in Amharic and English, whether the salary
is written (default yes, the owner's decision), and whether service in
other institutions is written (default no: an institution certifies the
service given to it). A request cannot be accepted for an institution with
no signatory; the request popup says so.

## Decisions taken while building

1. **Its own module, shown inside the employee-management menu.** The owner
   asked for a page "inside the employee management module". Employee
   management is Core code; new work goes into a module (ADR 0016/0017). So
   the code is `Modules/WorkExperience`, and its three pages are listed in
   the employee-management menu group. The employee's page is in My Portal
   under "My records".
2. **Not through the Letters module, for now.** The certificate carries its
   own number and verification code and prints with the institution's name
   and logo. Taking the outgoing number and letterhead from Letters
   (ADR 0037) needs a published template per institution and is left for
   later; the number format is one constant in `WorkExperienceRequests`.
3. **Verification is by the office.** The code is searched on the requests
   page. A public verification page is not built: it would be a new public
   surface and needs its own decision.
4. **Which office.** An employee's record is kept by the institution of
   their current assignment; for someone who has left and has no current
   assignment, by the institution of their last assignment.
5. **Who gets the permissions.** Employees: `work_experience.request_own`.
   HR Officer: view, record, decide requests, settings. Organizational
   administrators: view and settings.

## Left for later

- Outgoing number and letterhead from the Letters module.
- A public page that checks a verification code.
- A reminder when an accepted certificate is not sent for a long time.
- A scanned copy of the signed certificate, should the owner later want the
  employee to download it.
