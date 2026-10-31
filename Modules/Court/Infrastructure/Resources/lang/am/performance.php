<?php

return [
    // ከይግባኝ ሰሚ ፍርድ ቤቱ ውጤቶች የሚሰላው መስፈርት (EUISIS ADR 0013 #14)።
    'appeal_outcomes' => [
        'comment' => ':total ይግባኝ ተወስኗል፦ :confirmed ጸንቷል፣ :modified ተሻሽሏል፣ :reversed ተሽሯል።',
        'automatic' => 'ከመዝገቡ የሚሰላ',
        'counting' => 'የዚህ ዳኛ ችሎት የሰጣቸው ውሳኔዎች ላይ ይግባኝ ሰሚ ፍርድ ቤቱ በጊዜው ውስጥ የሰጠው ውሳኔ።',
        'result' => ':total ይግባኝ፦ :confirmed ጸንቷል፣ :modified ተሻሽሏል፣ :reversed ተሽሯል። ውጤት :score።',
        'none' => 'በጊዜው ውስጥ የተወሰነ ይግባኝ የለም፤ ይህ መስፈርት ከአጠቃላይ ውጤቱ ውጭ ነው።',
        'pick' => 'ለማየት ሠራተኛውንና ጊዜውን ይምረጡ።',
    ],
    'title' => 'የአፈጻጸም ግምገማዎች',
    'detail_title' => 'የግምገማ ዝርዝር',
    'new_title' => 'አዲስ የአፈጻጸም ግምገማ',
    'edit_title' => 'ግምገማ አርትዕ',
    'new_evaluation' => 'አዲስ ግምገማ',
    'edit_evaluation' => 'ግምገማ አርትዕ',
    'subtitle' => 'እያንዳንዱን መመዘኛ ከ0 (ዝቅተኛ) እስከ 10 (እጅግ ጥሩ) ይስጡ',
    'details' => 'የግምገማ ዝርዝሮች',
    'criteria_scores' => 'የመመዘኛ ውጤቶች',
    'criteria_breakdown' => 'የመመዘኛ ዝርዝር',
    'score_hint' => 'ውጤት፡ 0 = ዝቅተኛ · 5 = አማካይ · 10 = እጅግ ጥሩ',
    'score_hint_short' => '0 = ዝቅተኛ · 5 = አማካይ · 10 = እጅግ ጥሩ',
    'not_available' => 'የለም',

    'stats' => [
        'total' => 'ጠቅላላ',
        'draft' => 'ረቂቅ',
        'submitted' => 'የቀረበ',
        'reviewed' => 'የተገመገመ',
        'avg_score' => 'አማካይ ውጤት',
    ],

    'filters' => [
        'all_statuses' => 'ሁሉም ሁኔታዎች',
        'all_members' => 'ሁሉም አባላት',
        'all_periods' => 'ሁሉም ጊዜያት',
        'clear' => 'አጽዳ',
    ],

    'tabs' => [
        'all' => 'ሁሉም',
    ],

    'pagination' => [
        'showing' => 'ከ :total ግምገማዎች ውስጥ :from እስከ :to እየታየ ነው',
    ],

    'fields' => [
        'member' => 'አባል',
        'staff_member' => 'የሰራተኛ አባል',
        'period' => 'ጊዜ',
        'period_type' => 'የጊዜ አይነት',
        'period_start' => 'የጊዜ መጀመሪያ',
        'period_end' => 'የጊዜ መጨረሻ',
        'type' => 'አይነት',
        'score' => 'ውጤት',
        'status' => 'ሁኔታ',
        'evaluator' => 'ገምጋሚ',
        'date' => 'ቀን',
        'created' => 'የተፈጠረበት',
        'reviewed_by' => 'የገመገመው',
        'reviewed_at' => 'የተገመገመበት',
        'notes' => 'ማስታወሻዎች',
        'reviewer_notes' => 'የገምጋሚ ማስታወሻዎች',
        'general_notes' => 'አጠቃላይ ማስታወሻዎች',
        'weight' => 'ክብደት',
        'actions' => 'ተግባራት',
    ],

    'placeholders' => [
        'select_member' => 'አባል ይምረጡ...',
        'general_notes' => 'አማራጭ አጠቃላይ አስተያየቶች...',
        'criterion_comment' => 'ለዚህ መመዘኛ አማራጭ አስተያየት...',
        'comment' => 'አማራጭ አስተያየት...',
        'reviewer_notes' => 'የገምጋሚ ማስታወሻ ያክሉ (አማራጭ)...',
    ],

    'actions' => [
        'new' => 'አዲስ ግምገማ',
        'view' => 'እይ',
        'edit' => 'አርትዕ',
        'delete' => 'ሰርዝ',
        'cancel' => 'ሰርዝ',
        'back' => 'ተመለስ',
        'back_to_list' => 'ወደ ዝርዝር ተመለስ',
        'save_draft' => 'እንደ ረቂቅ አስቀምጥ',
        'submit_review' => 'ለግምገማ አቅርብ',
        'approve_reviewed' => 'አጽድቅ እና እንደተገመገመ ምልክት አድርግ',
        'create_first' => 'የመጀመሪያውን ፍጠር',
    ],

    'empty' => [
        'evaluations' => 'ምንም ግምገማ አልተገኘም',
    ],

    'statuses' => [
        'draft' => 'ረቂቅ',
        'submitted' => 'የቀረበ',
        'reviewed' => 'የተገመገመ',
    ],

    'periods' => [
        'monthly' => 'ወርሃዊ',
        'quarterly' => 'ሩብ ዓመታዊ',
        'annual' => 'ዓመታዊ',
    ],

    'score_labels' => [
        'excellent' => 'እጅግ ጥሩ',
        'good' => 'ጥሩ',
        'satisfactory' => 'አጥጋቢ',
        'needs_improvement' => 'መሻሻል ያስፈልጋል',
        'poor' => 'ዝቅተኛ',
        'average' => 'አማካይ',
    ],

    'categories' => [
        'general' => 'አጠቃላይ',
        'efficiency' => 'ቅልጥፍና',
        'quality' => 'ጥራት',
        'conduct' => 'ሥነ ምግባር',
    ],

    'review' => [
        'title' => 'ይህን ግምገማ ገምግም',
    ],

    'confirm' => [
        'delete' => 'ይህን ግምገማ ለመሰረዝ እርግጠኛ ነዎት?',
    ],

    'settings' => [
        'title' => 'የአፈጻጸም ግምገማ ቅንብር',
        'subtitle' => 'ግምገማ ሲፈጠር የሚጠቀሙባቸውን መመዘኛዎች፣ የውጤት ክብደቶች፣ ቅደም ተከተል እና ንቁ ሁኔታ ያስተዳድሩ።',
        'create_criterion_title' => 'መመዘኛ ጨምር',
        'create_criterion_subtitle' => 'ለወደፊት የአፈጻጸም ግምገማዎች አንድ የውጤት መመዘኛ ፍጠር።',
        'create_category_title' => 'ምድብ ጨምር',
        'create_category_subtitle' => 'የግምገማ መመዘኛዎችን ለመመደብ የሚጠቅም ምድብ ፍጠር።',
        'view_criterion_title' => 'የመመዘኛ ዝርዝር',
        'edit_criterion_title' => 'መመዘኛ አርትዕ',
        'back_to_evaluations' => 'ወደ ግምገማዎች ተመለስ',
        'criteria_title' => 'መመዘኛዎች እና ውጤቶች',
        'criteria_hint' => 'ንቁ መመዘኛዎች በአዲስ ግምገማ ቅጾች ላይ ይታያሉ። ክብደቶች አጠቃላይ ውጤት ለማስላት ይጠቅማሉ።',
        'active_weight' => 'የንቁ ክብደት',
        'used_scores' => 'የተጠቀመ ውጤት',
        'add_title' => 'መመዘኛ ጨምር',
        'add_hint' => 'ለወደፊት ግምገማዎች አዲስ የሚለካ መመዘኛ ፍጠር።',
        'active' => 'ንቁ',
        'inactive' => 'ንቁ ያልሆነ',
        'slug_hint' => 'ከስሙ በራስ-ሰር እንዲፈጠር ባዶ ይተዉት። ፊደላት፣ ቁጥሮች፣ ሰረዞች ወይም የታችኛው መስመር ይጠቀሙ።',
        'empty' => 'ምንም የአፈጻጸም መመዘኛ አልተገኘም።',
        'confirm_delete' => 'ይህን መመዘኛ ለመሰረዝ እርግጠኛ ነዎት? ቀድሞ የግምገማ ውጤቶች ካሉት ከመሰረዝ ይልቅ እንዲቦዝን ይደረጋል።',
        'fields' => [
            'name' => 'ስም',
            'name_am' => 'የአማርኛ ስም',
            'slug' => 'ስሉግ',
            'category' => 'ምድብ',
            'weight' => 'ክብደት',
            'order' => 'ቅደም ተከተል',
            'status' => 'ሁኔታ',
            'description' => 'መግለጫ',
        ],
        'actions' => [
            'add' => 'መመዘኛ ጨምር',
            'add_criterion' => 'መመዘኛ ጨምር',
            'add_category' => 'ምድብ ጨምር',
            'update' => 'አዘምን',
            'delete' => 'ሰርዝ',
        ],
        'messages' => [
            'created' => 'የአፈጻጸም መመዘኛ ተፈጥሯል።',
            'category_created' => 'የአፈጻጸም ምድብ ተፈጥሯል።',
            'category_slug_exists' => 'በዚህ ስሉግ የአፈጻጸም ምድብ ቀድሞ አለ።',
            'updated' => 'የአፈጻጸም መመዘኛ ተዘምኗል።',
            'deleted' => 'የአፈጻጸም መመዘኛ ተሰርዟል።',
            'deactivated_in_use' => 'ይህ መመዘኛ ቀድሞ ውጤቶች ስላሉት ከመሰረዝ ይልቅ እንዲቦዝን ተደርጓል።',
        ],
        'validation' => [
            'attributes' => [
                'name' => 'ስም',
                'name_am' => 'የአማርኛ ስም',
                'category' => 'ምድብ',
                'weight' => 'ክብደት',
                'description' => 'መግለጫ',
                'sort_order' => 'ቅደም ተከተል',
                'active' => 'ንቁ ሁኔታ',
            ],
        ],
        'category_validation' => [
            'attributes' => [
                'name' => 'ስም',
                'name_am' => 'የአማርኛ ስም',
                'slug' => 'ስሉግ',
                'sort_order' => 'ቅደም ተከተል',
                'active' => 'ንቁ ሁኔታ',
            ],
        ],
    ],

    'messages' => [
        'submitted' => 'ግምገማው በተሳካ ሁኔታ ቀርቧል።',
        'draft' => 'ግምገማው እንደ ረቂቅ ተቀምጧል።',
        'updated' => 'ግምገማው በተሳካ ሁኔታ ተሻሽሏል።',
        'deleted' => 'ግምገማው ተሰርዟል።',
        'reviewed' => 'ግምገማው ተገምግሞ ጸድቋል።',
        'reviewed_cannot_edit' => 'የተገመገሙ ግምገማዎችን ማርትዕ አይቻልም።',
        'reviewed_cannot_delete' => 'የተገመገሙ ግምገማዎችን መሰረዝ አይቻልም።',
        'submitted_only_review' => 'ለግምገማ የቀረቡ ግምገማዎች ብቻ መገምገም ይችላሉ።',
    ],

    'validation' => [
        'attributes' => [
            'evaluated_user_id' => 'የሰራተኛ አባል',
            'period_type' => 'የጊዜ አይነት',
            'period_start' => 'የጊዜ መጀመሪያ',
            'period_end' => 'የጊዜ መጨረሻ',
            'notes' => 'አጠቃላይ ማስታወሻዎች',
            'scores' => 'ውጤቶች',
            'scores.*.criterion_id' => 'መመዘኛ',
            'scores.*.score' => 'ውጤት',
            'scores.*.comment' => 'የመመዘኛ አስተያየት',
            'action' => 'ተግባር',
            'reviewer_notes' => 'የገምጋሚ ማስታወሻዎች',
        ],
    ],
];
