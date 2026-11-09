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
    $entry('institution-mandates.approve', 'institution-mandates', 50, 'Publish institution mandates', 'የተቋም ሕጋዊ ሥልጣኖችን ይፋ ያድርጉ',
        'Publish a draft mandate drafted by someone else, superseding the version in force.', 'በሌላ ሰው የተረቀቀ ሥልጣን ያትሙ፤ በሥራ ላይ ያለውን ስሪት ይተካል።'),

    $entry('organization-functions.viewAny', 'organization-functions', 10, 'View powers and functions', 'ሥልጣንና ተግባራትን ይመልከቱ',
        'View the powers and function tree of organizations within scope.', 'በወሰን ውስጥ ያሉ ተቋማትን ኃይሎችና የተግባር ዛፍ ይመልከቱ።'),
    $entry('organization-functions.manage', 'organization-functions', 20, 'Manage powers and functions', 'ሥልጣንና ተግባራትን ያስተዳድሩ',
        'Add, edit, move and remove powers and functions under a mandate within scope.', 'በወሰን ውስጥ በሥልጣን ሥር ሥልጣንና ተግባራትን ይጨምሩ፣ ያርትዑ፣ ያንቀሳቅሱ እና ያስወግዱ።'),

    $entry('service-catalog.viewAny', 'service-catalog', 10, 'View the service catalog', 'የአገልግሎት ካታሎግ ይመልከቱ',
        'List the services of organizations within scope.', 'በወሰን ውስጥ ያሉ ተቋማትን አገልግሎቶች ይዘርዝሩ።'),
    $entry('service-catalog.view', 'service-catalog', 20, 'View a service', 'አገልግሎት ይመልከቱ',
        'Open a service with its functions, classification and version history.', 'አገልግሎትን ከተግባራቱ፣ ምደባውና የስሪት ታሪኩ ጋር ይክፈቱ።'),
    $entry('service-catalog.create', 'service-catalog', 30, 'Draft services', 'የአገልግሎት ረቂቅ ይፍጠሩ',
        'Draft a new service or a new version of a published one.', 'አዲስ አገልግሎት ወይም የታተመ አገልግሎት አዲስ ስሪት ረቂቅ ይፍጠሩ።'),
    $entry('service-catalog.update', 'service-catalog', 40, 'Edit draft services', 'ረቂቅ አገልግሎቶችን ያርትዑ',
        'Edit a service and its function and ISIC links while it is a draft.', 'አገልግሎትን እና የተግባርና የISIC አገናኞቹን ረቂቅ እያለ ያርትዑ።'),
    $entry('service-catalog.approve', 'service-catalog', 50, 'Publish services', 'አገልግሎቶችን ይፋ ያድርጉ',
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
    $entry('processes.approve', 'processes', 50, 'Publish processes', 'ሂደቶችን ይፋ ያድርጉ',
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

    $entry('workforce-planning.viewAny', 'workforce-planning', 10, 'View manpower plans', 'የሰው ኃይል ዕቅዶችን ይመልከቱ',
        'List manpower plans of organizations within scope.', 'በወሰን ውስጥ ያሉ ተቋማትን የሰው ኃይል ዕቅዶች ይዘርዝሩ።'),
    $entry('workforce-planning.view', 'workforce-planning', 20, 'View a manpower plan', 'የሰው ኃይል ዕቅድ ይመልከቱ',
        'Open a plan with its lines, calculations and position requirements.', 'ዕቅድን ከመስመሮቹ፣ ስሌቶቹ እና የሥራ መደብ መስፈርቶቹ ጋር ይክፈቱ።'),
    $entry('workforce-planning.create', 'workforce-planning', 30, 'Start manpower plans', 'የሰው ኃይል ዕቅዶችን ይጀምሩ',
        'Open a new manpower plan for an organization within scope.', 'በወሰን ውስጥ ላለ ተቋም አዲስ የሰው ኃይል ዕቅድ ይክፈቱ።'),
    $entry('workforce-planning.update', 'workforce-planning', 40, 'Plan manpower', 'የሰው ኃይል ያቅዱ',
        'Edit draft plans, add and calculate lines, and adjust headcounts with a reason.', 'ረቂቅ ዕቅዶችን ያርትዑ፣ መስመሮችን ይጨምሩና ያስሉ፣ እና ከምክንያት ጋር የሠራተኛ ብዛትን ያስተካክሉ።'),
    $entry('workforce-planning.review', 'workforce-planning', 50, 'Review manpower plans', 'የሰው ኃይል ዕቅዶችን ይገምግሙ',
        'Return a submitted plan to its preparer with comments.', 'የቀረበን ዕቅድ ከአስተያየቶች ጋር ወደ አዘጋጁ ይመልሱ።'),
    $entry('workforce-planning.approve', 'workforce-planning', 60, 'Approve manpower plans', 'የሰው ኃይል ዕቅዶችን ያጽድቁ',
        'Approve a submitted plan prepared by someone else; this rolls lines up into position requirements.', 'በሌላ ሰው የተዘጋጀ የቀረበ ዕቅድ ያጽድቁ፤ ይህ መስመሮችን ወደ የሥራ መደብ መስፈርቶች ያጠቃልላል።'),
    $entry('workforce-planning.implement', 'workforce-planning', 70, 'Implement position requirements', 'የሥራ መደብ መስፈርቶችን ይተግብሩ',
        'Create the positions and approved establishments of an approved plan. Never assigns people.', 'የጸደቀ ዕቅድ የሥራ መደቦችንና የጸደቁ ድልድሎችን ይፍጠሩ። በጭራሽ ሰዎችን አይመድብም።'),

    $entry('methodologies.viewAny', 'methodologies', 10, 'View methodologies', 'ዘዴዎችን ይመልከቱ',
        'Browse the manpower estimation methodologies and their parameters.', 'የሰው ኃይል ግምት ዘዴዎችን እና መለኪያዎቻቸውን ያስሱ።'),
    $entry('methodologies.manage', 'methodologies', 20, 'Draft methodologies', 'ዘዴዎችን ያርቅቁ',
        'City-level: draft methodologies and new versions with their parameters.', 'የከተማ ደረጃ፡ ዘዴዎችንና አዲስ ስሪቶችን ከመለኪያዎቻቸው ጋር ያርቅቁ።'),
    $entry('methodologies.approve', 'methodologies', 30, 'Publish methodologies', 'ዘዴዎችን ይፋ ያድርጉ',
        'Publish a methodology drafted by someone else once every parameter it uses is set.', 'የሚጠቀመው እያንዳንዱ መለኪያ ከተቀመጠ በኋላ በሌላ ሰው የተረቀቀ ዘዴ ያትሙ።'),

    $entry('position-profiles.viewAny', 'position-profiles', 10, 'View position design', 'የሥራ መደብ ንድፍ ይመልከቱ',
        'Browse position profiles, work links and requirements of positions within scope.', 'በወሰን ውስጥ ያሉ የሥራ መደቦችን መገለጫዎች፣ የሥራ አገናኞች እና መስፈርቶች ያስሱ።'),
    $entry('position-profiles.manage', 'position-profiles', 20, 'Design positions', 'የሥራ መደቦችን ይንደፉ',
        'Draft position profiles, link positions to the work design, and write or derive their requirements.', 'የሥራ መደብ መገለጫዎችን ያርቅቁ፣ የሥራ መደቦችን ከሥራ ንድፉ ጋር ያገናኙ፣ እና መስፈርቶቻቸውን ይጻፉ ወይም ያመንጩ።'),
    $entry('position-profiles.approve', 'position-profiles', 30, 'Publish position profiles', 'የሥራ መደብ መገለጫዎችን ይፋ ያድርጉ',
        'Publish a profile drafted by someone else.', 'በሌላ ሰው የተረቀቀ መገለጫ ያትሙ።'),

    $entry('job-descriptions.viewAny', 'job-descriptions', 10, 'View job descriptions', 'የሥራ መግለጫዎችን ይመልከቱ',
        'Read job descriptions of positions within scope.', 'በወሰን ውስጥ ያሉ የሥራ መደቦችን የሥራ መግለጫዎች ያንብቡ።'),
    $entry('job-descriptions.manage', 'job-descriptions', 20, 'Draft job descriptions', 'የሥራ መግለጫዎችን ያርቅቁ',
        'Draft job descriptions from published profiles, write custom sections and regenerate derived ones.', 'ከታተሙ መገለጫዎች የሥራ መግለጫዎችን ያርቅቁ፣ ብጁ ክፍሎችን ይጻፉ እና የተመነጩትን ያድሱ።'),
    $entry('job-descriptions.approve', 'job-descriptions', 30, 'Approve job descriptions', 'የሥራ መግለጫዎችን ያጽድቁ',
        'Review and approve a submitted job description prepared by someone else.', 'በሌላ ሰው የተዘጋጀ የቀረበ የሥራ መግለጫ ይገምግሙና ያጽድቁ።'),
    $entry('job-descriptions.publish', 'job-descriptions', 40, 'Publish job descriptions', 'የሥራ መግለጫዎችን ይፋ ያድርጉ',
        'Publish an approved job description; it is recorded on the position\'s current assignments.', 'የጸደቀ የሥራ መግለጫ ያትሙ፤ በሥራ መደቡ የአሁኑ ምደባዎች ላይ ይመዘገባል።'),

    $entry('organization-design-reports.view', 'organization-design-reports', 10, 'View design reports', 'የንድፍ ሪፖርቶችን ይመልከቱ',
        'Coverage, traceability, gap, position readiness and workforce reports for organizations within scope.', 'በወሰን ውስጥ ላሉ ተቋማት የሽፋን፣ የመከታተያ፣ የክፍተት፣ የሥራ መደብ ዝግጁነትና የሰው ኃይል ሪፖርቶች።'),
    $entry('organization-design-reports.export', 'organization-design-reports', 20, 'Export design reports', 'የንድፍ ሪፖርቶችን ወደ ፋይል ያውጡ',
        'Queue CSV or XLSX exports of the design reports and download them.', 'የንድፍ ሪፖርቶችን በCSV ወይም XLSX ወደ ውጭ መላክ ያስይዙና ያውርዱ።'),

    $entry('data-mapping.view', 'data-mapping', 10, 'View the mapping workspace', 'የማዛመጃ የሥራ ቦታውን ይመልከቱ',
        'See existing records not yet linked to the design, their suggestions and the data-quality report. Mapping itself uses the permissions of each record kind.', 'ከንድፉ ጋር ገና ያልተገናኙ ነባር መዝገቦችን፣ ጥቆማዎቻቸውንና የመረጃ ጥራት ሪፖርቱን ይመልከቱ። ማገናኘቱ ራሱ የእያንዳንዱን መዝገብ ዓይነት ፈቃዶች ይጠቀማል።'),

    // Legal sources and legacy baselines (docs/organization-design-legacy-baseline-plan.md §5).
    $entry('legal-sources.viewAny', 'legal-sources', 10, 'View legal sources', 'የሕግ ምንጮችን ይመልከቱ',
        'List the proclamations, regulations, directives, decisions and studies the design cites, within scope and city-level.', 'ንድፉ የሚጠቅሳቸውን አዋጆች፣ ደንቦች፣ መመሪያዎች፣ ውሳኔዎችና ጥናቶች በወሰን ውስጥና በከተማ ደረጃ ይዘርዝሩ።'),
    $entry('legal-sources.view', 'legal-sources', 20, 'Open a legal source', 'የሕግ ምንጭ ይክፈቱ',
        'Read a legal source, its citations and download its documents.', 'የሕግ ምንጭን፣ ጥቅሶቹንና ሰነዶቹን ያንብቡ፤ ያውርዱ።'),
    $entry('legal-sources.create', 'legal-sources', 30, 'Record legal sources', 'የሕግ ምንጮችን ይመዝግቡ',
        'Record a legal source for an organization in scope and attach its documents.', 'በወሰን ውስጥ ላለ ተቋም የሕግ ምንጭ ይመዝግቡ፤ ሰነዶቹን ያያይዙ።'),
    $entry('legal-sources.update', 'legal-sources', 40, 'Edit legal sources', 'የሕግ ምንጮችን ያርትዑ',
        'Edit, mark superseded or repealed, and manage the documents of an organization\'s legal sources.', 'የተቋም የሕግ ምንጮችን ያርትዑ፣ የተተኩ ወይም የተሻሩ ምልክት ያድርጉ፣ ሰነዶቻቸውን ያስተዳድሩ።'),
    $entry('legal-sources.manageCatalog', 'legal-sources', 50, 'Manage city-level legal sources', 'የከተማ ደረጃ የሕግ ምንጮችን ያስተዳድሩ',
        'Record and edit the legal sources every institution may cite (city proclamations, regulations). Global, not organization-scoped.', 'እያንዳንዱ ተቋም ሊጠቅሳቸው የሚችሉትን የሕግ ምንጮች (የከተማ አዋጆች፣ ደንቦች) ይመዝግቡና ያርትዑ። ዓለም አቀፍ፤ በተቋም ወሰን አይገደብም።'),

    $entry('legacy-baselines.viewAny', 'legacy-baselines', 10, 'View legacy baselines', 'የቀድሞ መነሻ መዝገቦችን ይመልከቱ',
        'List the legacy organization baselines of organizations in scope.', 'በወሰን ውስጥ ያሉ ተቋማትን የቀድሞ መነሻ መዝገቦች ይዘርዝሩ።'),
    $entry('legacy-baselines.view', 'legacy-baselines', 20, 'Open a legacy baseline', 'የቀድሞ መነሻ መዝገብ ይክፈቱ',
        'Read a baseline, its steps, validation report, snapshot and history.', 'መነሻ መዝገብን፣ ደረጃዎቹን፣ የማረጋገጫ ሪፖርቱን፣ ቅጽበታዊ ቅጂውንና ታሪኩን ያንብቡ።'),
    $entry('legacy-baselines.create', 'legacy-baselines', 30, 'Start legacy baselines', 'የቀድሞ መነሻ መዝገብ ይጀምሩ',
        'Open a legacy baseline capture for an organization in scope.', 'በወሰን ውስጥ ላለ ተቋም የቀድሞ መነሻ መዝገብ ቀረጻ ይክፈቱ።'),
    $entry('legacy-baselines.update', 'legacy-baselines', 40, 'Work on legacy baselines', 'በቀድሞ መነሻ መዝገቦች ላይ ይሥሩ',
        'Save wizard steps, cite legal sources, run validation and submit a draft baseline for review.', 'የመመሪያ ደረጃዎችን ያስቀምጡ፣ የሕግ ምንጮችን ይጥቀሱ፣ ማረጋገጫ ያስኪዱ፣ ረቂቅ መነሻ መዝገብ ለግምገማ ያቅርቡ።'),
    $entry('legacy-baselines.review', 'legacy-baselines', 50, 'Review legacy baselines', 'የቀድሞ መነሻ መዝገቦችን ይገምግሙ',
        'Return a submitted baseline to its drafter with a reason.', 'የቀረበን መነሻ መዝገብ ከምክንያት ጋር ወደ አርቃቂው ይመልሱ።'),
    $entry('legacy-baselines.approve', 'legacy-baselines', 60, 'Approve legacy baselines', 'የቀድሞ መነሻ መዝገቦችን ያጽድቁ',
        'Approve a submitted baseline whose validation shows no error. Never the person who drafted it.', 'ማረጋገጫው ስህተት የማያሳይ የቀረበ መነሻ መዝገብ ያጽድቁ። በጭራሽ ያረቀቀው ሰው አይደለም።'),
    $entry('legacy-baselines.publish', 'legacy-baselines', 70, 'Publish legacy baselines', 'የቀድሞ መነሻ መዝገቦችን ይፋ ያድርጉ',
        'Publish an approved baseline: the snapshot is frozen and any earlier published baseline is superseded.', 'የጸደቀን መነሻ መዝገብ ይፋ ያድርጉ፡ ቅጽበታዊ ቅጂው ይቀዘቅዛል፤ ቀደም ብሎ ይፋ የሆነ መነሻ ይተካል።'),

    $entry('design-imports.viewAny', 'design-imports', 10, 'View design imports', 'የንድፍ ማስመጫዎችን ይመልከቱ',
        'List import batches of organizations in scope with their row results.', 'በወሰን ውስጥ ያሉ ተቋማትን የማስመጫ ባችዎች ከረድፍ ውጤቶቻቸው ጋር ይዘርዝሩ።'),
    $entry('design-imports.create', 'design-imports', 20, 'Upload design imports', 'የንድፍ ማስመጫ ይጫኑ',
        'Upload an Excel or CSV file into staging, download templates, fix mappings and re-validate. Nothing becomes authoritative here.', 'የExcel ወይም CSV ፋይል ወደ ማቆያ ይጫኑ፣ ቅጾችን ያውርዱ፣ ማዛመጃዎችን ያስተካክሉ፣ እንደገና ያረጋግጡ። እዚህ ምንም ባለሥልጣን አይሆንም።'),
    $entry('design-imports.commit', 'design-imports', 30, 'Commit design imports', 'የንድፍ ማስመጫ ያጽድቁ',
        'Commit a validated batch: rows become draft design records through the ordinary services.', 'የተረጋገጠ ባች ያጽድቁ፡ ረድፎች በመደበኛ አገልግሎቶች በኩል ረቂቅ የንድፍ መዝገቦች ይሆናሉ።'),

    $entry('design-suggestions.view', 'design-suggestions', 10, 'View document suggestions', 'የሰነድ ጥቆማዎችን ይመልከቱ',
        'See what the document assistant suggested from an uploaded source document, with its uncertainty marks.', 'የሰነድ ረዳቱ ከተጫነ የምንጭ ሰነድ የጠቆመውን ከእርግጠኛ ያልሆኑ ምልክቶቹ ጋር ይመልከቱ።'),
    $entry('design-suggestions.apply', 'design-suggestions', 20, 'Apply document suggestions', 'የሰነድ ጥቆማዎችን ይተግብሩ',
        'Accept a suggestion into a draft record, or reject it. Nothing is published from a suggestion.', 'ጥቆማን ወደ ረቂቅ መዝገብ ይቀበሉ፣ ወይም ውድቅ ያድርጉ። ከጥቆማ ምንም ይፋ አይሆንም።'),
];
