# Employee to ID card mapping audit

Audited on 2026-09-08. This change fixes mapping and rendering discrepancies without changing employee workflow, template permissions, approval rules, or stored card identities.

## Fields and source of truth

| Card field | Stored source |
| --- | --- |
| Amharic name | Explicit legacy `metadata.name_am` translation when present; otherwise `employees.full_name`, constructed from first/middle/last name |
| English name | `employees.name_en`, with legacy metadata and full-name fallback |
| Sex | `employees.gender` |
| Birth date | `employees.date_of_birth`; Ethiopian date on the Amharic row, Gregorian date on the English row |
| Nationality | `employees.nationality` |
| Employment Status | `employees.employment_type`, translated in both languages |
| Phone | `employees.phone` |
| ID Number | `id_cards.card_number` |
| Photo | `employees.photo_path` / `photo_url` |

All required columns, validation, create/update persistence, and employee detail fields already exist. Related migrations are applied locally. No schema change or inferred data backfill was necessary.

The local read-only audit found 7 employees: 6 lack employment type, and 5 lack each of nationality, English name, birth date, and photo. These are missing stored values, not display bugs. Enter the actual values through Employee Edit; account status cannot determine employment type.

## Fixed discrepancies

- React Show, Preview, and both export modals passed lifecycle `status` as employment status. They now share `mapCardEmployee` and use `employment_type` only.
- Portrait export received a shortened employee object that omitted English name, birth date, nationality, phone, and employment type. It now receives the same employee object as landscape export.
- The card resource omitted birth date, nationality, phone, and employment type. These are now included in the authorized resource.
- Server rendering fell back to English on the Amharic name row when no legacy translation existed. It now falls back to the saved employee full name consistently with React, while preserving explicitly saved Amharic translations.
- English birth dates could follow the browser locale or calendar preference. They now use an explicit English Gregorian format and UTC, matching the server field data.
- Employment translations now cover all seven enum values. Blank employment type stays blank; it never becomes Active/Inactive. Ethiopian nationality resolves consistently in both languages.
- Each bilingual field retains an Amharic label/value row followed by an English label/value row, including phone and ID number.
- The server photo resolver accepted only 2 MB while employee forms accept 4 MB. Employee photo export now accepts the same 4 MB limit; logo/seal limits remain unchanged.
- Card preview and browser print/export receive the server's QR URL based on configured `APP_URL`, avoiding request-host differences.

## Rendering and privacy

Screen preview, browser PNG capture, print, and browser Save as PDF share the React front components and employee mapper. Server SVG and server PNG use `IdCardRenderDataFactory` and `IdCardSvgRenderer`. Both front orientations retain the seven identity fields and template typography; organization, position code, employee database ID, and national ID are not rendered on the front. Existing fixed-size SVG layouts may abbreviate long values to fit.

Template PNG backgrounds continue through the existing template provider and server asset resolver. QR content is `rtrim(config('app.url'), '/') . '/id-checker/' . public_card_uuid`; it has no employee data or query parameters. Regression tests compare the complete stored card record and feedback-token record before and after employee/background changes. UUID, card number, encrypted legacy payload, and tokens remain unchanged.

The public ID checker keeps its existing OTP flow and explicit allowed field list. National ID, salary, address, emergency contacts, email, documents, and phone are excluded. Existing card permissions and organization scope are enforced.

## Files changed for this audit

- `app/Http/Controllers/Web/IdCardController.php`
- `app/Http/Resources/IdCardResource.php`
- `app/Services/IdCards/CardQrPayloadService.php`
- `app/Services/IdCards/IdCardAssetResolver.php`
- `app/Services/IdCards/IdCardRenderDataFactory.php`
- `app/Services/IdCards/IdCardSvgRenderer.php`
- `resources/js/Pages/IdCards/Show.tsx`
- `resources/js/Pages/IdCards/Preview.tsx`
- `resources/js/Components/IdCards/CardPrintExportModal.tsx`
- `resources/js/Components/IdCards/CardPortraitPrintExportModal.tsx`
- `resources/js/Components/IdCards/IdCardBilingualField.tsx`
- `resources/js/Components/IdCards/mapCardEmployee.ts`
- `resources/js/i18n/en.ts`
- `resources/js/i18n/am.ts`
- `tests/Feature/IdCards/IdCardEmployeeMappingTest.php`
- `tests/Feature/IdCards/IdCardFrontFieldsTest.php`
- `tests/js/id-card-employee.test.tsx`
- `docs/id-card-employee-mapping.md`

Other pre-existing workspace changes were preserved.

## Verification

- Initial focused Laravel regressions: 124 passed, 817 assertions.
- Final regressions after preserving the legacy Amharic-name fallback: all ID-card feature tests, employee additional-information tests, public-checker tests, and renderer unit tests passed (219 tests, 1,248 assertions).
- JavaScript mapping and rendered bilingual-field tests: 11 passed.
- `npx tsc --noEmit`: passed. No separate `npm run typecheck` script exists.
- `npm run build`: passed (TypeScript and Vite).
- `vendor/bin/pint` scoped to the eight PHP files changed for this audit: passed.
- `php artisan route:list --json`: passed, 661 routes.
- `php artisan migrate:status`: relevant employee/template/QR migrations already applied.
- Full `php artisan test --compact`: 1,535 passed, 8,174 assertions. The final legacy-name fallback adjustment was subsequently checked with the 219-test focused run above.

Run the JavaScript regressions with:

```powershell
npx esbuild tests/js/id-card-employee.test.tsx --bundle --platform=node --format=cjs --outfile=storage/framework/testing/id-card-employee.test.cjs
node --test storage/framework/testing/id-card-employee.test.cjs
```

Imagick is unavailable in this PHP environment, so actual server SVG-to-PNG conversion is not verified. Physical printing, browser PNG downloads, and browser Save as PDF were not exercised; their shared field data and rendered bilingual rows are covered by automated tests.
