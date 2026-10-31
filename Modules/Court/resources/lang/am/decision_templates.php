<?php

return [
    'title' => 'የውሳኔ ቅጾች',
    'description' => 'ለአመልካችና ለመልስ ሰጭ የመጨረሻ ውሳኔ ሰነዶችን ለማዘጋጀት የሚያገለግሉ ቅጾች።',
    'new' => 'አዲስ የውሳኔ ቅጽ',
    'create' => 'የውሳኔ ቅጽ ፍጠር',
    'create_help' => 'እንደገና ጥቅም ላይ የሚውል አቀማመጥ ይግለጹ። እንደ {{applicant_name}} ያሉ ቦታ ያዢዎችን ይጠቀሙ፤ የውሳኔ የመጨረሻ ውጤት ሲፈጠር ይሞላሉ።',
    'edit' => 'የውሳኔ ቅጽ አርትዕ',
    'edit_help' => 'የዚህን የውሳኔ ቅጽ አቀማመጥና ቦታ ያዢዎች ያዘምኑ።',

    'actions' => [
        'new_template' => 'አዲስ ቅጽ',
        'cancel' => 'ሰርዝ',
        'save_template' => 'ቅጽ አስቀምጥ',
        'edit' => 'አርትዕ',
        'delete' => 'ሰርዝ',
        'delete_template' => 'ይህን የውሳኔ ቅጽ ይሰርዙ?',
    ],

    'table' => [
        'title' => 'ርዕስ',
        'category' => 'ምድብ',
        'placeholders' => 'ቦታ ያዢዎች',
        'updated' => 'የዘመነበት',
        'actions' => 'ተግባራት',
        'empty' => 'እስካሁን ምንም የውሳኔ ቅጽ የለም።',
        'none' => 'ምንም',
        'missing' => '—',
    ],

    'form' => [
        'title' => 'ርዕስ',
        'category' => 'ምድብ',
        'category_placeholder' => 'ለምሳሌ፦ የመጨረሻ ፍርድ፣ ብይን',
        'is_default' => 'እንደ ነባሪ አቀማመጥ ተጠቀም',
        'is_default_help' => 'ቅጽ ሳይመረጥ ውሳኔ ሲፈጠር ነባሪው ቅጽ ጥቅም ላይ ይውላል። አንድ ብቻ ነባሪ ሊሆን ይችላል።',
        'placeholders' => 'ቦታ ያዢዎች',
        'placeholders_placeholder' => 'applicant_name, respondent_name, case_number',
        'placeholders_example' => 'በኮማ ወይም በአዲስ መስመር ይለዩ። እያንዳንዱ በይዘቱ ውስጥ የሚጠቀሙበት {{token}} ይሆናል።',
        'placeholders_help' => 'የሚገኙ፦ <code>{{applicant_name}}</code>, <code>{{respondent_name}}</code>, <code>{{case_number}}</code>, <code>{{case_file_number}}</code>, <code>{{judge_name}}</code>, <code>{{decision_date}}</code>, <code>{{decision_content}}</code>, <code>{{decision_name}}</code>, <code>{{judge_one}}</code>, <code>{{judge_two}}</code>, <code>{{judge_three}}</code>, <code>{{judge_one_vote}}</code>, <code>{{judge_two_vote}}</code>, <code>{{judge_three_vote}}</code>, <code>{{judges_signatures}}</code>',
        'header_image' => 'የራስጌ ምስል',
        'footer_image' => 'የግርጌ ምስል',
        'header_current' => 'የአሁኑ ራስጌ፦ :file',
        'footer_current' => 'የአሁኑ ግርጌ፦ :file',
        'remove_header' => 'የራስጌ ምስል አስወግድ',
        'remove_footer' => 'የግርጌ ምስል አስወግድ',
        'body' => 'ይዘት',
        'body_placeholder' => 'የውሳኔውን አቀማመጥ እዚህ ይጻፉ። እንደ {{applicant_name}} እና {{decision_content}} ያሉ ምልክቶችን ያስገቡ።',
        'body_help' => 'ከላይ ያሉትን ቦታ ያዢ ምልክቶች መጠቀም ይችላሉ፤ የመጨረሻ ውጤት ሲፈጠር ይተካሉ።',
    ],

    'output' => [
        'heading' => 'የመጨረሻ ውጤት',
        'choose_template' => 'የመጨረሻ ውሳኔ PDF ይፍጠሩ። ቅጽ ይምረጡ ወይም ነባሪ አቀማመጥ ይጠቀሙ።',
        'select' => 'ቅጽ ይምረጡ',
        'default_layout' => 'ነባሪ አቀማመጥ',
        'generate' => 'የመጨረሻ ውጤት ፍጠር',
        'download' => 'አውርድ',
        'none_available' => 'ምንም የውሳኔ ቅጽ የለም። መጀመሪያ አንድ ይፍጠሩ።',
    ],

    // Clean, single-language labels used only on the rendered PDF.
    'pdf' => [
        'date' => 'ቀን',
        'case_number' => 'የመዝገብ ቁጥር',
        'applicant' => 'አመልካች',
        'respondent' => 'መልስ ሰጭ',
        'judge' => 'ዳኛ :number',
        'judges' => 'ዳኞች',
        'header_title' => 'የኢትዮጵያ ፌዴራላዊ ዲሞክራሲያዊ ሪፐብሊክ',
        'header_subtitle' => 'ችሎት — የመጨረሻ ውሳኔ',
        'footer_note' => 'ይህ ሰነድ በፍርድ ቤት አስተዳደር ሥርዓት የተዘጋጀ ነው።',
    ],
];
