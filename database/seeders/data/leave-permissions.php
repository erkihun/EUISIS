<?php

declare(strict_types=1);

/*
 * Employee leave permissions (ADR 0039, Modules/Leave/README.md).
 *
 * Separated duties, never one broad module permission:
 *   own         the employee acting on their own leave
 *   team        the supervisor a request was routed to
 *   institution HR officers and administrators, inside their organization scope
 *   settings    the leave types and the legal figures
 * The sets each default role receives are published by the module in
 * Modules\Leave\Contracts\Data\LeaveRoles.
 */
$entry = static fn (string $name, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm): array => [
    'name' => $name,
    'group' => 'leave',
    'sort_order' => $sortOrder,
    'is_system' => false,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    // Employee self-service
    $entry('leave.view_own', 10, 'View own leave', 'የራስን ፈቃድ ይመልከቱ',
        'See your own leave balances and leave requests.',
        'የራስዎን ቀሪ የፈቃድ ቀናት እና የፈቃድ ጥያቄዎች ይመልከቱ።'),
    $entry('leave.request_own', 20, 'Ask for leave', 'ፈቃድ ይጠይቁ',
        'Submit a leave request for yourself.',
        'ለራስዎ የፈቃድ ጥያቄ ያቅርቡ።'),
    $entry('leave.cancel_own', 30, 'Withdraw own leave request', 'የራስን የፈቃድ ጥያቄ ይሰርዙ',
        'Withdraw your own request while it waits for a decision, or an approved leave that has not begun.',
        'ውሳኔ እየጠበቀ ያለ የራስዎን ጥያቄ፣ ወይም ገና ያልተጀመረ የጸደቀ ፈቃድዎን ይሰርዙ።'),

    // Supervisor
    $entry('leave.view_team', 40, 'View team leave requests', 'የቡድን የፈቃድ ጥያቄዎችን ይመልከቱ',
        'See the leave requests routed to you as the immediate supervisor. The reason of a sick or maternity leave is not shown.',
        'እንደ ቅርብ ኃላፊ ወደ እርስዎ የተላኩ የፈቃድ ጥያቄዎችን ይመልከቱ። የሕመም ወይም የወሊድ ፈቃድ ምክንያት አይታይም።'),
    $entry('leave.approve', 50, 'Decide team leave requests', 'የቡድን የፈቃድ ጥያቄዎችን ይወስኑ',
        'Approve or refuse the leave requests routed to you as the immediate supervisor. Never your own.',
        'እንደ ቅርብ ኃላፊ ወደ እርስዎ የተላኩ የፈቃድ ጥያቄዎችን ያጽድቁ ወይም ውድቅ ያድርጉ። የራስዎን ጥያቄ መወሰን አይችሉም።'),

    // Institution: HR officers and administrators
    $entry('leave.view_org', 60, 'View leave in the institution', 'የተቋሙን ፈቃድ ይመልከቱ',
        'See the leave requests and balances of employees in your organization scope.',
        'በተፈቀደልዎ የተቋም ወሰን ውስጥ ያሉ ሠራተኞችን የፈቃድ ጥያቄዎች እና ቀሪ ቀናት ይመልከቱ።'),
    $entry('leave.manage', 70, 'Confirm leave requests', 'የፈቃድ ጥያቄዎችን ያረጋግጡ',
        'Confirm or refuse the requests employees made after checking the balance, end a leave early and attach evidence, inside your organization scope. A request is always made by the employee, never entered for them.',
        'በተፈቀደልዎ የተቋም ወሰን ውስጥ ሠራተኞች ያቀረቧቸውን ጥያቄዎች ቀሪ ቀናትን አረጋግጠው ያጽድቁ ወይም ውድቅ ያድርጉ፣ ፈቃድን ቀድመው ያቋርጡ፣ ማስረጃ ያያይዙ። ጥያቄውን ሁልጊዜ የሚያቀርበው ሠራተኛው ራሱ ነው፤ ለሠራተኛ ተብሎ አይመዘገብም።'),
    $entry('leave.view_medical', 80, 'Read leave reasons and medical evidence', 'የፈቃድ ምክንያትና የሕክምና ማስረጃ ያንብቡ',
        'Privileged: read the reason and the attached evidence of sick and maternity leave. Every download is audited.',
        'ልዩ መብት፦ የሕመም እና የወሊድ ፈቃድ ምክንያትን እና የተያያዘ ማስረጃን ያንብቡ። እያንዳንዱ ማውረድ በኦዲት መዝገብ ይያዛል።'),
    $entry('leave.adjust_balance', 90, 'Correct leave balances', 'ቀሪ የፈቃድ ቀናትን ያስተካክሉ',
        'Enter opening balances and corrections to an employee\'s leave ledger, each with its reason.',
        'የመነሻ ቀሪ ቀናትን እና እርማቶችን ከምክንያታቸው ጋር በሠራተኛ የፈቃድ መዝገብ ላይ ያስገቡ።'),
    $entry('leave.postpone', 100, 'Postpone annual leave', 'የዓመት ፈቃድ ያስተላልፉ',
        'As head of the institution, let an employee use the unused annual leave of a budget year in a later year, within the years the law allows.',
        'እንደ ተቋሙ የበላይ ኃላፊ፣ ሠራተኛ በአንድ በጀት ዓመት ያልተጠቀመበትን የዓመት ፈቃድ ሕጉ በሚፈቅደው የዓመታት ገደብ ውስጥ በሚቀጥለው ዓመት እንዲጠቀም ይፍቀዱ።'),
    $entry('leave.reports.view', 110, 'View leave reports', 'የፈቃድ ሪፖርቶችን ይመልከቱ',
        'See who is on leave, leave taken by type, and unused leave about to end, inside your organization scope.',
        'በተፈቀደልዎ የተቋም ወሰን ውስጥ በፈቃድ ላይ ያሉ ሠራተኞችን፣ በዓይነት የተወሰደ ፈቃድን እና ሊያልቅ የተቃረበ ያልተወሰደ ፈቃድን ይመልከቱ።'),

    // Configuration
    $entry('leave.settings.manage', 120, 'Manage leave types and legal figures', 'የፈቃድ ዓይነቶችንና የሕግ ቁጥሮችን ያስተዳድሩ',
        'Change the leave types and the rule sets holding the figures of the law. A change of figures applies to new requests only.',
        'የፈቃድ ዓይነቶችን እና የሕጉን ቁጥሮች የያዙ የሕግ ስብስቦችን ይለውጡ። የቁጥር ለውጥ የሚሠራው በአዲስ ጥያቄዎች ላይ ብቻ ነው።'),
];
