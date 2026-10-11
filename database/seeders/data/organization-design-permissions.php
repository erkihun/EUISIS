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

    $entry('service-catalog.viewAny', 'service-catalog', 10, 'View the service catalog', 'የአገልግሎት ካታሎግ ይመልከቱ',
        'List the services of organizations within scope.', 'በወሰን ውስጥ ያሉ ተቋማትን አገልግሎቶች ይዘርዝሩ።'),
    $entry('service-catalog.view', 'service-catalog', 20, 'View a service', 'አገልግሎት ይመልከቱ',
        'Open a service with its functions, classification and version history.', 'አገልግሎትን ከተግባራቱ፣ ምደባውና የስሪት ታሪኩ ጋር ይክፈቱ።'),
    $entry('service-catalog.create', 'service-catalog', 30, 'Draft services', 'የአገልግሎት ረቂቅ ይፍጠሩ',
        'Draft a new service or a new version of a published one.', 'አዲስ አገልግሎት ወይም የታተመ አገልግሎት አዲስ ስሪት ረቂቅ ይፍጠሩ።'),
    $entry('service-catalog.update', 'service-catalog', 40, 'Edit draft services', 'ረቂቅ አገልግሎቶችን ያርትዑ',
        'Edit a service and its function and ISIC links while it is a draft.', 'አገልግሎትን እና የተግባርና የISIC አገናኞቹን ረቂቅ እያለ ያርትዑ።'),
    $entry('service-catalog.approve', 'service-catalog', 50, 'Publish services', 'አገልግሎቶችን ያትሙ',
        'Publish a draft service drafted by someone else.', 'በሌላ ሰው የተረቀቀ አገልግሎት ያትሙ።'),

    $entry('classifications.viewAny', 'classifications', 10, 'View classifications', 'ምደባዎችን ይመልከቱ',
        'Browse the local service classification, CPC and ISIC reference data.', 'የአካባቢ የአገልግሎት ምደባ፣ CPC እና ISIC ማጣቀሻ መረጃን ያስሱ።'),
    $entry('classifications.manage', 'classifications', 20, 'Manage classifications', 'ምደባዎችን ያስተዳድሩ',
        'City-level: import and maintain classification revisions. Institution designers only cite them.', 'የከተማ ደረጃ፡ የምደባ ስሪቶችን ያስገቡ እና ያስተዳድሩ። የተቋም ዲዛይነሮች ይጠቅሷቸዋል ብቻ።'),

    $entry('processes.viewAny', 'processes', 10, 'View processes', 'ሂደቶችን ይመልከቱ',
        'List the process architecture of organizations within scope.', 'በወሰን ውስጥ ያሉ ተቋማትን የሂደት አወቃቀር ይዘርዝሩ።'),
    $entry('processes.view', 'processes', 20, 'View a process', 'ሂደት ይመልከቱ',
        'Open a process with its activities, tasks, inputs/outputs and standards.', 'ሂደትን ከእንቅስቃሴዎቹ፣ ተግባሮቹ፣ ግብዓት/ውጤቶቹ እና ስታንዳርዶቹ ጋር ይክፈቱ።'),
    $entry('processes.create', 'processes', 30, 'Draft processes', 'የሂደት ረቂቅ ይፍጠሩ',
        'Draft a new process or a new version of a published one.', 'አዲስ ሂደት ወይም የታተመ ሂደት አዲስ ስሪት ረቂቅ ይፍጠሩ።'),
    $entry('processes.update', 'processes', 40, 'Design draft processes', 'ረቂቅ ሂደቶችን ይንደፉ',
        'Edit a draft process and its activities, tasks and typed inputs/outputs; trace position tasks to master tasks.', 'ረቂቅ ሂደትን እና እንቅስቃሴዎቹን፣ ተግባሮቹን እና ግብዓት/ውጤቶቹን ያርትዑ፤ የሥራ መደብ ተግባራትን ከዋና ተግባራት ጋር ያገናኙ።'),
    $entry('processes.approve', 'processes', 50, 'Publish processes', 'ሂደቶችን ያትሙ',
        'Publish a draft process drafted by someone else.', 'በሌላ ሰው የተረቀቀ ሂደት ያትሙ።'),

    $entry('professions.manage', 'professions', 10, 'Manage professions', 'ሙያዎችን ያስተዳድሩ',
        'City-level: add and deactivate professions and specializations under occupations. Designers cite them.', 'የከተማ ደረጃ፡ በሙያ ምድቦች ሥር ሙያዎችንና ልዩ ሙያዎችን ይጨምሩ እና ያቦዝኑ። ዲዛይነሮች ይጠቅሷቸዋል።'),

    $entry('competency-catalog.viewAny', 'competency-catalog', 10, 'View the competency catalog', 'የብቃት ካታሎግ ይመልከቱ',
        'Browse competency types, named levels, framework competencies and the education ladder.', 'የብቃት ዓይነቶችን፣ የተሰየሙ ደረጃዎችን፣ የማዕቀፍ ብቃቶችን እና የትምህርት ደረጃዎችን ያስሱ።'),
    $entry('competency-catalog.manage', 'competency-catalog', 20, 'Manage the competency catalog', 'የብቃት ካታሎግ ያስተዳድሩ',
        'City-level: maintain competency types, named levels, framework competencies and education levels.', 'የከተማ ደረጃ፡ የብቃት ዓይነቶችን፣ የተሰየሙ ደረጃዎችን፣ የማዕቀፍ ብቃቶችን እና የትምህርት ደረጃዎችን ያስተዳድሩ።'),

    $entry('organization-design.viewAny', 'organization-design', 10, 'View design studies', 'የንድፍ ጥናቶችን ይመልከቱ',
        'List design studies and the responsibility matrix of organizations within scope.', 'በወሰን ውስጥ ያሉ ተቋማትን የንድፍ ጥናቶች እና የኃላፊነት ማትሪክስ ይዘርዝሩ።'),
    $entry('organization-design.view', 'organization-design', 20, 'View a design study', 'የንድፍ ጥናት ይመልከቱ',
        'Open a design study with its unit proposals and responsibilities.', 'የንድፍ ጥናትን ከክፍል ሀሳቦቹ እና ኃላፊነቶቹ ጋር ይክፈቱ።'),
    $entry('organization-design.create', 'organization-design', 30, 'Start design studies', 'የንድፍ ጥናቶችን ይጀምሩ',
        'Open a new design study for an organization within scope.', 'በወሰን ውስጥ ላለ ተቋም አዲስ የንድፍ ጥናት ይክፈቱ።'),
    $entry('organization-design.update', 'organization-design', 40, 'Design units and responsibilities', 'ክፍሎችንና ኃላፊነቶችን ይንደፉ',
        'Edit draft studies, propose units, map responsibilities and hand approved proposals to the change-request pipeline.', 'ረቂቅ ጥናቶችን ያርትዑ፣ ክፍሎችን ያቅርቡ፣ ኃላፊነቶችን ያስተካክሉ እና የጸደቁ ሀሳቦችን ወደ የለውጥ ጥያቄ ሂደት ያስተላልፉ።'),
    $entry('organization-design.review', 'organization-design', 50, 'Review design studies', 'የንድፍ ጥናቶችን ይገምግሙ',
        'Return a submitted study to its preparer with comments.', 'የቀረበን ጥናት ከአስተያየቶች ጋር ወደ አዘጋጁ ይመልሱ።'),
    $entry('organization-design.approve', 'organization-design', 60, 'Approve design studies', 'የንድፍ ጥናቶችን ያጽድቁ',
        'Approve a submitted study prepared by someone else.', 'በሌላ ሰው የተዘጋጀ የቀረበ ጥናት ያጽድቁ።'),

    $entry('responsibility-types.manage', 'responsibility-types', 10, 'Manage responsibility types', 'የኃላፊነት ዓይነቶችን ያስተዳድሩ',
        'City-level: maintain the responsibility types (responsible, accountable, …) units are mapped with.', 'የከተማ ደረጃ፡ ክፍሎች የሚመደቡባቸውን የኃላፊነት ዓይነቶች (ኃላፊ፣ ተጠያቂ፣ …) ያስተዳድሩ።'),
];
