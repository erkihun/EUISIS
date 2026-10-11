<?php

declare(strict_types=1);

/*
 * Organization Design & Workforce Planning permissions
 * (docs/organization-design-workforce-planning-plan.md §6).
 *
 * Designer, reviewer and approver are separate capabilities: the person who
 * drafts a mandate never publishes it. WHERE a permission applies is decided
 * by OrganizationScopeService, never by the name.
 */
$entry = static fn (string $name, string $group, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm): array => [
    'name' => $name,
    'group' => $group,
    'sort_order' => $sortOrder,
    'is_system' => true,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    $entry('institution-mandates.viewAny', 'institution-mandates', 10, 'View institution mandates', 'የተቋም ሕጋዊ ሥልጣኖችን ይመልከቱ',
        'List the legal mandates of organizations within scope.', 'በወሰን ውስጥ ያሉ ተቋማትን ሕጋዊ ሥልጣኖች ይዘርዝሩ።'),
    $entry('institution-mandates.view', 'institution-mandates', 20, 'View an institution mandate', 'የተቋም ሕጋዊ ሥልጣን ይመልከቱ',
        'Open a mandate with its powers, functions and version history.', 'ሥልጣንን ከኃይሎቹ፣ ተግባራቱ እና የስሪት ታሪኩ ጋር ይክፈቱ።'),
    $entry('institution-mandates.create', 'institution-mandates', 30, 'Draft institution mandates', 'የተቋም ሕጋዊ ሥልጣን ረቂቅ ይፍጠሩ',
        'Draft a new mandate or a new version of a published one.', 'አዲስ ሥልጣን ወይም የታተመ ሥልጣን አዲስ ስሪት ረቂቅ ይፍጠሩ።'),
    $entry('institution-mandates.update', 'institution-mandates', 40, 'Edit draft mandates', 'ረቂቅ ሥልጣኖችን ያርትዑ',
        'Edit or cancel a mandate while it is a draft. Published versions are never edited.', 'ሥልጣንን ረቂቅ እያለ ያርትዑ ወይም ይሰርዙ። የታተሙ ስሪቶች በጭራሽ አይስተካከሉም።'),
    $entry('institution-mandates.approve', 'institution-mandates', 50, 'Publish institution mandates', 'የተቋም ሕጋዊ ሥልጣኖችን ያትሙ',
        'Publish a draft mandate drafted by someone else, superseding the version in force.', 'በሌላ ሰው የተረቀቀ ሥልጣን ያትሙ፤ በሥራ ላይ ያለውን ስሪት ይተካል።'),

    $entry('organization-functions.viewAny', 'organization-functions', 10, 'View powers and functions', 'ኃይሎችና ተግባራትን ይመልከቱ',
        'View the powers and function tree of organizations within scope.', 'በወሰን ውስጥ ያሉ ተቋማትን ኃይሎችና የተግባር ዛፍ ይመልከቱ።'),
    $entry('organization-functions.manage', 'organization-functions', 20, 'Manage powers and functions', 'ኃይሎችና ተግባራትን ያስተዳድሩ',
        'Add, edit, move and remove powers and functions under a mandate within scope.', 'በወሰን ውስጥ በሥልጣን ሥር ኃይሎችና ተግባራትን ይጨምሩ፣ ያርትዑ፣ ያንቀሳቅሱ እና ያስወግዱ።'),
];
