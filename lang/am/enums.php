<?php

// Amharic display labels for enums that label themselves (App\Enums\*::label()).
// Terms follow docs/amharic-terminology-guide.md: an approved transfer (ጸድቋል)
// is not yet an implemented one (ተዛውሯል).
return [
    'cafeteria_ledger_entry_type' => [
        'allocation' => 'ድልድል',
        'usage' => 'አጠቃቀም',
        'extra_usage' => 'ተጨማሪ አጠቃቀም',
        'carry_forward_deduction' => 'የተሸጋገረ ቅናሽ',
        'adjustment' => 'ማስተካከያ',
        'reversal' => 'መቀልበስ',
    ],
    'cafeteria_report_type' => [
        'daily' => 'ዕለታዊ ሪፖርት',
        'weekly' => 'ሳምንታዊ ሪፖርት',
        'monthly' => 'ወርሃዊ ሪፖርት',
    ],
    'cafeteria_subsidy_scope' => [
        'all_employees' => 'ሁሉም ሠራተኞች',
        'organization' => 'ተቋም',
        'employee_type' => 'የሠራተኛ ዓይነት',
        'selected_employees' => 'የተመረጡ ሠራተኞች',
    ],
    'cafeteria_transaction_status' => [
        'accepted' => 'ተቀባይነት አግኝቷል',
        'rejected' => 'ውድቅ ተደርጓል',
        'reversed' => 'ተቀልብሷል',
        'pending_review' => 'ግምገማ በመጠባበቅ ላይ',
    ],
    'establishment_status' => [
        'draft' => 'ረቂቅ',
        'approved' => 'ጸድቋል',
        'archived' => 'ወደ ማህደር ገብቷል',
    ],
    'occupancy_status' => [
        'active' => 'ንቁ',
        'released' => 'ተለቋል',
    ],
    'transfer_announcement_status' => [
        'draft' => 'ረቂቅ',
        'published' => 'ይፋ ሆኗል',
        'closed' => 'ተዘግቷል',
        'cancelled' => 'ተሰርዟል',
    ],
    'transfer_application_status' => [
        'submitted' => 'ቀርቧል',
        'under_review' => 'በግምገማ ላይ',
        'verified' => 'ተረጋግጧል',
        'selected' => 'ተመርጧል',
        'release_pending' => 'የሚለቅቀው ተቋም ይሁንታ በመጠባበቅ ላይ',
        'receiving_pending' => 'የሚቀበለው ተቋም ይሁንታ በመጠባበቅ ላይ',
        'final_approval_pending' => 'የመጨረሻ ማጽደቅ በመጠባበቅ ላይ',
        'approved' => 'ጸድቋል',
        'implementation_failed' => 'ዝውውሩን ተግባራዊ ማድረግ አልተሳካም',
        'transferred' => 'ተዛውሯል',
        'rejected' => 'ውድቅ ተደርጓል',
        'withdrawn' => 'ተነስቷል',
        'cancelled' => 'ተሰርዟል',
    ],
    'transfer_approval_status' => [
        'pending' => 'በመጠባበቅ ላይ',
        'approved' => 'ጸድቋል',
        'rejected' => 'ውድቅ ተደርጓል',
    ],
    'transfer_approval_type' => [
        'release' => 'የሚለቅቀው ተቋም ይሁንታ',
        'receiving' => 'የሚቀበለው ተቋም ይሁንታ',
        'final' => 'የመጨረሻ ማጽደቅ',
    ],
    'transfer_card_reprint_policy' => [
        'no_reprint' => 'ዳግም ሕትመት አይደረግም',
        'request_reprint' => 'የዳግም ሕትመት ጥያቄ መፍጠር',
        'auto_reprint' => 'በራስ-ሰር ዳግም ማተም',
    ],
    'transfer_document_verification_status' => [
        'pending' => 'በመጠባበቅ ላይ',
        'verified' => 'ተረጋግጧል',
        'rejected' => 'ውድቅ ተደርጓል',
    ],
    'transfer_override_status' => [
        'pending' => 'በመጠባበቅ ላይ',
        'approved' => 'ጸድቋል',
        'rejected' => 'ውድቅ ተደርጓል',
    ],
    'transfer_service_recalculation_policy' => [
        'no_recalculation' => 'ዳግም ስሌት አይደረግም',
        'recalculate_from_transfer' => 'ከዝውውሩ ቀን ጀምሮ ዳግም ማስላት',
        'recalculate_from_effective_date' => 'ከሚጸናበት ቀን ጀምሮ ዳግም ማስላት',
    ],
    'vacancy_announcement_status' => [
        'draft' => 'ረቂቅ',
        'published' => 'ይፋ ሆኗል',
        'closed' => 'ተዘግቷል',
        'cancelled' => 'ተሰርዟል',
    ],
    'vacancy_application_status' => [
        'submitted' => 'ቀርቧል',
        'withdrawn' => 'ተነስቷል',
        'screened' => 'ማጣሪያ ተደርጓል',
        'shortlisted' => 'ወደ አጭር ዝርዝር ገብቷል',
        'selected' => 'ተመርጧል',
        'rejected' => 'ውድቅ ተደርጓል',
        'transferred' => 'ተዛውሯል',
    ],
];
