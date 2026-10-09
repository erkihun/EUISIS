/* grievanceCases — የሠራተኞች የጉዳይ ዝርዝሮች፣ ዳሽቦርድ እና የጉዳይ ዝርዝር ገጽ። */
const grievanceCases = {
    dashboard: 'የቅሬታ ዳሽቦርድ',
    assigned: 'የተመደቡ ጉዳዮች',
    authorized: 'የተፈቀዱ ጉዳዮች በሙሉ',
    intake: 'የመቀበያ ግምገማ',
    queue: 'የእኔ ወረፋ (ቀድሞ የሚያበቃው መጀመሪያ)',
    empty: 'የሚታይ ነገር የለም።',
    metrics: {
        total: 'ለእኔ የተመደቡ', new: 'አዲስ (ገና ያልተቀበሉ)', due_soon: 'ጊዜያቸው የቀረበ', overdue: 'ጊዜያቸው ያለፈ', awaiting_hearing: 'ችሎት የሚጠብቁ',
        awaiting_decision: 'ውሳኔ የሚጠብቁ', returned: 'የተመለሱ ውሳኔዎች', escalated_in: 'ከፍ ብለው ወይም በይግባኝ የመጡ', pending_approval: 'የበላይ ማጽደቅ የሚጠብቁ',
    },
    dash: {
        subtitle: 'በእርስዎ ወይም በአካልዎ የሚስተናገዱ ጉዳዮች፤ ቀነ ገደባቸው የቀረበው መጀመሪያ።',
        review_intake: 'መቀበያ ይገምግሙ', open_list: 'ዝርዝሩን ክፈት', review: 'ይገምግሙ', act_now: 'አሁን ይፈጽሙ',
        total_hint: 'በእርስዎ ደረጃ ያሉ ክፍት ጉዳዮች', new_hint: 'ማስተናገድ ለመጀመር መቀበልዎን ያረጋግጡ',
        due_soon_hint: 'ዛሬ የሚያበቁትን ጨምሮ', overdue_hint: 'የደረጃው ቀነ ገደብ ያለፈባቸው', nothing_overdue: 'ጊዜው ያለፈ ጉዳይ የለም',
        where: 'ጉዳዮቼ የት ናቸው', open_count: ':n ክፍት',
        where_note: 'የክፍት ጉዳዮችዎ ብዛት። ከፍ ብሎ ወይም በይግባኝ የመጣ ጉዳይ በደረጃውም ይቆጠራል።',
        attention: 'ትኩረት የሚሹ', overdue_list: 'ጊዜያቸው ያለፈ', all_clear: 'ጊዜው ያለፈ ወይም የቀረበ የተመደበ ጉዳይ የለም።',
        attention_count: ':n ጊዜያቸው የቀረበ ወይም ያለፈ', open_cases: 'ጉዳዮቼን ክፈት',
        queue: 'የእኔ ወረፋ', queue_help: 'ቀድሞ የሚያበቃው መጀመሪያ · ቀጣዮቹ 10 ጉዳዮች', view_all: 'ሁሉንም ጉዳዮች ይመልከቱ',
        work_areas: 'ሌሎች የሥራ ክፍሎች', intake_hint: ':n የመቀበያ ውሳኔ የሚጠብቁ', approvals_hint: ':n እርስዎን የሚጠብቁ ውሳኔዎች',
        correspondence_hint: 'ኦፊሴላዊ ደብዳቤዎች፣ ማኅተሞች እና መላኪያ', committees_hint: 'አባልነት እና የጉዳይ ፓነሎች', reports_hint: 'አጠቃላይ፣ በሂደት ላይ ያተኮሩ ሪፖርቶች',
    },

    tabs: 'የጉዳዩ ክፍሎች',
    tab_overview: 'አጠቃላይ', tab_timeline: 'የጊዜ መስመር', tab_stages: 'ደረጃዎች', tab_evidence: 'ማስረጃ', tab_information: 'የመረጃ ጥያቄዎች',
    tab_hearings: 'ችሎቶች', tab_minutes: 'ቃለ ጉባኤ', tab_decision: 'ውሳኔ', tab_letters: 'ደብዳቤዎች', tab_appeals: 'ይግባኝ',
    tab_notes: 'ማስታወሻዎች እና ተግባራት', tab_outcomes: 'ውጤቶች', tab_audit: 'ኦዲት',
    workflow: 'የሥራ ሂደት ተግባራት', no_actions: 'በዚህ ጉዳይ ላይ አሁን ለእርስዎ የሚገኝ ተግባር የለም።',
    your_role: 'በዚህ ጉዳይ ላይ ያለዎት ሚና',
    oversight_notice: 'ይህን ጉዳይ ለቁጥጥር እያዩ ነው። ለእርስዎ በንባብ ብቻ ነው፤ መዳረሻዎም ይመዘገባል።',
    recusal_notice: 'በዚህ ደረጃ የጥቅም ግጭት ገልጸዋል። በጉዳዩ ላይ እርምጃ መውሰድ አይችሉም።',

    intake_decision: 'የመቀበያ ውሳኔ', intake_accept: 'ተቀብለህ ምራ', intake_return: 'ለማስተካከያ መልስ', intake_reject: 'በመቀበያ ውድቅ አድርግ',
    intake_submit: 'የመቀበያ ውሳኔውን መዝግብ', intake_reason_help: 'ሲመለስ ወይም ውድቅ ሲደረግ ያስፈልጋል።', intake_notes: 'የመቀበያ ማስታወሻ',
    receive: 'መቀበሉን አረጋግጥ', receive_help: 'አካልዎ ጉዳዩን መቀበሉን ያረጋግጣል።',
    start_review: 'ግምገማ ጀምር', start_review_help: 'በዚህ ደረጃ ጉዳዩን ለግምገማ በይፋ ይቀበላል።',
    withdrawal: 'ቅሬታ ማንሳት', approve_withdrawal: 'ማንሳቱን አጽድቅ (ካልተመረጠ ውድቅ ይደረጋል)', withdrawal_help: 'ግምገማ ከተጀመረ በኋላ ቅሬታ አቅራቢው ለማንሳት ጠይቋል።',
    move: 'ከፍ አድርግ፣ እንደገና መድብ፣ ምራ ወይም መልስ', target: 'የተዋቀረ መስመር', move_submit: 'ጉዳዩን አዛውር',
    move_help: 'የሚቀርቡት የተዋቀሩ እና የጸደቁ መስመሮች ብቻ ናቸው። ዝውውሩ ከምክንያቱ ጋር ይመዘገባል።',
    assign_officer: 'የጉዳይ ኃላፊ መድብ', officer: 'ኃላፊ', officers: 'የጉዳይ ኃላፊዎች', release: 'አንሳ', released: 'ተነስቷል',
    request_pause: 'የጊዜ ገደቡን አቁም', pause_help: 'የማቆም ፈቃድ ከሌለዎት ማጽደቅ ያስፈልገዋል። እያንዳንዱ ማቆሚያ ይመዘገባል።',
    declare_recusal: 'የጥቅም ግጭት ግለጽ', recusal_help: 'ሲጸድቅ ከዚህ ጉዳይ ይወገዳሉ፤ ማየት፣ ድምጽ መስጠት፣ መፈረም ወይም ቃለ ጉባኤ ማሻሻል አይችሉም።',
    classify: 'ምደባ', classify_help: 'መለያዎቹ ሂደቱን ይገልጻሉ፤ ጥፋትን አያመለክቱም።',
    respondent: 'የሚመለከተው', respondent_type: 'የሚመለከተው (አይነት)', root_cause: 'የመሠረታዊ ምክንያት ምድብ', systemic_issue: 'ሥርዓታዊ ችግር', corrective_required: 'የእርምት እርምጃ ያስፈልጋል',
    close_case: 'ጉዳዩን ዝጋ', reopen: 'እንደገና ክፈት', retention: 'ማቆያ እና የሕግ እገዳ',
    set_hold: 'የሕግ እገዳ ጣል', lift_hold: 'የሕግ እገዳ አንሳ', archive: 'ወደ ማህደር አስገባ', archive_help: 'ወደ ማህደር የሚገቡት የማቆያ ጊዜያቸው ያለፈ፣ የሕግ እገዳ የሌለባቸው የተዘጉ ጉዳዮች ብቻ ናቸው።',

    accepted_at: 'የተቀበለበት', resolved_at: 'የተፈታበት', closed_at: 'የተዘጋበት', reason_codes: 'የምክንያት ኮዶች', legal_hold: 'የሕግ እገዳ',
    retention_until: 'እስከ ይቆያል', reopened: 'እንደገና የተከፈተበት ብዛት', amendments: 'ከመመለስ በኋላ የተደረጉ ማስተካከያዎች', changes: 'ለውጦች',

    current_stage: 'የአሁኑ ደረጃ', movement: 'እንዴት እንደደረሰ', paused_days: 'የቆሙ ቀናት', panel: 'ለዚህ ጉዳይ የተሰየመ የኮሚቴ ፓነል',
    replacement: 'ተተኪ', recused: 'ራሱን አግልሏል', left: 'ኮሚቴውን ለቋል', pauses: 'የጊዜ ገደብ ማቆሚያዎች', stage_history: 'የማስተናገድ ታሪክ',
    pause_reason: 'ምክንያት', pause_status: 'ሁኔታ', pause_requested: 'የተጠየቀው', pause_period: 'ጊዜ', pause_due_change: 'የጊዜ ገደብ በፊት → በኋላ', pause_actions: 'ተግባራት',
    approve: 'አጽድቅ', reject: 'ውድቅ አድርግ', resume: 'ቀጥል',
    col_stage: 'ደረጃ', col_handler: 'አስተናጋጅ', col_movement: 'እንቅስቃሴ', col_status: 'ሁኔታ', col_received: 'የደረሰበት', col_due: 'የጊዜ ገደብ', col_completed: 'የተጠናቀቀበት',
    recusals: 'የጥቅም ግጭቶች', recusal_member: 'አባል', recusal_reason: 'ምክንያት', recusal_status: 'ሁኔታ', recusal_decision: 'ውሳኔ', recusal_actions: 'ተግባራት',
    find_replacement: 'ተተኪ በስም ወይም በቁጥር ፈልግ', approve_recusal: 'ራስን ማግለሉን አጽድቅ', decide: 'ውሳኔውን መዝግብ',

    ev_title: 'ርዕስ', ev_type: 'አይነት', ev_size: 'መጠን', ev_classification: 'ምደባ', ev_status: 'ሁኔታ', ev_submitted: 'የቀረበው', ev_actions: 'ተግባራት',
    by_complainant: 'ቅሬታ አቅራቢ', accept: 'ተቀበል', more: 'ተጨማሪ', new_version: 'አዲስ ስሪት ጫን', custody: 'የጥበቃ ሰንሰለት',
    upload_evidence: 'ማስረጃ አክል', evidence_help: 'ፋይሎች በግል ማከማቻ ይቀመጣሉ፤ ይዘታቸውም ይፈተሻል። ተቀባይነት ያገኘ ማስረጃ አይተካም፤ አዲስ ስሪት ከጎኑ ይቀመጣል።',

    pauses_sla: 'የጊዜ ገደቡን ያቆማል', record_response: 'ምላሽ መዝግብ', response: 'ምላሽ', close_request: 'ጥያቄውን ዝጋ', cancel_request: 'ጥያቄውን ሰርዝ',
    new_request: 'መረጃ ጠይቅ', requested_from: 'የተጠየቀው አካል', requested_from_name: 'የሰውዬው ወይም የአካሉ ስም', requested_from_name_help: 'ለሰው ሀብት እና ለሌሎች ተቋማት ያስፈልጋል።', request_text: 'የሚያስፈልገው መረጃ',

    scheduled_at: 'ቀን እና ሰዓት', location: 'ቦታ', duration: 'ቆይታ (ደቂቃ)', chair: 'ሰብሳቢ', meeting_link: 'የስብሰባ ሊንክ', held_at: 'የተካሄደበት',
    cancellation_reason: 'የስረዛ ምክንያት', update_hearing: 'ችሎቱን አሻሽል', schedule_hearing: 'ችሎት ያዝ', mode: 'ሁኔታ', agenda: 'አጀንዳ',
    hearing_help: 'ቅሬታ አቅራቢው እና ንቁ የፓነል አባላት በራስ-ሰር ይጋበዛሉ።',
    min_summary: 'ማጠቃለያ', min_discussion: 'ውይይት', min_resolutions: 'ውሳኔዎች', attendees: 'ተሳታፊዎች', confirmed_by: 'ያረጋገጠው',
    confirm_minutes: 'ቃለ ጉባኤውን አረጋግጥ', confirm_minutes_help: 'የተረጋገጠ ቃለ ጉባኤ የሚቀየረው በማሻሻያ ብቻ ነው።', amend_minutes: 'አሻሽል (አዲስ ስሪት)', new_minutes: 'ቃለ ጉባኤ መዝግብ',
    amendment_reason: 'የማሻሻያ ምክንያት',

    approver_actions: 'የበላይ ማጽደቅ', approver_help: 'የሚታየው ለተዋቀረው አጽዳቂ ብቻ ነው፤ ሥልጣንዎ በአገልጋዩ እንደገና ይረጋገጣል።',
    comment_required_help: 'ሲመለስ ወይም ውድቅ ሲደረግ ያስፈልጋል።',

    generate_letter: 'ከአብነት ደብዳቤ አዘጋጅ', language: 'ቋንቋ', no_reference: 'ገና የወጪ ቁጥር የለውም', signed_by: 'የፈረመው', sealed: 'ማኅተም ተደርጓል',
    visible_to_complainant: 'ለቅሬታ አቅራቢው ይታያል', body: 'የደብዳቤው ጽሑፍ', void_reason: 'የተሰረዘበት ምክንያት',
    finalize_letter: 'አጠናቅቅ (የወጪ ቁጥር ስጥ)', sign: 'ፈርም', signature_method: 'የፊርማ ዘዴ',
    signature_help: 'በPDF ላይ ያለ የፊርማ ምስል ምስጢራዊ ዲጂታል ፊርማ አይደለም።', apply_seal: 'ይፋዊ ማኅተም አድርግ', seal: 'ማኅተም',
    issue: 'አውጣ', issue_help: 'ማውጣት ደብዳቤውን ያጸናል፤ ማስተካከያ መሰረዝ እና አዲስ ስሪት ይፈልጋል።', void: 'ሰርዝ', revise_letter: 'የተስተካከለ ረቂቅ ፍጠር',
    dispatches: 'የመላኪያ መዝገብ', acknowledge: 'መድረሱን መዝግብ', dispatch: 'መላኩን መዝግብ', channel: 'መንገድ',
    dispatch_help: 'ኤስኤምኤስ እና ኢሜይል ማሳወቂያ ብቻ ይይዛሉ፤ የደብዳቤውን ይዘት በፍጹም። «ደርሷል» የሚመዘገበው ሲረጋገጥ ብቻ ነው።',
    hash_help: 'የተቀመጠው ፋይል በስህተት መተካቱን ይለያል። ዲጂታል ፊርማ አይደለም።',

    ap_status: 'ሁኔታ', ap_filed: 'የቀረበበት', ap_deadline: 'የመጨረሻ ቀን', ap_stages: 'ከ → ወደ ደረጃ', ap_reason: 'ምክንያት',

    notes: 'የውስጥ ማስታወሻዎች', notes_help: 'ለቅሬታ አቅራቢው በፍጹም አይታይም።', note: 'ማስታወሻ', add_note: 'ማስታወሻ አክል',
    tasks: 'ተግባራት', add_task: 'ተግባር አክል', task_title: 'ተግባር', mark_done: 'ተጠናቋል በል',
    corrective_actions: 'የእርምት እርምጃዎች', add_corrective: 'የእርምት እርምጃ አክል', referrals: 'የዲሲፕሊን ሪፈራሎች', add_referral: 'ወደ ዲሲፕሊን ሂደት ምራ',
    referral_help: 'ሪፈራል የተለየ፣ የተያያዘ መዝገብ ነው። ቅሬታው በፍጹም ወደ ዲሲፕሊን ጉዳይ አይቀየርም።',

    au_event: 'ክስተት', au_actor: 'በ', au_when: 'መቼ', au_values: 'የተመዘገቡ እሴቶች', show_values: 'አሳይ', system: 'ሥርዓቱ (በመርሐ ግብር)',
};

export default grievanceCases;
