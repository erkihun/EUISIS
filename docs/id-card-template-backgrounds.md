# ID card template backgrounds

Open System Settings → ID Card Settings → ID Card Templates, or `/system-settings/id-card-templates`.

Create or edit a named template, select the orientation and physical dimensions, browse for front/back PNGs, and save. Selected files preview immediately; saving uploads them. Replace and remove are available independently on each side. Choose an active default to apply its artwork to cards. The existing Classic, Modern, and Minimal design selector remains in ID Card Settings and controls the underlying layout.

## Storage and permissions

- Files are stored on the private `local` disk under generated UUIDv7 filenames. Database columns hold paths only; private paths are hidden from page payloads.
- Upload validation checks image content, MIME type, PNG extension, the Security settings upload limit, and dimensions of 100–6000 pixels on each axis.
- Replacements retain previous files until both uploads and the database transaction succeed. Failed saves remove new files. Soft-deleted templates retain their assets for recovery.
- Template management requires `id_card_templates.view`, `.create`, `.update`, or `.delete` as appropriate. Default selection additionally requires `.set_default`, including removing or deleting the current default. Default-only permission can select an existing active template without editing it.
- The existing Super Admin gate grants these abilities. Other administrators require the relevant permission assignments. Existing card permissions and approval policies are unchanged.
- Active default artwork is served to authenticated card consumers. Viewing draft artwork requires template view permission. The route accepts template identifiers and front/back sides, never arbitrary storage paths.

## Rendering and QR identity

Landscape and portrait React cards use the selected default artwork underneath the existing fields. Browser PNG capture and browser print/Save as PDF use those components and wait for their images to load. The server SVG renderer embeds PNG bytes as data URIs in the response, including the server PNG conversion source. No image data URIs are persisted in the database.

Each side falls back to the existing design when its background is absent. Deleting or unsetting the default restores the existing design globally. Template changes do not write to ID cards, employee records, QR payloads, public UUIDs, tokens, or card lifecycle status. A regression test compares the complete raw card record before and after background replacement and default changes.

Physical dimensions apply to browser capture/print and server output. SVG and React remain separate renderers with different typography. For nonstandard aspect ratios, SVG content is fitted proportionally to preserve square QR modules, while React uses its existing responsive layout.

## Verification and local runtime

- Full `php artisan test`: 1,438 passed, 7,580 assertions.
- Final focused template/renderer run: 19 passed, 149 assertions, including the additional default-only permission test.
- `vendor/bin/pint`: passed for the changed PHP files; unrelated existing edits were excluded.
- `npm run build`: passed, including TypeScript compilation. There is no separate `typecheck` script.
- `php artisan route:list`: passed; 661 routes, including six template routes.
- Template migration applied locally and five permissions registered. An authenticated HTTP kernel request returned 200 with component `SystemSettings/IdCardTemplates`.
- Imagick is unavailable locally, so actual server PNG rasterization was not executed. Browser PNG remains available. Interactive browser and physical print/PDF verification could not be performed because the in-app browser was unavailable.

For another environment, deploy the code and complete build, run `php artisan migrate --force`, and reset the permission cache. The local verification did not deploy to an external server.

## Files changed

- `app/Models/IdCardTemplate.php`
- `database/migrations/2026_09_06_143144_create_id_card_templates_table.php`
- `app/Http/Requests/IdCards/SaveIdCardTemplateRequest.php`
- `app/Services/IdCards/IdCardTemplateService.php`
- `app/Actions/IdCards/SaveIdCardTemplateAction.php`
- `app/Http/Controllers/Web/IdCardTemplateController.php`
- `app/Http/Controllers/Web/IdCardExportController.php`
- `app/Http/Controllers/Web/SystemSettingController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Services/IdCards/IdCardRenderData.php`
- `app/Services/IdCards/IdCardRenderDataFactory.php`
- `app/Services/IdCards/IdCardSvgRenderer.php`
- `app/Services/IdCards/IdCardPngExporter.php`
- `database/seeders/data/permissions.php`
- `lang/en/id-card-templates.php`
- `lang/am/id-card-templates.php`
- `routes/web.php`
- `tests/Feature/IdCards/IdCardTemplateManagementTest.php`
- `resources/js/Pages/SystemSettings/IdCardTemplates.tsx`
- `resources/js/Pages/SystemSettings/Index.tsx`
- `resources/js/Components/IdCards/IdCardTemplateContext.tsx`
- `resources/js/Components/IdCards/IdCardFront.tsx`
- `resources/js/Components/IdCards/IdCardBack.tsx`
- `resources/js/Components/IdCards/IdCardPortraitFront.tsx`
- `resources/js/Components/IdCards/IdCardPortraitBack.tsx`
- `resources/js/Components/IdCards/CardPrintExportModal.tsx`
- `resources/js/Components/IdCards/CardPortraitPrintExportModal.tsx`
- `resources/js/Pages/IdCards/Show.tsx`
- `resources/js/Pages/IdCards/Preview.tsx`
- `resources/js/hooks/useCardExport.ts`
- `resources/js/hooks/useWaitForCardAssets.ts`
- `resources/js/i18n/en/settings.ts`
- `resources/js/i18n/am/settings.ts`
- `resources/css/app.css`
- `docs/id-card-template-backgrounds.md`
