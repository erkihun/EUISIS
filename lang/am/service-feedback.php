<?php

declare(strict_types=1);

/*
 * የሕዝብ የደንበኛ አገልግሎት ግብረ መልስ — ማንነቱ ያልታወቀ ጎብኚ የሚያነባቸው መልዕክቶች።
 * ከ lang/en/service-feedback.php ጋር ይመሳሰላል።
 */
return [
    'service_not_provided' => 'ይህ አገልግሎት በዚህ ባለሙያ አይሰጥም።',
    'form_replayed' => 'ይህ ቅጽ አስቀድሞ ገብቷል። ሌላ ምላሽ ለመላክ ገጹን እንደገና ይክፈቱ።',
    'submission_failed' => 'ያስገቡት መረጃ ሊቀመጥ አልቻለም፤ ምንም አልተቀመጠም። እባክዎ ከጥቂት ጊዜ በኋላ እንደገና ይሞክሩ።',
    'too_many_submissions' => 'ከዚህ መሣሪያ ብዙ መልዕክቶች ተልከዋል። እባክዎ ጥቂት ደቂቃዎች ጠብቀው እንደገና ይሞክሩ፤ የጻፉት በገጹ ላይ አለ።',
    'category_not_applicable' => 'ይህ ምድብ ለዚህ ማቅረብ አይተገበርም።',

    // የምድብ አስተዳደር
    'category_saved' => 'ምድቡ ተቀምጧል።',
    'category_retired' => 'ጉዳዮች ይህን ምድብ ስለሚጠቅሱ ከመሰረዝ ይልቅ ተሰናብቷል።',
    'category_deleted' => 'ምድቡ ተሰርዟል።',
    'category_code_taken' => 'በዚህ ወሰን ውስጥ ይህ ኮድ ያለው ምድብ አስቀድሞ አለ።',

    // የተቋም የአስተያየት QR አስተዳደር
    'qrGenerated' => 'የተቋሙ የአስተያየት QR ነቅቷል።',
    'qrDisabled' => 'የተቋሙ የአስተያየት QR ታግዷል፤ የተቃኙበት ብዛት የማይገኝ ገጽን ያሳያሉ።',
    'qrEnabled' => 'የተቋሙ የአስተያየት QR እንደገና ነቅቷል።',
    'qrRotated' => 'አዲስ የተቋም የአስተያየት QR ተሰጥቷል፤ የቀድሞው ኮድ ተሽሯል።',
    'qrRevoked' => 'የተቋሙ የአስተያየት QR ተሽሯል።',

    // የሥራ ፍሰት፣ ምደባ እና መስመር
    'status_changed' => 'ሁኔታው ተዘምኗል።',
    'transition_not_allowed' => 'ለዚህ የጉዳይ ዓይነት ይህ ሁኔታ አሁን ካለው ሁኔታ ሊከተል አይችልም።',
    'assigned' => 'ጉዳዩ ለየሚያስተናግደው አካል ተሰጥቷል።',
    'handler_invalid' => 'ያለ የሚያስተናግድ አካል ይምረጡ።',
    'handler_out_of_scope' => 'የሚያስተናግደው አካል የጉዳዩ ተቋም አባል መሆን አለበት።',
    'handler_is_target' => 'ጉዳዩ የሚመለከተው ሠራተኛ ሊያስተናግደው አይችልም።',
    'not_assignable' => 'ውጤቱ የተመዘገበ ማቅረብ እንደገና ሊመደብ አይችልም።',
    'rule_saved' => 'የመስመር ደንቡ ተቀምጧል።',
    'rule_retired' => 'ጉዳዮች በዚህ ደንብ ስለተመሩ ከመሰረዝ ይልቅ ተሰናብቷል።',
    'rule_deleted' => 'የመስመር ደንቡ ተሰርዟል።',
    'category_out_of_scope' => 'ምድቡ ከተማ አቀፍ ወይም የደንቡ ተቋም መሆን አለበት።',
    'unit_out_of_scope' => 'የሥራ ክፍሉ የደንቡ ተቋም መሆን አለበት።',
    'settings_saved' => 'የሕዝብ አስተያየት ፖሊሲው ተቀምጧል።',
    'questionnaire_draft_created' => 'የመጠይቁ አዲስ ረቂቅ ተፈጥሯል።',
    'questionnaire_saved' => 'የመጠይቁ ረቂቅ ተቀምጧል።',
    'questionnaire_published' => 'መጠይቁ ታትሟል። አዲስ የሚቀርቡ አስተያየቶች ይህንን ስሪት ይመልሳሉ።',
    'questionnaire_retired' => 'መጠይቁ ከአገልግሎት ወጥቷል። ቅጹ አሁን መጠይቅ አያሳይም።',
    'questionnaire_deleted' => 'የመጠይቁ ረቂቅ ተሰርዟል።',
    'questionnaire_not_draft' => 'መለወጥ የሚቻለው ረቂቅ ብቻ ነው። የታተመ መጠይቅን ለማሻሻል አዲስ ረቂቅ ይፍጠሩ።',
    'questionnaire_not_published' => 'ከአገልግሎት ማውጣት የሚቻለው የታተመውን ስሪት ብቻ ነው።',
    'questionnaire_empty' => 'ከማተምዎ በፊት ቢያንስ አንድ ጥያቄ ያክሉ።',
    'questionnaire_missing_amharic' => 'ከማተምዎ በፊት እያንዳንዱ ጥያቄ እና ምርጫ የአማርኛ ጽሑፍ ያስፈልገዋል፦ :code',
    'questionnaire_missing_options' => 'የምርጫ ጥያቄ ቢያንስ ሁለት ምርጫዎች ያስፈልጉታል፦ :code',
    'questionnaire_duplicate_code' => 'እያንዳንዱ የጥያቄ ኮድ የተለየ መሆን አለበት፦ :code',
    'export_queued' => 'ፋይሉ እንዲዘጋጅ ተይዟል። ሲዘጋጅ ከታች ባለው ዝርዝር ውስጥ ይታያል።',
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
        'reference' => 'መለያ ቁጥር',
        'type' => 'ዓይነት',
        'target_type' => 'የሚመለከተው አካል ዓይነት',
        'target_name' => 'ዒላማ',
        'organization' => 'ተቋም',
        'organization_unit' => 'ክፍል',
        'category' => 'ምድብ',
        'status' => 'ሁኔታ',
        'handler' => 'የሚያስተናግደው አካል',
        'client_name' => 'የአቅራቢ ስም',
        'client_contact' => 'የአቅራቢ ስልክ / መገናኛ',
        'client_email' => 'የአቅራቢ ኢሜይል',
        'submitter_organization' => 'የአቅራቢ ተቋም',
        'submitter_address' => 'የአቅራቢ አድራሻ',
        'tip_type' => 'የጥቆማ ዓይነት',
        'involved_parties' => 'ሌሎች የተሳተፉ ሰዎች ወይም ቢሮዎች',
        'intake_channel' => 'የመቀበያ መንገድ',
        'subject' => 'ርዕስ',
        'message' => 'መልዕክት',
    ],

    // Header of the Feedback CSV (ServiceFeedbackController::export), in the requester's language.
    'feedback_export_columns' => [
        'submitted_at' => 'የቀረበበት ጊዜ', 'target_type' => 'የሚመለከተው ዓይነት', 'target' => 'የሚመለከተው',
        'employee' => 'ሠራተኛ', 'employee_number' => 'የሠራተኛ ቁጥር', 'organization' => 'ተቋም',
        'unit' => 'የሥራ ክፍል', 'service' => 'አገልግሎት', 'category' => 'ምድብ', 'rating' => 'ደረጃ',
        'status' => 'ሁኔታ', 'comment' => 'አስተያየት', 'client_name' => 'የተገልጋዩ ስም', 'client_contact' => 'የተገልጋዩ መገናኛ',
    ],

    // Values written into exports, in the requester's language (ExportPublicFeedbackReport).
    'export_values' => [
        'types' => ['feedback' => 'አስተያየት', 'suggestion' => 'ጥቆማ', 'complaint' => 'ቅሬታ'],
        'target_types' => ['employee' => 'ሠራተኛ', 'organization' => 'ተቋም'],
        'unit_selection' => ['GENERAL' => 'አጠቃላይ / አላውቅም'],
        'statuses' => [
            'pending' => 'በመጠባበቅ ላይ', 'unrouted' => 'ያልተላለፈ', 'assigned' => 'የተመደበ', 'reviewed' => 'ተገምግሟል',
            'under_review' => 'በግምገማ ላይ', 'accepted' => 'ተግባራዊ እንዲሆን ተቀባይነት ያገኘ', 'not_adopted' => 'ተቀባይነት ያላገኘ',
            'resolved' => 'ተፈትቷል', 'closed' => 'የተዘጋ', 'hidden' => 'ተደብቋል',
        ],
        'intake_channels' => ['WEB_QR' => 'የሕዝብ QR ድረ-ገጽ ቅጽ', 'PHONE' => 'በስልክ', 'ORAL' => 'በአካል (በቃል)', 'WRITTEN' => 'በጽሑፍ'],
    ],

    'types' => [
        'feedback' => 'አስተያየት',
        'suggestion' => 'ጥቆማ',
        'complaint' => 'ቅሬታ',
    ],

    'tip_types' => [
        'CORRUPTION' => 'የሙስና ጥቆማ',
        'IMPROPER_PRACTICE' => 'የብልሹ አሠራር ጥቆማ',
        'UNCLASSIFIED_LEGACY' => 'ያልተመደበ (ከጥቆማ ዓይነቶች በፊት)',
    ],

    'evidence_kind' => [
        'photo' => 'ፎቶ',
        'video' => 'ቪዲዮ',
        'audio' => 'ድምፅ',
        'document' => 'ሰነድ',
    ],

    'notifications' => [
        'open' => 'ጉዳዩን ይክፈቱ',
        'unrouted_title' => 'የቀረበ ጉዳይ የሚያስተናግድ አካል ይፈልጋል',
        'unrouted' => 'የሕዝብ :type :reference ከየትኛውም የማስተላለፊያ ደንብ ጋር አልተዛመደም፤ የሚያስተናግድ አካል እየጠበቀ ነው።',
        'assigned_title' => 'የቀረበ ጉዳይ ለእርስዎ ተመድቧል',
        'assigned' => 'የሕዝብ :type :reference ለእርስዎ ወይም ለቡድንዎ ተሰጥቷል።',
        'resolved_title' => 'የቀረበ ጉዳይ ውጤት ተመዝግቧል',
        'resolved' => 'ለሕዝብ :type :reference ውጤት ተመዝግቧል።',
        'referred_title' => 'የዲሲፕሊን ማስተላለፍ ተጠይቋል',
        'referred' => 'ለሕዝብ :type :reference የዲሲፕሊን ማስተላለፍ ተጠይቋል፤ ውሳኔ እየጠበቀ ነው።',
    ],

    'acknowledgement' => [
        'subject' => ':type ደርሶናል',
        'greeting' => 'እናመሰግናለን።',
        'received' => ':type በ :time ደርሶናል።',
        'reference' => 'መለያ ቁጥር፦ :reference። ለክትትል እባክዎ ይያዙት።',
        'no_tracking' => 'ይህ መልዕክት መድረሱን ብቻ ያረጋግጣል፤ ተቋሙ በውስጥ ይሠራበታል።',
    ],

    'validation' => [
        'service_required' => 'እባክዎ ያገኙትን አገልግሎት ይምረጡ።',
        'rating_required' => 'እባክዎ የእርካታ ደረጃ ይምረጡ።',
        'rating_range' => 'እባክዎ ከ1 እስከ 5 ኮከብ ያለ ደረጃ ይምረጡ።',
        'message_required' => 'እባክዎ የሆነውን በዝርዝር ይግለጹ።',
        'comment_required' => 'እባክዎ ስላገኙት አገልግሎት ይንገሩን።',
        'tip_type_required' => 'እባክዎ የጥቆማውን ዓይነት ይምረጡ።',
        'tip_type_invalid' => 'እባክዎ ከሁለቱ የጥቆማ ዓይነቶች አንዱን ይምረጡ።',
        'attachments_max' => 'እስከ :max ፋይሎች ብቻ ማያያዝ ይችላሉ።',
        'attachment_type' => 'ፎቶ፣ ቪዲዮ፣ የድምፅ ቅጂ እና PDF ፋይሎች ብቻ ይቀበላሉ።',
        'attachment_size' => 'ይህ ፋይል በጣም ትልቅ ነው።',
        'attachment_size_kind' => 'የ:kind ፋይል :max MB ወይም ከዚያ በታች መሆን አለበት።',
        'attachments_total' => 'ሁሉም ፋይሎች በአንድ ላይ :max MB ወይም ከዚያ በታች መሆን አለባቸው።',
        'incident_date_future' => 'የክስተቱ ቀን ወደፊት ሊሆን አይችልም።',
        'name_required' => 'እባክዎ ስምዎን ያስገቡ።',
        'contact_required' => 'እባክዎ ስልክ ቁጥርዎን ያስገቡ።',
        'phone_invalid' => 'እባክዎ ትክክለኛ ስልክ ቁጥር ያስገቡ።',
        'email_invalid' => 'እባክዎ ትክክለኛ ኢሜይል አድራሻ ያስገቡ።',
        'contact_invalid' => 'እባክዎ ትክክለኛ ስልክ ቁጥር ወይም ኢሜይል አድራሻ ያስገቡ።',
        'attachments_disabled' => 'በአሁኑ ጊዜ ፋይሎች ማያያዝ አይቻልም።',
        'questionnaire_updated' => 'ቅጹን በሚሞሉበት ጊዜ ቅጹ ተሻሽሏል። እባክዎ ጥያቄዎቹን ይመልከቱ እና እንደገና ይላኩ።',
        'office_invalid' => 'እባክዎ ከተዘረዘሩት ቢሮዎች አንዱን ይምረጡ።',
        'relevant_unit_invalid' => 'እባክዎ ከዚህ ተቋም የሚገኝ ትክክለኛ የሥራ ክፍል ይምረጡ።',
        'relevant_unit_required' => 'የሚመለከተውን የሥራ ክፍል ወይም አጠቃላይ / አላውቅም ይምረጡ።',
        'service_date_future' => 'አገልግሎቱን ያገኙበት ቀን ወደፊት ሊሆን አይችልም።',
        'service_date_too_old' => 'አገልግሎቱን ያገኙበት ቀን ባለፈው አንድ ዓመት ውስጥ መሆን አለበት።',
        'service_date_invalid' => 'እባክዎ ትክክለኛ ቀን ያስገቡ።',
        'answer_required' => 'እባክዎ ይህንን ይመልሱ፦ :attribute',
        'answer_invalid' => 'እባክዎ ከቀረቡት መልሶች አንዱን ይምረጡ፦ :attribute',
        'answer_too_long' => 'ይህ መልስ በጣም ረጅም ነው።',
    ],
];
