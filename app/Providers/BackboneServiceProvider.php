<?php

declare(strict_types=1);

namespace App\Providers;

use App\Backbone\Adapters\CardBasedServiceEligibility;
use App\Backbone\Adapters\ClosurePathOrganizationHierarchy;
use App\Backbone\Adapters\EloquentAssignmentContextReader;
use App\Backbone\Adapters\EloquentEmployeeDirectory;
use App\Backbone\Adapters\IdCardCredentialStatus;
use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Contracts\CardCredentialStatus;
use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Contracts\OrganizationHierarchy;
use App\Backbone\Contracts\ServiceEligibility;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the master identity and organization backbone contracts
 * (docs/architecture/master-backbone.md). Service modules depend on these
 * interfaces; the adapters can later be swapped for API clients if the
 * backbone is ever deployed on its own.
 */
class BackboneServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        EmployeeDirectory::class => EloquentEmployeeDirectory::class,
        AssignmentContextReader::class => EloquentAssignmentContextReader::class,
        OrganizationHierarchy::class => ClosurePathOrganizationHierarchy::class,
        CardCredentialStatus::class => IdCardCredentialStatus::class,
        ServiceEligibility::class => CardBasedServiceEligibility::class,
    ];
}
