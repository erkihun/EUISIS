<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Import;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/** Lets PhpSpreadsheet load one window of rows at a time. */
final class ChunkReadFilter implements IReadFilter
{
    private int $start = 1;

    private int $end = 1;

    public function setRows(int $start, int $count): void
    {
        $this->start = $start;
        $this->end = $start + $count - 1;
    }

    /** Signature untyped to match the PhpSpreadsheet interface. */
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return (int) $row >= $this->start && (int) $row <= $this->end;
    }
}
