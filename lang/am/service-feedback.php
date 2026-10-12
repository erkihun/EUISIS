<?php

declare(strict_types=1);

/*
 * የሕዝብ የደንበኛ አገልግሎት ግብረመልስ — ማንነቱ ያልታወቀ ጎብኚ የሚያነባቸው መልዕክቶች።
 * ከ lang/en/service-feedback.php ጋር ይመሳሰላል።
 */
return [
    'service_not_provided' => 'ይህ አገልግሎት በዚህ ባለሙያ አይሰጥም።',
    'form_replayed' => 'ይህ ቅጽ አስቀድሞ ገብቷል። ሌላ ምላሽ ለመላክ ገጹን እንደገና ይክፈቱ።',
    'category_not_applicable' => 'ይህ ምድብ ለዚህ ማቅረብ አይተገበርም።',

    // የምድብ አስተዳደር
    'category_saved' => 'ምድቡ ተቀምጧል።',
    'category_retired' => 'ማቅረቦች ይህን ምድብ ስለሚጠቅሱ ከመሰረዝ ይልቅ ተሰናብቷል።',
    'category_deleted' => 'ምድቡ ተሰርዟል።',
    'category_code_taken' => 'በዚህ ወሰን ውስጥ ይህ ኮድ ያለው ምድብ አስቀድሞ አለ።',

    // የተቋም የአስተያየት QR አስተዳደር
    'qrGenerated' => 'የተቋሙ የአስተያየት QR ነቅቷል።',
    'qrDisabled' => 'የተቋሙ የአስተያየት QR ታግዷል፤ ስካኖች የማይገኝ ገጽን ያሳያሉ።',
    'qrEnabled' => 'የተቋሙ የአስተያየት QR እንደገና ነቅቷል።',
    'qrRotated' => 'አዲስ የተቋም የአስተያየት QR ተሰጥቷል፤ የቀድሞው ኮድ ተሰርዟል።',
    'qrRevoked' => 'የተቋሙ የአስተያየት QR ተሰርዟል።',

    // የሥራ ፍሰት፣ ምደባ እና መስመር
    'status_changed' => 'ሁኔታው ተዘምኗል።',
    'transition_not_allowed' => 'ለዚህ የማቅረብ ዓይነት ይህ ሁኔታ አሁን ካለው ሁኔታ ሊከተል አይችልም።',
    'assigned' => 'ማቅረቡ ለአስተናጋጁ ተሰጥቷል።',
    'handler_invalid' => 'ያለ አስተናጋጅ ይምረጡ።',
    'handler_out_of_scope' => 'አስተናጋጁ የማቅረቡ ተቋም አባል መሆን አለበት።',
    'handler_is_target' => 'ማቅረቡ የሚመለከተው ሠራተኛ ሊያስተናግደው አይችልም።',
    'not_assignable' => 'ውጤቱ የተመዘገበ ማቅረብ እንደገና ሊመደብ አይችልም።',
    'rule_saved' => 'የመስመር ደንቡ ተቀምጧል።',
    'rule_retired' => 'ማቅረቦች በዚህ ደንብ ስለተመሩ ከመሰረዝ ይልቅ ተሰናብቷል።',
    'rule_deleted' => 'የመስመር ደንቡ ተሰርዟል።',
    'category_out_of_scope' => 'ምድቡ ከተማ አቀፍ ወይም የደንቡ ተቋም መሆን አለበት።',
    'settings_saved' => 'የሕዝብ አስተያየት ፖሊሲው ተቀምጧል።',
    'export_queued' => 'ወደ ውጭ መላክ ተሰልፏል። ፋይሉ ሲዘጋጅ ከታች ባለው ዝርዝር ውስጥ ይታያል።',
    'subject_added' => 'ሠራተኛው እንደ የምርመራ ተሳታፊ ተሰይሟል።',
    'subject_removed' => 'የምርመራ ተሳታፊው ተወግዷል።',
    'subject_not_in_organization' => 'የዚህ ማቅረብ ተቋም ንቁ ሠራተኛ ይምረጡ።',
    'subject_is_target' => 'ቅሬታው የሚመለከተው ሠራተኛ ዒላማው እንጂ ተሳታፊ አይደለም።',
    'subject_duplicate' => 'ይህ ሠራተኛ አስቀድሞ ተሰይሟል።',
    'referral_requested' => 'የዲሲፕሊን ማስተላለፍ ተጠይቋል።',
    'referral_decided' => 'የማስተላለፍ ውሳኔው ተመዝግቧል።',
    'referral_not_reviewed' => 'ቅሬታው ከተገመገመ በኋላ ማስተላለፍ ሊጠየቅ ይችላል።',
    'referral_open_exists' => 'የማስተላለፍ ጥያቄ አስቀድሞ ውሳኔ እየጠበቀ ነው።',
    'referral_already_decided' => 'ይህ የማስተላለፍ ጥያቄ አስቀድሞ ተወስኗል።',
    'referral_already_accepted' => 'ለዚህ ቅሬታ የተቀበለ ማስተላለፍ አስቀድሞ አለ።',

    'export_columns' => [
        'submitted_at' => 'የገባበት ጊዜ',
        'reference' => 'ማጣቀሻ',
        'type' => 'ዓይነት',
        'target_type' => 'የዒላማ ዓይነት',
        'target_name' => 'ዒላማ',
        'organization' => 'ተቋም',
        'organization_unit' => 'ክፍል',
        'category' => 'ምድብ',
        'status' => 'ሁኔታ',
        'handler' => 'አስተናጋጅ',
        'client_name' => 'የአቅራቢ ስም',
        'client_contact' => 'የአቅራቢ መገናኛ',
        'subject' => 'ርዕስ',
        'message' => 'መልዕክት',
    ],

    'types' => [
        'feedback' => 'አስተያየት',
        'suggestion' => 'ጥቆማ',
        'complaint' => 'ቅሬታ',
    ],

    'notifications' => [
        'open' => 'ማቅረቡን ይክፈቱ',
        'unrouted_title' => 'የሕዝብ ማቅረብ አስተናጋጅ ይፈልጋል',
        'unrouted' => 'የሕዝብ :type :reference ከየትኛውም የመስመር ደንብ ጋር አልተዛመደም፤ አስተናጋጅ እየጠበቀ ነው።',
        'assigned_title' => 'የሕዝብ ማቅረብ ለእርስዎ ተመድቧል',
        'assigned' => 'የሕዝብ :type :reference ለእርስዎ ወይም ለቡድንዎ ተሰጥቷል።',
        'resolved_title' => 'የሕዝብ ማቅረብ ውጤት ተመዝግቧል',
        'resolved' => 'ለሕዝብ :type :reference ውጤት ተመዝግቧል።',
        'referred_title' => 'የዲሲፕሊን ማስተላለፍ ተጠይቋል',
        'referred' => 'ለሕዝብ :type :reference የዲሲፕሊን ማስተላለፍ ተጠይቋል፤ ውሳኔ እየጠበቀ ነው።',
    ],

    'acknowledgement' => [
        'subject' => ':type ደርሶናል',
        'greeting' => 'እናመሰግናለን።',
        'received' => ':type በ :time ደርሶናል።',
        'reference' => 'የማጣቀሻ ቁጥር፦ :reference። ለማንኛውም ክትትል እባክዎ ያስቀምጡት።',
        'no_tracking' => 'ይህ መልዕክት መድረሱን ብቻ ያረጋግጣል፤ ተቋሙ በውስጥ ይሠራበታል።',
    ],

    'validation' => [
        'service_required' => 'እባክዎ ያገኙትን አገልግሎት ይምረጡ።',
        'rating_required' => 'እባክዎ የእርካታ ደረጃ ይምረጡ።',
        'rating_range' => 'እባክዎ ከ1 እስከ 5 ኮከብ ያለ ደረጃ ይምረጡ።',
        'message_required' => 'እባክዎ ጥቆማዎን ወይም ቅሬታዎን ይግለጹ።',
        'attachments_max' => 'እስከ 3 ፋይሎች ብቻ ማያያዝ ይችላሉ።',
        'attachment_type' => 'PDF፣ JPEG እና PNG ፋይሎች ብቻ ይቀበላሉ።',
        'attachment_size' => 'እያንዳንዱ ፋይል 5 MB ወይም ከዚያ በታች መሆን አለበት።',
        'incident_date_future' => 'የክስተቱ ቀን ወደፊት ሊሆን አይችልም።',
        'name_required' => 'እባክዎ ስምዎን ያስገቡ።',
        'contact_required' => 'እንድንደርስዎ እባክዎ ስልክ ቁጥር ወይም ኢሜይል አድራሻ ያስገቡ።',
        'contact_invalid' => 'እባክዎ ትክክለኛ ስልክ ቁጥር ወይም ኢሜይል አድራሻ ያስገቡ።',
        'attachments_disabled' => 'በአሁኑ ጊዜ ፋይሎች ማያያዝ አይቻልም።',
    ],
];
