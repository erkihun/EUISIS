# Amharic terminology guide

This guide is for developers, translators and Amharic language reviewers. It
sets one Amharic term for each concept in Addis One and explains how to write
Amharic interface text.

The machine-readable source is `resources/data/amharic-terminology.json`. The
translation audit (`scripts/i18n-audit.mjs`) reads its deprecated-term list and
fails the build when a blocking term appears. The tables in section 6 are
generated from that file. When the two disagree, the JSON wins and this guide
must be regenerated.

The findings and the change record of the 2026-10-12 language audit are in
`docs/amharic-language-audit.md`.

## 1. Where a term comes from

Choose the first source that has the term, and record it in the glossary.

1. **Law and regulation.** For grievance, discipline and appeal this is
   Regulation 201/2018 (`docs/` holds the PDF). Its wording is used verbatim
   and is marked *Legal* in the tables.
2. **Official government usage.** Proclamations, civil service directives and
   official forms of the Addis Ababa City Administration.
3. **Terms already approved for this project.** Marked *Approved*.
4. **Established usage in Ethiopian public institutions.**
5. **Natural professional Amharic.** Never a word-for-word translation of the
   English.
6. **No reliable source.** Use the best natural term, mark it
   `NEEDS_LANGUAGE_REVIEW`, and list it in the audit document. If an official
   source must exist but has not been found, mark it
   `OFFICIAL_TERMINOLOGY_SOURCE_REQUIRED`.

Nobody marks a term as reviewed on a language reviewer's behalf. A term leaves
review status only when a named reviewer signs it off (section 7).

## 2. Rules for every string

- **One term per concept.** Use the glossary term everywhere the concept
  appears: labels, help text, errors, exports, PDFs, email and SMS.
- **Distinct concepts stay distinct.** The pairs in section 3 must never share
  a word.
- **Translation keys, not hard-coded text.** React pages call `t('module.key')`
  and PHP calls `__('file.key')`. Amharic is never typed into a component,
  controller or Blade view. Keys are named by meaning, such as
  `transfers.statusWithdrawn`, not by the English words.
- **Both languages, always.** Every key exists in English and Amharic. The
  audit blocks a missing key, an empty value, English left in an Amharic
  value, Amharic in an English value, and mismatched placeholders.
- **No raw codes.** A status or type is shown through its label, never as
  `PENDING_REVIEW` or `release_pending`. A code may follow its Amharic meaning
  in brackets when stewards need to search for it, for example
  `ውሳኔ ያስፈልጋል (NEEDS_DECISION)`. Enums that label themselves read their
  labels from `lang/{en,am}/enums.php` or a module file.
- **Acronyms stay in Latin script** when they are clearer than an invented
  translation: QR, OTP, MFA, SMS, PDF, Excel, CSV, API, GPS, KPI, FTE, ISCO,
  ISIC, CPC, ID. The full list is `ALLOWED_LATIN` in the audit script.
- **User content is not translated.** Names, organization names, notes and
  anything a person typed stay as entered. Seeded reference data is changed
  only by a reviewed migration or seeder, never by overwriting production
  rows.
- **Official text is quoted exactly.** Legal quotations, signed letters,
  issued decisions and the official system name keep their wording. The
  official Amharic name keeps its approved spelling ሰራተኛ, even though the
  interface spelling is ሠራተኛ.
- **Historical records are not rewritten.** A PDF, letter or audit entry
  produced before a wording change keeps the wording it was produced with.

## 3. Concepts that must stay apart

| Concept | Amharic | Never confuse with |
| --- | --- | --- |
| Public complaint (QR) | ቅሬታ, in navigation የተገልጋይ ቅሬታ | Employee grievance, የሠራተኛ ቅሬታ |
| Employee grievance | የሠራተኛ ቅሬታ | Discipline case, appeal |
| Discipline charge | ክስ, የክስ መጥሪያ | A public complaint is never a ክስ |
| Appeal | ይግባኝ, to የአስተዳደር ፍርድ ቤት | A grievance review |
| Organization / unit | ተቋም / የሥራ ክፍል | ድርጅት is a private company |
| Position / employee / assignment | የሥራ መደብ / ሠራተኛ / ምደባ | A position exists without an employee |
| Occupation / profession / competency | የሙያ ምድብ / ሙያ / ብቃት | Qualification is የትምህርት ዝግጅት |
| Process / activity / task | ሂደት / እንቅስቃሴ / ተግባር | The Actions column is እርምጃዎች |
| FTE / required workforce / approved headcount | FTE / የሚያስፈልግ የሰው ኃይል / የጸደቀ የሠራተኛ ብዛት | FTE is a calculated number, not people |
| Transfer approved / implemented | ጸድቋል / ዝውውሩ ተግባራዊ ተደርጓል, ተዛውሯል | Approval does not move the employee |
| Permission / leave | የመዳረሻ መብት / ፈቃድ | ፈቃድ is never a system permission |
| Entitlement / right | ጥቅማ ጥቅም / መብት | |
| Cancel / delete | ይቅር / ይሰርዙ | Cancel closes a form and deletes nothing |
| Archive / delete | ወደ ማህደር ያስቀምጡ / ይሰርዙ | Archived records can be restored |
| Publish / print | ይፋ ያድርጉ / ያትሙ | |
| Issue a card / issue a letter | መስጠት / ማውጣት | ስጦታ is a gift |
| Calibration / moderation | የውጤት ማመጣጠን / ቁጥጥር | |
| Grade level / salary step | የሥራ ደረጃ / እርከን | |

## 4. Grammar and style

- **Buttons and actions** use the polite imperative: ያስቀምጡ, ያጽድቁ,
  ውድቅ ያድርጉ, ይሰርዙ. Never the familiar form (አስቀምጥ, አጽድቅ).
- **Options inside a setting** are noun phrases, not commands:
  በራስ-ሰር ዳግም ማተም, ዳግም ስሌት አይደረግም.
- **Statuses** use the perfect form: ጸድቋል, ውድቅ ተደርጓል, በመጠባበቅ ላይ.
  Filters and adjectives use the relative form: የጸደቀ, ውድቅ የተደረገ.
- **Success messages** state what happened: መዝገቡ በተሳካ ሁኔታ ተቀምጧል።
- **Errors** say what went wrong and what to do next, without blaming the user.
- **Punctuation:** ። ends a sentence, ፣ separates list items, ፤ joins
  clauses, ፦ introduces a value or list. Latin `:` stays only inside
  placeholders such as `:count`.
- **Numbers** use Arabic digits. Ethiopian calendar years carry ዓ.ም. Money is
  shown with ብር.
- **Placeholders** (`:name` in PHP, `{brand}` in React) are copied exactly and
  may move within the sentence to fit Amharic word order.
- **Public pages** (QR feedback, sign-in, home) use plain words a citizen
  knows. Administrative pages may use the formal terms in section 6.
- **Short forms** such as ክፍል for የሥራ ክፍል are allowed only in table
  column headers and chips where the screen already names the module.

## 5. Fonts and layout

Amharic strings are often longer than English. After changing wording, check
the screen at desktop width and at 390 × 844 for clipped buttons, wrapped
table headers and overflowing badges. ID card captions are printed at a fixed
size; keep them short (for example ማብቂያ, not የሚያበቃበት ቀን).

## 6. Glossary

### Organization and structure

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Organization | ተቋም | ድርጅት | Approved | ድርጅት only for a private company. |
| Organization unit | የሥራ ክፍል (short: ክፍል) | የተቋም ክፍል, ወላጅ ክፍል | Legal (Reg. 201) | Regulation 201 uses የሥራ ክፍል. The short form ክፍል is for table columns only. |
| Parent (unit, organization, node) | የበላይ | ወላጅ | Approved | Child is የበታች; the top unit is ዋና የሥራ ክፍል. |
| Unit (unit type) | ክፍል |  | Approved |  |
| Institution office | የተቋም ጽሕፈት ቤት |  | Approved | ቢሮ is reserved for a Bureau. |
| Office | ጽሕፈት ቤት |  | Approved | Public pages may say መሥሪያ ቤት for a government office in general. |
| Reporting line | የተጠሪነት መስመር | የሪፖርት መስመር | Approved |  |
| Organization type | የተቋም ዓይነት |  | Approved |  |
| Hierarchy version | የተዋረድ ስሪት |  | Approved |  |
| Organization structure | አደረጃጀት |  | Approved |  |

### Mandate, service, process, activity and task

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Mandate | ሥልጣን |  | Approved | Proclamations pair it as ሥልጣንና ተግባር (powers and duties). |
| Function (of a mandate) | ተግባር |  | NEEDS_LANGUAGE_REVIEW | Shares the word ተግባር with Task; the modules never show both on one screen. Reviewer to confirm. |
| Powers and functions | ሥልጣንና ተግባር | ኃይሎችና ተግባራት | Approved |  |
| Service | አገልግሎት |  | Approved |  |
| Service catalog | የአገልግሎት ካታሎግ |  | Approved |  |
| Service type | የአገልግሎት ዓይነት |  | Approved |  |
| Classification (ISIC, CPC, ISCO) | ምደባ |  | NEEDS_LANGUAGE_REVIEW | Shares ምደባ with an employee assignment; screens keep the two apart. Reviewer to confirm or choose ፈርጅ / ምድብ. |
| ISIC section / division / group / class | ዘርፍ / ንዑስ ዘርፍ / ቡድን / ንዑስ ቡድን |  | OFFICIAL_TERMINOLOGY_SOURCE_REQUIRED | Interim names; the official Amharic ISIC level names are to be confirmed. |
| Mapping (of legacy records) | ማዛመድ | ካርታ | Approved | ካርታ is a geographic map. |
| Process | ሂደት |  | NEEDS_LANGUAGE_REVIEW | Process, Activity and Task stay distinct: ሂደት / እንቅስቃሴ / ተግባር. |
| Activity (of a process) | እንቅስቃሴ |  | NEEDS_LANGUAGE_REVIEW |  |
| Task | ተግባር |  | NEEDS_LANGUAGE_REVIEW |  |

### Workforce planning

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Workload | የሥራ ጫና |  | Approved |  |
| FTE (full-time equivalent) | FTE |  | Approved | Kept as an acronym; FTE is a calculated number, not a count of people. |
| Required workforce | የሚያስፈልግ የሰው ኃይል |  | Approved | Derived from FTE; distinct from the approved headcount. |
| Approved headcount | የጸደቀ የሠራተኛ ብዛት |  | Approved |  |
| Workforce plan | የሰው ኃይል ዕቅድ |  | Approved |  |
| Position establishment | የጸደቀ የሥራ መደብ |  | NEEDS_LANGUAGE_REVIEW |  |

### Human resources: employee, position, occupation, competency

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Employee | ሠራተኛ |  | Legal (Reg. 201) |  |
| Position | የሥራ መደብ |  | Approved | A position exists with or without an employee in it. |
| Vacant position | ክፍት የሥራ መደብ |  | Approved |  |
| Assignment | ምደባ |  | Approved | An employee placed in a position. Acting assignment: ተጠባባቂ ምደባ. |
| Occupation (ISCO) | የሙያ ምድብ |  | OFFICIAL_TERMINOLOGY_SOURCE_REQUIRED | Distinct from Profession. ISCO groups: ዋና ቡድን, ንዑስ ዋና ቡድን, አነስተኛ ቡድን, መሠረታዊ ቡድን. |
| Profession | ሙያ |  | Approved |  |
| Specialization | ልዩ ሙያ |  | Approved |  |
| Competency | ብቃት |  | Approved | Distinct from Qualification. |
| Qualification | የትምህርት ዝግጅት |  | Approved |  |
| Education level | የትምህርት ደረጃ |  | Approved |  |
| Grade level, job grade | የሥራ ደረጃ | የደረጃ ደረጃ, የደረጃ መለኪያ | Approved | A salary step is እርከን, a different concept. |
| Salary | ደመወዝ |  | Legal (Reg. 201) |  |
| Employment type | የቅጥር ዓይነት |  | Approved |  |
| Probation | የሙከራ ጊዜ |  | Approved |  |
| Immediate supervisor | የቅርብ ኃላፊ |  | Legal (Reg. 201) |  |
| Higher official | የበላይ ኃላፊ |  | Legal (Reg. 201) |  |
| Leave | ፈቃድ |  | Approved | ፈቃድ is never used for a system permission. |

### Job description

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Job description | የሥራ መግለጫ |  | Approved |  |

### Transfer and vacancy

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Transfer | ዝውውር |  | Approved |  |
| Approved (transfer) | ጸድቋል |  | Approved | Approved is not implemented. |
| Implement transfer / Transferred | ዝውውሩን ተግባራዊ ማድረግ / ተዛውሯል |  | Approved |  |
| Release approval | የሚለቅቀው ተቋም ይሁንታ |  | Approved |  |
| Receiving approval | የሚቀበለው ተቋም ይሁንታ |  | Approved |  |
| Final approval | የመጨረሻ ማጽደቅ |  | Approved |  |
| Vacancy announcement | የክፍት የሥራ መደብ ማስታወቂያ |  | Approved |  |
| Shortlist | አጭር ዝርዝር |  | Approved |  |
| Withdrawn (application) | ተነስቷል | ተቀልፏል | Approved |  |

### Daily work

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Daily activity | ዕለታዊ የሥራ እንቅስቃሴ |  | NEEDS_LANGUAGE_REVIEW | Alternative under review: ዕለታዊ የሥራ ክንውን. |

### Field work

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Field work | የመስክ ሥራ |  | Approved |  |
| Destination | መድረሻ |  | Approved |  |

### Performance (EPMS)

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Performance | አፈጻጸም |  | Approved |  |
| Plan | ዕቅድ |  | Approved |  |
| Target | ዒላማ |  | Approved |  |
| Actual | ክንውን |  | Approved |  |
| KPI | KPI |  | Approved | KPI catalog: የKPI ማውጫ. |
| Weight | ክብደት |  | Approved |  |
| Performance agreement | የአፈጻጸም ስምምነት |  | Approved |  |
| Calibration | የውጤት ማመጣጠን | ካሊብሬሽን | Approved |  |
| Cascade (plan) | ዕቅድ ማውረድ |  | Approved |  |

### Assessment

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Assessment | ምዘና |  | Approved |  |
| Assessment cycle | የምዘና ዙር |  | Approved |  |
| Evaluator | ገምጋሚ |  | Approved |  |
| Reviewer (assessment records) | አረጋጋጭ |  | Approved | Second-level check, kept apart from the evaluator. |
| Moderation | ቁጥጥር |  | NEEDS_LANGUAGE_REVIEW |  |
| Peer assessment | የአቻ ምዘና |  | Approved |  |

### Public feedback (QR)

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Feedback (public) | አስተያየት |  | Approved |  |
| Suggestion (public) | ጥቆማ |  | Approved |  |
| Complaint (public) | ቅሬታ |  | Approved | Navigation: የተገልጋይ ቅሬታ. Never the employee grievance term. |
| Service user, customer | ተገልጋይ | ደንበኛ | Approved |  |
| Submission | የቀረበ ጉዳይ |  | Approved |  |
| Submitter (public) | ጉዳዩን ያቀረበው ሰው | አቅራቢ | Approved | አቅራቢ is a cafeteria or service provider. |
| Handler | የሚያስተናግድ አካል |  | NEEDS_LANGUAGE_REVIEW |  |
| Routing / routing rule / unrouted | ማስተላለፍ / የማስተላለፊያ ደንብ / ያልተላለፈ | የመስመር ደንብ, መምሪያ | Approved |  |
| Receipt (reference) number | መለያ ቁጥር |  | Approved |  |

### Employee grievance

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Employee grievance | የሠራተኛ ቅሬታ |  | Legal (Reg. 201) | Regulation 201 Art. 2(5). |
| Grievance review committee | ቅሬታ አጣሪ ኮሚቴ |  | Legal (Reg. 201) |  |
| Case reference number | የመዝገብ ቁጥር |  | Approved |  |
| Decision letter | የውሳኔ ደብዳቤ |  | Approved |  |
| Recommendation (committee) | የውሣኔ ሀሳብ |  | Legal (Reg. 201) |  |
| Recusal | ራስን ማግለል |  | Approved |  |
| Escalation / escalate / escalated | ማሳደጊያ / ከፍ ያድርጉ / ከፍ ብሏል |  | NEEDS_LANGUAGE_REVIEW | The grievance module uses ከፍ ማድረግ throughout. A reviewer may prefer ወደ በላይ ደረጃ ማስተላለፍ; change all of them together. |
| Respondent (grievance) | ቅሬታ የቀረበበት |  | Approved |  |

### Discipline

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Discipline | ዲሲፕሊን |  | Legal (Reg. 201) | Regulation title spelling. |
| Discipline committee | የዲሲፕሊን ኮሚቴ |  | Legal (Reg. 201) |  |
| Charge / statement of charge | ክስ / የክስ መጥሪያ |  | Legal (Reg. 201) | Only in discipline; a public complaint is never a ክስ. |
| Accused employee | ተከሳሽ |  | Legal (Reg. 201) |  |
| Defence (reply) | የመከላከያ መልስ |  | Legal (Reg. 201) |  |
| Evidence | ማስረጃ |  | Legal (Reg. 201) |  |
| Witness | ምስክር |  | Legal (Reg. 201) |  |
| Penalty | ቅጣት |  | Legal (Reg. 201) |  |
| Suspension | ከሥራ ማገድ |  | Legal (Reg. 201) |  |
| Dismissal from employment | ከሥራ ማሰናበት |  | Legal (Reg. 201) | Never used for closing a dialog (ይዝጉ). |
| Minor / serious disciplinary offence | ቀላል / ከባድ የዲሲፕሊን ጥፋት |  | Legal (Reg. 201) |  |
| Quorum / majority vote | ምልዓተ ጉባኤ / ድምፅ ብልጫ |  | Legal (Reg. 201) |  |
| Chair / secretary / member | ሰብሳቢ / ፀሐፊ / አባል |  | Legal (Reg. 201) |  |

### Appeal

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Appeal | ይግባኝ |  | Legal (Reg. 201) |  |
| Appellant | ይግባኝ ባይ |  | Legal (Reg. 201) |  |
| Respondent (appeal) | መልስ ሰጪ |  | Legal (Reg. 201) |  |
| Rejoinder | የመልስ መልስ |  | Legal (Reg. 201) |  |
| Application for leave to appeal | ይግባኝ ማስፈቀጃ ማመልከቻ |  | Legal (Reg. 201) |  |
| Administrative tribunal | የአስተዳደር ፍርድ ቤት |  | OFFICIAL_TERMINOLOGY_SOURCE_REQUIRED | Short form from Regulation 201; the tribunal's full official Amharic name is still to be confirmed. |

### Cafeteria and entitlements

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Cafeteria | ካፌቴሪያ |  | Approved |  |
| Entitlement | ጥቅማ ጥቅም | መብቶች | Approved | መብት is a right or an access permission. |
| Subsidy | ድጎማ |  | Approved |  |
| Allocation (ledger) | ድልድል |  | Approved |  |
| Provider | አቅራቢ |  | Approved |  |
| Transaction | ግብይት |  | Approved |  |
| Settlement | ሂሳብ ማወራረጃ |  | Approved |  |
| Ledger | የሂሳብ መዝገብ | ሌጀር, ደብተር | Approved |  |

### ID card

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| ID card | መታወቂያ ካርድ |  | Approved |  |
| Issue (a card) / issue date | መስጠት / የተሰጠበት ቀን | ስጦታ | Approved | A letter is issued with ማውጣት (ደብዳቤውን ያውጡ). |
| Expiry (card caption) | ማብቂያ |  | Approved |  |
| Print batch | የሕትመት ዙር |  | Approved |  |
| Template | አብነት |  | Approved |  |
| Scan | መቃኘት / ቅኝት | ስካን | NEEDS_LANGUAGE_REVIEW |  |
| Revoke | መሻር |  | Approved |  |

### Security and access

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Role | ሚና |  | Approved |  |
| Permission | የመዳረሻ መብት (short: መብት) |  | NEEDS_LANGUAGE_REVIEW |  |
| Organization scope | የተቋም ወሰን |  | Approved |  |
| Two-factor authentication | ባለሁለት ደረጃ ማረጋገጫ |  | Approved |  |
| Session (sign-in) | ክፍለ ጊዜ |  | Approved | A committee session is ስብሰባ. |
| Password | የይለፍ ቃል |  | Approved |  |
| Audit log | የኦዲት መዝገብ |  | Approved |  |

### Settings

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Backup / recovery | ባካፕ / መልሶ ማግኛ | ምትኬ | Approved | Changed 2026-10-19. |
| Settings | ቅንብሮች |  | Approved |  |
| Code rule | የኮድ ደንብ | የኮድ ሕግ | Approved |  |
| Recycle bin | የተሰረዙ መዝገቦች | ቆሻሻ መጣያ | Approved |  |
| Legacy | የቀድሞ | ቀደምት | Approved |  |
| On/off setting label | … ይታይ / … ይፈቀድ / … ያስፈልጋል |  | Approved | Never the familiar imperative (አሳይ, ፍቀድ). |

### Reports

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Report | ሪፖርት |  | Approved |  |
| Dashboard | ዳሽቦርድ |  | Approved |  |

### Actions (buttons)

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Save | ያስቀምጡ |  | Approved |  |
| Cancel (dismiss a form) | ይቅር |  | Approved | Never ይሰርዙ, which deletes. |
| Delete | ይሰርዙ |  | Approved |  |
| Edit | ያርትዑ |  | Approved |  |
| Create | ይፍጠሩ |  | Approved |  |
| Update | ያዘምኑ |  | Approved |  |
| Submit (a form) | ያስገቡ |  | Approved |  |
| Submit (a request or case for decision) | ያቅርቡ |  | Approved |  |
| Approve | ያጽድቁ |  | Approved |  |
| Reject | ውድቅ ያድርጉ |  | Approved |  |
| Assign | ይመድቡ | ይሰይሙ | Approved | ይሰይሙ means to name or designate. |
| Generate | ያመንጩ |  | Approved |  |
| Replace (a card or credential) | ይተኩ |  | Approved |  |
| Finalize | ያጠናቅቁ |  | Approved | Approve is ያጽድቁ. |
| Screen (applications) | ያጣሩ |  | Approved |  |
| Archive / restore | ወደ ማህደር ያስቀምጡ / ከማህደር ይመልሱ |  | Approved | Archiving never means deleting. |
| Publish | ይፋ ያድርጉ |  | NEEDS_LANGUAGE_REVIEW | Print is ማተም; the two are never mixed. |
| Print | ያትሙ |  | Approved |  |
| Export | ወደ ፋይል ያውጡ |  | Approved | With a format: በCSV ያውጡ. |
| Import | ከፋይል ያስገቡ |  | Approved |  |
| Activate / deactivate | ገቢር ያድርጉ / ያቦዝኑ |  | Approved |  |
| Filter | ያጣሩ / ማጣሪያ | ፍልተር | Approved |  |
| Search | ይፈልጉ |  | Approved |  |
| Dismiss (a notice) | ይዝጉ |  | Approved |  |
| Actions (column) | እርምጃዎች | ተግባራት | Approved | ተግባራት is Tasks. |

### Statuses

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Active / inactive | ንቁ / ንቁ ያልሆነ |  | Approved |  |
| Draft | ረቂቅ |  | Approved |  |
| Pending | በመጠባበቅ ላይ | በመጠበቅ ላይ | Approved |  |
| Approved | ጸድቋል |  | Approved |  |
| Rejected | ውድቅ ተደርጓል | ውድቅ ሆኗል, ተቀባይነት አላገኘም | Approved |  |
| Submitted | ቀርቧል |  | Approved |  |
| Closed | ተዘግቷል |  | Approved |  |
| Cancelled | ተሰርዟል |  | Approved |  |
| Published | ይፋ ሆኗል |  | Approved |  |

### Dates

| English | Amharic | Do not use | Status | Note |
|---|---|---|---|---|
| Effective from / to | የሚጸናበት ቀን / የሚያበቃበት ቀን |  | Approved | Filters say ከሚጸናበት ቀን / እስከሚጸናበት ቀን. |
| Start date / end date | የመጀመሪያ ቀን / የማብቂያ ቀን |  | Approved |  |
| Ethiopian calendar year | ዓ.ም |  | Approved |  |

### Spelling policy

| Write | Not |
|---|---|
| ሠራተኛ | ሰራተኛ (The official system name keeps its approved spelling ሰራተኛ verbatim.) |
| ሥራ | ስራ |
| ሥርዓት | ስርዓት, ሥርአት |
| ሥልጣን | ስልጣን |
| ሥልጠና | ስልጠና |
| አሠራር | አሰራር |
| ዓይነት | አይነት |
| ዓመት | አመት |
| ሕግ | ህግ |
| ሕዝብ | ህዝብ |
| ሕትመት | ህትመት |
| ኃላፊ | ሃላፊ |
| ኃይል | ሃይል |
| ዕቅድ | እቅድ |
| ዕለት | እለት |
| ጸደቀ / ማጽደቅ / ጸና / ቅርጸት | ፀደቀ, ማፅደቅ, ፀና, ቅርፀት (Exception: ፀሐፊ (secretary) as written in Regulation 201.) |
| ጽሕፈት ቤት | ፅህፈት ቤት |
| ጽሑፍ | ፅሁፍ |
| ደመወዝ | ደሞዝ |
| ሀሳብ | ሃሳብ |
| ሀብት | ሃብት |
| ማህደር | ማኅደር |
| ቅድመ እይታ | ቅድመ ዕይታ |
| ካፌቴሪያ | ካፍቴሪያ |
| ተዛውሯል | ተዛወሯል |
| ሂሳብ | ሒሳብ |
| ጎርጎርዮሳዊ | ጎርጎሮሳዊ, ግሪጎሪያን, ጎርጎሪያን |
| መንግሥት | መንግስት |

### Deprecated terms (enforced by the translation audit)

| Deprecated | Use instead | Audit | Why |
|---|---|---|---|
| ወላጅ (structure parent) | የበላይ / የበታች | blocks | ወላጅ is a biological parent. |
| ስጦታ (issuing) | መስጠት / የተሰጠበት ቀን | blocks | ስጦታ is a gift. |
| ፍልተር | ያጣሩ / ማጣሪያ | blocks | Transliteration retired. |
| ስካን | ይቃኙ / ቅኝት | blocks | Transliteration retired. |
| ካሊብሬሽን | የውጤት ማመጣጠን | blocks | Transliteration retired. |
| የደረጃ ደረጃ / የደረጃ መለኪያ | የሥራ ደረጃ | blocks | A grade level is the job grade. |
| የኮድ ሕግ | የኮድ ደንብ | blocks | ሕግ is a law. |
| የሪፖርት መስመር | የተጠሪነት መስመር | blocks | One term for reporting line. |
| የመስመር ደንብ | የማስተላለፊያ ደንብ | blocks | Routing rule wording. |
| የተቋም ክፍል / የተቋም የሥራ ክፍል | የሥራ ክፍል | blocks | One term for an organization unit. |
| በመጠበቅ ላይ | በመጠባበቅ ላይ | blocks | One form for pending. |
| ውድቅ ሆኗል | ውድቅ ተደርጓል | blocks | One form for rejected. |
| ተቀልፏል (withdrawn) | ተነስቷል | blocks | Wrong word. |
| ተዛወሯል | ተዛውሯል | blocks | Spelling. |
| ደሞዝ | ደመወዝ | blocks | Legal spelling. |
| ካፍቴሪያ | ካፌቴሪያ | blocks | One spelling. |
| ሰራተኛ | ሠራተኛ | blocks | Spelling policy. The official system name is configured in config/branding.php, not in translations. |
| ስራ | ሥራ | blocks | Spelling policy. |
| ስርዓት / ሥርአት | ሥርዓት | blocks | Spelling policy. |
| ህትመት | ሕትመት | blocks | Spelling policy. |
| ቅድመ ዕይታ | ቅድመ እይታ | blocks | One spelling. |
| ሃላፊ | ኃላፊ | blocks | Spelling policy. |
| አይነት | ዓይነት | blocks | Spelling policy. |
| ቤተ መጽሐፍት (catalogue) | ካታሎግ / ማውጫ | blocks | A library is not a catalogue. |
| ተቅድሷል / የጼደቁ / ለቀቅ | (typos) | blocks | Typos found in the audit. |
| ሪፓርት / ኮሚቲ / ቅፅ / ዉሳኔ | ሪፖርት / ኮሚቴ / ቅጽ / ውሳኔ | blocks | Spelling policy. |
| ደንበኛ | ተገልጋይ | warns | A citizen served by a government office is a ተገልጋይ. An API client is a technical term. |
| ድርጅት | ተቋም | warns | ድርጅት only for a private company. Cafeteria providers are private companies. |
| ኤፒአይ / ጂፒኤስ / ኤንኤፍሲ / ኤስኤምኤስ | API / GPS / NFC / SMS | blocks | Accepted acronyms stay in Latin script. |
| ሌጀር | የሂሳብ መዝገብ | blocks | Transliteration retired. |
| ሒሳብ | ሂሳብ | blocks | One spelling. |
| የሥራ መደብ ምደባ (establishment) | የጸደቀ የሥራ መደብ | blocks | ምደባ is an assignment. |
| ቆሻሻ መጣያ | የተሰረዙ መዝገቦች | blocks | Recycle bin wording. |
| ጎርጎሮሳዊ / ግሪጎሪያን / ጎርጎሪያን | ጎርጎርዮሳዊ | blocks | One spelling for Gregorian. |
| ሚኒስቴር | ቢሮ / ተቋም | warns | City bodies are bureaus; a federal ministry may legitimately appear. |
| ምርምር (review) | ግምገማ | warns | ምርምር is research. |
| ምትኬ / ባክ አፕ | ባካፕ | blocks | Backup is ባካፕ (term changed 2026-10-19). |
| Other spelling variants: ሃሳብ, ሃብት, እቅድ, እለት, ሃይል, ስልጣን, ስልጠና, አሰራር, ማኅደር, ፀደቀ, ማፅደቅ, ቅርፀት, ፀና, አመት, ህግ, መንግስት | ሀሳብ, ሀብት, ዕቅድ, ዕለት, ኃይል, ሥልጣን, ሥልጠና, አሠራር, ማህደር, ጸደቀ, ማጽደቅ, ቅርጸት, ጸና, ዓመት, ሕግ, መንግሥት | blocks | Spelling policy. ፀሐፊ (secretary) keeps the Regulation 201 spelling. |

## 7. Changing a term

1. Propose the change with its source (section 1) and the screens it affects.
2. For a *Legal* term, the change needs the regulation text or a legal
   adviser's written confirmation. Legal terms are not changed for style.
3. Edit `resources/data/amharic-terminology.json`. When the old term must not
   come back, add it to `deprecated` with `"block": true`.
4. Update every translation that uses the old term, in both
   `resources/js/i18n/am/` and `lang/am/`. Use translation keys only.
5. Regenerate the tables in section 6 from the JSON.
6. Run the audit and the localization tests:

   ```bash
   node scripts/i18n-audit.mjs --strict
   php artisan test tests/Feature/Localization
   ```

7. Check the affected screens in Amharic at desktop and mobile width.
8. Record the change, the reviewer's name and the date in
   `docs/amharic-language-audit.md`.

A term marked `NEEDS_LANGUAGE_REVIEW` becomes *Approved* only when a named
Amharic language reviewer signs it off in the audit document.

## 8. Continuous checks

- The test suite runs `tests/Feature/Localization/TranslationAuditTest.php`,
  which fails on any blocking audit finding. CI runs the suite on every push
  and pull request to `main`.
- `tests/Feature/Localization/EnumLabelLocalizationTest.php` fails when an
  enum that labels itself returns English, a raw code or a translation key in
  Amharic.
- Run `node scripts/i18n-audit.mjs --json report.json` for the full findings,
  including warnings such as Latin words inside Amharic sentences.
