<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Initial resolution (Regulation 201/2018 Art. 33(1))
    |--------------------------------------------------------------------------
    |
    | The pre-grievance stage: the employee must raise the matter in writing
    | with the immediate supervisor / responsible officer and attempt
    | resolution before a formal grievance may reach the committee.
    |
    | 'proof' — what the system treats as proof the written submission and
    |   discussion occurred (NEEDS_DECISION ND-1; legal electronic-proof
    |   standard unconfirmed). 'in_system' = the system record itself.
    |
    | 'require_before_formal' — when true, a formal grievance may be filed
    |   only from an initial issue whose outcome is Failed (unresolved /
    |   no response). Set false only where project policy permits skipping.
    */
    'initial_resolution' => [
        'proof' => env('GRIEVANCE_INITIAL_RESOLUTION_PROOF', 'in_system'),
        'require_before_formal' => env('GRIEVANCE_REQUIRE_INITIAL_RESOLUTION', true),
    ],
];
