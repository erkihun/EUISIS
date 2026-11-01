<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

/**
 * Only employees and institutions registered in EUISIS may sue or be sued
 * (services.euisis.parties_only, on with the EUISIS connection). New cases then
 * come from EUISIS: an employee files from the EUISIS employee portal (Flow 5)
 * or a grievance officer refers one (Flow 2), always against an EUISIS
 * institution. The applicant portal keeps following and answering cases.
 */
final class EuisisParties
{
    public static function only(): bool
    {
        return (bool) config('court.euisis.parties_only', false);
    }

    /**
     * Flow 6: employees file, follow and answer their cases in the EUISIS employee
     * portal, so court-ms's applicant and respondent portal and their sign-in,
     * registration and password pages are closed (CourtPortalInEuisis).
     */
    public static function portalClosed(): bool
    {
        return (bool) config('court.euisis.portal_in_euisis', false);
    }

    /** Where employees follow their cases (EUISIS My Portal › My court cases), when set. */
    public static function portalUrl(): ?string
    {
        $url = trim((string) config('court.euisis.portal_url', ''));

        return $url !== '' && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
