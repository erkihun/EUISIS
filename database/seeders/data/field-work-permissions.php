<?php

declare(strict_types=1);

$definitions = [
    ['field_work.view_own', 'View Own Field Work', 'የራስ የመስክ ሥራ ማየት', 'View Field Work records belonging to the signed-in employee.'],
    ['field_work.create', 'Create Field Work', 'የመስክ ሥራ መፍጠር', 'Create a Field Work draft for the signed-in employee.'],
    ['field_work.update_own', 'Update Own Field Work', 'የራስ የመስክ ሥራ ማስተካከል', 'Update an own draft or returned Field Work request.'],
    ['field_work.submit', 'Submit Own Field Work', 'የራስ የመስክ ሥራ ማቅረብ', 'Submit an own Field Work request to the configured supervisor.'],
    ['field_work.complete', 'Complete Field Work', 'የመስክ ሥራ ማጠናቀቅ', 'Record an authorized actual return and complete Field Work.'],
    ['field_work.view_team', 'View Team Field Work', 'የቡድን የመስክ ሥራ ማየት', 'View Field Work covered by an effective reviewer assignment.'],
    ['field_work.approve', 'Approve Field Work', 'የመስክ ሥራ ማጽደቅ', 'Approve Field Work covered by an effective reviewer assignment.'],
    ['field_work.return', 'Return Field Work', 'የመስክ ሥራ መመለስ', 'Return Field Work for correction with a reason.'],
    ['field_work.reject', 'Reject Field Work', 'የመስክ ሥራ ውድቅ ማድረግ', 'Reject Field Work with a reason.'],
    ['field_work.view_scoped', 'View Scoped Field Work', 'በወሰን የመስክ ሥራ ማየት', 'View Field Work inside the user’s organization scope.'],
    ['field_work.view_reports', 'View Field Work Reports', 'የመስክ ሥራ ሪፖርቶችን ማየት', 'View organization-scoped Field Work reports without precise coordinates.'],
    ['field_work.manage_types', 'Manage Field Work Types', 'የመስክ ሥራ ዓይነቶችን ማስተዳደር', 'Manage the configurable Field Work type catalog.'],
    ['field_work.view_team_availability', 'View Field Work Availability', 'የመስክ ሥራ ተገኝነትን ማየት', 'View scoped Field Work availability without precise coordinates.'],
    ['field_work.location.capture_own', 'Capture Own Field Work Location', 'የራስ የመስክ ሥራ ቦታ መመዝገብ', 'Explicitly capture an own participant check-in or check-out observation.'],
    ['field_work.location.view_own', 'View Own Location Status', 'የራስ የቦታ ሁኔታ ማየት', 'View own Field Work location verification status.'],
    ['field_work.location.view_team', 'View Team Location Status', 'የቡድን የቦታ ሁኔታ ማየት', 'View location verification status for the covered team.'],
    ['field_work.location.view_org', 'View Scoped Location Status', 'በወሰን የቦታ ሁኔታ ማየት', 'View organization-scoped location verification status.'],
    ['field_work.location.view_precise', 'View Precise Field Work Location', 'ትክክለኛ የመስክ ሥራ ቦታ ማየት', 'View sensitive exact coordinates when a dedicated read surface authorizes them.'],
    ['field_work.location.export', 'Export Precise Field Work Location', 'ትክክለኛ የመስክ ሥራ ቦታ ወደ ውጭ መላክ', 'Export sensitive exact coordinates through an explicitly authorized export.'],
];

return array_map(
    static fn (array $definition, int $index): array => [
        'name' => $definition[0],
        'group' => 'Field Work',
        'sort_order' => ($index + 1) * 10,
        'is_system' => true,
        'label_en' => $definition[1],
        'label_am' => $definition[2],
        'description_en' => $definition[3],
        'description_am' => $definition[2].' ፈቃድ ይሰጣል።',
    ],
    $definitions,
    array_keys($definitions),
);
