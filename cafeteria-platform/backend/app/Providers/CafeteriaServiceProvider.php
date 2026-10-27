<?php

declare(strict_types=1);

namespace App\Providers;

use App\Backbone\Adapters\CafeteriaCardCredentialStatus;
use App\Backbone\Adapters\EloquentAssignmentContextReader;
use App\Backbone\Adapters\EloquentEmployeeDirectory;
use App\Backbone\Adapters\EuisisCredentialLookup;
use App\Backbone\Adapters\MirrorServiceEligibility;
use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Contracts\CardCredentialStatus;
use App\Backbone\Contracts\CredentialLookup;
use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Contracts\ServiceEligibility;
use App\Contracts\SmsGateway;
use App\Support\SmsGatewayAdapter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The cafeteria module's seams onto master data, answered by the platform:
 * the backbone contracts the copied EUISIS code reads employees, placements
 * and credentials through are bound to the EUISIS mirror (ADR 0014).
 */
class CafeteriaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssignmentContextReader::class, EloquentAssignmentContextReader::class);
        $this->app->bind(EmployeeDirectory::class, EloquentEmployeeDirectory::class);
        $this->app->bind(CardCredentialStatus::class, CafeteriaCardCredentialStatus::class);
        $this->app->bind(CredentialLookup::class, EuisisCredentialLookup::class);
        $this->app->bind(ServiceEligibility::class, MirrorServiceEligibility::class);
        $this->app->bind(SmsGateway::class, SmsGatewayAdapter::class);
    }

    public function boot(): void
    {
        // Cafeteria records are UUID-keyed too: anything else is a 404 before the database.
        foreach (['cafeteriaProvider', 'cafeteriaTransaction', 'cafeteriaReport', 'cafeteriaSubsidyRule', 'cafeteriaSpecialDay', 'cafeteriaDayRule',
            'employeeCafeteriaExclusion', 'exclusion', 'publicHoliday', 'providerUser', 'branch', 'payee', 'network', 'access', 'assignment', 'policy', 'settlement', 'menu', 'order', 'transaction'] as $parameter) {
            Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }
    }
}
