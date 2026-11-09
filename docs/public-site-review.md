# Public site — end-to-end review (2026-10-11)

Amharic: ይፋዊ ድረ-ገጽ — ከጫፍ እስከ ጫፍ ግምገማ

Every public page was walked in the browser (Amharic and English, desktop
1280 px and phone 390 px, light and dark theme) against the local server
with built assets, and every public transaction was run to completion on
the development database with synthetic data. Automated coverage for each
fix lives in the tests named below and runs on every change.

## Pages walked

| Page | Route | Transaction run | Result |
|---|---|---|---|
| Home | `/` | — (signed in and signed out) | OK |
| Announcements | `/announcements`, `/announcements/{slug}` | search, category, detail | OK |
| Transfer announcements | `/announcements/transfers`, `/announcements/transfer/{id}` | list → detail | **crashed with real data** — fixed |
| Transfer application | `/announcements/transfer/{id}/apply` | sign in → attach documents → submit → `TRA-2026-000001` | **unusable with required documents** — fixed |
| Services | `/services`, `/services/{slug}` | search, detail | polish |
| Support | `/support` | FAQ open/close | polish |
| Verify | `/verify` | typed UUID → ID checker | OK |
| ID checker | `/id-checker/{uuid}` | send code → wrong code → right code → details | localization fixes |
| Service feedback | `/service-feedback/{token}` + `/feedback`, `/suggestion`, `/complaint` | rating submitted; complaint with 2 attachments → `PSC-2026-000001` | consistency + localization fixes |
| Maintenance | any content page with `public_site.enabled = 0` | 503 notice, verify still reachable | OK |

No page scrolled horizontally at 390 px.

## Defects found and fixed

1. **Public transfer page crashed** (React error #31) for any announcement
   whose eligibility rules were saved by the admin form — rules are
   `{type, operator, value}` objects and the page rendered them as text
   children. Now `Components/public/transferPresentation.ts` turns a rule
   into a localized sentence ("የቅጥር ሁኔታ ነው active"); plain-string rules
   from older rows still render. The employee portal page reuses it.
   Test: `tests/js/public-transfers.test.tsx`.
2. **Transfer application could not succeed when documents were required.**
   The server matches uploads to required documents by ordinal, but the
   form offered one unordered multi-file pile labelled "optional" and only
   listed the announcement's own documents, not the ones from the transfer
   settings. The apply page now receives the merged list in the server's
   order and shows one labelled slot per document (the kit's `FileUpload`),
   blocks submission until every slot is filled, and sends `documents[i]`
   so the ordinal survives an empty slot. Free uploads remain when nothing
   is required. Tests: `PublicTransferApplicationTest` (merged list;
   localized refusals).
3. **Refusals were English-only** in the Amharic interface: "Required
   document missing", "does not meet the eligibility criteria",
   the feedback form's "Please choose a rating…" and "This service is not
   provided by this officer." All now come from `lang/{en,am}/transfers.php`
   and the new `lang/{en,am}/service-feedback.php`. Tests:
   `PublicTransferApplicationTest`, `ServiceFeedbackPublicTest`.
4. **Empty red bar on the transfer detail page.** The shared `flash` prop is
   always an object, so `flash && …` rendered an empty error box on every
   visit. `Components/public/PublicFlash.tsx` renders only with a message
   and announces it (`alert` / `status`). Test: `public-transfers.test.tsx`.
5. **Salary shown raw** ("9000.00 – 12500.00") → "9,000 – 12,500".
6. **ID checker named the organization, unit and position in English** in
   the Amharic interface and printed ISO dates. The service now returns the
   Amharic name when one is recorded (English fallback) and the page renders
   dates through `LocalizedDateDisplay` (Ethiopian calendar in Amharic).
   Test: `PublicIdCheckerTest`.
7. **Complaint form used the browser's native date and file controls**
   ("mm/dd/yyyy", "Choose Files / No file chosen"), unlike every other form.
   It now uses `LocalizedDatePicker` and the new `MultiFileUpload`
   (button + file list with remove), the same control the free-upload
   transfer form uses. Validation errors carry `role="alert"`; the rating
   form gained the "back to options" link the other two forms had; the
   service-point context no longer repeats the role twice.
8. **Consistency polish:** the feedback pages carry the same compact title
   band as the ID checker; "View details" sits on one baseline across
   service cards; a service with no written sections says so instead of
   showing an empty page; justified text (uneven gaps in Ethiopic) removed
   from the home and support cards; the position name in the apply page's
   title band was body-gray on navy and is now white.

## Verified after the fixes

Transfer detail renders with structured rules; application submitted with
two typed documents (`TRA-2026-000001`, documents stored under their
required types); complaint submitted with Ethiopian date picker and the
shared file control; ID checker shows Amharic position and Ethiopian dates
after a valid code. `tsc`, `vite build`, the i18n key parity check, Pint
and the public-site, transfers, employee-portal, ID-checker, feedback and
UI-consistency test groups pass.

Run of record (SQLite, 2026-10-12, `tests/Feature` in two sequential
halves, `tests/Unit` whole): 3,081 Feature tests passed, 155 Unit tests
passed. The only failures are the documented baseline unrelated to the
public site — `DemoDataSeederTest` 01 and 10 (clock-dependent),
`IdCardFrontFieldsTest` ×2 and `CourtCasesPlaceholderTest` (uncommitted
work in the working copy). Four further `DemoDataSeederTest` failures
("The file could not be saved" at the ID-card print snapshot) appeared only
while the two halves ran concurrently and share the fake storage
directory; the file passes on its own apart from 01 and 10.

## Left as found

- The Vite development origin `http://[::1]:5173` in the local
  `Content-Security-Policy` is rejected by browsers as an invalid source
  (four console errors on every local page). It is asserted by
  `SecurityHeadersTest` and is development-only, so it was not changed.
- Eligibility rule *values* (e.g. `active`, a grade code) are shown as the
  administrator entered them; only the rule type and operator are translated.
- The kit's `FileUpload` is the browser's native file input styled by the
  kit; its "Choose file" wording is the browser's.
- `demo.*@example.test` portal accounts are not linked to employee records
  (`users.employee_id` is null), so the transfer application cannot be
  demonstrated with them without linking one first.
