<?php

declare(strict_types=1);

/* ወደ ፋይል የሚወጣው የአፈጻጸም መዝገብ የዓምድ ርእሶችና እሴቶች። */
return [
    'too_many_rows' => 'የሚወጣው ፋይል ከ:max ረድፎች በላይ ይይዛል። ማጣሪያዎቹን አጥብበው እንደገና ይሞክሩ።',
    'columns' => [
        'institution_code' => 'የተቋም ኮድ',
        'institution' => 'ተቋም',
        'work_unit' => 'የሥራ ክፍል',
        'responsible_officer' => 'ተጠሪ ኃላፊ',
        'report_type' => 'የሪፖርት ዓይነት',
        'period' => 'የሪፖርት ጊዜ',
        'original_deadline' => 'የመጀመሪያው የማቅረቢያ ቀን',
        'effective_deadline' => 'በሥራ ላይ ያለው የማቅረቢያ ቀን',
        'first_submission' => 'ለመጀመሪያ ጊዜ የቀረበበት',
        'workflow' => 'የሥራ ሂደት ሁኔታ',
        'timeliness' => 'ወቅታዊነት (በሥራ ላይ ባለው ቀን)',
        'timeliness_original' => 'ወቅታዊነት (በመጀመሪያው ቀን)',
        'days_overdue' => 'የዘገየባቸው ቀናት',
        'applicability' => 'ተፈጻሚነት',
        'reviewer' => 'ገምጋሚ',
        'review_deadline' => 'የግምገማ ማጠናቀቂያ ቀን',
    ],
    'values' => [
        'workflow' => [
            'not_started' => 'አልተጀመረም', 'draft' => 'ረቂቅ', 'submitted' => 'ቀርቧል', 'under_review' => 'በግምገማ ላይ',
            'returned' => 'ለማስተካከያ ተመልሷል', 'approved' => 'ጸድቋል', 'rejected' => 'ውድቅ ተደርጓል',
        ],
        'timeliness' => [
            'not_due' => 'ጊዜው አልደረሰም', 'early' => 'ቀድሞ ቀርቧል', 'on_time' => 'በወቅቱ', 'late' => 'ዘግይቶ', 'overdue' => 'ጊዜው አልፏል፤ አልቀረበም',
        ],
        'applicability' => ['required' => 'ይጠበቃል', 'exempted' => 'ነፃ ተደርጓል', 'cancelled' => 'ተሰርዟል'],
    ],
];
