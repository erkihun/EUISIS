<?php

declare(strict_types=1);

return [
    'saved' => 'Saved.',
    'deleted' => 'Deleted.',
    'draft_saved' => 'Draft standard saved. It measures work only once approved.',
    'approved' => 'Standard approved. It measures work from its effective date.',
    'retired' => 'Standard retired. Work already recorded keeps the values it was measured against.',
    'starts_before_approved' => 'This version must start after the latest approved version. Choose a later effective date.',
    'starts_in_past' => 'A version that replaces an approved one must start today or later, so work already open for entry keeps its standard.',
    'only_approved_retire' => 'Only an approved standard can be retired.',
    'approved_immutable' => 'An approved standard cannot be changed. Create a new version instead.',
    'dimension_required' => 'Set at least one planned value: quantity, time or quality.',
    'in_use' => 'Work has already been recorded against this. Make it inactive instead of deleting it.',
];
