<?php

declare(strict_types=1);

/*
 * Starter form, entered from the bureau's Word templates (accountability,
 * institution building, technology-supported services, service standard).
 *
 * Generalised on purpose (docs/service-monitoring.md §Starter form):
 *  - no "sub-city" / "woreda" / "city" rows or column groups: every
 *    institution reports for itself and the summary groups institutions by
 *    organization type;
 *  - no fiscal year in the text: the year comes from the reporting period;
 *  - the stamped copy is uploaded with the report instead of being sent
 *    over Telegram or WhatsApp.
 *
 * It is seeded as a draft. Administrators assign organization types, adjust
 * it in the builder and publish it; they own it from then on.
 */
$role = static fn (): array => [
    ['key' => 'appointed', 'label' => 'ሹመኛ አመራር', 'type' => 'check', 'group' => 'role'],
    ['key' => 'merit', 'label' => 'ሜሪት አመራር /ዳይሬክተር ቡድን መሪ/', 'type' => 'check', 'group' => 'role'],
    ['key' => 'staff', 'label' => 'ባለሙያ /ሰራተኛ/', 'type' => 'check', 'group' => 'role'],
    ['key' => 'client', 'label' => 'ተገልጋይ', 'type' => 'check', 'group' => 'role'],
];

$comparison = static fn (string $prefix, string $group): array => [
    ['key' => "{$prefix}_above", 'label' => 'ከስታንዳርድ በላይ (+) በአዎንታ', 'type' => 'number', 'group' => $group],
    ['key' => "{$prefix}_at", 'label' => 'በስታንዳርድ (=)', 'type' => 'number', 'group' => $group],
    ['key' => "{$prefix}_below", 'label' => 'ከስታንዳርድ በታች (-) በአሉታ', 'type' => 'number', 'group' => $group],
];

return [
    'form' => [
        'code' => 'SERVICE-DELIVERY-QUARTERLY',
        'name_am' => 'የአገልግሎት አሰጣጥ ክትትልና ድጋፍ መረጃ መሰብሰቢያ ቅጽ',
        'name_en' => 'Service delivery monitoring and support data collection form',
        'description' => "ፐብሊክ ሰርቪስና የሰው ሀብት ልማት ቢሮ — የአገልግሎት አሰጣጥና አውትሶርሲንግ ዘርፍ — የአገልግሎት አሰጣጥ ክትትልና ድጋፍ ዳይሬክቶሬት።\nበየሩብ ዓመቱ (3፣ 6፣ 9 እና 12 ወር) የሚሰበሰብ።",
    ],
    'schema' => [
        'sections' => [
            [
                'key' => 'accountability',
                'title' => 'ክፍል አንድ — የተጠያቂነት ማጠቃለያ',
                'blocks' => [
                    [
                        'type' => 'table',
                        'key' => 'accountable_persons',
                        'title' => 'I. በአገልግሎት ማዘግየት፤ በሌብነትና ብልሹ አሰራር ተጠያቂ የተደረጉ አመራሮች፣ ፈጻሚዎችና ተገልጋዮች',
                        'description' => 'ተጠያቂ የተደረገ እያንዳንዱን ሰው በአንድ ረድፍ ያስገቡ። የስራ ድርሻውን በ"X" ምልክት ያመልክቱ።',
                        'row_mode' => 'dynamic',
                        'max_rows' => 500,
                        'show_totals' => true,
                        'column_groups' => [
                            ['key' => 'role', 'label' => 'ተጠያቂ የተደረገው የስራ ድርሻ (የ"X" ምልክት በማድረግ)'],
                        ],
                        'columns' => [
                            ['key' => 'office_name', 'label' => 'የተቋሙ / የጽ/ቤቱ ስም', 'type' => 'text'],
                            ['key' => 'full_name', 'label' => 'ተጠያቂ የተደረጉ አመራር/ባለሙያ ሙሉ ስም', 'type' => 'text', 'required' => true],
                            ['key' => 'reason', 'label' => 'ተጠያቂ የተደረጉበት ምክንያት በዝርዝር', 'type' => 'textarea', 'required' => true],
                            ['key' => 'action_taken', 'label' => 'የተወሰደ የእርምት እርምጃ በዝርዝር', 'type' => 'textarea', 'required' => true],
                            ...$role(),
                            ['key' => 'remarks', 'label' => 'ምርመራ', 'type' => 'text'],
                        ],
                    ],
                    [
                        'type' => 'table',
                        'key' => 'accountability_counts',
                        'title' => 'የተጠያቂነት የቁጥር መረጃ ማጠቃለያ',
                        'row_mode' => 'fixed',
                        'show_totals' => true,
                        'rows' => [
                            ['key' => 'warning', 'label' => 'የቃልና የጽሁፍ ማስጠንቀቂያ'],
                            ['key' => 'salary_penalty', 'label' => 'የደመወዝ ቅጣት'],
                            ['key' => 'demotion', 'label' => 'ከደረጃ ዝቅ የተደረጉ'],
                            ['key' => 'removed', 'label' => 'ከኃላፊነት የተነሱ'],
                            ['key' => 'dismissal', 'label' => 'ስንብት'],
                            ['key' => 'legal_action', 'label' => 'የህግ እርምጃ/ፍርድ የተሰጠበት'],
                            ['key' => 'other', 'label' => 'ሌላ ካለ'],
                        ],
                        'column_groups' => [],
                        'columns' => [
                            ['key' => 'appointed', 'label' => 'ሹመኛ /ሜሪት+ሹመት/', 'type' => 'number'],
                            ['key' => 'director', 'label' => 'ዳይሬክተር/ቡድን መሪ', 'type' => 'number'],
                            ['key' => 'staff', 'label' => 'ባለሙያ/ሰራተኛ', 'type' => 'number'],
                            ['key' => 'client', 'label' => 'ተገልጋይ', 'type' => 'number'],
                            ['key' => 'total', 'label' => 'ድምር', 'type' => 'sum', 'sum_of' => ['appointed', 'director', 'staff', 'client']],
                            ['key' => 'remarks', 'label' => 'ምርመራ', 'type' => 'text'],
                        ],
                    ],
                    [
                        'type' => 'note',
                        'key' => 'accountability_note',
                        'text' => "ማሳሰቢያ፡-\n• አንድ ሰራተኛ ሁለት አይነት ቅጣት ሊቀጣ የሚችልበት አጋጣሚ ስለሚኖር የመረጃ መጣረዝ እንዳይኖር ለመጨረሻ ጊዜ የተወሰደው እርምጃ ላይ በመመስረት ቅጹን ይሙሉ።\n• \"ዲስፕሊን እርምጃ\" ሂደት እንጂ ውሳኔ ሊሆን አይችልም፤ መረጃው ሲሞላ የመጨረሻ የተወሰደው እርምጃ ላይ ትኩረት ይደረግ።\n• ተገልጋይ ላይ የተወሰደ እርምጃ ካለ \"ሌላ ካለ\" በሚለው ረድፍ ምርመራ ላይ በዝርዝር ይቀመጥ።\n• በብዛት ተጠያቂ ያደረጉ ጽ/ቤቶች ለአብነት በምርመራ ላይ ይጠቀሱ።",
                    ],
                ],
            ],
            [
                'key' => 'institution_building',
                'title' => 'ክፍል ሁለት — የተቋም ግንባታ ስራዎች አፈፃፀም ማጠቃለያ',
                'blocks' => [
                    [
                        'type' => 'table',
                        'key' => 'facilities',
                        'title' => 'II. የተቋም ግንባታ ስራዎች አፈፃፀም',
                        'row_mode' => 'single',
                        'show_totals' => false,
                        'column_groups' => [
                            ['key' => 'waiting_area', 'label' => 'የባለጉዳይ ማረፍያ'],
                        ],
                        'columns' => [
                            ['key' => 'daycare', 'label' => 'የህጻናት ማቆያ ብዛት', 'type' => 'number'],
                            ['key' => 'cafeteria', 'label' => 'ካፍቴሪያ ብዛት', 'type' => 'number'],
                            ['key' => 'consumer_shop', 'label' => 'የሸማች ሱቅ ብዛት', 'type' => 'number'],
                            ['key' => 'waiting_area_count', 'label' => 'ቁጥር', 'type' => 'number', 'group' => 'waiting_area'],
                            ['key' => 'waiting_area_capacity', 'label' => 'የሚይዘው ሰው ብዛት', 'type' => 'number', 'group' => 'waiting_area'],
                            ['key' => 'suggestion_box', 'label' => 'ደረጃውን የጠበቀ አስተያየት መስጫ ሳጥን ብዛት', 'type' => 'number'],
                            ['key' => 'led_display', 'label' => 'ኤልዲ ብዛት', 'type' => 'number'],
                            ['key' => 'direction_sign', 'label' => 'አቅጣጫ ጠቋሚ ብዛት', 'type' => 'number'],
                            ['key' => 'total_staff', 'label' => 'አጠቃላይ ሰራተኛ ብዛት (ሹፌር ሳይጨምር)', 'type' => 'number'],
                            ['key' => 'chest_badge', 'label' => 'የደረት ባጅ ብዛት (ሹፌር ሳይጨምር)', 'type' => 'number'],
                            ['key' => 'desk_badge', 'label' => 'የጠረጴዛ ባጅ ብዛት', 'type' => 'number'],
                            ['key' => 'awareness_events', 'label' => 'የተገልጋይ ንቃተ-ህሊና ማሳደጊያ ስልጠና/መድረክ ብዛት', 'type' => 'number'],
                            ['key' => 'awareness_participants', 'label' => 'የመድረኩ ተሳታፊ ተገልጋይ ብዛት', 'type' => 'number'],
                        ],
                    ],
                    [
                        'type' => 'note',
                        'key' => 'facilities_note',
                        'text' => 'ማሳሰቢያ፡- ካፍቴሪያ፣ ሸማችና ህጻናት ማቆያ በበጀት ዓመቱ አዲስ የተደራጁ ብቻ ይካተታሉ።',
                    ],
                    [
                        'type' => 'text',
                        'key' => 'special_support',
                        'label' => 'ልዩ ድጋፍ የሚፈልጉ ተገልጋዮችን በተመለከተ፡- አገልግሎቱን በውጤታማ መልኩ ተደራሽ ለማድረግ የተሰራ ስራ ካለ በዝርዝር ይግለጹ',
                        'required' => false,
                    ],
                    [
                        'type' => 'note',
                        'key' => 'digital_note',
                        'text' => 'ማሳሰቢያ፡- የሚሞላው መረጃ በኢኖቬሽንና ቴክኖሎጂ ቢሮ የተረጋገጠና ተግባራዊ የሆነ ብቻ መሆን አለበት። "የአገልግሎት ስም" በቴክኖሎጂ ተደግፈው የሚሰጡ አገልግሎቶች ብቻ ከዋና አገልግሎት ጀምሮ ወደ ንዑስ እየተዘረዘረ ይቀመጣል፤ በዚህ በጀት ዓመት ወደ ተግባር የገቡ ብቻ ይካተታሉ።',
                    ],
                    [
                        'type' => 'table',
                        'key' => 'digital_overview',
                        'title' => 'III. በቴክኖሎጂ ተደግፈው የሚሰጡ አገልግሎቶች ማጠቃለያ',
                        'row_mode' => 'single',
                        'show_totals' => false,
                        'column_groups' => [
                            ['key' => 'digital', 'label' => 'በቴክኖሎጂ ተደግፈው የሚሰጡ /ዲጂታላይዝ የሆኑ/ አገልግሎቶች'],
                        ],
                        'columns' => [
                            ['key' => 'main_services', 'label' => 'የተቋሙ ዋና አገልግሎት ብዛት', 'type' => 'number'],
                            ['key' => 'sub_services', 'label' => 'የተቋሙ ንዑስ አገልግሎት ብዛት', 'type' => 'number'],
                            ['key' => 'digital_main', 'label' => 'ዋና አገልግሎቶች ብዛት', 'type' => 'number', 'group' => 'digital'],
                            ['key' => 'digital_sub', 'label' => 'ንዑስ አገልግሎቶች ብዛት', 'type' => 'number', 'group' => 'digital'],
                        ],
                    ],
                    [
                        'type' => 'table',
                        'key' => 'digital_services',
                        'title' => 'በቴክኖሎጂ ተደግፈው የሚሰጡ አገልግሎቶች ዝርዝር',
                        'row_mode' => 'dynamic',
                        'max_rows' => 300,
                        'show_totals' => true,
                        'column_groups' => [],
                        'columns' => [
                            ['key' => 'service_name', 'label' => 'የአገልግሎት ስም', 'type' => 'text', 'required' => true],
                            ['key' => 'technology', 'label' => 'የቴክኖሎጂው አይነት/የሲስተሙ ስም', 'type' => 'text', 'required' => true],
                            ['key' => 'system_count', 'label' => 'የሲስተም ብዛት', 'type' => 'number'],
                            ['key' => 'remarks', 'label' => 'ምርመራ', 'type' => 'text'],
                        ],
                    ],
                    [
                        'type' => 'table',
                        'key' => 'service_standard',
                        'title' => 'IV. የአገልግሎት አሰጣጥ ስታንዳርድ አፈፃፀም',
                        'row_mode' => 'single',
                        'show_totals' => false,
                        'column_groups' => [
                            ['key' => 'main_comparison', 'label' => 'ጥቅል የዋና አገልግሎት ስታንዳርድ አፈፃፀም ንፅፅር'],
                            ['key' => 'sub_comparison', 'label' => 'ጥቅል የንዑስ አገልግሎት ስታንዳርድ አፈፃፀም ንፅፅር'],
                        ],
                        'columns' => [
                            ['key' => 'main_services', 'label' => 'ዋና አገልግሎት ብዛት', 'type' => 'number'],
                            ['key' => 'sub_services', 'label' => 'የንዑስ አገልግሎት ብዛት', 'type' => 'number'],
                            ...$comparison('main', 'main_comparison'),
                            ...$comparison('sub', 'sub_comparison'),
                            ['key' => 'below_reason', 'label' => 'ከስታንዳርድ በታች ለሆነ አፈፃፀም ምክንያት', 'type' => 'textarea'],
                            ['key' => 'below_action', 'label' => 'ከስታንዳርድ በታች በመሰጠቱ የተወሰደ እርምጃ ለአብነት', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'type' => 'note',
                        'key' => 'general_note',
                        'text' => "ማሳሰቢያ፡-\n1. የስታንዳርድ ንጽጽርና ትንተና መረጃ ወቅታዊ በሆነው መረጃችሁ ተመስርታችሁ ይሙሉ።\n2. መረጃው በየሩብ ዓመቱ ይሰበሰባል፤ ከ6 ወር፣ ከ9 ወር እና ዓመታዊ መረጃዎች ጋር እንዳይጣረዝ የማናበብ እና የማረጋገጥ ስራ ይሰራ።\n3. በይዘቱ ዙሪያ የግልጸኝነት ችግር ካለ ደጋፊ ባለሙያዎችን ይጠይቁ።\n4. የመረጃ ለውጥ ከሌለ እንኳ \"የለም\" በሚል ሞልተው ያቅርቡ።\n5. ሪፖርቱን ከማቅረብዎ በፊት በተቋሙ ማህተም የተረጋገጠውን ቅጂ (PDF ወይም ፎቶ) ያያይዙ።\n6. ሪፖርቱ በወቅቱ መድረስ አለበት፤ የሪፖርት ጊዜውን ያልጠበቀ መረጃ ተቀባይነት አይኖረውም።",
                    ],
                ],
            ],
        ],
    ],
];
