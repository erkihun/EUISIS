<?php

// Core oversight messages (ADR 0011): data quality, reports and exports, notifications, system health.
return [
    'data_quality_updated' => 'ችግሩ ተዘምኗል።',
    'data_quality_scan_started' => 'የመረጃ ጥራት ፍተሻው እየተካሄደ ነው፤ ሲያልቅ ችግሮቹ ይዘምናሉ።',
    'export_queued' => 'የሚወጣው ፋይል እየተዘጋጀ ነው። ሲዘጋጅ በሚወጡ ፋይሎችዎ ውስጥ ይታያል።',
    'export_not_ready' => 'ይህ የሚወጣ ፋይል ገና አልተዘጋጀም።',
    'notifications_read' => 'ማሳወቂያዎቹ እንደተነበቡ ተመዝግበዋል።',
    'job_retried' => 'ሥራው እንደገና ወረፋ ገብቷል።',
    'job_forgotten' => 'ያልተሳካው ሥራ ተወግዷል።',

    // Export titles (the report names).
    'reports' => [
        'organizations' => 'የተቋማት ሪፖርት', 'employees' => 'የሠራተኞች ሪፖርት', 'positions' => 'የሥራ መደቦች ሪፖርት', 'vacancies' => 'የክፍት የሥራ መደቦች ሪፖርት',
        'assignments' => 'የምደባዎች ሪፖርት', 'assignment_history' => 'የምደባ ታሪክ ሪፖርት', 'hierarchy' => 'የተዋረድ ሪፖርት',
        'hierarchy_changes' => 'የተዋረድ ለውጦች ሪፖርት', 'card_status' => 'የመታወቂያ ካርድ ሁኔታ ሪፖርት', 'card_coverage' => 'የካርድ ሽፋን ሪፖርት',
        'expiring_cards' => 'ጊዜያቸው የሚያልፍ ካርዶች ሪፖርት', 'lost_damaged_replaced' => 'የጠፉ፣ የተበላሹና የተተኩ ካርዶች ሪፖርት',
        'credential_status' => 'የካርድ የማረጋገጫ መለያ ሁኔታ ሪፖርት', 'data_quality' => 'የመረጃ ጥራት ሪፖርት', 'user_access' => 'የተጠቃሚዎች መዳረሻ ሪፖርት',
        'organization_scope' => 'የተቋም ወሰን ሪፖርት', 'audit' => 'የኦዲት ሪፖርት', 'api_client_usage' => 'የAPI ደንበኞች አጠቃቀም ሪፖርት',
        'webhook_deliveries' => 'የዌብሁክ መልእክቶች ሪፖርት', 'security_events' => 'የደህንነት ክስተቶች ሪፖርት',
    ],

    // Values in exports, in the export's language.
    'values' => [
        'boolean' => ['yes' => 'አዎ', 'no' => 'አይ'],
        'organization_status' => ['active' => 'ንቁ', 'inactive' => 'ንቁ ያልሆነ', 'merged' => 'የተዋሀደ', 'dissolved' => 'የፈረሰ', 'archived' => 'በማህደር የተቀመጠ', 'draft' => 'ረቂቅ'],
        'employee_status' => ['draft' => 'ረቂቅ', 'submitted' => 'የቀረበ', 'verified' => 'የተረጋገጠ', 'approved' => 'የጸደቀ', 'active' => 'ንቁ', 'returned' => 'የተመለሰ',
            'retired' => 'ጡረታ የወጣ', 'terminated' => 'ውሉ የተቋረጠ', 'deceased' => 'በሞት የተለየ', 'transferred' => 'ወደ ሌላ የተዛወረ'],
        'position_status' => ['draft' => 'ረቂቅ', 'approved' => 'የጸደቀ', 'suspended' => 'የታገደ', 'abolished' => 'የተሰረዘ', 'archived' => 'በማህደር የተቀመጠ'],
        'assignment_status' => ['active' => 'ንቁ', 'pending' => 'ማጽደቅ በመጠባበቅ ላይ', 'closed' => 'ያበቃ'],
        'assignment_type' => ['initial' => 'የመጀመሪያ ምደባ', 'transfer' => 'ዝውውር', 'promotion' => 'የደረጃ ዕድገት', 'demotion' => 'የደረጃ ዝቅታ', 'reassignment' => 'እንደገና ምደባ',
            'correction' => 'እርማት', 'unspecified' => 'ያልተመዘገበ'],
        'hierarchy_status' => ['draft' => 'ረቂቅ', 'submitted' => 'የቀረበ', 'approved' => 'የጸደቀ', 'published' => 'የታተመ', 'archived' => 'በማህደር የተቀመጠ', 'rejected' => 'ውድቅ የተደረገ'],
        'card_status' => ['pending_print' => 'ሕትመት በመጠባበቅ ላይ', 'printed' => 'የታተመ', 'issued' => 'የተሰጠ', 'active' => 'ንቁ', 'suspended' => 'የታገደ', 'lost' => 'የጠፋ',
            'damaged' => 'የተበላሸ', 'expired' => 'ጊዜው ያለፈ', 'revoked' => 'የተሰረዘ', 'replaced' => 'የተተካ'],
        'qc_status' => ['pending' => 'ፍተሻ በመጠባበቅ ላይ', 'passed' => 'ያለፈ', 'failed' => 'ያላለፈ', 'not_recorded' => 'ያልተመዘገበ'],
        'credential_status' => ['active' => 'ንቁ', 'superseded' => 'የተተካ', 'revoked' => 'የተሰረዘ'],
        'severity' => ['critical' => 'አሳሳቢ', 'high' => 'ከፍተኛ', 'medium' => 'መካከለኛ', 'low' => 'ዝቅተኛ', 'info' => 'መረጃ'],
        'issue_status' => ['open' => 'ክፍት', 'investigating' => 'በምርመራ ላይ', 'resolved' => 'የተፈታ', 'accepted_risk' => 'ስጋቱ ተቀባይነት ያገኘ', 'false_positive' => 'የተሳሳተ ግኝት'],
        'check' => [
            'positions_missing_organization' => 'የሌለ ተቋም የሚጠቅስ የሥራ መደብ', 'positions_foreign_unit' => 'የሌላ ተቋም የሥራ ክፍል ያለው የሥራ መደብ',
            'employees_bad_pointer' => 'የተሳሳተ ምደባ የሚጠቁም ሠራተኛ', 'assignments_unpointed' => 'ሠራተኛው የማይጠቁመው ምደባ',
            'replacements_unlinked' => 'ያልተገናኘ ምትክ', 'separated_still_placed' => 'የተለየ ሆኖ ገና የተመደበ', 'separated_card_active' => 'የተለየ ሆኖ ንቁ ካርድ ያለው',
            'employees_multiple_active_cards' => 'ከአንድ በላይ ንቁ ካርድ', 'positions_over_capacity' => 'ከመቀመጫው በላይ የተያዘ የሥራ መደብ',
            'placed_in_inactive_position' => 'ባልጸደቀ የሥራ መደብ ላይ የተመደበ', 'placed_in_ended_organization' => 'ንቁ ባልሆነ ተቋም ውስጥ የተመደበ',
            'active_without_placement' => 'ምደባ የሌለው ንቁ ሠራተኛ', 'overlapping_assignments' => 'የሚደራረቡ ምደባዎች',
            'possible_duplicate_employees' => 'ሊደገም የሚችል መዝገብ', 'expired_card_still_active' => 'ጊዜው አልፎ ገና ንቁ የሆነ ካርድ', 'active_without_card' => 'ካርድ የሌለው ንቁ ሠራተኛ',
            'active_card_inactive_employee' => 'ንቁ ካርድ፣ ንቁ ያልሆነ ባለቤት', 'active_card_revoked_credential' => 'ንቁ ካርድ፣ የተሰረዘ መለያ',
            'active_without_photo' => 'የሚያስፈልግ ፎቶ የሌለው', 'active_missing_required_document' => 'የሚያስፈልግ ሰነድ የሌለው',
            'occupancy_without_assignment' => 'ምደባ የሌለው ይዞታ', 'hierarchy_multiple_parents' => 'ከአንድ በላይ የበላይ ተቋም',
            'hierarchy_edge_ended_organization' => 'በተዋረዱ ውስጥ ያለ ንቁ ያልሆነ ተቋም',
        ],
    ],
];
