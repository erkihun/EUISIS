<?php

declare(strict_types=1);

/*
 * Dependency direction of the master identity and organization backbone
 * (docs/architecture/master-backbone.md):
 *
 *   1. Backbone code does not import service-module classes.
 *   2. Service modules do not create or bulk-update backbone records directly;
 *      they go through backbone commands.
 *
 * The allowlists are today's known exceptions (backbone-refactor-migration.md,
 * section 2). They may only shrink: a new violation fails, and so does an
 * allowlisted one that no longer exists, so its entry gets removed.
 */

const BACKBONE_PATHS = '#^app/(Backbone/|Actions/(Organizations|OrganizationUnits|OrganizationTypes|OrganizationUnitTypes|Positions|Employees|IdCards|CodeRules|GradeLevels|Occupations|IsicActivities)/|Services/(Organizations|OrganizationUnits|Positions|Employees|IdCards|Hierarchy|OrganizationScope|Verification|CodeGeneration|Core)/|Http/Controllers/Core/|Observers/|Models/(Employee|EmployeeAssignment|Position|PositionOccupancy|PositionProfile|JobDescription|Organization|OrganizationUnit|OrganizationType|OrganizationUnitType|OrganizationEdge|OrganizationClosurePath|HierarchyVersion|IdCard|CardRequest|CardIssuance|CardReplacement|CardVerification|IdCardPrintSnapshot|CodeRule|GradeLevel|Occupation|Profession)\.php)#';

// The integration layer (outbox, change feed, transfer events) is platform, not a service module.
const SERVICE_CLASS = '#^App\\\\(?!Services\\\\Integration\\\\)(Models|Services|Actions|Jobs|Events|Enums)\\\\(.*\\\\)?[A-Za-z]*(Cafeteria|Transport|Grievance|Disciplin|Performance|Kpi|StrategicGoal|Assessment|DailyActivit|FieldWork|Vacanc|Transfer|PublicFeedback|ServiceFeedback|EmployeeFeedbackToken|Intelligence|Assistant|SituationRoom|Communication|SupportTicket|Provider|Nfc|OrganizationDesign)#';

const SERVICE_PATH = '#^app/(?!Services/Integration/).*(Cafeteria|Transport|Grievance|Disciplin|Performance|Kpi|StrategicGoal|Assessment|DailyActivit|FieldWork|Vacanc|Transfer|PublicFeedback|ServiceFeedback|Intelligence|Assistant|SituationRoom|Communication|SupportTicket|ProviderPortal|Nfc|OrganizationDesign)#i';

// Scanned per line: a static create, or a query chain on the same line that writes.
const BACKBONE_MODEL = '(Employee|EmployeeAssignment|Position|PositionOccupancy|Organization|OrganizationUnit|IdCard)';
const BACKBONE_CREATE = '#\b'.BACKBONE_MODEL.'::(create|insert|upsert|updateOrCreate|firstOrCreate|forceCreate)\(#';
const BACKBONE_QUERY_WRITE = '#\b'.BACKBONE_MODEL.'::query\(\).*->(create|update|delete|forceDelete|insert|upsert|increment|decrement)\(#';

/** @return list<string> repository-relative PHP paths under app/ */
function backboneAppFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
    sort($files);

    return $files;
}

it('keeps backbone code free of service-module imports', function (): void {
    $root = dirname(__DIR__, 2);
    $found = [];
    foreach (backboneAppFiles() as $path) {
        if (! preg_match(BACKBONE_PATHS, $path)) {
            continue;
        }
        preg_match_all('/^use (App\\\\[A-Za-z0-9_\\\\]+);/m', (string) file_get_contents($root.'/'.$path), $m);
        foreach ($m[1] as $class) {
            if (preg_match(SERVICE_CLASS, $class)) {
                $found[] = $path.' -> '.$class;
            }
        }
    }

    $allowed = [
        // Position profiles and job descriptions share the design-record lifecycle
        // (draft, review, approved, published, superseded) with organization design.
        // The enum is shared vocabulary, not a service dependency; kept on purpose.
        'app/Models/JobDescription.php -> App\Enums\OrganizationDesignStatus',
        'app/Models/PositionProfile.php -> App\Enums\OrganizationDesignStatus',
    ];

    expect(array_values(array_diff($found, $allowed)))->toBe([], 'New backbone -> service dependency. Depend on a contract or event instead (master-backbone.md).')
        ->and(array_values(array_diff($allowed, $found)))->toBe([], 'This exception is gone: remove it from the allowlist.');
});

it('keeps service modules from creating or bulk-updating backbone records', function (): void {
    $root = dirname(__DIR__, 2);
    $found = [];
    foreach (backboneAppFiles() as $path) {
        if (preg_match(BACKBONE_PATHS, $path) || ! preg_match(SERVICE_PATH, $path)) {
            continue;
        }
        foreach (preg_split('/\R/', (string) file_get_contents($root.'/'.$path)) as $line) {
            if (preg_match(BACKBONE_CREATE, $line) || preg_match(BACKBONE_QUERY_WRITE, $line)) {
                $found[] = $path;
                break;
            }
        }
    }

    $allowed = [
        // Development load-test seeder, never run in production.
        'app/Console/Commands/Transport/SeedTransportLoadTest.php',
    ];

    expect(array_values(array_diff($found, $allowed)))->toBe([], 'A service module writes a backbone record. Call a backbone command instead (master-backbone.md).')
        ->and(array_values(array_diff($allowed, $found)))->toBe([], 'This exception is gone: remove it from the allowlist.');
});

it('makes service modules use backbone contracts instead of the services behind them', function (): void {
    // Stage 3: credential parsing and the service gate are reached through
    // CredentialLookup and ServiceEligibility, never the concrete classes.
    $concrete = ['App\Services\EmployeeServiceEligibilityService', 'App\Services\IdCards\CardQrPayloadService'];
    $root = dirname(__DIR__, 2);
    $found = [];
    foreach (backboneAppFiles() as $path) {
        if (preg_match(BACKBONE_PATHS, $path) || ! preg_match(SERVICE_PATH, $path)) {
            continue;
        }
        $source = (string) file_get_contents($root.'/'.$path);
        foreach ($concrete as $class) {
            if (str_contains($source, 'use '.$class.';')) {
                $found[] = $path.' -> '.$class;
            }
        }
    }

    $allowed = [
        // Development load-test seeder, never run in production.
        'app/Console/Commands/Transport/SeedTransportLoadTest.php -> App\Services\IdCards\CardQrPayloadService',
    ];

    expect(array_values(array_diff($found, $allowed)))->toBe([], 'Use the backbone contract (App\Backbone\Contracts) instead.')
        ->and(array_values(array_diff($allowed, $found)))->toBe([], 'This exception is gone: remove it from the allowlist.');
});
