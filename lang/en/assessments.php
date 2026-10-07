<?php

declare(strict_types=1);

return [
    'validation' => [
        'name_required' => 'Give the form a name.',
        'section_required' => 'Add at least one section.',
        'criterion_required' => 'Section ":section" needs at least one criterion.',
        'options_required' => 'Criterion ":criterion" needs at least two rating options.',
        'negative_score' => 'Criterion ":criterion" has a negative rating score.',
        'criterion_max' => 'The maximum score of ":criterion" must equal its best rating option (:best).',
        'section_max' => 'The maximum of section ":section" must equal the sum of its criteria maxima (:sum).',
        'criterion_weights' => 'In section ":section", either give every criterion a weight, with weights adding up to 100, or none.',
        'form_max' => 'The form total must equal the sum of the section maxima (:sum).',
        'section_weights' => 'The weighted scoring method needs a weight on every section, adding up to 100.',
        'contribution_required' => 'This scoring method needs an overall contribution weight.',
        'contribution_range' => 'The overall contribution weight must be above 0 and at most 100.',
        'evaluator_required' => 'Add at least one evaluator type.',
        'evaluator_weights' => 'With more than one evaluator type, every type needs a contribution weight, and the weights must add up to 100.',
        'target_required' => 'Add at least one target rule that includes employees.',
        'target_incomplete' => 'A target rule is missing what it targets.',
        'target_missing' => 'The selected target no longer exists.',
        'target_outside' => 'An organization\'s form can only target that organization.',
        'effective_dates' => 'The end date must be on or after the start date.',
    ],
    'errors' => [
        'published_immutable' => 'A published version cannot be changed. Create a new version instead.',
        'draft_exists' => 'This form already has a draft version. Publish or discard it first.',
        'only_version' => 'The only version of a form cannot be discarded.',
    ],
    'flash' => [
        'created' => 'Form created with a draft version.',
        'saved' => 'Draft saved.',
        'valid' => 'The draft is valid and can be published.',
        'invalid' => 'The draft has problems to fix before it can be published.',
        'published' => 'Version published. New assessments will use it.',
        'version_created' => 'New draft version created from the latest version.',
        'cloned' => 'Form cloned as a new draft.',
        'archived' => 'Form archived. It is no longer offered; its history is kept.',
        'discarded' => 'Draft discarded.',
    ],
];
