<?php

return (static function (): array {
    $actions = [
        'view_status' => ['View backup health', 'የምትኬ ሁኔታ ይመልከቱ'],
        'view_history' => ['View backup history', 'የምትኬ ታሪክ ይመልከቱ'],
        'run' => ['Record operator recovery execution', 'የመልሶ ማግኛ አፈጻጸም ይመዝግቡ'],
        'verify' => ['Backup verification oversight', 'የምትኬ ማረጋገጫ ክትትል'],
        'restore_request' => ['Request database recovery', 'የውሂብ መልሶ ማግኛ ይጠይቁ'],
        'restore_review' => ['Review recovery evidence', 'የመልሶ ማግኛ ማስረጃ ይገምግሙ'],
        'restore_approve' => ['Approve recovery requests', 'የመልሶ ማግኛ ጥያቄዎችን ያጽድቁ'],
        'manage_policy' => ['Backup policy oversight', 'የምትኬ ፖሊሲ ክትትል'],
        'view_logs' => ['Receive backup operational alerts', 'የምትኬ ማስጠንቀቂያዎችን ይቀበሉ'],
    ];
    $entries = [];
    foreach ($actions as $action => [$en, $am]) {
        $entries[] = ['name' => 'backups.'.$action, 'group' => 'backups', 'sort_order' => count($entries) * 10,
            'is_system' => true, 'label_en' => $en, 'label_am' => $am, 'description_en' => $en.'. Infrastructure access is separately controlled.', 'description_am' => $am.'።'];
    }

    return $entries;
})();
