<?php

declare(strict_types=1);

/*
 * Employee Performance Management (EPMS) permissions — docs/epms-permissions.md.
 * Separated duties: plan preparer / reviewer / approver / publisher, employee,
 * manager, calibrator and appeal committee are distinct permissions.
 */
$entry = static fn (string $name, string $group, int $order, string $en, string $am): array => [
    'name' => $name,
    'group' => $group,
    'sort_order' => $order,
    'is_system' => false,
    'label_en' => $en,
    'label_am' => $am,
    'description_en' => $en.'.',
    'description_am' => $am.'።',
];

return [
    $entry('performance_cycles.view', 'performance_cycles', 10, 'View performance cycles', 'የአፈጻጸም ዑደቶችን ይመልከቱ'),
    $entry('performance_cycles.create', 'performance_cycles', 20, 'Create performance cycles', 'የአፈጻጸም ዑደቶችን ይፍጠሩ'),
    $entry('performance_cycles.update', 'performance_cycles', 30, 'Update performance cycles', 'የአፈጻጸም ዑደቶችን ያሻሽሉ'),
    $entry('performance_cycles.activate', 'performance_cycles', 40, 'Activate performance cycle phases', 'የአፈጻጸም ዑደት ምዕራፎችን ያስጀምሩ'),
    $entry('performance_cycles.close', 'performance_cycles', 50, 'Finalize or close performance cycles', 'የአፈጻጸም ዑደቶችን ያጽድቁ ወይም ይዝጉ'),
    $entry('performance_cycles.approve', 'performance_cycles', 60, 'Approve performance cycle setup', 'የአፈጻጸም ዑደት ዝግጅትን ያጽድቁ'),
    $entry('performance_cycles.publish', 'performance_cycles', 70, 'Publish performance cycles', 'የአፈጻጸም ዑደቶችን ያትሙ'),

    $entry('strategic_goals.view', 'strategic_goals', 10, 'View strategic goals', 'ስትራቴጂያዊ ግቦችን ይመልከቱ'),
    $entry('strategic_goals.create', 'strategic_goals', 20, 'Create strategic goals', 'ስትራቴጂያዊ ግቦችን ይፍጠሩ'),
    $entry('strategic_goals.update', 'strategic_goals', 30, 'Update and submit strategic goals', 'ስትራቴጂያዊ ግቦችን ያዘምኑ እና ያቅርቡ'),
    $entry('strategic_goals.delete_draft', 'strategic_goals', 40, 'Delete draft strategic goals', 'ረቂቅ ስትራቴጂያዊ ግቦችን ይሰርዙ'),
    $entry('strategic_goals.approve', 'strategic_goals', 50, 'Approve strategic goals', 'ስትራቴጂያዊ ግቦችን ያጽድቁ'),
    $entry('strategic_goals.publish', 'strategic_goals', 60, 'Publish strategic goals', 'ስትራቴጂያዊ ግቦችን ያትሙ'),
    $entry('strategic_goal_allocations.view', 'strategic_goals', 70, 'View goal allocations', 'የግብ ድልድሎችን ይመልከቱ'),
    $entry('strategic_goal_allocations.manage', 'strategic_goals', 80, 'Manage goal allocations', 'የግብ ድልድሎችን ያስተዳድሩ'),

    $entry('performance_plans.view', 'performance_plans', 10, 'View performance plans', 'የአፈጻጸም ዕቅዶችን ይመልከቱ'),
    $entry('performance_plans.create', 'performance_plans', 20, 'Create performance plans', 'የአፈጻጸም ዕቅዶችን ይፍጠሩ'),
    $entry('performance_plans.update', 'performance_plans', 30, 'Prepare and submit performance plans', 'የአፈጻጸም ዕቅዶችን ያዘጋጁ እና ያቅርቡ'),
    $entry('performance_plans.review', 'performance_plans', 40, 'Review performance plans', 'የአፈጻጸም ዕቅዶችን ይገምግሙ'),
    $entry('performance_plans.approve', 'performance_plans', 50, 'Approve performance plans', 'የአፈጻጸም ዕቅዶችን ያጽድቁ'),
    $entry('performance_plans.publish', 'performance_plans', 60, 'Publish performance plans', 'የአፈጻጸም ዕቅዶችን ያትሙ'),
    $entry('performance_objectives.manage', 'performance_plans', 70, 'Manage objectives and cascading', 'ግቦችን እና ማስተላለፍን ያስተዳድሩ'),
    $entry('performance_objectives.view', 'performance_plans', 71, 'View performance objectives', 'የአፈጻጸም ዓላማዎችን ይመልከቱ'),
    $entry('unit_performance_plans.manage', 'performance_plans', 80, 'Manage unit plans', 'የክፍል ዕቅዶችን ያስተዳድሩ'),
    $entry('position_performance_plans.manage', 'performance_plans', 90, 'Manage position plans', 'የሥራ መደብ ዕቅዶችን ያስተዳድሩ'),

    $entry('kpis.view', 'kpis', 10, 'View the KPI library', 'የKPI ቤተ-መጽሐፍትን ይመልከቱ'),
    $entry('kpis.create', 'kpis', 20, 'Create KPIs', 'KPIዎችን ይፍጠሩ'),
    $entry('kpis.update', 'kpis', 30, 'Update KPIs', 'KPIዎችን ያሻሽሉ'),
    $entry('kpi_targets.manage', 'kpis', 40, 'Manage KPI targets and amendments', 'የKPI ዒላማዎችን እና ማሻሻያዎችን ያስተዳድሩ'),
    $entry('kpi_targets.view', 'kpis', 41, 'View KPI targets', 'የKPI ዒላማዎችን ይመልከቱ'),
    $entry('kpi_actuals.enter', 'kpis', 50, 'Enter KPI actuals', 'የKPI ትክክለኛ ውጤቶችን ያስገቡ'),
    $entry('kpi_actuals.verify', 'kpis', 60, 'Verify KPI actuals', 'የKPI ትክክለኛ ውጤቶችን ያረጋግጡ'),

    $entry('employee_performance_agreements.view_own', 'performance_agreements', 10, 'View own performance agreement', 'የራስን የአፈጻጸም ስምምነት ይመልከቱ'),
    $entry('employee_performance_agreements.manage', 'performance_agreements', 20, 'Manage team performance agreements', 'የቡድን የአፈጻጸም ስምምነቶችን ያስተዳድሩ'),
    $entry('employee_performance_agreements.approve', 'performance_agreements', 30, 'Approve performance agreements', 'የአፈጻጸም ስምምነቶችን ያጽድቁ'),

    $entry('performance_checkins.view_own', 'performance_reviews', 10, 'View own check-ins', 'የራስን የክትትል ውይይቶች ይመልከቱ'),
    $entry('performance_checkins.manage', 'performance_reviews', 20, 'Record check-ins', 'የክትትል ውይይቶችን ይመዝግቡ'),
    $entry('performance_reviews.self_assess', 'performance_reviews', 30, 'Submit own self-assessment', 'የራስን ግምገማ ያቅርቡ'),
    $entry('performance_reviews.manage', 'performance_reviews', 40, 'Conduct performance reviews', 'የአፈጻጸም ግምገማዎችን ያካሂዱ'),
    $entry('performance_reviews.finalize', 'performance_reviews', 50, 'Finalize and release results', 'ውጤቶችን ያጽድቁ እና ይልቀቁ'),

    $entry('performance_calibration.view', 'performance_calibration', 10, 'View calibration sessions', 'የካሊብሬሽን ስብሰባዎችን ይመልከቱ'),
    $entry('performance_calibration.manage', 'performance_calibration', 20, 'Run calibration sessions', 'የካሊብሬሽን ስብሰባዎችን ያካሂዱ'),
    $entry('performance_calibration.finalize', 'performance_calibration', 30, 'Finalize calibration', 'ካሊብሬሽንን ያጽድቁ'),

    $entry('performance_appeals.create', 'performance_appeals', 10, 'File a performance appeal', 'የአፈጻጸም ይግባኝ ያቅርቡ'),
    $entry('performance_appeals.view_own', 'performance_appeals', 20, 'View own appeals', 'የራስን ይግባኞች ይመልከቱ'),
    $entry('performance_appeals.review', 'performance_appeals', 30, 'Review performance appeals', 'የአፈጻጸም ይግባኞችን ይገምግሙ'),
    $entry('performance_appeals.decide', 'performance_appeals', 40, 'Decide performance appeals', 'በአፈጻጸም ይግባኞች ላይ ይወስኑ'),

    $entry('performance_reports.view', 'performance_reports', 10, 'View performance dashboards and reports', 'የአፈጻጸም ዳሽቦርዶችን እና ሪፖርቶችን ይመልከቱ'),
    $entry('performance_reports.export', 'performance_reports', 20, 'Export performance reports', 'የአፈጻጸም ሪፖርቶችን ይላኩ'),

    $entry('performance_settings.view', 'performance_settings', 10, 'View performance settings', 'የአፈጻጸም ቅንብሮችን ይመልከቱ'),
    $entry('performance_settings.update', 'performance_settings', 20, 'Update performance settings', 'የአፈጻጸም ቅንብሮችን ያሻሽሉ'),
];
