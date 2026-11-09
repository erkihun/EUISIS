<?php

declare(strict_types=1);

/*
 * Support help-desk grants (docs/assistant/support-helpdesk.md).
 *
 * Every signed-in user may file a ticket and read their own; these two grants
 * are for the people who work the queue. Reading another person's ticket shows
 * the reporter's name, the page they were on and their own description —
 * never a cookie, a token, a header or a form payload, which are never stored.
 */
$entry = static fn (string $name, int $sort, string $en, string $am, string $descEn, string $descAm): array => [
    'name' => $name, 'group' => 'support', 'sort_order' => $sort, 'is_system' => true,
    'label_en' => $en, 'label_am' => $am, 'description_en' => $descEn, 'description_am' => $descAm,
];

return [
    $entry('support-tickets.view-all', 10, 'View all support tickets', 'ሁሉንም የድጋፍ ጥያቄዎች ይመልከቱ',
        'Open every support ticket in the help desk, not only the viewer\'s own, with its safe diagnostic context and timeline.',
        'በድጋፍ ማዕከሉ ውስጥ ያሉትን ሁሉንም የድጋፍ ጥያቄዎች፣ የተመልካቹን ብቻ ሳይሆን፣ ከደህንነቱ የተጠበቀ የምርመራ አውድ እና የጊዜ መስመር ጋር መክፈት ያስችላል።'),
    $entry('support-tickets.manage', 20, 'Work support tickets', 'የድጋፍ ጥያቄዎችን ያስተናግዱ',
        'Change a ticket\'s status, assign it to a team or a person and add notes. Every change is recorded as an event and audited.',
        'የጥያቄን ሁኔታ መቀየር፣ ለቡድን ወይም ለሰው መመደብ እና ማስታወሻ ማከል ያስችላል። እያንዳንዱ ለውጥ እንደ ክስተት ይመዘገባል እና በኦዲት ይከታተላል።'),
];
