<?php

declare(strict_types=1);

$resources = [
    'transport-providers' => ['Transport Provider', 'የትራንስፖርት አቅራቢ'],
    'transport-routes' => ['Transport Route', 'የትራንስፖርት መስመር'],
    'transport-vehicles' => ['Transport Vehicle', 'የትራንስፖርት ተሽከርካሪ'],
    'transport-drivers' => ['Transport Driver', 'የትራንስፖርት አሽከርካሪ'],
    'transport-passes' => ['Transport Route Assignment', 'የትራንስፖርት መስመር ምደባ'],
    'transport-contracts' => ['Transport Contract', 'የትራንስፖርት ውል'],
    'transport-schedules' => ['Transport Schedule', 'የትራንስፖርት የጊዜ ሰሌዳ'],
    'transport-trips' => ['Transport Trip', 'የትራንስፖርት ጉዞ'],
    'transport-eligibility' => ['Transport Eligibility', 'የትራንስፖርት ብቁነት'],
    'transport-devices' => ['Transport Scanner Device', 'የትራንስፖርት ስካነር መሣሪያ'],
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
        if (in_array($group, ['transport-passes', 'transport-trips', 'transport-eligibility', 'transport-contracts'], true) && $action === 'delete') {
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
    ['transport-scan.create', 'Record Transport Scan', 'የትራንስፖርት ስካን ይመዝግቡ', 'Allows recording a boarding scan at the admin scan terminal.', 'በአስተዳደር ስካን ተርሚናል የመሳፈር ስካን መመዝገብ ያስችላል።'],
    ['transport-scan.manual', 'Record Manual Boarding Exception', 'በእጅ የመሳፈር ልዩ ሁኔታ ይመዝግቡ', 'Allows recording a boarding without a card scan, with a reason that is kept as an exception.', 'ያለ ካርድ ስካን፣ እንደ ልዩ ሁኔታ የሚቀመጥ ምክንያት ጋር መሳፈር መመዝገብ ያስችላል።'],
    ['transport-transactions.void', 'Void Transport Boarding', 'የትራንስፖርት መሳፈርን ይሰርዙ', 'Allows voiding a boarding record with a reason; the record itself is never deleted.', 'ምክንያት ጋር የመሳፈር መዝገብን መሰረዝ ያስችላል፤ መዝገቡ ራሱ አይጠፋም።'],
    ['transport-trips.dispatch', 'Dispatch Transport Trips', 'የትራንስፖርት ጉዞዎችን ያሰማሩ', 'Allows assigning vehicles and drivers, starting, completing, cancelling trips and replacing vehicles.', 'ተሽከርካሪዎችን እና አሽከርካሪዎችን መመደብ፣ ጉዞዎችን መጀመር፣ ማጠናቀቅ፣ መሰረዝ እና ተሽከርካሪ መቀየር ያስችላል።'],
    ['transport-trips.approveExtra', 'Approve Extra Transport Trip', 'ተጨማሪ የትራንስፖርት ጉዞን ያጽድቁ', 'Allows approving an extra trip a provider requested outside the schedule.', 'አቅራቢ ከጊዜ ሰሌዳ ውጭ የጠየቀውን ተጨማሪ ጉዞ ማጽደቅ ያስችላል።'],
    ['transport-manifests.view', 'View Trip Manifests', 'የጉዞ የተሳፋሪ ዝርዝሮችን ይመልከቱ', 'Allows viewing who boarded a trip, with boarding time, stop and verification method.', 'በአንድ ጉዞ ማን እንደተሳፈረ፣ የመሳፈሪያ ሰዓት፣ ፌርማታ እና የማረጋገጫ ዘዴ ጋር ማየት ያስችላል።'],
    ['transport-exceptions.viewAny', 'List Transport Exceptions', 'የትራንስፖርት ልዩ ሁኔታዎችን ዘርዝር', 'Allows viewing operational exceptions such as overcapacity, duplicate attempts and vehicle substitutions.', 'እንደ ከአቅም በላይ፣ ድግግሞሽ ሙከራ እና የተሽከርካሪ ቅያሬ ያሉ የሥራ ልዩ ሁኔታዎችን ማየት ያስችላል።'],
    ['transport-exceptions.review', 'Review Transport Exceptions', 'የትራንስፖርት ልዩ ሁኔታዎችን ይገምግሙ', 'Allows marking an exception reviewed or dismissed with notes.', 'ልዩ ሁኔታን በማስታወሻ እንደተገመገመ ወይም እንደታለፈ ምልክት ማድረግ ያስችላል።'],
    ['transport-invoices.viewAny', 'List Transport Invoices', 'የትራንስፖርት ደረሰኞችን ዘርዝር', 'Allows viewing invoices providers submitted.', 'አቅራቢዎች ያቀረቧቸውን ደረሰኞች ማየት ያስችላል።'],
    ['transport-invoices.view', 'View Transport Invoice', 'የትራንስፖርት ደረሰኝን ይመልከቱ', 'Allows viewing one invoice and its reconciliation.', 'አንድ ደረሰኝ እና ማስታረቁን ማየት ያስችላል።'],
    ['transport-invoices.review', 'Review Transport Invoices', 'የትራንስፖርት ደረሰኞችን ይገምግሙ', 'Allows taking an invoice under review, returning it or marking it disputed.', 'ደረሰኝን ለግምገማ መውሰድ፣ መመለስ ወይም ክርክር እንዳለበት ምልክት ማድረግ ያስችላል።'],
    ['transport-settlements.viewAny', 'List Transport Settlements', 'የትራንስፖርት ሂሳብ ማወራረጃዎችን ዘርዝር', 'Allows viewing settlement statements.', 'የሂሳብ ማወራረጃ መግለጫዎችን ማየት ያስችላል።'],
    ['transport-settlements.view', 'View Transport Settlement', 'የትራንስፖርት ሂሳብ ማወራረጃን ይመልከቱ', 'Allows viewing one settlement statement, its basis and adjustments.', 'አንድ የሂሳብ ማወራረጃ መግለጫ፣ መሠረቱን እና ማስተካከያዎቹን ማየት ያስችላል።'],
    ['transport-settlements.manage', 'Manage Transport Settlements', 'የትራንስፖርት ሂሳብ ማወራረጃዎችን ያስተዳድሩ', 'Allows computing statements from verified usage, submitting them for review and recording adjustments.', 'ከተረጋገጠ አጠቃቀም መግለጫዎችን ማስላት፣ ለግምገማ ማቅረብ እና ማስተካከያዎችን መመዝገብ ያስችላል።'],
    ['transport-settlements.approve', 'Approve Transport Settlements', 'የትራንስፖርት ሂሳብ ማወራረጃዎችን ያጽድቁ', 'Allows approving or rejecting a statement and recording the payment reference. The approver cannot be the person who computed it.', 'መግለጫን ማጽደቅ ወይም ውድቅ ማድረግ እና የክፍያ ማጣቀሻ መመዝገብ ያስችላል። አጽዳቂው ያሰላው ሰው ሊሆን አይችልም።'],
    ['transport-devices.revoke', 'Revoke Transport Scanner Device', 'የትራንስፖርት ስካነር መሣሪያን ይሰርዙ', 'Allows revoking a scanner device credential so it can no longer record boardings.', 'ስካነር መሣሪያ ከዚህ በኋላ መሳፈር እንዳይመዘግብ ምስክርነቱን መሰረዝ ያስችላል።'],
] as $index => [$name, $labelEn, $labelAm, $descriptionEn, $descriptionAm]) {
    $group = explode('.', $name, 2)[0];
    $definitions[] = [$name, $group, ($index + 1) * 10, $labelEn, $labelAm, $descriptionEn, $descriptionAm];
}

return $definitions;
