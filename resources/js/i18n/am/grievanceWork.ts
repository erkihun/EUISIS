/* grievanceWork — ውሳኔዎች፣ ማጽደቂያዎች፣ ይግባኞች፣ የደብዳቤ መዝገብ እና ሪፖርቶች። */
const grievanceWork = {
    decision: 'ውሳኔ', decision_type: 'የውሳኔ አይነት', new_decision: 'ውሳኔ አርቅቅ', save_decision: 'ውሳኔውን አስቀምጥ',
    findings: 'ግኝቶች', facts_considered: 'የታዩ ፍሬ ነገሮች', legal_basis: 'የሕግ መሠረት', analysis: 'ትንተና', decision_text: 'ውሳኔ',
    recommendations: 'የውሳኔ ሃሳቦች', corrective_action_required: 'የእርምት እርምጃ ያስፈልጋል', disciplinary_referral_recommended: 'ወደ ዲሲፕሊን እንዲመራ ይመከራል',
    transition: 'ቀጣይ እርምጃ', submit_for_review: 'ለውስጥ ግምገማ ላክ', endorse: 'ደግፍ', return_internal: 'ለአዘጋጁ መልስ',
    submit_for_approval: 'ለበላይ ማጽደቅ ላክ', finalize: 'አጠናቅቅ', revise: 'የተስተካከለ ስሪት ፍጠር', vote: 'ድምጽ', opinion: 'አስተያየት / የተለየ ሃሳብ',

    approvals: 'የበላይ ማጽደቂያዎች', pending: 'በመጠባበቅ ላይ', history: 'ታሪክ', review_case: 'ጉዳዩን ክፈት', record_approval: 'የማጽደቅ ውሳኔ መዝግብ',
    approve: 'አጽድቅ', return_for_correction: 'ለማስተካከያ መልስ', reject: 'ውድቅ አድርግ', comment_required: 'ለመመለስ ወይም ውድቅ ለማድረግ አስተያየት ያስፈልጋል።',

    appeals: 'ይግባኞች',

    correspondence: 'የደብዳቤ መዝገብ',
    correspondence_help: 'እርስዎ ማየት የሚችሏቸው ይፋዊ የቅሬታ ደብዳቤዎች። የመዝገብ ቤት ኃላፊዎች የተፈረሙ ደብዳቤዎችን እዚህ ማኅተም ያደርጋሉ፣ ያወጣሉ፣ ይልካሉ።',
    search_reference: 'የወጪ ወይም የጉዳይ ቁጥር', no_letters: 'ደብዳቤ የለም።', sealed: 'ማኅተም ተደርጓል', apply_seal: 'ማኅተም አድርግ', seal: 'ማኅተም', issue: 'አውጣ',
    dispatch: 'መላኩን መዝግብ', channel: 'መንገድ',
    col_reference: 'የወጪ ቁጥር', col_case: 'ጉዳይ', col_type: 'አይነት', col_subject: 'ርዕሰ ጉዳይ', col_status: 'ሁኔታ', col_signed: 'የፈረመው', col_issued: 'የወጣበት', col_actions: 'ተግባራት',

    reports: 'የቅሬታ ሪፖርቶች',
    reports_note: 'የሂደት ማጠቃለያ ሪፖርቶች። ቅሬታ ያቀረቡ ሠራተኞችን ደረጃ አያወጡም፣ አይዘረዝሩም።',
    no_rows: 'ለእነዚህ ማጣሪያዎች መረጃ የለም።', suppressed: 'ከ :min ያነሱ ጉዳዮች ያሏቸው :count ቡድን(ኖች) ለግላዊነት ተደብቀዋል።',
    export_csv: 'CSV ላክ', export_xlsx: 'Excel ላክ', export_pdf: 'PDF ላክ',
    report_summary: 'ማጠቃለያ', report_by_organization: 'በተቋም', report_by_category: 'በምድብ', report_by_handler: 'በአስተናጋጅ አካል',
    report_by_status: 'በሁኔታ', report_overdue: 'ጊዜ ያለፈባቸው', report_escalations: 'ማሳደጊያዎች', report_appeals: 'ይግባኞች', report_approvals: 'የበላይ ማጽደቂያዎች',
    report_resolution_time: 'የመፍቻ ጊዜ', report_sla_compliance: 'የአገልግሎት ጊዜ ተገዢነት', report_correspondence: 'ደብዳቤዎች', report_trend: 'ወርሃዊ አዝማሚያ',
    col_submitted: 'የቀረቡ', col_open: 'ክፍት', col_decision_issued: 'ውሳኔ የተሰጣቸው', col_closed: 'የተዘጉ', col_withdrawn: 'የተነሱ',
    col_rejected_at_intake: 'በመቀበያ ውድቅ የሆኑ', col_returned_for_correction: 'ለማስተካከያ የተመለሱ', col_auto_escalated: 'በራሳቸው ከፍ ያሉ',
    col_appealed: 'ይግባኝ የቀረበባቸው', col_overdue_now: 'አሁን ጊዜ ያለፈባቸው', col_name_en: 'ስም', col_total: 'ድምር', col_resolved: 'የተፈቱ',
    col_escalated_out: 'ከፍ የተደረጉ', col_overdue: 'ጊዜ ያለፈባቸው', col_key: 'ሁኔታ', col_reference_number: 'የጉዳይ ቁጥር', col_handler: 'አስተናጋጅ አካል',
    col_stage_no: 'ደረጃ', col_due_at: 'የጊዜ ገደብ', col_days_overdue: 'ያለፉ ቀናት', col_movement_type: 'እንቅስቃሴ', col_target: 'ወደ', col_pending: 'በመጠባበቅ ላይ',
    col_pending_overdue: 'ጊዜ ያለፈባቸው በመጠባበቅ ላይ', col_approved: 'የጸደቁ', col_returned: 'የተመለሱ', col_rejected: 'ውድቅ የሆኑ', col_resolved_cases: 'የተፈቱ ጉዳዮች',
    col_average_days: 'አማካይ ቀናት', col_median_days: 'መካከለኛ ቀናት', col_max_days: 'ረጅሙ (ቀናት)', col_handler_type: 'የአካል አይነት', col_stages: 'ደረጃዎች',
    col_on_time: 'በጊዜው', col_late_or_escalated: 'የዘገዩ ወይም ከፍ የተደረጉ', col_compliance_percent: 'ተገዢነት %', col_letter_type: 'የደብዳቤ አይነት',
    col_month: 'ወር', col_category: 'ምድብ', col_systemic_flagged: 'ሥርዓታዊ ተብለው የተለዩ',
};

export default grievanceWork;
