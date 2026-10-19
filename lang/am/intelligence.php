<?php

declare(strict_types=1);

/*
 * የAddis One አስተውሎት — የገጾቹ የጋራ መዝገበ ቃላት (App\Services\Intelligence\IntelligenceLabels)።
 * የክፍል ፓነሎች፣ ቁልፍ አመልካቾች እና ግንዛቤዎች በ intelligence_business.php እና
 * intelligence_system.php ውስጥ ናቸው።
 */
return [
    'title' => 'የAddis One አስተውሎት',

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
        'TELEMETRY_OFF' => ['title' => 'ምልክት መሰብሰብ ጠፍቷል', 'description' => 'የመድረክ ምልክት መሰብሰብ በሥርዓት ቅንብሮች › አስተውሎት ውስጥ ጠፍቷል፤ ስለዚህ ለዚህ ቁጥር ምንም አይሰበሰብም።'],
        'UNKNOWN' => ['title' => 'አልተለካም', 'description' => 'ይህ ተከላ ይህን ምልክት አያሳይም፤ ስለዚህ ሁኔታው ጤናማ ተብሎ ከመገመት ይልቅ ያልታወቀ ነው።'],
        'NOT_CONFIGURED' => ['title' => 'አልተዋቀረም', 'description' => 'ለዚህ ሕግ ገደብ በሥርዓት ቅንብሮች › አስተውሎት ውስጥ ያስቀምጡ። እስከዚያ ድረስ ሕጉ በመነሻው ላይ ብቻ ይመሠረታል።'],
        'SLO_CONFIGURATION_REQUIRED' => ['title' => 'ዓላማ አልተዋቀረም', 'description' => 'ለዚህ ተከላ የመዘግየት፣ የስህተት መጠን ወይም የመገኘት ዓላማ አልተወሰነም፤ ስለዚህ ተገዢነት አይታይም።'],
        'FORECAST_DATA_INSUFFICIENT' => ['title' => 'ለትንበያ በቂ ታሪክ የለም', 'description' => 'ትንበያ ቢያንስ ስድስት የተሟሉ ወርሃዊ ነጥቦችን ይፈልጋል፤ ይህ ተከታታይ ከዚያ ያነሰ ስላለው አይታይም።'],
        'NEEDS_DEPLOYMENT_CONFIGURATION' => ['title' => 'የተመዘገበ ዝርጋታ የለም', 'description' => 'ልቀቶች ከልቀት ስክሪፕት በ `php artisan intelligence:record-deployment` ይመዘገባሉ። ምንም አልተመዘገበም፤ ስለዚህ ምንም አይተሳሰርም እና ምንም አይገመትም።'],
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

    'flash' => [
        'alert_updated' => 'ማንቂያው ተዘምኗል።',
        'incident_updated' => 'ክስተቱ ተዘምኗል።',
        'recommendation_reviewed' => 'ግምገማው ተመዝግቧል።',
        'explain_requested' => 'የሰው ሠራሽ ብልህነት ግምገማ ተጠይቋል፤ የጀርባ ወረፋው ሲያስኬደው በዚህ ገጽ ላይ ይታያል።',
        'ai_unavailable' => 'የሰው ሠራሽ ብልህነት ማብራሪያዎች አሁን አይገኙም (ጠፍቷል፣ አልተዋቀረም ወይም ከውድቀቶች በኋላ ቆሟል)።',
    ],

    'notifications' => [
        'open_alerts' => 'ማንቂያዎችን ይክፈቱ',
        'alert' => ['title' => 'አዲስ :severity የሥራ ማንቂያ', 'message' => 'በ :area ውስጥ ከተማ አቀፍ ማንቂያ ተከፍቷል። ማስረጃውን እና ምክረ ሐሳቡን ይገምግሙ።'],
        'open' => 'ክስተቱን ይክፈቱ',
        'opened' => ['title' => 'ክስተት :reference ተከፍቷል (:severity)', 'message' => 'በ :component ላይ የቴክኒክ ክስተት ተገኝቷል። ማስረጃውን እና ምክረ ሐሳቦቹን ይገምግሙ።'],
        'escalated' => ['title' => 'ክስተት :reference ወደ :severity ከፍ ብሏል', 'message' => 'በ :component ላይ ያለው ሁኔታ ከተገኘበት ጊዜ ጀምሮ ተባብሷል።'],
    ],

    'data_classes' => ['aggregates' => 'ድምር መረጃዎች', 'structured_signals' => 'የተዋቀሩ ምልክቶች', 'redacted_logs' => 'የተሰወሩ የስህተት ፊርማዎች', 'knowledge_documents' => 'የዕውቀት ሰነዶች', 'public_content' => 'የታተመ የሕዝብ ይዘት'],
    'sources_short' => ['rule' => 'ቆራጥ ደንቦች', 'ai' => 'የሰው ሠራሽ ብልህነት ረዳት'],
    'forecast_metrics' => ['complaints_monthly' => 'በወር ቅሬታዎች', 'vacancies_monthly' => 'በወር ክፍት የሥራ መደቦች'],
    'domain' => ['business' => 'የሥራ', 'technical' => 'የቴክኒክ'],
    'alert_type' => ['incident' => 'ክስተት', 'insight' => 'ግንዛቤ', 'data_quality' => 'የመረጃ ጥራት', 'forecast' => 'ትንበያ'],
    'alert_status' => ['open' => 'ክፍት', 'acknowledged' => 'የታወቀ', 'resolved' => 'የተፈታ'],
    'areas_short' => ['workforce' => 'የሰው ኃይል', 'public_voice' => 'የሕዝብ ድምፅ', 'performance' => 'አፈጻጸም', 'data_quality' => 'የመረጃ ጥራት', 'incidents' => 'ክስተቶች', 'executive' => 'አመራር', 'service' => 'አገልግሎት', 'forecasting' => 'ትንበያ'],

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
        'first_value' => 'የመጀመሪያ እሴት', 'last_value' => 'የመጨረሻ እሴት', 'slope_per_step' => 'በደረጃ ዝንባሌ', 'rising_steps_share' => 'የጨመሩ ደረጃዎች ድርሻ',
        'release' => 'ልቀት', 'deployed_at' => 'የተዘረጋበት', 'detected_at' => 'የተገኘበት',
        'route' => 'የመስመር ቡድን',
    ],
];
