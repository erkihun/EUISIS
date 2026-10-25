<?php

declare(strict_types=1);

/*
 * የአገልጋይ አስተውሎት — የዕውቀት ረዳት መዝገበ ቃላት (lang/en/intelligence_knowledge.php
 * ቁልፍ በቁልፍ ይመሳሰላል)።
 */
return [
    'classifications' => [
        'public' => 'ይፋዊ',
        'internal' => 'ውስጣዊ',
        'confidential' => 'ሚስጥራዊ',
        'restricted' => 'የተገደበ',
    ],

    'statuses' => [
        'draft' => 'ረቂቅ',
        'approved' => 'የጸደቀ',
        'retired' => 'ከአገልግሎት የወጣ',
    ],

    'modes' => [
        'ai' => 'የተጠናቀረ መልስ',
        'extractive' => 'የተጠቀሱ ምንባቦች',
        'none' => 'ረዳቱ ጠፍቷል',
    ],

    'source_types' => [
        'manual' => 'በእጅ የገባ',
        'repo_doc' => 'የማከማቻ ሰነድ',
        'upload' => 'የተሰቀለ ፋይል',
    ],

    'notices' => [
        'disabled' => 'የዕውቀት ረዳቱ በሥርዓት ቅንብሮች ውስጥ ጠፍቷል።',
        'no_passages' => 'ከዚህ ጥያቄ ጋር የሚዛመድ የጸደቀ ምንባብ የለም።',
        'ai_off' => 'የሰው ሠራሽ ብልህነት ማጠናቀር ጠፍቷል ወይም የዕውቀት ሰነዶች ወደ ውጭ መላክ አይፈቀድም፤ የሚዛመዱት ምንባቦች እንዳሉ ተጠቅሰዋል።',
        'unavailable' => 'የሰው ሠራሽ ብልህነት አቅራቢው አልተገኘም፤ የሚዛመዱት ምንባቦች እንዳሉ ተጠቅሰዋል።',
        'refused' => 'ጥያቄው በመተላለፊያው ፖሊሲ ውድቅ ተደርጓል፤ የሚዛመዱት ምንባቦች እንዳሉ ተጠቅሰዋል።',
        'failed' => 'የሰው ሠራሽ ብልህነት አቅራቢው አልተሳካም፤ የሚዛመዱት ምንባቦች እንዳሉ ተጠቅሰዋል።',
        'invalid' => 'የሰው ሠራሽ ብልህነት መልሱ ማረጋገጫውን አላለፈም፤ የሚዛመዱት ምንባቦች እንዳሉ ተጠቅሰዋል።',
    ],

    'flash' => [
        'document_created' => 'የዕውቀት ሰነዱ እንደ ረቂቅ ተቀምጧል።',
        'document_updated' => 'የዕውቀት ሰነዱ ተዘምኗል።',
        'document_approved' => 'የዕውቀት ሰነዱ ጸድቋል።',
        'document_retired' => 'የዕውቀት ሰነዱ ከአገልግሎት ወጥቷል።',
        'document_deleted' => 'የዕውቀት ሰነዱ ተሰርዟል።',
    ],

    'validation' => [
        'organization_out_of_scope' => 'ያ ተቋም ከእርስዎ ወሰን ውጭ ነው።',
        'unknown_permission' => 'ያ የመዳረሻ መብት በካታሎጉ ውስጥ የለም።',
    ],
];
