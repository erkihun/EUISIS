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

    // Assessment Form Builder (docs/assessment-form-builder.md)
    $entry('assessment_forms.view', 'assessment_forms', 10, 'View assessment forms', 'የምዘና ቅጾችን ይመልከቱ'),
    $entry('assessment_forms.create', 'assessment_forms', 20, 'Create and clone assessment forms', 'የምዘና ቅጾችን ይፍጠሩ እና ይቅዱ'),
    $entry('assessment_forms.edit_draft', 'assessment_forms', 30, 'Edit draft assessment form versions', 'ረቂቅ የምዘና ቅጽ ስሪቶችን ያስተካክሉ'),
    $entry('assessment_forms.publish', 'assessment_forms', 40, 'Publish assessment form versions', 'የምዘና ቅጽ ስሪቶችን ያትሙ'),
    $entry('assessment_forms.archive', 'assessment_forms', 50, 'Archive assessment forms', 'የምዘና ቅጾችን በማህደር ያስቀምጡ'),

    // Assessment Oversight & Compliance (docs/assessment-oversight.md). Aggregate,
    // employee-status, result and criterion-response access are separate grants.
    $entry('assessment_oversight.view_dashboard', 'assessment_oversight', 10, 'View the assessment oversight dashboard', 'የምዘና ክትትል ዳሽቦርድን ይመልከቱ'),
    $entry('assessment_oversight.view_institutions', 'assessment_oversight', 20, 'Monitor institutions in scope', 'በወሰን ውስጥ ያሉ ተቋማትን ይከታተሉ'),
    $entry('assessment_oversight.view_unit', 'assessment_oversight', 25, 'Monitor own organization unit only', 'የራስን የሥራ ክፍል ብቻ ይከታተሉ'),
    $entry('assessment_oversight.view_employee_status', 'assessment_oversight', 30, 'View employee assessment status lists', 'የሠራተኞችን የምዘና ሁኔታ ዝርዝር ይመልከቱ'),
    $entry('assessment_oversight.view_results', 'assessment_oversight', 40, 'View final assessment results', 'የመጨረሻ የምዘና ውጤቶችን ይመልከቱ'),
    $entry('assessment_oversight.view_responses', 'assessment_oversight', 50, 'View criterion-level assessment responses', 'በመስፈርት ደረጃ የምዘና ምላሾችን ይመልከቱ'),
    $entry('assessment_oversight.view_demographics', 'assessment_oversight', 60, 'View gender analysis', 'የጾታ ትንተናን ይመልከቱ'),
    $entry('assessment_oversight.view_data_quality', 'assessment_oversight', 70, 'View assessment data quality', 'የምዘና መረጃ ጥራትን ይመልከቱ'),
    $entry('assessment_oversight.manage_cycles', 'assessment_oversight', 80, 'Manage assessment cycles, participation and eligibility', 'የምዘና ዙሮችን፣ ተሳትፎን እና ብቁነትን ያስተዳድሩ'),
    $entry('assessment_oversight.manage_policies', 'assessment_oversight', 90, 'Manage result bands and unassessed reasons', 'የውጤት ደረጃዎችን እና ያልተመዘኑበትን ምክንያቶች ያስተዳድሩ'),
    $entry('assessment_exclusions.request', 'assessment_exclusions', 10, 'Request an assessment exclusion', 'ከምዘና የማግለል ጥያቄ ያቅርቡ'),
    $entry('assessment_exclusions.approve', 'assessment_exclusions', 20, 'Approve or reject assessment exclusions', 'ከምዘና የማግለል ጥያቄን ያጽድቁ ወይም ውድቅ ያድርጉ'),
    $entry('assessment_submissions.submit', 'assessment_submissions', 10, 'Submit the institutional assessment summary', 'የተቋሙን የምዘና ማጠቃለያ ያቅርቡ'),
    $entry('assessment_submissions.review', 'assessment_submissions', 20, 'Review institutional assessment submissions', 'የተቋማትን የምዘና ማጠቃለያዎች ይገምግሙ'),
    $entry('assessment_submissions.return', 'assessment_submissions', 30, 'Return or reject institutional submissions', 'የተቋማትን ማጠቃለያ ለእርማት ይመልሱ ወይም ውድቅ ያድርጉ'),
    $entry('assessment_submissions.verify', 'assessment_submissions', 40, 'Verify institutional submissions', 'የተቋማትን ማጠቃለያ ያረጋግጡ'),
    $entry('assessment_submissions.finalize', 'assessment_submissions', 50, 'Finalize institutional submissions', 'የተቋማትን ማጠቃለያ ያጠናቅቁ'),
    $entry('assessment_reports.view', 'assessment_reports', 10, 'View assessment oversight reports', 'የምዘና ክትትል ሪፖርቶችን ይመልከቱ'),
    $entry('assessment_reports.export', 'assessment_reports', 20, 'Export assessment oversight reports', 'የምዘና ክትትል ሪፖርቶችን ወደ ውጭ ይላኩ'),

    // Assessment Execution & Evaluator Workspace (docs/assessment-execution.md).
    // Permission = WHAT; the evaluator assignment = WHO; scope = WHERE; status = WHEN.
    $entry('assessments.view_assigned', 'assessments', 10, 'View assessments assigned to me', 'ለእኔ የተመደቡ ምዘናዎችን ይመልከቱ'),
    $entry('assessments.complete_assigned', 'assessments', 20, 'Rate assessments assigned to me', 'ለእኔ የተመደቡ ምዘናዎችን ይሙሉ'),
    $entry('assessments.submit', 'assessments', 30, 'Submit assessments assigned to me', 'ለእኔ የተመደቡ ምዘናዎችን ያቅርቡ'),
    $entry('assessments.view_own_result', 'assessments', 40, 'View my own assessment results', 'የራሴን የምዘና ውጤት ይመልከቱ'),
    $entry('assessments.review', 'assessments', 50, 'Review submitted assessments in scope', 'በወሰን ውስጥ የቀረቡ ምዘናዎችን ይገምግሙ'),
    $entry('assessments.return_for_correction', 'assessments', 60, 'Return assessments for correction', 'ምዘናዎችን ለእርማት ይመልሱ'),
    $entry('assessments.finalize', 'assessments', 70, 'Finalize or reopen assessments', 'ምዘናዎችን ያጠናቅቁ ወይም እንደገና ይክፈቱ'),
    $entry('assessment_assignments.view', 'assessment_assignments', 10, 'View assessment assignments in scope', 'በወሰን ውስጥ የምዘና ምደባዎችን ይመልከቱ'),
    $entry('assessment_assignments.generate', 'assessment_assignments', 20, 'Generate cycle assessment assignments', 'የዙር የምዘና ምደባዎችን ያመንጩ'),
    $entry('assessment_assignments.reassign', 'assessment_assignments', 30, 'Assign or change evaluators', 'ገምጋሚዎችን ይመድቡ ወይም ይቀይሩ'),
    $entry('assessment_results.view', 'assessment_results', 10, 'View final assessment results in scope', 'በወሰን ውስጥ የመጨረሻ የምዘና ውጤቶችን ይመልከቱ'),
    $entry('assessment_results.view_detailed', 'assessment_results', 20, 'View criterion-level responses and evaluator identity', 'በመስፈርት ደረጃ ምላሾችን እና የገምጋሚ ማንነትን ይመልከቱ'),
];
