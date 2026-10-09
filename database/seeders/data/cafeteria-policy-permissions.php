<?php

declare(strict_types=1);

/*
 * Cafeteria network, access, assignment, policy and settlement permissions
 * (docs/cafeteria-policy-architecture.md).
 *
 * Separated duties: drafting a financial policy, reviewing it and approving it
 * are different permissions. None of these is needed to scan cards.
 */
$entry = static fn (string $name, string $group, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm): array => [
    'name' => $name,
    'group' => $group,
    'sort_order' => $sortOrder,
    'is_system' => true,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    // Networks
    $entry('cafeteria_networks.view', 'cafeteria-networks', 10, 'View Cafeteria Networks', 'የካፍቴሪያ ኔትወርኮችን ይመልከቱ',
        'View cafeteria networks with their main cafeteria, branches and service points.',
        'የካፍቴሪያ ኔትወርኮችን ከዋና ካፍቴሪያ፣ ቅርንጫፎች እና የአገልግሎት ቦታዎች ጋር ማየት ያስችላል።'),
    $entry('cafeteria_networks.manage', 'cafeteria-networks', 20, 'Manage Cafeteria Networks', 'የካፍቴሪያ ኔትወርኮችን ያስተዳድሩ',
        'Create networks and add or change their main cafeteria, branches and service points.',
        'ኔትወርኮችን መፍጠር እና ዋና ካፍቴሪያ፣ ቅርንጫፎችና የአገልግሎት ቦታዎችን ማከል ወይም መቀየር ያስችላል።'),

    // Organization access
    $entry('cafeteria_access.view', 'cafeteria-access', 10, 'View Organization Cafeteria Access', 'የተቋም የካፍቴሪያ ፈቃድን ይመልከቱ',
        'View which organizations may use which cafeteria networks and locations.',
        'የትኞቹ ተቋማት የትኞቹን የካፍቴሪያ ኔትወርኮችና ቦታዎች መጠቀም እንደሚችሉ ማየት ያስችላል።'),
    $entry('cafeteria_access.manage', 'cafeteria-access', 20, 'Manage Organization Cafeteria Access', 'የተቋም የካፍቴሪያ ፈቃድን ያስተዳድሩ',
        'Grant, change or end an organization’s access to a cafeteria network, its primary cafeteria and cross-location usage.',
        'የተቋምን የካፍቴሪያ ኔትወርክ ፈቃድ፣ ዋና ካፍቴሪያውን እና በተፈቀዱ ቅርንጫፎች መጠቀምን መስጠት፣ መቀየር ወይም ማቋረጥ ያስችላል።'),
    $entry('cafeteria_access.approve', 'cafeteria-access', 30, 'Approve Organization Cafeteria Access', 'የተቋም የካፍቴሪያ ፈቃድን ያጽድቁ',
        'Approve organization cafeteria access before it takes effect.',
        'የተቋም የካፍቴሪያ ፈቃድ ሥራ ላይ ከመዋሉ በፊት ማጽደቅ ያስችላል።'),

    // Service assignments
    $entry('cafeteria_assignments.view', 'cafeteria-assignments', 10, 'View Cafeteria Service Assignments', 'የካፍቴሪያ አገልግሎት ምደባዎችን ይመልከቱ',
        'View which provider is authorized to serve which organization.',
        'የትኛው አቅራቢ የትኛውን ተቋም ለማገልገል እንደተፈቀደለት ማየት ያስችላል።'),
    $entry('cafeteria_assignments.create', 'cafeteria-assignments', 20, 'Create Cafeteria Service Assignments', 'የካፍቴሪያ አገልግሎት ምደባ ይፍጠሩ',
        'Authorize a provider, network or cafeteria to serve an organization.',
        'አቅራቢ፣ ኔትወርክ ወይም ካፍቴሪያ ተቋምን እንዲያገለግል መፍቀድ ያስችላል።'),
    $entry('cafeteria_assignments.update', 'cafeteria-assignments', 30, 'Update Cafeteria Service Assignments', 'የካፍቴሪያ አገልግሎት ምደባን ያሻሽሉ',
        'Change a service assignment before it is approved.',
        'የአገልግሎት ምደባን ከመጽደቁ በፊት ማሻሻል ያስችላል።'),
    $entry('cafeteria_assignments.end', 'cafeteria-assignments', 40, 'End Cafeteria Service Assignments', 'የካፍቴሪያ አገልግሎት ምደባን ያቋርጡ',
        'End a provider’s authorization to serve an organization from a date.',
        'የአቅራቢን ተቋም የማገልገል ፈቃድ ከአንድ ቀን ጀምሮ ማቋረጥ ያስችላል።'),
    $entry('cafeteria_assignments.approve', 'cafeteria-assignments', 50, 'Approve Cafeteria Service Assignments', 'የካፍቴሪያ አገልግሎት ምደባን ያጽድቁ',
        'Approve a service assignment before it takes effect.',
        'የአገልግሎት ምደባ ሥራ ላይ ከመዋሉ በፊት ማጽደቅ ያስችላል።'),

    // Service policies
    $entry('cafeteria_policies.view', 'cafeteria-policies', 10, 'View Cafeteria Service Policies', 'የካፍቴሪያ አገልግሎት ፖሊሲዎችን ይመልከቱ',
        'View organization cafeteria policies, their versions and financial terms.',
        'የተቋም የካፍቴሪያ ፖሊሲዎችን፣ ስሪቶቻቸውን እና የገንዘብ ውሎቻቸውን ማየት ያስችላል።'),
    $entry('cafeteria_policies.create', 'cafeteria-policies', 20, 'Create Cafeteria Service Policies', 'የካፍቴሪያ አገልግሎት ፖሊሲ ይፍጠሩ',
        'Draft a new policy or a new version of an existing policy.',
        'አዲስ ፖሊሲ ወይም የነባር ፖሊሲ አዲስ ስሪት ማርቀቅ ያስችላል።'),
    $entry('cafeteria_policies.update_draft', 'cafeteria-policies', 30, 'Edit Draft Cafeteria Policies', 'ረቂቅ የካፍቴሪያ ፖሊሲዎችን ያሻሽሉ',
        'Edit a policy while it is a draft. Approved policies are never edited.',
        'ፖሊሲው ረቂቅ ሲሆን ማሻሻል ያስችላል። የጸደቁ ፖሊሲዎች አይሻሻሉም።'),
    $entry('cafeteria_policies.submit', 'cafeteria-policies', 40, 'Submit Cafeteria Policies for Review', 'የካፍቴሪያ ፖሊሲዎችን ለግምገማ ያቅርቡ',
        'Submit a draft policy for review.',
        'ረቂቅ ፖሊሲን ለግምገማ ማቅረብ ያስችላል።'),
    $entry('cafeteria_policies.review', 'cafeteria-policies', 50, 'Review Cafeteria Policies', 'የካፍቴሪያ ፖሊሲዎችን ይገምግሙ',
        'Review a submitted policy and return it for correction.',
        'የቀረበን ፖሊሲ መገምገም እና ለእርማት መመለስ ያስችላል።'),
    $entry('cafeteria_policies.approve', 'cafeteria-policies', 60, 'Approve Cafeteria Policies', 'የካፍቴሪያ ፖሊሲዎችን ያጽድቁ',
        'Approve a reviewed policy so it applies from its effective date.',
        'የተገመገመ ፖሊሲ ከሚጀምርበት ቀን ጀምሮ እንዲሠራ ማጽደቅ ያስችላል።'),
    $entry('cafeteria_policies.activate', 'cafeteria-policies', 70, 'Activate Cafeteria Policies', 'የካፍቴሪያ ፖሊሲዎችን ሥራ ላይ ያውሉ',
        'Mark an approved policy active once its effective date has arrived.',
        'የጸደቀ ፖሊሲ የሚጀምርበት ቀን ሲደርስ ሥራ ላይ መሆኑን ማመልከት ያስችላል።'),
    $entry('cafeteria_policies.end', 'cafeteria-policies', 80, 'End or Cancel Cafeteria Policies', 'የካፍቴሪያ ፖሊሲዎችን ያቋርጡ ወይም ይሰርዙ',
        'End an active policy from a date, or cancel one that has not taken effect.',
        'ሥራ ላይ ያለን ፖሊሲ ከአንድ ቀን ጀምሮ ማቋረጥ ወይም ሥራ ላይ ያልዋለን መሰረዝ ያስችላል።'),

    // Transactions (export) and settlements
    $entry('cafeteria_transactions.export', 'cafeteria-transactions', 40, 'Export Cafeteria Transactions', 'የካፍቴሪያ ግብይቶችን ወደ ውጭ ይላኩ',
        'Export cafeteria transactions and analytics reports.',
        'የካፍቴሪያ ግብይቶችን እና የትንተና ሪፖርቶችን ወደ ውጭ መላክ ያስችላል።'),
    $entry('cafeteria_settlements.view', 'cafeteria-settlements', 10, 'View Cafeteria Settlements', 'የካፍቴሪያ ሒሳብ ማወራረጃዎችን ይመልከቱ',
        'View provider settlements grouped by employee organization and cafeteria.',
        'በሠራተኛ ተቋምና በካፍቴሪያ የተመደቡ የአቅራቢ ሒሳብ ማወራረጃዎችን ማየት ያስችላል።'),
    $entry('cafeteria_settlements.manage', 'cafeteria-settlements', 20, 'Manage Cafeteria Settlements', 'የካፍቴሪያ ሒሳብ ማወራረጃዎችን ያስተዳድሩ',
        'Create, finalize or cancel provider settlements.',
        'የአቅራቢ ሒሳብ ማወራረጃዎችን መፍጠር፣ ማጠናቀቅ ወይም መሰረዝ ያስችላል።'),
];
