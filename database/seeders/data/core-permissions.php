<?php

declare(strict_types=1);

/*
 * The Core workspace (docs/architecture/core-workspace.md): the master records
 * of organizations, positions, employees, assignments and credentials, opened
 * at /core with its own navigation. This permission only opens the workspace;
 * every page in it keeps its own permission and organization scope.
 */
return [
    ['name' => 'core.access', 'group' => 'core', 'sort_order' => 10, 'is_system' => true,
        'label_en' => 'Open Core', 'label_am' => 'ዋና መዝገብን ይክፈቱ',
        'description_en' => 'Allows opening the Core workspace and its dashboard. Each page inside it still checks its own permission and organization scope.',
        'description_am' => 'የዋና መዝገብ ክፍሉንና ዳሽቦርዱን መክፈት ያስችላል። በውስጡ ያለ እያንዳንዱ ገጽ የራሱን የመዳረሻ መብትና የተቋም ወሰን ይፈትሻል።'],
];
