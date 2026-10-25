<?php

declare(strict_types=1);

/*
 * የድጋፍ ማዕከል — የአገልጋይ ጎን ጽሑፎች (docs/assistant/support-helpdesk.md)፦
 * የፍላሽ መልዕክቶች እና የጥያቄ ምድብ፣ ቅድሚያ፣ ሁኔታ እና ነባሪ ቡድን መለያዎች።
 */
return [
    'title' => 'የድጋፍ ጥያቄዎች',
    // የምድብ መስመር ካልተዋቀረ ጥያቄው የሚደርሰው ቡድን (config/support.php)።
    'default_team' => 'የአገልግሎት ዴስክ',

    'flash' => [
        'created' => 'ጥያቄ :reference ተፈጥሯል። የድጋፍ ቡድኑ ይከታተላል።',
        'updated' => 'ጥያቄው ተዘምኗል።',
    ],

    'categories' => [
        'access' => 'መዳረሻ ወይም የመዳረሻ መብት',
        'error' => 'የስህተት መልዕክት',
        'data' => 'የተሳሳተ ወይም የጎደለ መረጃ',
        'how_to' => 'እንዴት…',
        'performance' => 'ዘገምተኛ ወይም ምላሽ የማይሰጥ',
        'other' => 'ሌላ',
    ],

    'priorities' => [
        'low' => 'ዝቅተኛ',
        'normal' => 'መደበኛ',
        'high' => 'ከፍተኛ',
    ],

    'statuses' => [
        'open' => 'ክፍት',
        'in_progress' => 'በሂደት ላይ',
        'resolved' => 'የተፈታ',
        'closed' => 'የተዘጋ',
    ],

    'events' => [
        'created' => 'ጥያቄ ተፈጥሯል',
        'status_changed' => 'ሁኔታ ተቀይሯል',
        'assigned' => 'ተመድቧል',
        'note' => 'ማስታወሻ',
    ],

    'team' => [
        'default' => 'የአገልግሎት ዴስክ',
    ],
];
