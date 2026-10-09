<?php

declare(strict_types=1);

/*
 * Grievance Management permissions (docs/grievance-management.md §7).
 *
 * A permission is necessary but never sufficient for case work: access to a
 * case also needs complainant ownership, active committee membership on the
 * stage, a case-officer assignment, or oversight scope — see
 * GrievanceCaseAccessService. No permission here grants "see every case".
 */
$entry = static fn (string $name, string $group, int $order, string $labelEn, string $labelAm, string $descEn, string $descAm): array => [
    'name' => $name,
    'group' => $group,
    'sort_order' => $order,
    'is_system' => true,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descEn,
    'description_am' => $descAm,
];

return [
    // ── Complainant (My Portal) ─────────────────────────────────────────────
    $entry('grievances.view_own', 'grievances', 110, 'View Own Grievances', 'የራስን ቅሬታዎች ይመልከቱ',
        'See the progress, safe timeline and issued letters of grievances the user filed.', 'ተጠቃሚው ያቀረባቸውን ቅሬታዎች ሂደት፣ የጊዜ መስመር እና የወጡ ደብዳቤዎችን ማየት።'),
    $entry('grievances.create', 'grievances', 120, 'Create Grievances', 'ቅሬታ ይፍጠሩ',
        'Start a grievance draft for the user\'s own employee record.', 'ለራሱ የሠራተኛ መዝገብ የቅሬታ ረቂቅ መጀመር።'),
    $entry('grievances.update_draft', 'grievances', 130, 'Edit Own Grievance Drafts', 'የራስን የቅሬታ ረቂቅ ያሻሽሉ',
        'Edit or delete the user\'s own draft, and correct a grievance returned at intake.', 'የራስን ረቂቅ ማሻሻል ወይም መሰረዝ፣ በመቀበያ የተመለሰን ቅሬታ ማስተካከል።'),
    $entry('grievances.submit', 'grievances', 140, 'Submit Grievances', 'ቅሬታ ያቅርቡ',
        'Formally submit an own grievance, appeal a decision and answer information requests.', 'የራስን ቅሬታ በይፋ ማቅረብ፣ በውሳኔ ላይ ይግባኝ ማለት እና ለመረጃ ጥያቄዎች መልስ መስጠት።'),
    $entry('grievances.withdraw', 'grievances', 150, 'Withdraw Own Grievances', 'የራስን ቅሬታ ያንሱ',
        'Withdraw an own grievance, or request withdrawal once review has started.', 'የራስን ቅሬታ ማንሳት፣ ወይም ግምገማ ከተጀመረ በኋላ እንዲነሳ መጠየቅ።'),

    // ── Case handling ────────────────────────────────────────────────────────
    $entry('grievances.view_assigned', 'grievances', 210, 'View Assigned Grievance Cases', 'የተመደቡ የቅሬታ ጉዳዮችን ይመልከቱ',
        'Open cases at a stage the user handles (committee panel member or case officer). No other cases.', 'ተጠቃሚው በሚያስተናግደው ደረጃ ያሉ ጉዳዮችን (የኮሚቴ አባል ወይም የጉዳይ ኃላፊ) መክፈት። ሌሎች ጉዳዮች አይካተቱም።'),
    $entry('grievances.intake_review', 'grievances', 220, 'Intake Review', 'የመቀበያ ግምገማ',
        'Accept, return for correction or (where policy allows) reject submitted grievances of organizations in scope.', 'በወሰን ውስጥ ያሉ ተቋማትን የቀረቡ ቅሬታዎች መቀበል፣ ለማስተካከያ መመለስ ወይም (ፖሊሲ ሲፈቅድ) ውድቅ ማድረግ።'),
    $entry('grievances.assign', 'grievances', 230, 'Assign Case Officers', 'የጉዳይ ኃላፊዎችን ይመድቡ',
        'At a Team/Directorate stage of the user\'s unit: see its cases and assign or release case officers.', 'በተጠቃሚው ክፍል የቡድን/ዳይሬክቶሬት ደረጃ፡ ጉዳዮቹን ማየት እና የጉዳይ ኃላፊዎችን መመደብ ወይም ማንሳት።'),
    $entry('grievances.review', 'grievances', 240, 'Review Grievance Cases', 'የቅሬታ ጉዳዮችን ይገምግሙ',
        'Work an assigned case: evidence, notes, tasks, recusals, votes, corrective actions and referrals.', 'የተመደበ ጉዳይ ማስተናገድ፡ ማስረጃ፣ ማስታወሻ፣ ተግባራት፣ ራስን ማግለል፣ ድምጽ፣ የእርምት እርምጃዎች እና ሪፈራሎች።'),
    $entry('grievances.request_information', 'grievances', 250, 'Request Information', 'መረጃ ይጠይቁ',
        'Send information requests on an assigned case to the complainant, HR, a unit or another institution.', 'በተመደበ ጉዳይ ላይ ለቅሬታ አቅራቢው፣ ለሰው ሀብት፣ ለክፍል ወይም ለሌላ ተቋም የመረጃ ጥያቄ መላክ።'),
    $entry('grievances.escalate', 'grievances', 260, 'Escalate Grievance Cases', 'የቅሬታ ጉዳዮችን ያሳድጉ',
        'Manually escalate an assigned case along a configured route, with a reason.', 'የተመደበን ጉዳይ በተዋቀረ መስመር በምክንያት በእጅ ማሳደግ።'),
    $entry('grievances.reassign', 'grievances', 270, 'Reassign Grievance Cases', 'የቅሬታ ጉዳዮችን እንደገና ይመድቡ',
        'Move a case to another handler through a configured route, with a reason code.', 'ጉዳዩን በተዋቀረ መስመር በምክንያት ኮድ ወደ ሌላ አካል ማዛወር።'),
    $entry('grievances.sla_pause', 'grievances', 280, 'Approve Deadline Pauses', 'የጊዜ ገደብ ማቆሚያዎችን ያጽድቁ',
        'Approve, start and end pauses of a case stage deadline for an approved reason.', 'በጸደቀ ምክንያት የጉዳይ ደረጃ የጊዜ ገደብ ማቆሚያን ማጽደቅ፣ መጀመር እና ማብቃት።'),
    $entry('grievances.decide_recusal', 'grievances', 290, 'Decide Recusals', 'ራስን የማግለል ጥያቄዎችን ይወስኑ',
        'Approve or reject a panel member\'s conflict-of-interest recusal and appoint a replacement.', 'የኮሚቴ አባል የጥቅም ግጭት ራስን የማግለል ጥያቄን ማጽደቅ ወይም ውድቅ ማድረግ እና ተተኪ መመደብ።'),
    $entry('grievances.close', 'grievances', 300, 'Close Grievance Cases', 'የቅሬታ ጉዳዮችን ይዝጉ',
        'Close a handled case with a configured closure reason.', 'የተስተናገደን ጉዳይ በተዋቀረ የመዝጊያ ምክንያት መዝጋት።'),
    $entry('grievances.reopen', 'grievances', 310, 'Reopen Grievance Cases', 'የቅሬታ ጉዳዮችን እንደገና ይክፈቱ',
        'Reopen a closed case with a reason. Fully audited; use only where policy allows.', 'የተዘጋን ጉዳይ በምክንያት እንደገና መክፈት። ሙሉ በሙሉ ይመዘገባል፤ ፖሊሲ ሲፈቅድ ብቻ።'),
    $entry('grievances.archive', 'grievances', 320, 'Archive and Legal Hold', 'ማህደር እና የሕግ እገዳ',
        'Archive closed cases past retention and place or lift legal holds (which block archive).', 'የማቆያ ጊዜ ያለፈባቸውን የተዘጉ ጉዳዮች ማህደር ማስገባት እና የሕግ እገዳ መጣል ወይም ማንሳት።'),
    $entry('grievances.oversight_view', 'grievances', 330, 'Grievance Oversight (Read-Only)', 'የቅሬታ ቁጥጥር (ንባብ ብቻ)',
        'Read cases of organizations in scope for oversight. Highly restricted cases show metadata only.', 'በወሰን ውስጥ ያሉ ተቋማትን ጉዳዮች ለቁጥጥር ማንበብ። በጣም የተገደቡ ጉዳዮች መረጃ-ዝርዝር ብቻ ያሳያሉ።'),
    $entry('grievances.view_audit', 'grievances', 340, 'View Case Audit Trail', 'የጉዳይ ኦዲት መዝገብ ይመልከቱ',
        'See the full audit trail tab of cases the user may already open.', 'ተጠቃሚው መክፈት የሚችላቸውን ጉዳዮች ሙሉ የኦዲት መዝገብ ማየት።'),

    // ── Committees ───────────────────────────────────────────────────────────
    $entry('grievance_committees.view', 'grievance_committees', 10, 'View Grievance Committees', 'የቅሬታ ኮሚቴዎችን ይመልከቱ',
        'See committees of organizations in scope and their member history.', 'በወሰን ውስጥ ያሉ ተቋማትን ኮሚቴዎች እና የአባላት ታሪክ ማየት።'),
    $entry('grievance_committees.manage', 'grievance_committees', 20, 'Manage Grievance Committees', 'የቅሬታ ኮሚቴዎችን ያስተዳድሩ',
        'Create and edit committees of organizations in scope and end their term.', 'በወሰን ውስጥ ያሉ ተቋማትን ኮሚቴዎች መፍጠር፣ ማሻሻል እና የሥራ ዘመናቸውን ማብቃት።'),
    $entry('grievance_committees.approve', 'grievance_committees', 30, 'Approve Grievance Committees', 'የቅሬታ ኮሚቴዎችን ያጽድቁ',
        'Approve a newly constituted committee so it can receive cases. Cannot approve one the user created.', 'አዲስ የተቋቋመ ኮሚቴ ጉዳይ እንዲቀበል ማጽደቅ። ራሱ የፈጠረውን ማጽደቅ አይችልም።'),
    $entry('grievance_committee_members.manage', 'grievance_committee_members', 10, 'Manage Committee Members', 'የኮሚቴ አባላትን ያስተዳድሩ',
        'Appoint members (Chairperson, Writer, Member) and end memberships; history is kept.', 'አባላትን (ሰብሳቢ፣ ጸሐፊ፣ አባል) መሾም እና አባልነትን ማብቃት፤ ታሪኩ ይጠበቃል።'),

    // ── Routing and SLA ──────────────────────────────────────────────────────
    $entry('grievance_routes.view', 'grievance_routes', 10, 'View Grievance Routes', 'የቅሬታ መስመሮችን ይመልከቱ',
        'See the configured grievance routing graph.', 'የተዋቀረውን የቅሬታ መምሪያ መስመር ማየት።'),
    $entry('grievance_routes.manage', 'grievance_routes', 20, 'Manage Grievance Routes', 'የቅሬታ መስመሮችን ያስተዳድሩ',
        'Propose and edit routes, including cross-organization routes. New routes need approval.', 'መስመሮችን፣ ተቋም ተሻጋሪዎችን ጨምሮ፣ ማቅረብ እና ማሻሻል። አዲስ መስመሮች ማጽደቅ ይፈልጋሉ።'),
    $entry('grievance_routes.approve', 'grievance_routes', 30, 'Approve Grievance Routes', 'የቅሬታ መስመሮችን ያጽድቁ',
        'Approve a proposed route so it is used for new cases. Cannot approve one the user proposed.', 'የቀረበ መስመር ለአዲስ ጉዳዮች እንዲውል ማጽደቅ። ራሱ ያቀረበውን ማጽደቅ አይችልም።'),
    $entry('grievance_sla.view', 'grievance_sla', 10, 'View SLA Policies', 'የአገልግሎት ጊዜ ፖሊሲዎችን ይመልከቱ',
        'See deadline profiles per handler level and category.', 'በአካል ደረጃ እና ምድብ የጊዜ ገደብ መገለጫዎችን ማየት።'),
    $entry('grievance_sla.manage', 'grievance_sla', 20, 'Manage SLA Policies', 'የአገልግሎት ጊዜ ፖሊሲዎችን ያስተዳድሩ',
        'Create and end deadline profiles. Existing stages keep the deadline they were given.', 'የጊዜ ገደብ መገለጫዎችን መፍጠር እና ማብቃት። ነባር ደረጃዎች የተሰጣቸውን ጊዜ ይዘው ይቆያሉ።'),

    // ── Decisions ────────────────────────────────────────────────────────────
    $entry('grievance_decisions.view', 'grievance_decisions', 10, 'View Grievance Decisions', 'የቅሬታ ውሳኔዎችን ይመልከቱ',
        'See decision drafts and versions of cases the user may open.', 'ተጠቃሚው መክፈት የሚችላቸውን ጉዳዮች የውሳኔ ረቂቆች እና ስሪቶች ማየት።'),
    $entry('grievance_decisions.create', 'grievance_decisions', 20, 'Draft Grievance Decisions', 'የቅሬታ ውሳኔ ያርቅቁ',
        'Draft and revise the decision of an assigned case stage.', 'የተመደበ የጉዳይ ደረጃ ውሳኔን ማርቀቅ እና ማሻሻል።'),
    $entry('grievance_decisions.review', 'grievance_decisions', 30, 'Review Grievance Decisions', 'የቅሬታ ውሳኔዎችን ይገምግሙ',
        'Endorse or return a draft in internal review (e.g. committee chairperson).', 'በውስጥ ግምገማ ረቂቅን መደገፍ ወይም መመለስ (ለምሳሌ የኮሚቴ ሰብሳቢ)።'),
    $entry('grievance_decisions.submit_for_approval', 'grievance_decisions', 40, 'Submit Decisions for Approval', 'ውሳኔዎችን ለማጽደቅ ያቅርቡ',
        'Send a reviewed decision to the configured executive approver.', 'የተገመገመ ውሳኔን ለተዋቀረው የበላይ አጽዳቂ መላክ።'),
    $entry('grievance_decisions.approve', 'grievance_decisions', 50, 'Approve Grievance Decisions', 'የቅሬታ ውሳኔዎችን ያጽድቁ',
        'Approve decisions routed to the user as the configured approver (or delegate). Never one the user prepared.', 'ለተጠቃሚው እንደ ተዋቀረ አጽዳቂ (ወይም ተወካይ) የቀረቡ ውሳኔዎችን ማጽደቅ። ራሱ ያዘጋጀውን በፍጹም።'),
    $entry('grievance_decisions.return_for_correction', 'grievance_decisions', 60, 'Return Decisions for Correction', 'ውሳኔዎችን ለማስተካከያ ይመልሱ',
        'Return a decision to its preparers with a required comment.', 'ውሳኔን በአስገዳጅ አስተያየት ለአዘጋጆቹ መመለስ።'),
    $entry('grievance_decisions.reject', 'grievance_decisions', 70, 'Reject Grievance Decisions', 'የቅሬታ ውሳኔዎችን ውድቅ ያድርጉ',
        'Reject a decision with a required reason.', 'ውሳኔን በአስገዳጅ ምክንያት ውድቅ ማድረግ።'),
    $entry('grievance_decisions.finalize', 'grievance_decisions', 80, 'Finalize Grievance Decisions', 'የቅሬታ ውሳኔዎችን ያጠናቅቁ',
        'Finalize an approved (or approval-free) decision so its letter can be issued.', 'የጸደቀ (ወይም ማጽደቅ የማያስፈልገውን) ውሳኔ ደብዳቤው እንዲወጣ ማጠናቀቅ።'),

    // ── Hearings ─────────────────────────────────────────────────────────────
    $entry('grievance_hearings.view', 'grievance_hearings', 10, 'View Grievance Hearings', 'የቅሬታ ችሎቶችን ይመልከቱ',
        'See hearings and minutes of cases the user may open.', 'ተጠቃሚው መክፈት የሚችላቸውን ጉዳዮች ችሎቶች እና ቃለ ጉባኤዎች ማየት።'),
    $entry('grievance_hearings.manage', 'grievance_hearings', 20, 'Manage Grievance Hearings', 'የቅሬታ ችሎቶችን ያስተዳድሩ',
        'Schedule hearings, record attendance and draft or confirm minutes on assigned cases.', 'በተመደቡ ጉዳዮች ችሎት መያዝ፣ ተገኝነትን መመዝገብ እና ቃለ ጉባኤ ማርቀቅ ወይም ማረጋገጥ።'),

    // ── Correspondence ───────────────────────────────────────────────────────
    $entry('grievance_correspondence.view', 'grievance_correspondence', 10, 'View Grievance Correspondence', 'የቅሬታ ደብዳቤዎችን ይመልከቱ',
        'See official letters of cases the user may open and the correspondence register in scope.', 'ተጠቃሚው መክፈት የሚችላቸውን ጉዳዮች ይፋዊ ደብዳቤዎች እና በወሰን ያለውን የደብዳቤ መዝገብ ማየት።'),
    $entry('grievance_correspondence.create', 'grievance_correspondence', 20, 'Prepare Grievance Letters', 'የቅሬታ ደብዳቤዎችን ያዘጋጁ',
        'Generate letters from templates and edit drafts on assigned cases.', 'በተመደቡ ጉዳዮች ከአብነት ደብዳቤ ማመንጨት እና ረቂቆችን ማሻሻል።'),
    $entry('grievance_correspondence.sign', 'grievance_correspondence', 30, 'Sign Grievance Letters', 'የቅሬታ ደብዳቤዎችን ይፈርሙ',
        'Sign finalized letters as the authorized signatory.', 'የተጠናቀቁ ደብዳቤዎችን እንደ ተፈቀደ ፈራሚ መፈረም።'),
    $entry('grievance_correspondence.apply_seal', 'grievance_correspondence', 40, 'Apply Official Seal', 'ይፋዊ ማኅተም ያድርጉ',
        'Apply the institution\'s controlled seal to a signed letter. Every use is audited.', 'የተቋሙን ቁጥጥር ያለበት ማኅተም በተፈረመ ደብዳቤ ላይ ማድረግ። እያንዳንዱ አጠቃቀም ይመዘገባል።'),
    $entry('grievance_correspondence.issue', 'grievance_correspondence', 50, 'Issue Grievance Letters', 'የቅሬታ ደብዳቤዎችን ይስጡ',
        'Assign the outgoing reference, issue (freeze) and dispatch letters; void and re-issue with a reason.', 'የወጪ ቁጥር መስጠት፣ ደብዳቤዎችን መስጠት (ማጽናት) እና መላክ፤ በምክንያት መሰረዝ እና እንደገና መስጠት።'),

    // ── Reports, settings, controlled assets ─────────────────────────────────
    $entry('grievance_reports.view', 'grievance_reports', 10, 'View Grievance Reports', 'የቅሬታ ሪፖርቶችን ይመልከቱ',
        'Aggregate, process-focused grievance reports for organizations in scope. No employee ranking.', 'በወሰን ውስጥ ላሉ ተቋማት ሂደት-ተኮር የቅሬታ ሪፖርቶች። የሠራተኛ ደረጃ አሰጣጥ የለም።'),
    $entry('grievance_reports.export', 'grievance_reports', 20, 'Export Grievance Reports', 'የቅሬታ ሪፖርቶችን ይላኩ',
        'Export grievance reports to CSV, Excel or PDF. Exports are audited.', 'የቅሬታ ሪፖርቶችን ወደ CSV፣ Excel ወይም PDF መላክ። ይመዘገባል።'),
    $entry('grievance_settings.view', 'grievance_settings', 10, 'View Grievance Settings', 'የቅሬታ ቅንብሮችን ይመልከቱ',
        'See grievance policy settings, categories, reason codes, approval rules and letter templates.', 'የቅሬታ ፖሊሲ ቅንብሮችን፣ ምድቦችን፣ የምክንያት ኮዶችን፣ የማጽደቅ ደንቦችን እና የደብዳቤ አብነቶችን ማየት።'),
    $entry('grievance_settings.update', 'grievance_settings', 20, 'Update Grievance Settings', 'የቅሬታ ቅንብሮችን ያሻሽሉ',
        'Change grievance policy settings, categories, reason codes, approval rules, external authorities, letterheads and templates.', 'የቅሬታ ፖሊሲ ቅንብሮችን፣ ምድቦችን፣ የምክንያት ኮዶችን፣ የማጽደቅ ደንቦችን፣ የውጭ አካላትን፣ የደብዳቤ ራሶችን እና አብነቶችን መቀየር።'),
    $entry('grievance_settings.manage_delegations', 'grievance_settings', 30, 'Manage Approval Delegations', 'የማጽደቅ ውክልናዎችን ያስተዳድሩ',
        'Record acting/delegated approval authority for a period. Nobody can delegate to themselves.', 'ለተወሰነ ጊዜ የተጠባባቂ/የውክልና የማጽደቅ ሥልጣን መመዝገብ። ማንም ለራሱ ውክልና መስጠት አይችልም።'),
    $entry('grievance_settings.manage_seals', 'grievance_settings', 40, 'Manage Official Seals', 'ይፋዊ ማኅተሞችን ያስተዳድሩ',
        'Upload, approve and retire institution seals (a controlled asset on private storage).', 'የተቋም ማኅተሞችን መጫን፣ ማጽደቅ እና ማቋረጥ (በግል ማከማቻ ያለ ቁጥጥር የሚደረግበት ንብረት)።'),
];
