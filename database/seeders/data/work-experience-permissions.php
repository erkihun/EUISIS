<?php

declare(strict_types=1);

/*
 * Work experience permissions (ADR 0040, Modules/WorkExperience/README.md).
 *
 *   own       the employee reading their own recorded experience and asking for a certificate
 *   office    the office that manages employees: reads and records experience, decides requests,
 *             prints the certificate and records that it was sent
 *   settings  who signs the certificate and what it carries
 * The sets each default role receives are published by the module in
 * Modules\WorkExperience\Contracts\Data\WorkExperienceRoles.
 */
$entry = static fn (string $name, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm): array => [
    'name' => $name,
    'group' => 'work_experience',
    'sort_order' => $sortOrder,
    'is_system' => false,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    $entry('work_experience.request_own', 10, 'Ask for own work experience certificate', 'የራስን የሥራ ልምድ ማስረጃ ይጠይቁ',
        'See your own recorded work experience and ask for a written certificate.',
        'የተመዘገበውን የራስዎን የሥራ ልምድ ማየት እና የጽሑፍ ማስረጃ እንዲሰጥዎ መጠየቅ።'),
    $entry('work_experience.view', 20, 'View work experience', 'የሥራ ልምድ ይመልከቱ',
        'See the recorded work experience of employees and the certificate requests of the institutions in your scope.',
        'በእርስዎ ወሰን ውስጥ ባሉ ተቋማት የሠራተኞችን የተመዘገበ የሥራ ልምድ እና የማስረጃ ጥያቄዎችን ማየት።'),
    $entry('work_experience.records.manage', 30, 'Record work experience', 'የሥራ ልምድ ይመዝግቡ',
        'Record, correct and remove the work experience of employees, with its salary and evidence.',
        'የሠራተኞችን የሥራ ልምድ ከደመወዙ እና ከማስረጃው ጋር መመዝገብ፣ ማረም እና ማስወገድ።'),
    $entry('work_experience.requests.manage', 40, 'Decide work experience requests', 'የሥራ ልምድ ጥያቄዎችን ይወስኑ',
        'Accept or return certificate requests, print the certificate, record that it was sent, and enter a request for an employee who has left.',
        'የማስረጃ ጥያቄዎችን መቀበል ወይም መመለስ፣ ማስረጃውን ማተም፣ መላኩን መመዝገብ፣ እና ለለቀቀ ሠራተኛ ጥያቄ መመዝገብ።'),
    $entry('work_experience.settings.manage', 50, 'Manage work experience settings', 'የሥራ ልምድ ቅንብሮችን ያስተዳድሩ',
        'Set who signs the work experience certificate of an institution and what the certificate carries.',
        'የአንድ ተቋም የሥራ ልምድ ማስረጃ ፈራሚ ማን እንደሆነ እና ማስረጃው የሚይዘውን መወሰን።'),
];
