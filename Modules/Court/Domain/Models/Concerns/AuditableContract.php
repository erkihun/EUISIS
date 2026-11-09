<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models\Concerns;

/** Marks a court record whose changes are kept in `court_audits` (see Auditable). */
interface AuditableContract {}
