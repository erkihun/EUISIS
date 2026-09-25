<?php

declare(strict_types=1);

/*
 * Daily Activity Register permissions.
 *
 * Three separated duties: the employee manages only their own log, the
 * reviewer acts only on employees assigned to them, and scoped oversight is
 * read-only within the viewer's organization scope. None of these grants
 * attendance or performance scoring authority.
 */
$entry = static fn (string $name, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm, string $group = 'daily_activities'): array => [
    'name' => $name,
    'group' => $group,
    'sort_order' => $sortOrder,
    'is_system' => false,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    // Employee self-service
    $entry('daily_activities.view_own', 10, 'View own daily activity', 'የራስን ዕለታዊ የሥራ እንቅስቃሴ ይመልከቱ',
        'View your own daily activity register, calendar and history.',
        'የራስዎን ዕለታዊ የሥራ እንቅስቃሴ መዝገብ፣ ቀን መቁጠሪያ እና ታሪክ ይመልከቱ።'),
    $entry('daily_activities.create', 20, 'Register daily activity', 'ዕለታዊ የሥራ እንቅስቃሴ ይመዝግቡ',
        'Create your own daily activity log for a working day.',
        'ለሥራ ቀን የራስዎን ዕለታዊ የሥራ እንቅስቃሴ መዝገብ ይፍጠሩ።'),
    $entry('daily_activities.update_draft', 30, 'Edit own daily activity draft', 'የራስን ረቂቅ ዕለታዊ እንቅስቃሴ ያሻሽሉ',
        'Edit your own daily activity while it is a draft or returned for correction.',
        'ዕለታዊ እንቅስቃሴዎ ረቂቅ ወይም ለእርማት የተመለሰ ሲሆን ያሻሽሉ።'),
    $entry('daily_activities.submit', 40, 'Submit daily activity', 'ዕለታዊ እንቅስቃሴ ያስገቡ',
        'Submit your own daily activity for the day.',
        'የዕለቱን የራስዎን የሥራ እንቅስቃሴ ያስገቡ።'),
    $entry('daily_activities.resubmit', 50, 'Resubmit corrected daily activity', 'የተስተካከለ ዕለታዊ እንቅስቃሴ እንደገና ያስገቡ',
        'Resubmit your own daily activity after it was returned for correction.',
        'ለእርማት ከተመለሰ በኋላ የራስዎን ዕለታዊ እንቅስቃሴ እንደገና ያስገቡ።'),

    // Supervisor review
    $entry('daily_activities.view_team', 60, 'View team daily activity', 'የቡድን ዕለታዊ እንቅስቃሴ ይመልከቱ',
        'View daily activity of the employees you are assigned to review.',
        'እንዲገመግሟቸው የተመደቡልዎትን ሠራተኞች ዕለታዊ እንቅስቃሴ ይመልከቱ።'),
    $entry('daily_activities.review', 70, 'Open daily activity review queue', 'የዕለታዊ እንቅስቃሴ የግምገማ ወረፋ ይክፈቱ',
        'Open the review queue for assigned employees.',
        'ለተመደቡ ሠራተኞች የግምገማ ወረፋውን ይክፈቱ።'),
    $entry('daily_activities.approve', 80, 'Approve daily activity', 'ዕለታዊ እንቅስቃሴ ያጽድቁ',
        'Approve submitted daily activity of assigned employees.',
        'የተመደቡ ሠራተኞችን የቀረበ ዕለታዊ እንቅስቃሴ ያጽድቁ።'),
    $entry('daily_activities.return_for_correction', 90, 'Return daily activity for correction', 'ዕለታዊ እንቅስቃሴ ለእርማት ይመልሱ',
        'Return submitted daily activity to the employee with a required comment.',
        'የቀረበ ዕለታዊ እንቅስቃሴ ከአስተያየት ጋር ለሠራተኛው ለእርማት ይመልሱ።'),
    $entry('daily_activities.reopen', 100, 'Reopen approved daily activity', 'የጸደቀ ዕለታዊ እንቅስቃሴ እንደገና ይክፈቱ',
        'Reopen an approved daily activity for correction. Requires a reason and is audited.',
        'የጸደቀ ዕለታዊ እንቅስቃሴ ለእርማት እንደገና ይክፈቱ። ምክንያት ያስፈልጋል፤ በኦዲት ይመዘገባል።'),

    // Scoped oversight
    $entry('daily_activities.view_scoped', 110, 'View daily activity in scope', 'በወሰን ውስጥ ያለ ዕለታዊ እንቅስቃሴ ይመልከቱ',
        'View daily activity of employees inside your organization scope.',
        'በድርጅት ወሰንዎ ውስጥ ያሉ ሠራተኞችን ዕለታዊ እንቅስቃሴ ይመልከቱ።'),
    $entry('daily_activities.view_reports', 120, 'View daily activity reports', 'የዕለታዊ እንቅስቃሴ ሪፖርቶችን ይመልከቱ',
        'View daily activity dashboards and reports inside your organization scope.',
        'በድርጅት ወሰንዎ ውስጥ የዕለታዊ እንቅስቃሴ ዳሽቦርዶችን እና ሪፖርቶችን ይመልከቱ።'),
    $entry('daily_activities.export', 130, 'Export daily activity reports', 'የዕለታዊ እንቅስቃሴ ሪፖርቶችን ይላኩ',
        'Export daily activity reports to Excel, CSV or PDF inside your organization scope.',
        'የዕለታዊ እንቅስቃሴ ሪፖርቶችን በድርጅት ወሰንዎ ውስጥ ወደ Excel፣ CSV ወይም PDF ይላኩ።'),
    $entry('daily_activities.manage_reviewers', 140, 'Assign daily activity reviewers', 'የዕለታዊ እንቅስቃሴ ገምጋሚዎችን ይመድቡ',
        'Assign which users review the daily activity of which units or employees, inside your organization scope.',
        'በድርጅት ወሰንዎ ውስጥ የትኞቹ ተጠቃሚዎች የየትኞቹን ክፍሎች ወይም ሠራተኞች ዕለታዊ እንቅስቃሴ እንደሚገመግሙ ይመድቡ።'),

    // Module settings
    $entry('daily_activity_settings.view', 10, 'View daily activity settings', 'የዕለታዊ እንቅስቃሴ ቅንብሮችን ይመልከቱ',
        'View Daily Activity module settings such as deadlines, backdating and reminders.',
        'እንደ የማስገቢያ ጊዜ ገደብ፣ ወደኋላ ማስገባት እና ማስታወሻ ያሉ የዕለታዊ እንቅስቃሴ ቅንብሮችን ይመልከቱ።',
        'daily_activity_settings'),
    $entry('daily_activity_settings.update', 20, 'Update daily activity settings', 'የዕለታዊ እንቅስቃሴ ቅንብሮችን ያሻሽሉ',
        'Change Daily Activity module settings. Does not grant any access permissions.',
        'የዕለታዊ እንቅስቃሴ ቅንብሮችን ይቀይሩ። ምንም የመዳረሻ ፈቃድ አይሰጥም።',
        'daily_activity_settings'),
];
