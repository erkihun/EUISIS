<?php

declare(strict_types=1);

return [
    'eligible' => 'The employee is eligible to receive service.',
    'service_blocked' => 'Service blocked',
    'id_card_not_active' => 'ID card is not active',
    'no_active_id_card' => 'No active ID card found',
    'id_card_expired' => 'ID card expired',
    'id_card_revoked' => 'ID card revoked',
    'id_card_lost' => 'ID card lost',
    'message' => 'This employee cannot receive service because the ID card is not active.',
    'reasons' => [
        'no_active_id_card' => 'No active ID card found. This employee cannot receive service because the ID card is not active.',
        'id_card_pending' => 'This employee cannot receive service because the ID card is pending and not active.',
        'id_card_expired' => 'This employee cannot receive service because the ID card has expired.',
        'id_card_revoked' => 'This employee cannot receive service because the ID card has been revoked.',
        'id_card_lost' => 'This employee cannot receive service because the ID card is reported lost.',
        'id_card_replaced' => 'This employee cannot receive service because the ID card has been replaced.',
        'id_card_suspended' => 'This employee cannot receive service because the ID card is suspended.',
        'id_card_not_active' => 'This employee cannot receive service because the ID card is not active.',
        'employee_inactive' => 'This employee cannot receive service because the employee record is not active.',
    ],
];
