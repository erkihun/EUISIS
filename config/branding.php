<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Product branding — boot-time fallbacks
|--------------------------------------------------------------------------
|
| The authoritative values live in System Settings (group `general`:
| application_name, application_short_name, official_name_en,
| official_name_am, identity_system_logo, favicon). These defaults let the
| application boot and render its name before the settings table exists or
| is reachable. Resolve through App\Services\Branding\BrandingService; never
| read these keys from views or components directly.
|
| Policy (docs/branding.md): `name` is the compact brand for titles,
| sign-in, navigation and sender labels; `short_name` is the official
| identifier for formal references and file names; the official names are
| for formal documents and subtitles. The institution an employee belongs to
| keeps its own name, logo and colours — that is a different brand.
|
*/

return [

    'name' => env('APP_NAME', 'Addis One'),

    'short_name' => env('APP_SHORT_NAME', 'AA-IEMSS'),

    'official_name_en' => env('APP_FULL_NAME_EN', 'Addis Ababa Integrated Employee Management & Service System'),

    'official_name_am' => env('APP_FULL_NAME_AM', 'የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት'),

    /*
    | Former product names. Used only to recognise stored defaults that the
    | `branding:migrate-addis-one` command may move, and by the readiness gate
    | to report legacy branding still in current settings. Never shown.
    */
    'legacy_names' => [
        'EUISIS',
        'Employee Unified ID and Service Integration System',
        'Employee Unified ID & Service Integration System',
        'Unified Employee ID & Service Integration System',
        'Addis Ababa Employee Unified ID & Service Platform',
        'AA Employee ID',
        'Employee Unified Identity System',
    ],

];
