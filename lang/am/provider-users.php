<?php

declare(strict_types=1);

return [
    'created' => 'የአቅራቢ ፖርታል መለያ ተፈጥሯል።',
    'updated' => 'የአቅራቢ ፖርታል መለያ ተሻሽሏል።',
    'suspended' => 'መለያው ታግዷል። ባለቤቱ በሚቀጥለው ጥያቄው ከአቅራቢ ፖርታል ይወጣል።',
    'activated' => 'መለያው ገቢር ሆኗል።',
    'password_reset' => 'የይለፍ ቃሉ ዳግም ተቀናብሯል። ባለቤቱ በሚቀጥለው መግቢያ አዲስ የይለፍ ቃል መምረጥ አለበት።',
    'deleted' => 'መለያው ተሰርዟል። ከተሰረዙ መለያዎች ዝርዝር መመለስ ይቻላል።',
    'restored' => 'መለያው ተመልሷል።',
    'email_or_username_required' => 'የኢሜይል አድራሻ ወይም የተጠቃሚ ስም ያስገቡ። መለያው ከሁለቱ በአንዱ ይገባል።',
    'permission_not_offered' => 'አንድ ወይም ከዚያ በላይ ፈቃዶች በዚህ አቅራቢ ገቢር አገልግሎቶች አይሰጡም።',

    'attributes' => [
        'provider' => 'አቅራቢ',
        'name' => 'ሙሉ ስም',
        'email' => 'የኢሜይል አድራሻ',
        'username' => 'የተጠቃሚ ስም',
        'phone_number' => 'ስልክ ቁጥር',
        'role' => 'ሚና',
        'permissions' => 'ፈቃዶች',
        'password' => 'የይለፍ ቃል',
    ],

    'legacy' => [
        'none' => 'የሚዛወሩ የቀድሞ የአቅራቢ ተጠቃሚ መለያዎች (service_provider_users) የሉም።',
        'migrated' => 'ተዛውሯል፦ :email',
        'skipped_existing' => 'ተዘሏል (በዚህ ኢሜይል ወይም የተጠቃሚ ስም የፖርታል መለያ አስቀድሞ አለ)፦ :email',
        'needs_decision' => 'NEEDS_DECISION፦ :email፦ :reason',
        'no_provider' => 'ከአገልግሎት አቅራቢው ጋር የሚዛመድ አቅራቢ አልተገኘም',
        'ambiguous_provider' => 'አገልግሎት አቅራቢው ከብዙ አቅራቢዎች ጋር ይዛመዳል',
        'no_sign_in' => 'የኢሜይል አድራሻም ሆነ የተጠቃሚ ስም የለውም',
        'summary' => ':migrated ተዛውረዋል፣ :skipped ተዘለዋል፣ :decisions ውሳኔ ይፈልጋሉ።',
        'dry_run' => 'ሙከራ ብቻ፦ ምንም አልተጻፈም። ለማዛወር በ --apply እንደገና ያስኪዱ።',
    ],
];
