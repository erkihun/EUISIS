<?php

declare(strict_types=1);

/*
 * የAddis One ብልህ ትንተና — የገጾቹ የጋራ መዝገበ ቃላት (App\Services\Intelligence\IntelligenceLabels)።
 * የክፍል ፓነሎች፣ ቁልፍ አመልካቾች እና ግንዛቤዎች በ intelligence_business.php እና
 * intelligence_system.php ውስጥ ናቸው።
 */
return [
    'title' => 'የAddis One ብልህ ትንተና',

    'ui' => [
        'insights' => 'ግንዛቤዎች',
        'no_insights' => 'በተመረጠው ጊዜ እና ወሰን በዚህ እይታ ውስጥ ያልተለመደ ነገር የለም።',
        'current' => 'አሁን',
        'baseline' => 'መነሻ',
        'change' => 'ለውጥ',
        'recommendation' => 'የሚመከር ቀጣይ እርምጃ',
        'view_evidence' => 'ማስረጃ ይመልከቱ',
        'open_detail' => 'ዝርዝር ይክፈቱ',
        'source' => 'ምንጭ',
        'confidence' => 'እርግጠኝነት',
        'generated_at' => 'የተሰላበት',
        'data_through' => 'መረጃ እስከ',
    ],

    'severity' => [
        'critical' => 'እጅግ አሳሳቢ',
        'high' => 'ከፍተኛ',
        'medium' => 'መካከለኛ',
        'low' => 'ዝቅተኛ',
        'info' => 'መረጃ',
    ],

    'confidence' => [
        'high' => 'ከፍተኛ',
        'medium' => 'መካከለኛ',
        'low' => 'ዝቅተኛ',
        'high_help' => 'በርካታ ገለልተኛ ምልክቶች ይስማማሉ እና መረጃው ለመስኮቱ የተሟላ ነው።',
        'medium_help' => 'ምልክቶቹ ይስማማሉ ነገር ግን መስኮቱ አጭር ነው ወይም አንድ ምንጭ ይጎድላል።',
        'low_help' => 'አንድ ምልክት ወይም አጭር መስኮት፦ እንደሚፈተሽ መላምት ይውሰዱት።',
    ],

    'health' => [
        'healthy' => 'ጤናማ',
        'degraded' => 'የተዳከመ',
        'unhealthy' => 'ጤናማ ያልሆነ',
        'unknown' => 'ያልታወቀ',
    ],

    'reasons' => [
        'TELEMETRY_OFF' => ['title' => 'ምልክት መሰብሰብ ጠፍቷል', 'description' => 'የመድረክ ምልክት መሰብሰብ በሥርዓት ቅንብሮች › ብልህ ትንተና ውስጥ ጠፍቷል፤ ስለዚህ ለዚህ ቁጥር ምንም አይሰበሰብም።'],
        'UNKNOWN' => ['title' => 'አልተለካም', 'description' => 'ይህ ተከላ ይህን ምልክት አያሳይም፤ ስለዚህ ሁኔታው ጤናማ ተብሎ ከመገመት ይልቅ ያልታወቀ ነው።'],
        'NOT_CONFIGURED' => ['title' => 'አልተዋቀረም', 'description' => 'ለዚህ ሕግ ገደብ በሥርዓት ቅንብሮች › ብልህ ትንተና ውስጥ ያስቀምጡ። እስከዚያ ድረስ ሕጉ በመነሻው ላይ ብቻ ይመሠረታል።'],
        'SLO_CONFIGURATION_REQUIRED' => ['title' => 'ዓላማ አልተዋቀረም', 'description' => 'ለዚህ ተከላ የመዘግየት፣ የስህተት መጠን ወይም የመገኘት ዓላማ አልተወሰነም፤ ስለዚህ ተገዢነት አይታይም።'],
        'FORECAST_DATA_INSUFFICIENT' => ['title' => 'ለትንበያ በቂ ታሪክ የለም', 'description' => 'ትንበያ ቢያንስ ስድስት የተሟሉ ወርሃዊ ነጥቦችን ይፈልጋል፤ ይህ ተከታታይ ከዚያ ያነሰ ስላለው አይታይም።'],
        'AI_UNAVAILABLE' => ['title' => 'የሰው ሠራሽ ብልህነት ማብራሪያ አይገኝም', 'description' => 'የሰው ሠራሽ ብልህነት አቅራቢው ጠፍቷል ወይም በጊዜው አልመለሰም። ቁጥሮቹ በራሳቸው ይቆማሉ።'],
    ],

    'sources' => [
        'telemetry' => 'የመድረክ ምልክቶች (intelligence_signals)',
        'request_counters' => 'የHTTP ጥያቄ ቆጣሪዎች',
        'error_fingerprints' => 'የስህተት ፊርማዎች',
        'queue' => 'የወረፋ ሠንጠረዦች',
        'audit_log' => 'የኦዲት መዝገብ',
        'backups' => 'የመጠባበቂያ ሁኔታ',
        'integrations' => 'የውህደት አጠቃቀም መዝገቦች',
        'deployments' => 'የተመዘገቡ ዝርጋታዎች',
        'situation_room' => 'የሁኔታ መከታተያ ድምሮች',
        'workforce' => 'የሥራ መደቦች፣ ምሥረታዎች እና ምደባዎች',
        'public_submissions' => 'የሕዝብ ማቅረቢያዎች',
        'epms' => 'የአፈጻጸም አስተዳደር (EPMS)',
        'daily_activity' => 'የዕለት ተግባር መመዝገቢያ',
        'data_quality' => 'የመረጃ ጥራት ፍተሻዎች',
        'forecasts' => 'የትንበያ ቅጽበታዊ ምስሎች',
    ],

    'evidence' => [
        'window' => 'መስኮት',
        'samples' => 'ናሙናዎች',
        'baseline_window' => 'የመነሻ መስኮት',
        'threshold' => 'የተዋቀረ ገደብ',
        'count' => 'ብዛት',
        'previous' => 'ያለፈው ጊዜ',
        'first_seen' => 'መጀመሪያ የታየበት',
        'last_seen' => 'መጨረሻ የታየበት',
        'component' => 'አካል',
        'route' => 'የመስመር ቡድን',
    ],
];
