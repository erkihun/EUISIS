# Public QR mobile actions

What a visitor can do from the landing screen of a scanned feedback QR, and
where each action's target comes from. Applies to both QR kinds:

| QR | URL | Target |
| --- | --- | --- |
| Employee (back of the ID card) | `/service-feedback/{token}` | the employee, while active |
| Institution | `/feedback/{token}` | the institution, while active |

The landing screen shows the existing identity confirmation (employee photo
and full name, or institution name and logo) and the existing choice cards.
Below the `md` breakpoint (768 px) a fixed bottom action dock is added:

| Action | Label (am / en) | Goes to |
| --- | --- | --- |
| Feedback | አስተያየት / Feedback | `links.feedback` — the existing rating form |
| Suggestion | ጥቆማ / Suggestion | `links.suggestion` — the existing suggestion form |
| Complaint | ቅሬታ / Complaint | `links.complaint` — the existing public complaint form |
| Call | ይደውሉ / Call | `tel:` link to the institution's public contact phone |

## Submissions

The three submission actions use the same server-built URLs as the cards
(`PublicServiceFeedbackController::links()`). They carry only the scanned
token; the target is resolved again on the server for every form view and
submission (`PublicFeedbackTargetResolver`). A type switched off in the public
feedback policy (`enabledTypes`) appears neither as a card nor in the dock.
A public complaint stays separate from employee grievances, discipline,
appeals and EPMS.

## Call

The number is the institution's **public contact phone**
(`organizations.public_contact_phone`, edited on the institution's create and
edit pages under "Public contact", by anyone who may already edit that
institution; changes are recorded in the existing `organization_updated`
audit event).

- **Institution QR**: the scanned institution's number.
- **Employee QR**: the number of the institution of the employee's current
  assignment (`employee_assignments.is_current`), loaded in the same query
  that resolves the target. After a transfer the same QR calls the new
  institution; past submissions keep their own context.
- **Never** an employee's own phone, emergency contact or any other personal
  number. There is no fallback: without a valid institution number the Call
  action is absent and the remaining actions share the dock's width. The
  system's technical support number is a different concept and is not used.
- The page receives only `call: {href, display}` or `null`. No institution or
  employee id is sent for this purpose.
- Tapping Call opens the dialler; nothing is recorded and nothing is created
  (no submission, case, grievance or performance event).

### Accepted numbers

`App\Support\Phone\PublicContactPhone` decides what may become a link:

| Entered | Stored / dialled | Shown |
| --- | --- | --- |
| `011 123 4567`, `0111234567`, `(011) 123-4567`, `+251 11 123 4567`, `251111234567` | `+251111234567` | `011 123 4567` |
| `0911 223 344`, `+251-911-22-33-44` | `+251911223344` | `0911 223 344` |
| `8044` (3–5 digit short code) | `8044` | `8044` |
| `+44 20 7946 0958` | `+442079460958` | `+442079460958` |

Only digits, spaces, dots, hyphens, brackets and one leading `+` are allowed.
Letters, `:`, `;`, `,`, `#`, extensions and schemes such as `javascript:` are
refused by the form (`App\Rules\PublicContactPhoneNumber`). A nine-digit
number without its leading 0 is refused rather than guessed at. The model
stores valid numbers in dialable form; a value written past validation (an
old import, a direct database edit) is kept as is, never becomes a link, and
is reported by `php artisan feedback:data-quality`:

- `active_organizations_without_public_phone` — INFO (a policy choice);
- `organizations_with_invalid_public_phone` — NEEDS_DATA_REVIEW.

The bulk organization-structure import has no column for the number; set it
on the institution's edit page.

## Stability and privacy

Setting or changing a public number does not rotate any QR token. The QR
pages keep `X-Robots-Tag: noindex, nofollow, noarchive`, `Referrer-Policy:
no-referrer` and `Cache-Control: no-store, private`. A `tel:` link needs no
CSP change. Calling the institution reveals, by its number, which
institution the employee serves; the page itself still shows only the
employee's photo and full name.
