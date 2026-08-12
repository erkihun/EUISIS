<?php

declare(strict_types=1);

$resources = [
    'transport-providers' => ['Transport Provider', 'የትራንስፖርት አቅራቢ'],
    'transport-routes' => ['Transport Route', 'የትራንስፖርት መስመር'],
    'transport-vehicles' => ['Transport Vehicle', 'የትራንስፖርት ተሽከርካሪ'],
    'transport-drivers' => ['Transport Driver', 'የትራንስፖርት አሽከርካሪ'],
    'transport-passes' => ['Transport Pass', 'የትራንስፖርት ፈቃድ'],
];

$actions = [
    'viewAny' => [10, 'List %s', '%sዎችን ዘርዝር', 'Allows viewing the list of %s records.', 'የ%s መዝገቦችን ዝርዝር ማየት ያስችላል።'],
    'view' => [20, 'View %s', '%sን ይመልከቱ', 'Allows viewing %s details.', 'የ%s ዝርዝር መረጃን ማየት ያስችላል።'],
    'create' => [30, 'Create %s', '%s ይመዝግቡ', 'Allows creating a new %s record.', 'አዲስ %s መመዝገብ ያስችላል።'],
    'update' => [40, 'Update %s', '%sን ያሻሽሉ', 'Allows updating %s details.', 'የ%s መረጃን ማሻሻል ያስችላል።'],
    'delete' => [50, 'Delete %s', '%sን ይሰርዙ', 'Allows deleting a %s record.', '%sን መሰረዝ ያስችላል።'],
];

$definitions = [];

foreach ($resources as $group => [$resourceEn, $resourceAm]) {
    foreach ($actions as $action => [$order, $labelEn, $labelAm, $descriptionEn, $descriptionAm]) {
        if ($group === 'transport-passes' && $action === 'delete') {
            continue;
        }

        $definitions[] = [
            "{$group}.{$action}", $group, $order,
            sprintf($labelEn, $resourceEn), sprintf($labelAm, $resourceAm),
            sprintf($descriptionEn, strtolower($resourceEn)), sprintf($descriptionAm, $resourceAm),
        ];
    }
}

$definitions[] = ['transport-providers.restore', 'transport-providers', 60, 'Restore Transport Provider', 'የትራንስፖርት አቅራቢን ወደነበረበት ይመልሱ', 'Allows restoring an archived transport provider.', 'ወደ ማህደር የተዛወረ የትራንስፖርት አቅራቢን ወደነበረበት መመለስ ያስችላል።'];
$definitions[] = ['transport-passes.cancel', 'transport-passes', 50, 'Cancel Transport Pass', 'የትራንስፖርት ፈቃድን ይሰርዙ', 'Allows cancelling an active transport pass.', 'በሥራ ላይ ያለ የትራንስፖርት ፈቃድን መሰረዝ ያስችላል።'];

foreach ([
    ['transport-transactions.viewAny', 'List Transport Transactions', 'የትራንስፖርት ግብይቶችን ዘርዝር', 'Allows viewing the list of transport transactions.', 'የትራንስፖርት ግብይቶችን ዝርዝር ማየት ያስችላል።'],
    ['transport-transactions.view', 'View Transport Transaction', 'የትራንስፖርት ግብይትን ይመልከቱ', 'Allows viewing transport transaction details.', 'የትራንስፖርት ግብይት ዝርዝር መረጃን ማየት ያስችላል።'],
    ['transport-transactions.export', 'Export Transport Transactions', 'የትራንስፖርት ግብይቶችን ወደ ውጭ ላክ', 'Allows exporting transport transaction data.', 'የትራንስፖርት ግብይት መረጃን ወደ ውጭ መላክ ያስችላል።'],
    ['transport-reports.view', 'View Transport Reports', 'የትራንስፖርት ሪፖርቶችን ይመልከቱ', 'Allows viewing transport reports.', 'የትራንስፖርት ሪፖርቶችን ማየት ያስችላል።'],
    ['transport-reports.export', 'Export Transport Reports', 'የትራንስፖርት ሪፖርቶችን ወደ ውጭ ላክ', 'Allows exporting transport reports.', 'የትራንስፖርት ሪፖርቶችን ወደ ውጭ መላክ ያስችላል።'],
    ['transport-settings.view', 'View Transport Settings', 'የትራንስፖርት ቅንብሮችን ይመልከቱ', 'Allows viewing transport configuration settings.', 'የትራንስፖርት ውቅር ቅንብሮችን ማየት ያስችላል።'],
    ['transport-settings.update', 'Update Transport Settings', 'የትራንስፖርት ቅንብሮችን ያሻሽሉ', 'Allows updating transport configuration settings.', 'የትራንስፖርት ውቅር ቅንብሮችን ማሻሻል ያስችላል።'],
    ['transport-scan.create', 'Record Transport Scan', 'የትራንስፖርት ስካን ይመዝግቡ', 'Allows recording a transport pass scan at the admin scan terminal.', 'በአስተዳደር ስካን ተርሚናል የትራንስፖርት ፈቃድ ስካን መመዝገብ ያስችላል።'],
] as $index => [$name, $labelEn, $labelAm, $descriptionEn, $descriptionAm]) {
    $group = explode('.', $name, 2)[0];
    $definitions[] = [$name, $group, ($index + 1) * 10, $labelEn, $labelAm, $descriptionEn, $descriptionAm];
}

return $definitions;
