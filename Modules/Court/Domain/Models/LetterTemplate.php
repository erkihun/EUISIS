<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Court\Domain\Models\Concerns\AuditableContract as AuditableContract;
use Modules\Court\Domain\Models\Concerns\Auditable;

class LetterTemplate extends Model implements AuditableContract
{
    protected $table = 'court_letter_templates';

    use Auditable;

    protected $fillable = [
        'title',
        'category',
        'subject_prefix',
        'reference_sequence',
        'placeholders',
        'body',
        'header_image_path',
        'footer_image_path',
    ];

    protected $casts = [
        'placeholders' => 'array',
        'reference_sequence' => 'integer',
    ];
}
