<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Base of every Reporting model. The models are a persistence detail of the
 * module: nothing outside Modules\Reporting may use them, and none of them
 * declares a relation to another owner's model (organizations and users are
 * plain references; see the README, "Data ownership").
 *
 * Every moment is stored in UTC. The application sets its runtime timezone
 * from a system setting (Africa/Addis_Ababa in production), and Eloquent by
 * default writes a date in whatever zone the object happens to carry and
 * reads a stored value in the runtime zone. Left alone, a deadline computed
 * as 14:30 UTC would be written as "14:30" and read back as 14:30 in Addis
 * Ababa: three hours early. The two methods below pin both directions.
 */
abstract class ReportingModel extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** A date-only value is a civil date and has no zone; anything else is converted to UTC before it is written. */
    public function fromDateTime($value)
    {
        if (empty($value) || (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1)) {
            return $value;
        }

        return $this->asDateTime($value)->setTimezone('UTC')->format($this->getDateFormat());
    }

    /** A stored value carries no zone: it is UTC, not the application's runtime timezone. */
    protected function asDateTime($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2}(\.\d+)?)?$/', $value) === 1) {
            return Date::parse($value, 'UTC');
        }

        return parent::asDateTime($value);
    }
}
