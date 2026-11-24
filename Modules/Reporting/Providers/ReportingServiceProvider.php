<?php

declare(strict_types=1);

namespace Modules\Reporting\Providers;

use App\Support\NotificationPresenter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Reporting\Application\Ports\ArtifactQueue;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\DocumentRenderer;
use Modules\Reporting\Application\Ports\EventOutbox;
use Modules\Reporting\Application\Ports\NotificationPublisher;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Ports\PdfConverter;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Ports\RichTextCleaner;
use Modules\Reporting\Application\Ports\UploadInspector;
use Modules\Reporting\Application\Ports\WorkingCalendar;
use Modules\Reporting\Application\Queries\ReportingComplianceService;
use Modules\Reporting\Application\Support\MetricSources;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Events\ReportSubmittedV1;
use Modules\Reporting\Contracts\ReportingCompliance;
use Modules\Reporting\Infrastructure\Console\ReportingHealthCommand;
use Modules\Reporting\Infrastructure\Console\RunReportingCommand;
use Modules\Reporting\Infrastructure\Documents\DatabaseArtifactQueue;
use Modules\Reporting\Infrastructure\Documents\HtmlToWord;
use Modules\Reporting\Infrastructure\Documents\LibreOfficePdfConverter;
use Modules\Reporting\Infrastructure\Documents\PhpWordDocumentRenderer;
use Modules\Reporting\Infrastructure\Documents\UploadedFileInspector;
use Modules\Reporting\Infrastructure\Integration\CoreAccessResolver;
use Modules\Reporting\Infrastructure\Integration\CoreAuditRecorder;
use Modules\Reporting\Infrastructure\Integration\CoreOrganizationDirectory;
use Modules\Reporting\Infrastructure\Integration\CoreWorkingCalendar;
use Modules\Reporting\Infrastructure\Integration\PlatformRichTextCleaner;
use Modules\Reporting\Infrastructure\Notifications\DeduplicatedNotificationPublisher;
use Modules\Reporting\Infrastructure\Notifications\ReportingNotification;
use Modules\Reporting\Infrastructure\Outbox\DatabaseOutbox;
use Modules\Reporting\Infrastructure\Outbox\NotifyReviewersOnSubmission;

/**
 * The module's single entry point: everything the application needs to know
 * about Reporting is registered here and nowhere else (bootstrap/providers.php
 * only lists this class). It holds wiring, never business rules.
 *
 * Registers: port bindings and the public contract; configuration;
 * migrations; translations (namespace "reporting"); routes; the integration
 * event listener; console commands and their schedule; the notice renderer.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    /** Ports and their adapters. Swapping an adapter (another organization source, another renderer) is a change here only. */
    public array $bindings = [
        OrganizationDirectory::class => CoreOrganizationDirectory::class,
        WorkingCalendar::class => CoreWorkingCalendar::class,
        AuditRecorder::class => CoreAuditRecorder::class,
        NotificationPublisher::class => DeduplicatedNotificationPublisher::class,
        RichTextCleaner::class => PlatformRichTextCleaner::class,
        UploadInspector::class => UploadedFileInspector::class,
        EventOutbox::class => DatabaseOutbox::class,
        ArtifactQueue::class => DatabaseArtifactQueue::class,
        // The public contract other modules may use.
        ReportingCompliance::class => ReportingComplianceService::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/reporting.php', 'reporting');

        // Per request or job: cached scope answers and settings never leak between users.
        $this->app->scoped(ReportingAccessResolver::class, CoreAccessResolver::class);
        $this->app->scoped(ReportingSettings::class);

        $this->app->bind(DocumentRenderer::class, fn ($app): PhpWordDocumentRenderer => new PhpWordDocumentRenderer(
            $app->make(HtmlToWord::class), (string) $app->make(ReportingSettings::class)->get('document_font'),
        ));
        $this->app->bind(PdfConverter::class, fn (): LibreOfficePdfConverter => new LibreOfficePdfConverter(
            config('reporting.pdf.soffice'), (int) config('reporting.pdf.timeout', 120),
        ));
        // Optional indicator sources: a class that cannot be built is left out, never fatal.
        $this->app->bind(MetricSources::class, fn ($app): MetricSources => new MetricSources(array_values(array_filter(array_map(
            static function (string $class) use ($app): ?object {
                try {
                    return $app->make($class);
                } catch (\Throwable) {
                    return null;
                }
            },
            (array) config('reporting.metric_sources', []),
        )))));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/../Lang', 'reporting');
        Route::middleware('web')->group(__DIR__.'/../Routes/web.php');

        // Reporting's own consumer of its integration event; other modules listen to the same Contracts\Events classes.
        Event::listen(ReportSubmittedV1::class, [NotifyReviewersOnSubmission::class, 'handle']);

        NotificationPresenter::registerRenderer('reporting', ReportingNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RunReportingCommand::class, ReportingHealthCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // Each task is idempotent and catches up on its own; the maintenance switch is checked inside the command.
            $schedule->command('institutional-reporting:run cycles')->hourlyAt(5)->withoutOverlapping()->onOneServer();
            $schedule->command('institutional-reporting:run deadlines')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
            $schedule->command('institutional-reporting:run reminders')->dailyAt('07:00')->timezone('Africa/Addis_Ababa')->withoutOverlapping()->onOneServer();
            $schedule->command('institutional-reporting:run outbox')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
            $schedule->command('institutional-reporting:run artifacts')->everyTenMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
