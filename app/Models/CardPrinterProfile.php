<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * How one physical printer takes ID cards: a direct-to-card printer gets one
 * card face per page at the card's own size; a desktop printer gets several
 * cards per A4/Letter sheet. Offsets and scale correct where the printer
 * actually lands, which differs from model to model.
 */
class CardPrinterProfile extends Model
{
    use HasUuidPrimaryKey;

    public const MODES = ['card', 'sheet'];

    public const PAPERS = ['a4' => [210.0, 297.0], 'letter' => [215.9, 279.4]];

    public const SIDES = ['both', 'front', 'back'];

    public const DUPLEX_ORDERS = ['interleaved', 'grouped'];

    protected $fillable = [
        'name', 'mode', 'paper', 'sides', 'duplex_order',
        'offset_x_mm', 'offset_y_mm', 'scale_percent', 'margin_mm', 'gap_mm',
        'cut_guides', 'mirror_backs', 'is_default', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'offset_x_mm' => 'float', 'offset_y_mm' => 'float', 'scale_percent' => 'integer',
            'margin_mm' => 'float', 'gap_mm' => 'float',
            'cut_guides' => 'boolean', 'mirror_backs' => 'boolean', 'is_default' => 'boolean',
        ];
    }

    /**
     * Cards per sheet for a card of the given size, after margins, gaps and
     * scale. A card printer always takes one per page.
     *
     * @return array{columns: int, rows: int, per_page: int, page_w: float, page_h: float}
     */
    public function grid(float $cardWidthMm, float $cardHeightMm): array
    {
        $scale = $this->scale_percent / 100;
        if ($this->mode !== 'sheet') {
            return ['columns' => 1, 'rows' => 1, 'per_page' => 1, 'page_w' => $cardWidthMm, 'page_h' => $cardHeightMm];
        }
        [$pageW, $pageH] = self::PAPERS[$this->paper ?? 'a4'] ?? self::PAPERS['a4'];
        $fit = fn (float $page, float $card): int => max(1, (int) floor(
            ($page - 2 * $this->margin_mm + $this->gap_mm) / ($card * $scale + $this->gap_mm),
        ));
        $columns = $fit($pageW, $cardWidthMm);
        $rows = $fit($pageH, $cardHeightMm);

        return ['columns' => $columns, 'rows' => $rows, 'per_page' => $columns * $rows, 'page_w' => $pageW, 'page_h' => $pageH];
    }

    /** @return array<string, mixed> */
    public function presentation(): array
    {
        return $this->only([
            'id', 'name', 'mode', 'paper', 'sides', 'duplex_order', 'offset_x_mm', 'offset_y_mm',
            'scale_percent', 'margin_mm', 'gap_mm', 'cut_guides', 'mirror_backs', 'is_default', 'notes',
        ]);
    }
}
