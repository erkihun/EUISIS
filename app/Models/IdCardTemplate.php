<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IdCardTemplate extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'description', 'front_background_path', 'back_background_path',
        'width_mm', 'height_mm', 'orientation', 'is_default', 'status', 'created_by', 'updated_by',
        'text_style_config',
        'layout_config',
        'header_config',
        'back_photo_config',
    ];

    // Storage paths never reach the client; assets are served through a route.
    protected $hidden = [
        'front_background_path', 'back_background_path',
        'logo_primary_path', 'logo_secondary_path',
        'seal_path', 'signature_path',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean', 'width_mm' => 'float', 'height_mm' => 'float',
            'text_style_config' => 'array',
            'layout_config' => 'array',
            'header_config' => 'array',
            'back_photo_config' => 'array',
        ];
    }
}
