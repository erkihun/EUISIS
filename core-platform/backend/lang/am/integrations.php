<?php

declare(strict_types=1);

return [
    'errors' => [
        'application_required' => 'የተመዘገበ መተግበሪያ ይምረጡ።',
        'event_types_required' => 'ቢያንስ አንድ የክስተት ዓይነት ይምረጡ።',
        'event_types_not_allowed' => 'የመተግበሪያው ስኮፖች እነዚህን አይፈቅዱም፦ :types።',
        'reason_required' => 'እንደገና የሚላክበትን ምክንያት ይስጡ።',
        'subscription_inactive' => 'እንደገና ከመላክዎ በፊት ምዝገባውን ያንቁ።',
        'replay_criteria_required' => 'ያልተሳኩ አቅርቦቶችን፣ የጊዜ ክልልን ወይም የክስተት መለያዎችን ይምረጡ።',
        'replay_too_large' => 'ከ:max በላይ ክስተቶች ይዛመዳሉ። ክልሉን ያጥብቡ።',
        'url_invalid_url' => 'ትክክለኛ URL ያስገቡ።',
        'url_url_too_long' => 'URL በጣም ረጅም ነው።',
        'url_https_required' => 'የመቀበያ አድራሻው HTTPS መጠቀም አለበት።',
        'url_credentials_in_url' => 'በURL ውስጥ የመግቢያ መረጃ አያስገቡ።',
        'url_port_not_allowed' => 'ይህ ፖርት አይፈቀድም።',
        'url_destination_not_allowed' => 'ይህ መድረሻ የግል፣ የራስ፣ የአካባቢ አገናኝ ወይም የሜታዳታ አድራሻ ስለሆነ አይፈቀድም።',
        'url_unresolvable_host' => 'የአስተናጋጁ ስም አልተገኘም።',
    ],
    'flash' => [
        'created' => 'ምዝገባው ተፈጥሯል። የመፈረሚያ ሚስጥሩን አሁን ይቅዱ — አንድ ጊዜ ብቻ ይታያል።',
        'updated' => 'ምዝገባው ተሻሽሏል።',
        'rotated' => 'ሚስጥሩ ተቀይሯል። አዲሱን አሁን ይቅዱ — የቀድሞው የሽግግሩ ጊዜ እስኪያልቅ ይፈርማል።',
        'status_active' => 'ምዝገባው ነቅቷል።',
        'status_disabled' => 'ምዝገባው ቆሟል። ታሪኩ ይጠበቃል።',
        'test_ok' => 'የሙከራ ክስተቱ ተቀባይነት አግኝቷል (HTTP :status፣ :ms ሚሊሰከንድ)።',
        'test_failed' => 'የሙከራ ክስተቱ አልተሳካም (HTTP :status፣ :category)።',
        'replayed' => ':count ክስተት(ቶች) እንደገና ለመላክ ወረፋ ገብተዋል።',
    ],
];
