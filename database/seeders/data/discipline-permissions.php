<?php

declare(strict_types=1);

/*
 * Employee Discipline permissions (docs/employee-discipline.md). Separate
 * duties, never one broad permission. Holding a permission is necessary but
 * not sufficient: DisciplinaryCaseAccess also checks organization scope, the
 * user's seat on the case and the accused-party conflict.
 */
$entry = static fn (string $name, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm): array => [
    'name' => $name,
    'group' => 'discipline',
    'sort_order' => $sortOrder,
    'is_system' => true,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    $entry('discipline.view_own', 10, 'View own disciplinary cases', 'የራስን የዲሲፕሊን ጉዳዮች ይመልከቱ',
        'See disciplinary cases in which the user is the accused employee.',
        'ተጠቃሚው ተከሳሽ የሆነባቸውን የዲሲፕሊን ጉዳዮች ማየት።'),
    $entry('discipline.respond_own', 20, 'Answer own disciplinary case', 'የራስን የዲሲፕሊን ክስ መልስ ይስጡ',
        'File the defence, defence evidence and final statement in the user\'s own case.',
        'በራስ ጉዳይ ላይ የመከላከያ መልስ፣ የመከላከያ ማስረጃ እና የመጨረሻ ሀሳብ ማቅረብ።'),
    $entry('discipline.view', 30, 'View disciplinary cases', 'የዲሲፕሊን ጉዳዮችን ይመልከቱ',
        'Open disciplinary cases of organizations within the user\'s scope.',
        'በተጠቃሚው ወሰን ውስጥ ያሉ ተቋማትን የዲሲፕሊን ጉዳዮች መክፈት።'),
    $entry('discipline.create', 40, 'Open disciplinary cases', 'የዲሲፕሊን ጉዳይ ይክፈቱ',
        'Open a draft case and record each charge with its particulars and evidence list.',
        'ረቂቅ ጉዳይ መክፈት እና እያንዳንዱን ክስ ከዝርዝሩና ከማስረጃ ዝርዝሩ ጋር መመዝገብ።'),
    $entry('discipline.charge', 50, 'File and serve charges', 'ክስ ያቅርቡ እና መጥሪያ ይላኩ',
        'Sign and file the charge, serve the summons, and discontinue a charge before a recommendation.',
        'ክሱን ፈርሞ ማቅረብ፣ መጥሪያ መላክ እና ከውሳኔ ሀሳብ በፊት ክስ ማቋረጥ።'),
    $entry('discipline.manage', 60, 'Manage disciplinary cases', 'የዲሲፕሊን ጉዳዮችን ያስተዳድሩ',
        'Assign the committee or investigator, decide recusals, and close or end cases.',
        'ኮሚቴ ወይም አጣሪ መመደብ፣ ከጉዳይ መነሳትን መወሰን እና ጉዳዮችን መዝጋት።'),
    $entry('discipline.investigate', 70, 'Record the investigation', 'ምርመራውን ይመዝግቡ',
        'Seated committee member or investigator: record responses, evidence, witnesses and objections.',
        'የተመደበ የኮሚቴ አባል ወይም አጣሪ፡ መልሶችን፣ ማስረጃዎችን፣ ምስክሮችን እና መቃወሚያዎችን መመዝገብ።'),
    $entry('discipline.recommend', 80, 'Adopt the recommendation', 'የውሳኔ ሀሳብ ያጽድቁ',
        'Committee chair, secretary or investigator: record the vote and submit the recommendation to the head.',
        'የኮሚቴ ሰብሳቢ፣ ፀሐፊ ወይም አጣሪ፡ ድምፅ መመዝገብ እና የውሳኔ ሀሳቡን ለበላይ ኃላፊው ማቅረብ።'),
    $entry('discipline.decide', 90, 'Decide disciplinary cases', 'በዲሲፕሊን ጉዳይ ይወስኑ',
        'Head of institution: approve, modify or overturn the recommendation, or order re-examination.',
        'የበላይ ኃላፊ፡ የውሳኔ ሀሳቡን ማጽደቅ፣ ማሻሻል፣ መሻር ወይም እንደገና እንዲመረመር ማዘዝ።'),
    $entry('discipline.deliver_decision', 100, 'Record decision delivery', 'የውሳኔ መድረስን ይመዝግቡ',
        'Record when and how the signed decision reached the employee.',
        'የተፈረመው ውሳኔ ለሠራተኛው መቼና እንዴት እንደደረሰ መመዝገብ።'),
    $entry('discipline.view_reports', 110, 'View discipline reports', 'የዲሲፕሊን ሪፖርቶችን ይመልከቱ',
        'Aggregate discipline figures and deadline compliance within scope; no case content.',
        'በወሰን ውስጥ አጠቃላይ የዲሲፕሊን አሃዞችና የጊዜ ገደብ ተገዢነት፤ የጉዳይ ይዘት የለም።'),
];
