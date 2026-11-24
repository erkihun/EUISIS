<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

/** A module setting changed from the settings page; defaults live in Config/reporting.php. */
final class Setting extends Model
{
    public $incrementing = false;

    protected $table = 'reporting_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
