<?php

declare(strict_types=1);

/* በማሳወቂያ ደወልና በኢሜይል የሚላኩ መልእክቶች። */
return [
    'action' => 'በሪፖርት ሥርዓቱ ይክፈቱ',

    'submission_before_subject' => 'ሪፖርት ማቅረቢያ :days ቀን ቀርቶታል፦ :type',
    'submission_before_body' => 'የ:period :type (:institution) እስከ :due ድረስ መቅረብ አለበት።',
    'submission_due_subject' => 'ሪፖርት ዛሬ መቅረብ አለበት፦ :type',
    'submission_due_body' => 'የ:period :type (:institution) እስከ :due ድረስ መቅረብ አለበት።',
    'submission_overdue_subject' => 'ሪፖርት ጊዜው አልፏል፦ :type',
    'submission_overdue_body' => 'የ:period :type (:institution) እስከ :due ድረስ መቅረብ ነበረበት፤ እስካሁን አልቀረበም።',
    'submission_escalated_subject' => 'ለበላይ አካል የቀረበ፦ ሪፖርት እስካሁን አልቀረበም — :type',
    'submission_escalated_body' => 'የ:period :type (:institution) እስከ :due ድረስ መቅረብ ነበረበት፤ አሁንም አልቀረበም።',

    'revision_before_subject' => 'ማስተካከያ ማቅረቢያ :days ቀን ቀርቶታል፦ :type',
    'revision_before_body' => 'የተመለሰው የ:period :type (:institution) እስከ :due ድረስ እንደገና መቅረብ አለበት።',
    'revision_due_subject' => 'ማስተካከያ ዛሬ መቅረብ አለበት፦ :type',
    'revision_due_body' => 'የተመለሰው የ:period :type (:institution) እስከ :due ድረስ እንደገና መቅረብ አለበት።',
    'revision_overdue_subject' => 'የማስተካከያ ጊዜው አልፏል፦ :type',
    'revision_overdue_body' => 'የተመለሰው የ:period :type (:institution) እስከ :due ድረስ እንደገና መቅረብ ነበረበት።',
    'revision_escalated_subject' => 'ለበላይ አካል የቀረበ፦ ማስተካከያ እስካሁን አልቀረበም — :type',
    'revision_escalated_body' => 'የተመለሰው የ:period :type (:institution) እስከ :due ድረስ እንደገና መቅረብ ነበረበት።',

    'review_before_subject' => 'ግምገማ ማጠናቀቂያ :days ቀን ቀርቶታል፦ :institution',
    'review_before_body' => ':institution ያቀረበው የ:period :type ግምገማ እስከ :due ድረስ መጠናቀቅ አለበት።',
    'review_due_subject' => 'ግምገማ ዛሬ መጠናቀቅ አለበት፦ :institution',
    'review_due_body' => ':institution ያቀረበው የ:period :type ግምገማ እስከ :due ድረስ መጠናቀቅ አለበት።',
    'review_overdue_subject' => 'የግምገማ ጊዜው አልፏል፦ :institution',
    'review_overdue_body' => ':institution ያቀረበው የ:period :type ግምገማ እስከ :due ድረስ መጠናቀቅ ነበረበት።',
    'review_escalated_subject' => 'ለበላይ አካል የቀረበ፦ ግምገማ እስካሁን አልተጠናቀቀም — :institution',
    'review_escalated_body' => ':institution ያቀረበው የ:period :type ግምገማ እስከ :due ድረስ መጠናቀቅ ነበረበት፤ አሁንም ክፍት ነው።',

    'workflow_submitted_subject' => 'ሪፖርት ደርሷል፦ :institution',
    'workflow_submitted_body' => ':institution የ:period :type (:reference) አቅርቧል። ግምገማ እየጠበቀ ነው።',
    'workflow_returned_subject' => 'ሪፖርት ለማስተካከያ ተመልሷል፦ :type',
    'workflow_returned_body' => 'የ:period :type (:reference) ተመልሷል። የገምጋሚውን አስተያየት አንብበው አስተካክለው እንደገና ያቅርቡ።',
    'workflow_approved_subject' => 'ሪፖርት ጸድቋል፦ :type',
    'workflow_approved_body' => 'የ:period :type (:reference) ጸድቋል።',
    'workflow_rejected_subject' => 'ሪፖርት ውድቅ ተደርጓል፦ :type',
    'workflow_rejected_body' => 'የ:period :type (:reference) ውድቅ ተደርጓል። ምክንያቱ በሪፖርቱ ገጽ ላይ ይገኛል።',
    'workflow_follow_up_assigned_subject' => 'የክትትል ተግባር ተሰጥቶዎታል',
    'workflow_follow_up_assigned_body' => 'የክትትል ተግባር ተሰጥቶዎታል፦ :title።',
];
