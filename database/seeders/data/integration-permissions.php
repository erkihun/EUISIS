<?php

declare(strict_types=1);

/*
 * Event integration layer administration (docs/integration/partner-onboarding.md).
 * Replay and payload inspection are high-risk and granted to no role by
 * default (Super Admin passes every gate).
 */
return [
    ['name' => 'integrations.view', 'group' => 'integrations', 'sort_order' => 10, 'is_system' => true,
        'label_en' => 'View Event Integration', 'label_am' => 'የክስተት ውህደትን ይመልከቱ',
        'description_en' => 'Allows viewing the change feed status, webhook subscriptions and integration KPIs.',
        'description_am' => 'የለውጥ ዝርዝሩን ሁኔታ፣ የዌብሁክ ምዝገባዎችንና የውህደት መለኪያዎችን ማየት ያስችላል።'],
    ['name' => 'integrations.manage', 'group' => 'integrations', 'sort_order' => 20, 'is_system' => true,
        'label_en' => 'Manage Webhook Subscriptions', 'label_am' => 'የዌብሁክ ምዝገባዎችን ያስተዳድሩ',
        'description_en' => 'Allows creating, editing, disabling and testing webhook subscriptions and rotating their secrets.',
        'description_am' => 'የዌብሁክ ምዝገባዎችን መፍጠር፣ ማሻሻል፣ ማቆም፣ መሞከርና ሚስጥራቸውን መቀየር ያስችላል።'],
    ['name' => 'integrations.deliveries.view', 'group' => 'integrations', 'sort_order' => 30, 'is_system' => true,
        'label_en' => 'View Webhook Deliveries', 'label_am' => 'የዌብሁክ አቅርቦቶችን ይመልከቱ',
        'description_en' => 'Allows viewing delivery history: attempts, status codes, latency and failures. No payloads.',
        'description_am' => 'የአቅርቦት ታሪክን ማየት ያስችላል፦ ሙከራዎች፣ የሁኔታ ኮዶች፣ መዘግየትና ውድቀቶች። ይዘት አያካትትም።'],
    ['name' => 'integrations.replay', 'group' => 'integrations', 'sort_order' => 40, 'is_system' => true,
        'label_en' => 'Replay Webhook Events', 'label_am' => 'የዌብሁክ ክስተቶችን እንደገና ይላኩ',
        'description_en' => 'Allows re-delivering published events to a subscription. Audited with a reason.',
        'description_am' => 'የታተሙ ክስተቶችን ለአንድ ምዝገባ እንደገና መላክ ያስችላል። ከምክንያት ጋር ኦዲት ይደረጋል።'],
    ['name' => 'integrations.payload.view', 'group' => 'integrations', 'sort_order' => 50, 'is_system' => true,
        'label_en' => 'Inspect Event Payloads', 'label_am' => 'የክስተት ይዘትን ይመርምሩ',
        'description_en' => 'Allows viewing the minimised payload of an integration event. Audited.',
        'description_am' => 'የአንድ የውህደት ክስተት የተቀነሰ ይዘት ማየት ያስችላል። ኦዲት ይደረጋል።'],
];
