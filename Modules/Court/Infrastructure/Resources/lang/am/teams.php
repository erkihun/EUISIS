<?php

return [
    'title' => 'ቡድኖች',

    'page_header' => [
        'index' => 'የቡድን አስተዳደር',
        'create' => 'ቡድን ፍጠር',
        'edit' => 'ቡድን አርትዕ',
        'show' => 'የቡድን ዝርዝር',
    ],

    'headings' => [
        'current' => 'አሁን ያሉ ቡድኖች',
        'details' => 'የቡድን ዝርዝሮች',
        'members' => 'አባላት',
        'overview' => 'የቡድን አጠቃላይ እይታ',
    ],

    'descriptions' => [
        'current' => 'ለአንድ ተጠቃሚ አንድ ቡድን ብቻ ይፈቀዳል፣ እና ምደባዎች በቡድን ዝርዝር ገጾች ውስጥ ይከናወናሉ።',
        'details' => 'የፍርድ ቤት ቡድኖችዎን ለማደራጀት ስም፣ መግለጫ እና ተዋረድ (hierarchy) ያስፈልጋሉ።',
        'edit_notice' => 'እዚህ የሚደረጉ ለውጦች በፋይል (Case) ምደባ መምረጫ ዝርዝሮች እና ሪፖርቶች ውስጥ ይንጸባረቃሉ።',
        'members_edit_notice' => 'ተጠቃሚዎችን ለዚህ ቡድን ይመድቡ (እያንዳንዱ ተጠቃሚ የአንድ ቡድን አባል ብቻ ሊሆን ይችላል)።',
        'members_leader_notice' => 'የቡድን መሪው ሁልጊዜ አባል ሆኖ መቆየት አለበት፤ እዚህ ምልክቱን ማንሳት ምንም ውጤት አይኖረውም።',
        'leader_optional' => 'አማራጭ። ለዚህ ቡድን ዋና የእውቂያ ሰው ይምረጡ።',
        'description_optional' => 'ምንም መግለጫ አልቀረበም።',
        'description_missing' => 'ለዚህ ቡድን ምንም መግለጫ አልተሰጠም።',
        'members_empty' => 'ገና ምንም አባላት አልተመደቡም።',
    ],

    'labels' => [
        'team' => 'ቡድን',
        'members' => 'አባላት',
        'leader' => 'መሪ',
        'description' => 'መግለጫ',
        'actions' => 'ድርጊቶች',
        'name' => 'ስም',
        'parent' => 'እናት/ዋና ቡድን',
        'team_leader' => 'የቡድን መሪ',
        'none' => 'ምንም',
        'reports_to' => 'ሪፖርት የሚያደርገው ለ:team',
        'top_level' => 'ዋና/የመጀመሪያ ደረጃ ቡድን',
        'member_count' => 'አባላት',
        'assigned' => 'ተመድቧል',
        'leader_prefix' => 'መሪ:',
    ],

    'buttons' => [
        'add_new' => 'አዲስ ቡድን ጨምር',
        'view' => 'ተመልከት',
        'edit' => 'አርትዕ',
        'delete' => 'ሰርዝ',
        'manage_members' => 'አባላትን አስተዳድር',
        'create' => 'ቡድን ፍጠር',
        'save_changes' => 'ለውጦችን አስቀምጥ',
        'save_members' => 'አባላትን አስቀምጥ',
        'back_to_list' => 'ወደ ዝርዝሩ ተመለስ',
    ],

    'confirmations' => [
        'delete' => 'ይህን ቡድን መሰረዝ ይፈልጋሉ?',
    ],

    'empty' => 'ገና ምንም ቡድኖች አልተገለጹም።',

    'meta' => [
        'unassigned' => 'ያልተመደበ',
        'unknown_email' => '',
    ],

    'errors' => [
        'parent_cycle' => 'ቡድን ለራሱ ወይም ከሥሩ ላሉ ንዑስ ቡድኖች ተጠሪ ሊሆን አይችልም።',
        'fix' => 'እባክዎን የሚከተሉትን ያርሙ:',
    ],
];
