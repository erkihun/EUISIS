<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Letterhead contact details of an organization (the logo and colours stay on organizations). Shared by any module that issues official letters.
 */
class OrganizationLetterhead extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'organization_letterheads';

    protected $fillable = [
        'organization_id',
        'header_line_en',
        'header_line_am',
        'address_en',
        'address_am',
        'po_box',
        'phone',
        'fax',
        'email',
        'website',
        'footer_en',
        'footer_am',
        'updated_by',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
