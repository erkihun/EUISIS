<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Development / QA / UAT demo dataset (docs/demo-seed-data.md)
    |--------------------------------------------------------------------------
    |
    | Password for the demo accounts DemoDataSeeder creates. Required outside
    | the local and testing environments; there the documented development
    | fallback "password" applies. Demo seeding always refuses production.
    |
    */

    'user_password' => env('DEMO_USER_PASSWORD'),

];
