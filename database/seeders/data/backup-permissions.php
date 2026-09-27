<?php

return (static function (): array {
    $actions = [
        'view_status' => ['View backup health', 'የምትኬ ሁኔታ ማየት'],
        'view_history' => ['View backup history', 'የምትኬ ታሪክ ማየት'],
        'run' => ['Record operator recovery execution', 'የመልሶ ማግኛ አፈጻጸም መመዝገብ'],
        'verify' => ['Backup verification oversight', 'የምትኬ ማረጋገጫ ክትትል'],
        'restore_request' => ['Request database recovery', 'የውሂብ መልሶ ማግኛ መጠየቅ'],
        'restore_review' => ['Review recovery evidence', 'የመልሶ ማግኛ ማስረጃ መገምገም'],
        'restore_approve' => ['Approve recovery requests', 'የመልሶ ማግኛ ጥያቄ ማጽደቅ'],
        'manage_policy' => ['Backup policy oversight', 'የምትኬ ፖሊሲ ክትትል'],
        'view_logs' => ['Receive backup operational alerts', 'የምትኬ ማስጠንቀቂያ መቀበል'],
    ];
    $entries = [];
    foreach ($actions as $action => [$en, $am]) {
        $entries[] = ['name' => 'backups.'.$action, 'group' => 'backups', 'sort_order' => count($entries) * 10,
            'is_system' => true, 'label_en' => $en, 'label_am' => $am, 'description_en' => $en.'. Infrastructure access is separately controlled.', 'description_am' => $am.'።'];
    }

    return $entries;
})();
