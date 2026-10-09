<?php

declare(strict_types=1);

namespace App\Exports\Organizations;

use App\Services\OrganizationStructureImport\StructureSheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The blank Organization Structure workbook offered by the "Download Template"
 * button: one sheet per {@see StructureSheet}, each carrying exactly the
 * columns the reader understands.
 *
 * The headers come from StructureSheet itself rather than a hard-coded list, so
 * the template and the parser cannot drift apart.
 */
final class OrganizationStructureTemplateExport implements WithMultipleSheets
{
    use Exportable;

    /** @return array<int, OrganizationStructureTemplateSheet> */
    public function sheets(): array
    {
        return array_map(
            static fn (StructureSheet $sheet): OrganizationStructureTemplateSheet => new OrganizationStructureTemplateSheet($sheet),
            StructureSheet::cases(),
        );
    }
}
