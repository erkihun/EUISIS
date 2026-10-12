# Amharic language audit

Audit of the Amharic used across Addis One, carried out on 2026-10-12. It is
written for project leads, the Amharic language reviewer and developers.

The terminology decisions themselves are in `docs/amharic-terminology-guide.md`
and `resources/data/amharic-terminology.json`. Every changed translation value
is listed, before and after, in `docs/amharic-language-changes.csv` for the
reviewer.

## 1. Result

The interface now uses one Amharic term for each concept. Every translation
key exists in both languages, and no status code reaches an Amharic screen
bare. A lint enforces these rules in CI.

The wording has **not** been reviewed by a qualified Amharic language
reviewer. Thirteen terms are marked for that review (section 6), and three need
an official source. Until the review is signed off, the language work is
conditionally ready.

## 2. What was audited

| Source | What it holds | How it was checked |
| --- | --- | --- |
| `resources/js/i18n/` | React interface text, English and Amharic | Audit script, key by key, plus manual review |
| `lang/{en,am}/` | Server messages, validation, exports, notifications | Audit script, key by key, plus manual review |
| `lang/{en,am}/enums.php` (new) | Labels of enums that label themselves | Enum label test |
| `database/seeders/data/*permissions*.php` | Permission names and descriptions on the role pages | Audit script and a full manual read of all 705 labels |
| `app/Support/Rbac/DefaultRoleMatrix.php` | Default role descriptions | Deprecated-term scan, manual review |
| `app/Services/SystemSettings/SystemSettingsRegistry.php` | Settings labels and help text | Deprecated-term scan, manual review |
| `app/Services/CodeGeneration/CodeFormatTokenRegistry.php` | Code format token names | Deprecated-term scan, manual review |
| Seed defaults (code rules, public site) | Names created on a new installation | Deprecated-term scan |

Out of scope on purpose:

- **User-authored content** in the database: names, notes, organization
  names, edited public-site pages. These are never rewritten.
- **Historical and signed records.** PDFs, letters and audit entries keep the
  wording they were produced with.
- **Legal quotations** in `app/Support/Legal/Regulation201.php`.
- **The official system name**, which keeps its approved spelling ሰራተኛ.
- **Transport Management**, which is outside the production scope.
- **The employee discipline module**, which is still uncommitted work in
  progress. A spelling pass touched its untracked file
  `resources/js/i18n/am/discipline.ts` before that was noticed; those edits
  are only in the working copy and were not committed.
- **Demo data** in `app/Support/Demo/DemoDataset.php`.

## 3. Method

1. Load the React translation tree exactly as `useLocale` builds it, and every
   PHP language file with the framework's English fallback.
2. Compare the locales key by key, then read the Amharic by module.
3. Take terms from Regulation 201/2018 first, then official usage, then
   natural professional Amharic (guide, section 1).
4. Change one concept at a time across all files, recording every value
   before and after.
5. Encode each decision in the glossary, add retired terms to the deprecated
   list, and make the lint enforce them.
6. Run the affected tests after each batch.

## 4. Defects found and fixed

Wrong words that changed the meaning:

- **ስጦታ ("gift")** was used for issuing an ID card. It is now መስጠት, and
  the card's issue date reads የተሰጠበት ቀን.
- **ወላጅ ("biological parent")** was used for the parent of a unit,
  organization or node. It is now የበላይ, with የበታች for the child.
- **ፈቃድ ("leave")** was used for a system permission. It is now የመዳረሻ
  መብት. One transfer step also said "final leave" for final approval.
- **ያትሙ ("print")** was used for publish in about 150 places. Publish is now
  ይፋ ያድርጉ.
- **ይሰርዙ ("delete")** was used for cancel, revoke and reject. Cancel on a
  form is now ይቅር, revoke is ይሽሩ, and reject is ውድቅ ያድርጉ.
- **Archive** read as delete in places. It now reads ወደ ማህደር ያስቀምጡ.
- **ድርጅት ("company")** was used for organization, and **ዩኒት** was a
  transliteration for unit. These are now ተቋም and የሥራ ክፍል.
- **ቦታ ("place")** was used for position and vacancy. These are now
  የሥራ መደብ and ክፍት የሥራ መደብ.
- **ሙያ ("profession")** was used for an ISCO occupation. Occupation is now
  የሙያ ምድብ.
- **ሚኒስቴር ("ministry")** was used for the city administration.
- **ካርታ ("geographic map")** was used for mapping legacy records. It is now
  ማዛመድ.
- **ኃይሎች ("forces")** was used for powers. It is now ሥልጣን.
- **የደረጃ ደረጃ ("grade's grade")** was the name of the grade-level module.
  It is now የሥራ ደረጃ.
- **ትክክለኛ ውጤት ("correct result")** was used for KPI actuals. It is now
  ክንውን.
- **ተቀልፏል** was the label for a withdrawn application. It is now ተነስቷል.
- **"Actions" table columns** said ተግባራት, the word for Tasks. They now
  say እርምጃዎች.

Text that was not Amharic:

- **Garbled text** in the public-feedback permission descriptions: ቨቴንታ,
  አትኤናዮ, በቆማናነት, ይላቁ and ቅንብሩች.
- **Garbled text** in a change-request message: ስላልፈፈሉ, ብዙነት, ሥናቦች
  and የተቀጀሩ.
- **Other non-words:** ጊምቶችን, ለማታዘዝ, ለኋሊት, የታቆመ, ቋሚ ሆነ, and the ID
  card caption ያልቃ (now ማብቂያ).
- **Typos:** ተቅድሷል, የጼደቁ, ለቀቅ and እነደ.

Register and grammar:

- **Familiar-register commands.** About 1,100 labels used the familiar form
  (ፍጠር, ሰርዝ, አሳይ). These include roughly 250 permission labels and 25
  setting toggles. They now use the polite form, or "… ይታይ / … ይፈቀድ" for
  settings.
- **Object marker.** Plural objects were missing the -ን marker in about 60
  permission labels.

Completeness and display:

- **Missing Amharic translations.** Password-reset and pagination messages
  and about 40 validation rules had no Amharic.
- **English on Amharic screens.** Sixteen backend enums returned hard-coded
  English. My Portal showed transfer statuses such as "Release Pending" in
  English.
- **Bare status codes.** Codes such as NEEDS_DECISION started Amharic
  sentences. The Amharic meaning now comes first, with the code in brackets
  as a reference.
- **Transliterated acronyms.** ኤፒአይ, ጂፒኤስ, ኤንኤፍሲ and ኤስኤምኤስ now read
  API, GPS, NFC and SMS. ሌጀር now reads የሂሳብ መዝገብ.
- **Spelling variants.** Spelling is now unified. Examples: ሠራተኛ, ሥራ,
  ሥርዓት, ዓይነት, ሕግ, ኃላፊ, ደመወዝ, ካፌቴሪያ and ሂሳብ. The full policy is in
  the guide.
- **Amharic inside components.** Ten inline translations moved to keys. They
  were in the date pickers, the two scan pages, the grievance handler form
  and My Performance. Two scan pages also spelled four month names
  differently from the shared calendar.
- **Messages that showed their own key.** Code asked for 36 translation keys
  that no language file defined, so users saw text such as
  "positionEstablishments.created" after saving. Affected areas: position
  establishments, NFC, transfers, cafeteria branches, service providers,
  occupation validation and the recycle bin.
- **Raw group keys on the role editor.** The 126 permission groups were shown
  by their keys (api_management). They now have labels in both languages.
- **English status badges.** The shared status badge fell back to English for
  60 statuses. It now reads `status.<code>` from the translations first.
- **ፈቃድ ("leave") for a system permission** in about 60 messages, such as
  "ፈቃድ የለዎትም". These now read መብት in prose and የመዳረሻ መብት as the term.
  Camera and location permissions keep ፈቃድ, because there it means a
  browser's consent.
- **Plural forms the first passes missed.** Ge'ez fuses the plural into the
  last syllable (ክፍል becomes ክፍሎች), so several retired terms survived in
  plural. About 40 strings were fixed and the lint patterns now match the
  fused forms.
- **ISIC level names.** ክፍል ("unit") and ክፍለ, which is not a word, were
  replaced by interim names that await an official source.
- **A stray lint log.** `lang/am/VALIDATION.txt` had been committed by
  mistake and was removed.

## 5. What changed

Translation keys audited: 16252 across 135 modules.
Amharic values changed by key: 2696 (each listed in docs/amharic-language-changes.csv).

| Change | Values | Example: before | After |
|---|---|---|---|
| Polite imperative on buttons and actions | 813 | ቢሮ ፍጠር | ጽሕፈት ቤት ይፍጠሩ |
| Organization unit (የሥራ ክፍል) | 217 | በተቋም ዩኒት የሠራተኞች ብዛት | በሥራ ክፍል የሠራተኞች ብዛት |
| Core wording and corrections | 206 | እንደ ነበር መልስ | ከማህደር ይመልሱ |
| Spelling policy | 167 | ይህ መብት በሌላ ካፍቴሪያ አስቀድሞ ጥቅም ላይ ውሏል። | ይህ ጥቅማ ጥቅም በሌላ ካፌቴሪያ አስቀድሞ ጥቅም ላይ ውሏል። |
| Public feedback wording | 160 | የሕዝብ ማቅረብ አስተናጋጅ ይፈልጋል | የቀረበ ጉዳይ የሚያስተናግድ አካል ይፈልጋል |
| Publish vs print | 137 | የክፍት ቦታ ማስታወቂያ ታትሟል | የክፍት የሥራ መደብ ማስታወቂያ ይፋ ሆኗል |
| Permission vs leave | 89 | ፈቃዶች | የመዳረሻ መብቶች |
| Acronyms in Latin script (API, GPS, NFC, SMS) | 88 | በኤፒአይ ሰነድ ገጽ ላይ አሳይ | በAPI ሰነድ ገጽ ላይ ያሳዩ |
| Position (የሥራ መደብ) | 87 | የክፍት ቦታ ማስታወቂያ ተፈጥሯል | የክፍት የሥራ መደብ ማስታወቂያ ተፈጥሯል |
| Parent / child in structures (የበላይ / የበታች) | 80 | ወላጅ ቢሮ | የበላይ አካል ጽሕፈት ቤት |
| Export (ወደ ፋይል ማውጣት) | 66 | ወደ ውጭ ላክ | ወደ ፋይል ያውጡ |
| Office (ጽሕፈት ቤት) and bureau | 65 | የተቋም ቢሮዎች የሉም | የተቋም ጽሕፈት ቤቶች የሉም |
| Navigation labels | 62 | ዕቅዶች እና ማስተላለፍ | ዕቅዶችና የዕቅድ ማውረድ |
| Entitlement (ጥቅማ ጥቅም) | 45 | መብት ተሰጥቷል | ጥቅማ ጥቅም ተሰጥቷል |
| Occupation vs profession | 43 | ሙያዎች | የሙያ ምድቦች (ISCO) |
| Scan (መቃኘት / ቅኝት) | 43 | መታወቂያ ካርድ ስካን | መታወቂያ ካርድ ይቃኙ |
| Approval and transfer consent | 36 | ፈቃድ በመጠባበቅ ላይ | ማጽደቅ በመጠባበቅ ላይ |
| Revoke (መሻር) | 34 | መብት ሰርዝ | ጥቅማ ጥቅም ይሽሩ |
| Grade level (የሥራ ደረጃ) | 33 | የደረጃ ደረጃ ፍጠር | የሥራ ደረጃ ይፍጠሩ |
| Statuses | 33 | ዳግም ህትመት ጥያቄ ፍጠር | የዳግም ሕትመት ጥያቄ መፍጠር |
| Import (ከፋይል ማስገባት) | 30 | የማስመጣት ቅድመ እይታ | የምዝገባ ቅድመ እይታ |
| Organization (ተቋም) | 29 | ያ የድርጅት ክፍል የሌላ ድርጅት ነው። | ያ የሥራ ክፍል የሌላ ተቋም ነው። |
| Handler (የሚያስተናግድ አካል) | 22 | አስተናጋጅ | የሚያስተናግደው አካል |
| Cancel vs delete | 18 | ሰርዝ | ይቅር |
| Pending (በመጠባበቅ ላይ) | 15 | በጥበቃ ላይ ያሉ የካርድ ጥያቄዎች | በመጠባበቅ ላይ ያሉ የካርድ ጥያቄዎች |
| Archive vs delete | 14 | የታለመው የድርጅት ክፍል ተመዝግቦ ተቀምጧል። | የታለመው የሥራ ክፍል ወደ ማህደር ተዛውሯል። |
| Calibration (የውጤት ማመጣጠን) | 10 | ካሊብሬሽን በመጠበቅ ላይ | የውጤት ማመጣጠን በመጠባበቅ ላይ |
| Filter (ማጣራት) | 9 | በሁኔታ ፍልተር | በሁኔታ ያጣሩ |
| Reference numbers | 8 | የማጣቀሻ ቁጥር | የመዝገብ ቁጥር |
| Print | 7 | የህትመት ቡድን ፍጠር | የሕትመት ዙር ይፍጠሩ |
| Review (ግምገማ) | 7 | ምርምር ጀምር | ግምገማ ይጀምሩ |
| Issue (መስጠት / ማውጣት) | 7 | ስጦታ በመጠባበቅ ላይ | መሰጠትን በመጠባበቅ ላይ |
| Actual (ክንውን) | 6 | የKPI ትክክለኛ ውጤት ተመዝግቧል | የKPI ክንውን ተመዝግቧል |
| Qualification vs competency | 5 | የትምህርት ብቃቶች | የትምህርት ዝግጅት |
| Status codes shown with their meaning | 5 | NEEDS_DECISION፦ :email፦ :reason | ውሳኔ ያስፈልጋል (NEEDS_DECISION)፦ :email፦ :reason |


Text outside the translation files was edited in place:

| File group | Lines changed |
| --- | --- |
| Permission catalogue (`database/seeders/data/*permissions*.php`) | about 700 |
| Settings registry, code format tokens, role descriptions | about 60 |
| Seed defaults (code rules, public site) | 8 |

**Installed systems.** The migration
`2026_10_12_200000_refresh_amharic_permission_and_role_labels.php` copies the
corrected permission labels and default role descriptions onto an installed
database. It grants and revokes nothing.

Seeded code-rule names and public-site texts are created only when missing,
so existing databases keep their current wording. Administrators can rename
them in the interface.

## 6. Terms that need a decision

`NEEDS_LANGUAGE_REVIEW` marks terms that are in use but must be confirmed by a
qualified Amharic language reviewer:

| Concept | Current term | Note |
| --- | --- | --- |
| Function (of a mandate) | ተግባር | Shares the word ተግባር with Task; the modules never show both on one screen. Reviewer to confirm. |
| Classification (ISIC, CPC, ISCO) | ምደባ | Shares ምደባ with an employee assignment; screens keep the two apart. Reviewer to confirm or choose ፈርጅ / ምድብ. |
| Process | ሂደት | Process, Activity and Task stay distinct: ሂደት / እንቅስቃሴ / ተግባር. |
| Activity (of a process) | እንቅስቃሴ |  |
| Task | ተግባር |  |
| Position establishment | የጸደቀ የሥራ መደብ |  |
| Daily activity | ዕለታዊ የሥራ እንቅስቃሴ | Alternative under review: ዕለታዊ የሥራ ክንውን. |
| Moderation | ቁጥጥር |  |
| Handler | የሚያስተናግድ አካል |  |
| Escalation / escalate / escalated | ማሳደጊያ / ከፍ ያድርጉ / ከፍ ብሏል | The grievance module uses ከፍ ማድረግ throughout. A reviewer may prefer ወደ በላይ ደረጃ ማስተላለፍ; change all of them together. |
| Scan | መቃኘት / ቅኝት |  |
| Permission | የመዳረሻ መብት |  |
| Publish | ይፋ ያድርጉ | Print is ማተም; the two are never mixed. |

`OFFICIAL_TERMINOLOGY_SOURCE_REQUIRED` marks terms that need an official Amharic source before they are final:

| Concept | Current term | Source needed |
| --- | --- | --- |
| ISIC section / division / group / class | ዘርፍ / ንዑስ ዘርፍ / ቡድን / ንዑስ ቡድን | Interim names; the official Amharic ISIC level names are to be confirmed. |
| Occupation (ISCO) | የሙያ ምድብ | Distinct from Profession. ISCO groups: ዋና ቡድን, ንዑስ ዋና ቡድን, አነስተኛ ቡድን, መሠረታዊ ቡድን. |
| Administrative tribunal | የአስተዳደር ፍርድ ቤት | Short form from Regulation 201; the tribunal's full official Amharic name is still to be confirmed. |

To settle a term, follow section 7 of the terminology guide, and record the
reviewer's name and the date in section 10 below.

## 7. Enforcement

`scripts/i18n-audit.mjs` runs in CI as `npm run i18n:audit` and inside the test
suite. It blocks:

- a key missing or empty in either language
- English left in an Amharic value, or Amharic in an English value
- placeholders that differ between the languages
- a bare status code in Amharic text
- any deprecated term marked blocking in the glossary (38 entries, matching plural forms)
- a permission label in the familiar register
- Amharic wording written into a React component behind a locale check
- a translation key used in code but defined in neither language
- a permission group without a label in either language
- ፈቃድ used for a system permission

It warns about Latin words inside Amharic sentences. The remaining warnings
are product names (pgBackRest, Chrome), CSV column names users must type,
file formats and bracketed codes.

| Test | Guards |
| --- | --- |
| `tests/Feature/Localization/TranslationAuditTest.php` | The audit has no blocking finding, covers the permission catalogue, and finds no Amharic hidden in components |
| `tests/Feature/Localization/EnumLabelLocalizationTest.php` | Every self-labelled enum is Amharic in Amharic and English in English |
| `tests/Feature/Localization/TerminologyGlossaryTest.php` | The glossary is well formed and every term appears in the guide |
| `tests/Feature/Localization/PermissionLabelRefreshMigrationTest.php` | Installed systems receive the corrected labels without grant changes |

## 8. Browser check

The interface was checked in Amharic on the local server with the demo data.
Every page scan looked for English words in buttons, headings, labels, table
headers, tabs and badges, for raw codes, and for clipped or overflowing
controls.

| Check | Scope | Result |
| --- | --- | --- |
| Administrator pages, desktop | 106 pages from the sidebar | All loaded. The defects found (group keys, ISIC levels, recycle-bin keys, English badges, quoted codes) are fixed |
| Phone width 390 × 844 | 17 administrator pages, My Portal, the vacancy applications page, sign-in | One overflow, on the role editor, caused by unbroken permission codes; fixed |
| Sign-in page | Amharic default | No English in the interface |
| Exports | PDF typography, institution QR sheet, cafeteria statement spreadsheet, daily-activity exports, employee CSV import | Covered by the test suite, which passes |

English that remains on Amharic screens is data, not interface text:
organization and user names, ISCO occupation titles that have no official
Amharic yet, codes shown beside their Amharic names, and file extensions.

## 9. Remaining work

- **Language review.** A qualified Amharic reviewer should read
  `docs/amharic-language-changes.csv` (2,696 rows) and the 13 flagged terms.
  Nothing in this audit counts as that review.
- **Official sources.** The occupation (ISCO) group names, the ISIC level names and the full name of
  the administrative tribunal still need official Amharic sources.
- **Registries still hold Amharic in code.** The settings registry, code
  format tokens and default role matrix keep bilingual labels in PHP. The
  wording is corrected, but moving it into translation keys is a separate
  refactor. Those files also hold other uncommitted work.
- **Calendar data in components.** Ethiopian month and weekday names are
  fixed data and stay in the calendar components and services. Several
  components keep their own copy of the month list; they now share one
  spelling, but a single shared list would be cleaner.
- **Existing seeded names.** Code-rule names and public-site texts in
  installed databases keep their old wording until an administrator renames
  them.
- **Discipline and transport.** The discipline module's Amharic should be
  checked against the glossary when that work is committed. One of its
  strings, `discipline.notice`, still says ፈቃድ for a permission, and the
  lint flags it while the module is uncommitted. Transport stays out of scope.
- **Local build name.** A local, untracked `.env.production` still sets
  `APP_NAME=EUISIS`, so bundles built with it show EUISIS in the title of
  pages rendered without shared props, such as the 404 page. The tracked
  `.env.example` already says Addis One.

## 10. Sign-off record

| Date | Reviewer | Scope | Decision |
| --- | --- | --- | --- |
| | | | |

## Appendix: coverage by module

Open findings are warnings only; no module has a blocking finding.


| Module | Keys | English | Amharic | Open findings |
|---|---|---|---|---|
| perm:catalogue | 1432 | 1432 | 1432 | 0 |
| php:api_scopes | 14 | 14 | 14 | 0 |
| php:apiManagement | 12 | 12 | 12 | 0 |
| php:assessments | 141 | 141 | 141 | 0 |
| php:audit-logs | 9 | 9 | 9 | 0 |
| php:auth | 35 | 35 | 35 | 0 |
| php:cafeteria | 204 | 204 | 204 | 0 |
| php:cafeteria-policy | 57 | 57 | 57 | 0 |
| php:cafeteria-statement | 24 | 24 | 24 | 0 |
| php:calendar | 16 | 16 | 16 | 0 |
| php:code-rules | 52 | 52 | 52 | 1 |
| php:common | 56 | 56 | 56 | 0 |
| php:daily-activities | 156 | 156 | 156 | 1 |
| php:dashboard | 3 | 3 | 3 | 0 |
| php:discipline | 54 | 54 | 54 | 0 |
| php:employee-portal | 159 | 159 | 159 | 0 |
| php:employees | 81 | 81 | 81 | 1 |
| php:employment_types | 7 | 7 | 7 | 0 |
| php:entitlement-rules | 4 | 4 | 4 | 0 |
| php:entitlements | 14 | 14 | 14 | 0 |
| php:enums | 68 | 68 | 68 | 0 |
| php:errors | 20 | 20 | 20 | 0 |
| php:exports | 21 | 21 | 21 | 0 |
| php:field-work | 59 | 59 | 59 | 1 |
| php:grade-levels | 7 | 7 | 7 | 0 |
| php:grievances | 248 | 248 | 248 | 0 |
| php:hierarchy-versions | 44 | 44 | 44 | 0 |
| php:id-card-fields | 25 | 25 | 25 | 0 |
| php:id-card-templates | 40 | 40 | 40 | 0 |
| php:id-cards | 39 | 39 | 39 | 0 |
| php:idChecker | 6 | 6 | 6 | 0 |
| php:institution-offices | 95 | 95 | 95 | 0 |
| php:isic-activities | 22 | 22 | 22 | 1 |
| php:navigation | 14 | 14 | 14 | 0 |
| php:nfc | 4 | 4 | 4 | 0 |
| php:occupations | 23 | 23 | 23 | 0 |
| php:organization-design | 417 | 417 | 417 | 7 |
| php:organization-structure-import | 87 | 87 | 87 | 1 |
| php:organization-types | 31 | 31 | 31 | 0 |
| php:organization-units | 20 | 20 | 20 | 0 |
| php:organizational-change-requests | 98 | 98 | 98 | 0 |
| php:organizations | 83 | 83 | 83 | 1 |
| php:pagination | 2 | 2 | 2 | 2 |
| php:password-policy | 18 | 18 | 18 | 0 |
| php:passwords | 5 | 5 | 5 | 0 |
| php:performance | 155 | 155 | 155 | 0 |
| php:permissions | 12 | 12 | 12 | 0 |
| php:position-establishments | 29 | 29 | 29 | 0 |
| php:positions | 35 | 35 | 35 | 1 |
| php:profile | 8 | 8 | 8 | 0 |
| php:provider-portal | 101 | 101 | 101 | 0 |
| php:provider-users | 26 | 26 | 26 | 3 |
| php:providers | 16 | 16 | 16 | 0 |
| php:recycle-bin | 12 | 12 | 12 | 0 |
| php:relationships | 34 | 34 | 34 | 0 |
| php:reports | 26 | 26 | 26 | 0 |
| php:roles | 25 | 25 | 25 | 0 |
| php:security | 32 | 32 | 32 | 0 |
| php:service-eligibility | 17 | 17 | 17 | 0 |
| php:service-feedback | 80 | 80 | 80 | 0 |
| php:service-types | 4 | 4 | 4 | 0 |
| php:settings | 24 | 24 | 24 | 0 |
| php:transfers | 48 | 48 | 48 | 0 |
| php:transport | 20 | 20 | 20 | 0 |
| php:users | 62 | 62 | 62 | 0 |
| php:vacancies | 85 | 85 | 85 | 0 |
| php:validation | 164 | 164 | 164 | 4 |
| php:work-standards | 11 | 11 | 11 | 0 |
| ts:apiManagement | 132 | 132 | 132 | 0 |
| ts:assessmentOversight | 464 | 464 | 464 | 1 |
| ts:assessments | 285 | 285 | 285 | 0 |
| ts:assessmentWorkspace | 169 | 169 | 169 | 0 |
| ts:auditLogs | 341 | 341 | 341 | 1 |
| ts:auth | 132 | 132 | 132 | 1 |
| ts:backup | 233 | 233 | 233 | 14 |
| ts:cafeteria | 476 | 476 | 476 | 2 |
| ts:cafeteriaPolicy | 268 | 268 | 268 | 0 |
| ts:calendar | 52 | 52 | 52 | 0 |
| ts:codeRules | 197 | 197 | 197 | 0 |
| ts:common | 144 | 144 | 144 | 0 |
| ts:confirmations | 35 | 35 | 35 | 1 |
| ts:courtCases | 4 | 4 | 4 | 0 |
| ts:dailyActivities | 379 | 379 | 379 | 5 |
| ts:dashboard | 221 | 221 | 221 | 0 |
| ts:discipline | 202 | 202 | 202 | 1 |
| ts:employeePortal | 133 | 133 | 133 | 0 |
| ts:employees | 211 | 211 | 211 | 3 |
| ts:entitlementRules | 16 | 16 | 16 | 0 |
| ts:entitlements | 31 | 31 | 31 | 0 |
| ts:errors | 22 | 22 | 22 | 0 |
| ts:fieldWork | 217 | 217 | 217 | 0 |
| ts:gradeLevels | 19 | 19 | 19 | 0 |
| ts:grievanceAdmin | 233 | 233 | 233 | 0 |
| ts:grievanceCases | 242 | 242 | 242 | 0 |
| ts:grievancePortal | 26 | 26 | 26 | 0 |
| ts:grievances | 403 | 403 | 403 | 0 |
| ts:grievanceWork | 110 | 110 | 110 | 0 |
| ts:hierarchyVersions | 113 | 113 | 113 | 0 |
| ts:home | 153 | 153 | 153 | 0 |
| ts:idCards | 317 | 317 | 317 | 0 |
| ts:idChecker | 58 | 58 | 58 | 2 |
| ts:institutionOffices | 102 | 102 | 102 | 0 |
| ts:isicActivities | 36 | 36 | 36 | 1 |
| ts:nav | 251 | 251 | 251 | 0 |
| ts:nfc | 156 | 156 | 156 | 0 |
| ts:occupations | 37 | 37 | 37 | 0 |
| ts:organizationalChangeRequests | 195 | 195 | 195 | 0 |
| ts:organizationDesign | 750 | 750 | 750 | 5 |
| ts:organizations | 231 | 231 | 231 | 2 |
| ts:organizationStructureImport | 39 | 39 | 39 | 0 |
| ts:organizationTypes | 46 | 46 | 46 | 1 |
| ts:organizationUnits | 108 | 108 | 108 | 1 |
| ts:organizationUnitTypes | 34 | 34 | 34 | 0 |
| ts:performance | 606 | 606 | 606 | 2 |
| ts:permissions | 176 | 176 | 176 | 1 |
| ts:positionEstablishments | 30 | 30 | 30 | 0 |
| ts:positions | 103 | 103 | 103 | 1 |
| ts:profile | 45 | 45 | 45 | 0 |
| ts:providerPortal | 158 | 158 | 158 | 1 |
| ts:providers | 113 | 113 | 113 | 0 |
| ts:providerUsers | 114 | 114 | 114 | 0 |
| ts:publicSite | 138 | 138 | 138 | 1 |
| ts:recycleBin | 36 | 36 | 36 | 0 |
| ts:relationships | 69 | 69 | 69 | 0 |
| ts:roles | 38 | 38 | 38 | 0 |
| ts:security | 33 | 33 | 33 | 0 |
| ts:serviceFeedback | 408 | 408 | 408 | 4 |
| ts:serviceTypes | 19 | 19 | 19 | 0 |
| ts:settings | 443 | 443 | 443 | 4 |
| ts:status | 71 | 71 | 71 | 0 |
| ts:transfers | 218 | 218 | 218 | 0 |
| ts:transport | 77 | 77 | 77 | 0 |
| ts:users | 143 | 143 | 143 | 0 |
| ts:vacancies | 83 | 83 | 83 | 0 |
| ts:workStandards | 56 | 56 | 56 | 3 |
