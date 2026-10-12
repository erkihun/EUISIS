<?php

declare(strict_types=1);

return [
    'title' => 'ግንኙነቶች',
    'reporting_lines' => 'የተጠሪነት መስመሮች',
    'target_types' => [
        'organization' => 'ተቋም',
        'institution_office' => 'የተቋም ጽሕፈት ቤት',
        'organization_unit' => 'የሥራ ክፍል',
    ],
    'types' => [
        'reports_to' => 'ሪፖርት ያቀርባል',
        'geographically_under' => 'በጂኦግራፊያዊ ወሰን ሥር',
        'service_scope' => 'የአገልግሎት ወሰን',
        'oversight' => 'ቁጥጥር',
        'structural_parent' => 'መዋቅራዊ የበላይ',
        'functional_reporting' => 'ተግባራዊ ሪፖርት',
        'technical_supervision' => 'ቴክኒካዊ ቁጥጥር',
        'administrative_reporting' => 'አስተዳደራዊ ሪፖርት',
        'coordination' => 'ማስተባበር',
        'service_delivery' => 'አገልግሎት አቅርቦት',
        'budget_reporting' => 'የበጀት ሪፖርት',
        'temporary_assignment' => 'ጊዜያዊ ምደባ',
        'dotted_line_reporting' => 'ተጨማሪ የተጠሪነት መስመር',
        'other' => 'ሌላ',
    ],
    'statuses' => [
        'active' => 'ንቁ',
        'inactive' => 'ንቁ ያልሆነ',
        'expired' => 'ጊዜው ያለፈ',
        'cancelled' => 'የተሰረዘ',
    ],
    'messages' => [
        'created' => 'ግንኙነት በተሳካ ሁኔታ ተፈጥሯል።',
        'updated' => 'ግንኙነት በተሳካ ሁኔታ ተዘምኗል።',
        'deleted' => 'ግንኙነት በተሳካ ሁኔታ ተሰርዟል።',
        'restored' => 'ግንኙነት በተሳካ ሁኔታ ተመልሷል።',
    ],
    'validation' => [
        'target_not_found' => 'የተመረጠው የግንኙነት መዳረሻ አልተገኘም።',
        'invalid_effective_dates' => 'የማብቂያ ቀን ከመጀመሪያ ቀን በኋላ ወይም እኩል መሆን አለበት።',
        'only_one_primary_structural_parent' => 'አንድ ንቁ ዋና መዋቅራዊ የበላይ ብቻ ይፈቀዳል።',
        'structural_cycle' => 'መዋቅራዊ ግንኙነት ዑደት መፍጠር አይችልም።',
        'invalid_structural_target' => 'ይህን የመዳረሻ ዓይነት እንደ መዋቅራዊ የበላይ መጠቀም አይቻልም።',
        'invalid_unit_relationship_target' => 'የሥራ ክፍል ግንኙነት ወደ ተቋም ወይም ወደ ሌላ የሥራ ክፍል ብቻ መሆን አለበት።',
        'duplicate_active_relationship' => 'ለዚህ ምንጭ፣ መዳረሻ እና የግንኙነት ዓይነት ንቁ ግንኙነት ቀድሞውኑ አለ።',
    ],
];
