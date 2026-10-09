<?php

declare(strict_types=1);

/*
 * Court Cases — planned standalone module (docs/court-cases.md).
 *
 * Only the entry permission exists until the module is designed. Do not add
 * create/assign/decide/appeal permissions here before that design is agreed.
 */
return [
    ['name' => 'court_cases.view', 'group' => 'court_cases', 'sort_order' => 10, 'is_system' => true,
        'label_en' => 'View Court Cases', 'label_am' => 'የፍርድ ቤት ጉዳዮችን ይመልከቱ',
        'description_en' => 'Open the Court Cases module. The module is planned; no court case records are kept yet.',
        'description_am' => 'የፍርድ ቤት ጉዳዮች ሞጁልን መክፈት። ሞጁሉ የታቀደ ነው፤ እስካሁን የፍርድ ቤት ጉዳይ መዝገብ አይያዝም።'],
];
