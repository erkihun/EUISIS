<?php

declare(strict_types=1);

return [
    'mandate_created' => 'Mandate draft created.',
    'mandate_updated' => 'Mandate updated.',
    'mandate_version_created' => 'New mandate version drafted. Publish it to bring it into force.',
    'mandate_published' => 'Mandate published.',
    'mandate_cancelled' => 'Mandate draft cancelled.',
    'mandate_not_draft' => 'Only a draft mandate can be changed. Published versions are immutable; draft a new version instead.',
    'mandate_publish_same_person' => 'The person who drafted a mandate cannot publish it. Another authorized approver must publish.',
    'mandate_version_from_published_only' => 'A new version can only be drafted from the published version.',
    'mandate_draft_exists' => 'This mandate already has a draft version. Publish or cancel it first.',
    'mandate_version_must_start_later' => 'A new version must take effect after the version currently in force.',
    'power_saved' => 'Power saved.',
    'power_removed' => 'Power removed.',
    'power_invalid' => 'The power does not belong to this mandate.',
    'function_saved' => 'Function saved.',
    'function_moved' => 'Function moved.',
    'function_removed' => 'Function removed.',
    'function_cycle' => 'A function cannot be placed under itself or one of its own sub-functions.',
    'function_parent_invalid' => 'The parent function must belong to the same organization.',
    'function_has_children' => 'Remove or move the sub-functions first.',
    'function_mandate_mismatch' => 'A function is created under the mandate of its own organization.',

    'fields' => [
        'code' => 'Code',
        'title_en' => 'Title (English)',
        'title_am' => 'Title (Amharic)',
        'legal_instrument_type' => 'Legal instrument type',
        'legal_instrument_number' => 'Legal instrument number',
        'effective_from' => 'Effective from',
        'effective_to' => 'Effective to',
    ],
];
