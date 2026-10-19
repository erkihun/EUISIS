<?php

declare(strict_types=1);

/*
 * Addis One Intelligence grants (docs/intelligence-architecture.md).
 *
 * One module, two separated security domains: the business areas read the
 * domain aggregates a viewer's organization scope allows; the technical areas
 * read platform telemetry and never an HR figure. A grant says WHAT a viewer
 * may open; OrganizationScopeService says WHERE.
 */
$entry = static fn (string $name, int $sort, string $en, string $am, string $descEn, string $descAm): array => [
    'name' => $name, 'group' => 'intelligence', 'sort_order' => $sort, 'is_system' => true,
    'label_en' => $en, 'label_am' => $am, 'description_en' => $descEn, 'description_am' => $descAm,
];

return [
    $entry('intelligence.view', 10, 'Open Addis One Intelligence', 'የAddis One ብልህ ትንተናን ይክፈቱ',
        'Open the Intelligence launcher and the Overview. Each area needs its own grant.',
        'የብልህ ትንተና ማስጀመሪያውን እና አጠቃላይ እይታውን መክፈት ያስችላል። እያንዳንዱ ክፍል የራሱን ፈቃድ ይፈልጋል።'),

    // ── Business / management intelligence ──────────────────────────────
    $entry('intelligence.executive.view', 20, 'View executive intelligence', 'የአመራር ብልህ ትንተናን ይመልከቱ',
        'View priority business risks, trends and recommended management actions within the viewer\'s scope.',
        'በተመልካቹ ወሰን ውስጥ ቅድሚያ የሚሰጣቸውን የሥራ ስጋቶች፣ አዝማሚያዎች እና የሚመከሩ የአመራር እርምጃዎችን ማየት ያስችላል።'),
    $entry('intelligence.workforce.view', 30, 'View workforce intelligence', 'የሰው ኃይል ብልህ ትንተናን ይመልከቱ',
        'View staffing gaps, vacancies, approved headcount against required FTE, leadership vacancies and assignment anomalies as aggregates.',
        'የሠራተኛ ክፍተቶችን፣ ክፍት ቦታዎችን፣ የጸደቀ የሠራተኛ ብዛትን ከሚያስፈልገው ሙሉ ጊዜ አቻ ጋር፣ የአመራር ክፍት ቦታዎችን እና የምደባ ልዩነቶችን በድምር ማየት ያስችላል።'),
    $entry('intelligence.service.view', 40, 'View service intelligence', 'የአገልግሎት ብልህ ትንተናን ይመልከቱ',
        'View service volume, backlog, delay and complaint rates where an authoritative service linkage exists.',
        'ትክክለኛ የአገልግሎት ትስስር ባለበት የአገልግሎት መጠንን፣ የተከማቸ ሥራን፣ መዘግየትን እና የቅሬታ መጠንን ማየት ያስችላል።'),
    $entry('intelligence.public-voice.view', 50, 'View public voice intelligence', 'የሕዝብ ድምፅ ብልህ ትንተናን ይመልከቱ',
        'View Feedback, Suggestion and Complaint trends and anomalies by organization, unit, service and category. Never a submitter or a narrative.',
        'የአስተያየት፣ የጥቆማ እና የቅሬታ አዝማሚያዎችን እና ልዩነቶችን በተቋም፣ በክፍል፣ በአገልግሎት እና በምድብ ማየት ያስችላል። አቅራቢን ወይም ዝርዝር ጽሑፍን አያሳይም።'),
    $entry('intelligence.performance.view', 60, 'View performance intelligence', 'የአፈጻጸም ብልህ ትንተናን ይመልከቱ',
        'View EPMS cycle progress, overdue workflow steps, completion trends and reporting gaps using the EPMS weighting rules.',
        'የአፈጻጸም ዑደት ሂደትን፣ ጊዜያቸው ያለፈ የሥራ ፍሰት ደረጃዎችን፣ የማጠናቀቅ አዝማሚያዎችን እና የሪፖርት ክፍተቶችን በአፈጻጸም ሥርዓቱ የክብደት ሕግ መሠረት ማየት ያስችላል።'),
    $entry('intelligence.forecasting.view', 70, 'View forecasts', 'ትንበያዎችን ይመልከቱ',
        'View forecast snapshots with their method, data window, confidence and limitations.',
        'ትንበያዎችን ከዘዴያቸው፣ ከመረጃ ጊዜያቸው፣ ከእርግጠኝነታቸው እና ከገደባቸው ጋር ማየት ያስችላል።'),
    $entry('intelligence.data-quality.view', 80, 'View data quality intelligence', 'የመረጃ ጥራት ብልህ ትንተናን ይመልከቱ',
        'View detected data quality issues with what, where, count, impact, evidence and remediation.',
        'የተገኙ የመረጃ ጥራት ችግሮችን ከምንነታቸው፣ ከቦታቸው፣ ከብዛታቸው፣ ከተጽዕኖአቸው፣ ከማስረጃቸው እና ከመፍትሔያቸው ጋር ማየት ያስችላል።'),
    $entry('intelligence.knowledge.view', 90, 'Use the Knowledge Assistant', 'የዕውቀት ረዳቱን ይጠቀሙ',
        'Ask questions answered only from approved Addis One knowledge sources the viewer may read, with source references.',
        'ተመልካቹ ማንበብ ከሚችላቸው የጸደቁ የAddis One የዕውቀት ምንጮች ብቻ ከምንጭ ማጣቀሻ ጋር መልስ የሚያገኙ ጥያቄዎችን መጠየቅ ያስችላል።'),

    // ── System health / diagnostic intelligence ─────────────────────────
    $entry('intelligence.system-health.view', 100, 'View system health', 'የሥርዓት ጤንነትን ይመልከቱ',
        'View the health of the application, queues, database, cache, storage, scheduler, backups and integrations. No HR data.',
        'የመተግበሪያውን፣ የወረፋዎችን፣ የመረጃ ቋቱን፣ የመሸጎጫውን፣ የማከማቻውን፣ የመርሐግብሩን፣ የመጠባበቂያዎችን እና የውህደቶችን ጤንነት ማየት ያስችላል። የሰው ኃይል መረጃ የለም።'),
    $entry('intelligence.incidents.view', 110, 'View incidents', 'ክስተቶችን ይመልከቱ',
        'View detected technical incidents with their severity, timeline, evidence and recommendations.',
        'የተገኙ የቴክኒክ ክስተቶችን ከክብደታቸው፣ ከጊዜ መስመራቸው፣ ከማስረጃቸው እና ከምክረ ሐሳባቸው ጋር ማየት ያስችላል።'),
    $entry('intelligence.incidents.manage', 115, 'Manage incidents', 'ክስተቶችን ያስተዳድሩ',
        'Change an incident\'s status and owner and record its resolution and verification.',
        'የክስተትን ሁኔታ እና ባለቤት መቀየር እንዲሁም መፍትሔውን እና ማረጋገጫውን መመዝገብ ያስችላል።'),
    $entry('intelligence.diagnostics.view', 120, 'View technical diagnostics', 'የቴክኒክ ምርመራዎችን ይመልከቱ',
        'View error fingerprints, endpoints, service areas, query behaviour and deployment correlation. Secrets and personal data stay redacted.',
        'የስህተት ፊርማዎችን፣ የመዳረሻ ነጥቦችን፣ የአገልግሎት ክፍሎችን፣ የመጠይቅ ባህሪን እና የዝርጋታ ትስስርን ማየት ያስችላል። ሚስጥሮች እና የግል መረጃ ተደብቀው ይቆያሉ።'),
    $entry('intelligence.application-health.view', 130, 'View application performance', 'የመተግበሪያ አፈጻጸምን ይመልከቱ',
        'View HTTP error rates and latency by route group over time.',
        'የኤችቲቲፒ ስህተት መጠንን እና መዘግየትን በመስመር ቡድን በጊዜ ሂደት ማየት ያስችላል።'),
    $entry('intelligence.database-health.view', 140, 'View database health', 'የመረጃ ቋት ጤንነትን ይመልከቱ',
        'View database response time, connection usage and slow-query patterns where the database exposes them. Query parameters are never shown.',
        'የመረጃ ቋት ምላሽ ጊዜን፣ የግንኙነት አጠቃቀምን እና የዘገዩ መጠይቆች ዘይቤን የመረጃ ቋቱ በሚያሳይበት መጠን ማየት ያስችላል። የመጠይቅ መለኪያዎች በጭራሽ አይታዩም።'),
    $entry('intelligence.queue-health.view', 150, 'View queue and cache health', 'የወረፋ እና የመሸጎጫ ጤንነትን ይመልከቱ',
        'View pending and failed jobs, oldest job age, worker activity and cache or Redis availability.',
        'በመጠባበቅ ላይ ያሉ እና ያልተሳኩ ሥራዎችን፣ የቆየውን ሥራ ዕድሜ፣ የሠራተኛ ሂደት እንቅስቃሴን እና የመሸጎጫ ወይም የሬዲስ መገኘትን ማየት ያስችላል።'),
    $entry('intelligence.integration-health.view', 160, 'View integration health', 'የውህደት ጤንነትን ይመልከቱ',
        'View success, failure, latency and last successful call of e-mail, SMS, external API and backup integrations. Never a credential.',
        'የኢሜይል፣ የSMS፣ የውጭ API እና የመጠባበቂያ ውህደቶችን ስኬት፣ ውድቀት፣ መዘግየት እና የመጨረሻ የተሳካ ጥሪ ማየት ያስችላል። ምስጢር ቁልፍ በጭራሽ አይታይም።'),
    $entry('intelligence.deployment-analysis.view', 170, 'View deployment analysis', 'የዝርጋታ ትንተናን ይመልከቱ',
        'View recorded releases and how incidents, new error signatures and latency changes line up with them.',
        'የተመዘገቡ ልቀቶችን እና ክስተቶች፣ አዲስ የስህተት ፊርማዎች እና የመዘግየት ለውጦች ከእነርሱ ጋር እንዴት እንደሚጣጣሙ ማየት ያስችላል።'),
    $entry('intelligence.recommendations.view', 180, 'View recommendations', 'ምክረ ሐሳቦችን ይመልከቱ',
        'View recorded recommendations with their source, evidence, confidence and review status.',
        'የተመዘገቡ ምክረ ሐሳቦችን ከምንጫቸው፣ ከማስረጃቸው፣ ከእርግጠኝነታቸው እና ከግምገማ ሁኔታቸው ጋር ማየት ያስችላል።'),
    $entry('intelligence.recommendations.review', 185, 'Review recommendations', 'ምክረ ሐሳቦችን ይገምግሙ',
        'Mark a recommendation useful, not useful, accepted, rejected or an incorrect diagnosis.',
        'አንድን ምክረ ሐሳብ ጠቃሚ፣ የማይጠቅም፣ ተቀባይነት ያገኘ፣ ውድቅ የተደረገ ወይም የተሳሳተ ምርመራ ብሎ ምልክት ማድረግ ያስችላል።'),
    $entry('intelligence.runbooks.view', 190, 'View runbooks', 'የአሠራር መመሪያዎችን ይመልከቱ',
        'Read the step-by-step technical runbooks.',
        'ደረጃ በደረጃ የቴክኒክ አሠራር መመሪያዎችን ማንበብ ያስችላል።'),
    $entry('intelligence.runbooks.execute', 195, 'Execute approved runbook actions', 'የጸደቁ የአሠራር እርምጃዎችን ያስፈጽሙ',
        'Run an allow-listed, audited runbook action after explicit confirmation. Disabled until runbook execution is enabled in settings.',
        'ግልጽ ማረጋገጫ ከተሰጠ በኋላ በፈቃድ ዝርዝር ውስጥ ያለ እና የሚመዘገብ የአሠራር እርምጃ ማስኬድ ያስችላል። በቅንብሮች ውስጥ እስኪነቃ ድረስ ተሰናክሏል።'),

    // ── Governance ──────────────────────────────────────────────────────
    $entry('intelligence.governance.view', 200, 'View AI governance', 'የሰው ሠራሽ ብልህነት አስተዳደርን ይመልከቱ',
        'View the configured provider and model, feature status, prompt versions, data sources, evaluations and the AI run log.',
        'የተዋቀረውን አቅራቢ እና ሞዴል፣ የባህሪያት ሁኔታን፣ የጥያቄ ቅጽ ስሪቶችን፣ የመረጃ ምንጮችን፣ ግምገማዎችን እና የሰው ሠራሽ ብልህነት አሂድ መዝገብን ማየት ያስችላል።'),
    $entry('intelligence.governance.manage', 205, 'Manage AI governance', 'የሰው ሠራሽ ብልህነት አስተዳደርን ያስተዳድሩ',
        'Approve knowledge documents, record evaluations and retire prompt versions. Provider secrets stay in the environment.',
        'የዕውቀት ሰነዶችን ማጽደቅ፣ ግምገማዎችን መመዝገብ እና የጥያቄ ቅጽ ስሪቶችን ማስወገድ ያስችላል። የአቅራቢ ምስጢሮች በአካባቢ ውቅር ውስጥ ይቆያሉ።'),

    // The settings group is edited on System Settings, under that module's own grant.
    ['name' => 'system-settings.manageIntelligence', 'group' => 'system-settings', 'sort_order' => 117, 'is_system' => true,
        'label_en' => 'Manage Intelligence settings', 'label_am' => 'የብልህ ትንተና ቅንብሮችን ያስተዳድሩ',
        'description_en' => 'Change AI feature switches, provider alias, limits, detection thresholds, baseline windows and retention. Secrets are never shown.',
        'description_am' => 'የሰው ሠራሽ ብልህነት ባህሪ ማብሪያዎችን፣ የአቅራቢ ስምን፣ ገደቦችን፣ የማወቂያ ወሰኖችን፣ የመነሻ መስኮቶችን እና የማቆያ ጊዜን መቀየር ያስችላል። ምስጢሮች በጭራሽ አይታዩም።'],
];
