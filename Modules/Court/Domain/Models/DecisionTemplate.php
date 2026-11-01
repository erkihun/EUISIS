<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Court\Domain\Models\Concerns\AuditableContract as AuditableContract;
use Modules\Court\Domain\Models\Concerns\Auditable;

class DecisionTemplate extends Model implements AuditableContract
{
    protected $table = 'court_decision_templates';

    use Auditable;

    protected $fillable = [
        'title',
        'category',
        'is_default',
        'placeholders',
        'body',
        'header_image_path',
        'footer_image_path',
    ];

    protected $casts = [
        'placeholders' => 'array',
        'is_default' => 'boolean',
    ];

    /**
     * The default template used when generating a decision's final output
     * without an explicitly chosen template.
     */
    public static function default(): ?self
    {
        return static::where('is_default', true)->first()
            ?? static::orderBy('id')->first();
    }
}
