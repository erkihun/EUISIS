<?php

declare(strict_types=1);

/*
 * Letters permissions (ADR 0037, docs/letters.md).
 *
 * Every permission is exercised inside OrganizationScopeService scope (the
 * registry entry's institution). Platform templates and the platform
 * numbering default also need city-wide reach (LettersAccess).
 */
$entry = static fn (string $name, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm): array => [
    'name' => $name,
    'group' => 'letters',
    'sort_order' => $sortOrder,
    'is_system' => false,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    $entry('letters.view', 10, 'View the letter registry', 'የደብዳቤ መዝገብን ይመልከቱ',
        'See the registry entries, letters and routing of the institutions in scope.', 'በወሰን ውስጥ ያሉ ተቋማትን የመዝገብ ግቤቶች፣ ደብዳቤዎችና መመራት ይመልከቱ።'),
    $entry('letters.register', 20, 'Register incoming letters', 'ገቢ ደብዳቤዎችን ይመዝግቡ',
        'Register letters that arrive from outside the system and receive letters dispatched by other institutions, drawing the institution\'s own number.', 'ከሥርዓቱ ውጭ የሚመጡ ደብዳቤዎችን ይመዝግቡ፤ ሌሎች ተቋማት የላኳቸውን ደብዳቤዎች ተቀብለው የተቋሙን ቁጥር ይስጡ።'),
    $entry('letters.compose', 30, 'Compose letters', 'ደብዳቤዎችን ያዘጋጁ',
        'Draft outgoing and internal letters, from a template or blank, and edit drafts.', 'ወጪና የውስጥ ደብዳቤዎችን ከአብነት ወይም ከባዶ ይረቅቁ፤ ረቂቆችን ያርሙ።'),
    $entry('letters.approve', 40, 'Sign letters', 'ደብዳቤዎችን ይፈርሙ',
        'Sign (approve) or return letters awaiting signature in the institutions in scope.', 'በወሰን ውስጥ ባሉ ተቋማት ፊርማ የሚጠብቁ ደብዳቤዎችን ይፈርሙ ወይም ይመልሱ።'),
    $entry('letters.dispatch', 50, 'Dispatch letters', 'ደብዳቤዎችን ይላኩ',
        'Dispatch signed letters: the registry number is drawn and the letter becomes final.', 'የተፈረሙ ደብዳቤዎችን ይላኩ፤ የመዝገብ ቁጥር ተሰጥቶ ደብዳቤው የመጨረሻ ይሆናል።'),
    $entry('letters.route', 60, 'Route letters', 'ደብዳቤዎችን ይምሩ',
        'Forward registered letters to units and people inside the institution with instructions, and close their handling.', 'የተመዘገቡ ደብዳቤዎችን በተቋሙ ውስጥ ላሉ ክፍሎችና ሰዎች በትእዛዝ ይምሩ፤ አያያዛቸውን ይዝጉ።'),
    $entry('letters.handle', 70, 'Handle routed letters', 'የተመሩ ደብዳቤዎችን ይያዙ',
        'Acknowledge, complete or return the routing steps addressed to you.', 'ለእርስዎ የተመሩ የመምራት ደረጃዎችን ያረጋግጡ፣ ያጠናቅቁ ወይም ይመልሱ።'),
    $entry('letters.view_confidential', 75, 'Read confidential letters', 'ሚስጥራዊ ደብዳቤዎችን ያንብቡ',
        'Open the contents and files of confidential and secret letters of the institutions in scope. The people handling a letter (author, signatory, routed addressees) read it without this grant.', 'በወሰን ውስጥ ያሉ ተቋማትን ሚስጥራዊና ጥብቅ ሚስጥራዊ ደብዳቤዎች ይዘትና ፋይሎች ይክፈቱ። ደብዳቤውን የሚይዙ ሰዎች (አዘጋጅ፣ ፈራሚ፣ የተመራላቸው) ያለዚህ ፈቃድ ያነቡታል።'),
    $entry('letters.templates.view', 80, 'View letter templates', 'የደብዳቤ አብነቶችን ይመልከቱ',
        'See the platform templates and those of the institutions in scope.', 'የመድረኩን አብነቶችና በወሰን ውስጥ ያሉ ተቋማትን አብነቶች ይመልከቱ።'),
    $entry('letters.templates.manage', 90, 'Manage institution templates', 'የተቋም አብነቶችን ያስተዳድሩ',
        'Create, edit, publish and archive the letter templates of the institutions in scope.', 'በወሰን ውስጥ ያሉ ተቋማትን የደብዳቤ አብነቶች ይፍጠሩ፣ ያርሙ፣ ያትሙ፣ በማህደር ያስቀምጡ።'),
    $entry('letters.templates.manage_platform', 100, 'Manage platform templates', 'የመድረክ አብነቶችን ያስተዳድሩ',
        'Create, edit, publish and archive the templates offered to every institution (city-wide).', 'ለሁሉም ተቋማት የሚቀርቡ አብነቶችን ይፍጠሩ፣ ያርሙ፣ ያትሙ፣ በማህደር ያስቀምጡ (ከተማ አቀፍ)።'),
    $entry('letters.numbering.manage', 110, 'Manage letter numbering', 'የደብዳቤ ቁጥር አሰጣጥን ያስተዳድሩ',
        'Set the numbering pattern and reset rule per direction for the institutions in scope (the platform default needs city-wide reach).', 'በወሰን ውስጥ ላሉ ተቋማት በአቅጣጫ የቁጥር ቅርጽና የመጀመሪያ ደንብ ያዘጋጁ (የመድረኩ ነባሪ ከተማ አቀፍ ወሰን ይፈልጋል)።'),
    $entry('letters.letterhead.manage', 115, 'Manage letter headers and footers', 'የደብዳቤ ራስጌና ግርጌዎችን ያስተዳድሩ',
        'Create and edit the header and footer layouts (content, fonts, colours, alignment, page setup, images and stamp) of the institutions in scope (platform layouts need city-wide reach).', 'በወሰን ውስጥ ላሉ ተቋማት የራስጌና ግርጌ ቅርጾችን (ይዘት፣ ፊደል፣ ቀለም፣ አሰላለፍ፣ የገጽ አቀማመጥ፣ ምስሎችና ማህተም) ይፍጠሩ፣ ያርሙ (የመድረኩ ቅርጾች ከተማ አቀፍ ወሰን ይፈልጋሉ)።'),
    $entry('letters.standards.manage', 117, 'Manage letter standards', 'የደብዳቤ ስታንዳርዶችን ያስተዳድሩ',
        'Set the reply-time standard (days by priority, interim reply, escalation) and the file classification scheme of the institutions in scope (the platform defaults need city-wide reach).', 'በወሰን ውስጥ ላሉ ተቋማት የምላሽ ጊዜ ስታንዳርድ (በቅድሚያ ቀናት፣ ጊዜያዊ ምላሽ፣ ማሳደግ) እና የፋይል ምደባ መርሃ ግብር ያዘጋጁ (የመድረኩ ነባሪዎች ከተማ አቀፍ ወሰን ይፈልጋሉ)።'),
    $entry('letters.reports.view', 120, 'View letter reports', 'የደብዳቤ ሪፖርቶችን ይመልከቱ',
        'Counts, handling times and overdue routing for the institutions in scope.', 'በወሰን ውስጥ ላሉ ተቋማት ቁጥሮች፣ የአያያዝ ጊዜና ጊዜያቸው ያለፈ መመራቶች።'),
    $entry('letters.export', 130, 'Export the letter registry', 'የደብዳቤ መዝገብን ወደ ውጭ ይላኩ',
        'Export registry lists of the institutions in scope.', 'በወሰን ውስጥ ያሉ ተቋማትን የመዝገብ ዝርዝሮች ወደ ውጭ ይላኩ።'),
];
