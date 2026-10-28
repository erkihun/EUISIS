<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\IntelligenceAiRun;
use App\Models\IntelligenceAlert;
use App\Models\IntelligenceDeployment;
use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceForecast;
use App\Models\IntelligenceIncident;
use App\Models\IntelligenceKnowledgeQuestion;
use App\Models\IntelligenceRecommendation;
use App\Models\User;
use App\Services\Intelligence\AI\PromptRegistry;
use App\Services\Intelligence\Alerts\AlertEngine;
use App\Services\Intelligence\Forecasting\Forecaster;
use App\Services\Intelligence\Forecasting\ForecastSnapshotService;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\Knowledge\KnowledgeRetriever;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Detection\IncidentManager;
use App\Services\Intelligence\System\ErrorFingerprinter;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use App\Services\Intelligence\SystemContext;
use App\Support\Database\SqlDateBucket;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DEVELOPMENT / QA / UAT demo data for Agelgay Intelligence
 * (docs/demo-seed-data.md, "Intelligence"):
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\DemoIntelligenceSeeder"
 *
 * Fills what the Intelligence areas read on a machine where no collector,
 * detection run or public submission has happened:
 *
 *   - 30 days of telemetry (hourly, the last 72 hours every 5 minutes) with
 *     office-hour traffic, a cafeteria lunch peak, a commute peak for
 *     transport and the episodes the incidents below describe — only in
 *     buckets the collector has not written;
 *   - deployments and error groups, then incidents raised, repeated,
 *     escalated and deployment-correlated by the IncidentManager itself (with
 *     its rule recommendations) and moved through their lifecycle the way the
 *     incident page moves them;
 *   - root-cause runs of a `demo` provider (no AI provider is called),
 *     reviewed recommendations, resolved alert history and knowledge
 *     questions answered by the real retriever;
 *   - a year of public submissions on the existing organizations and
 *     employees (DEMO-PF- references), forecast back-tests of the real
 *     Forecaster on that year, today's forecast snapshot, and finally the
 *     real AlertEngine.
 *
 * Nothing is sent: notifications are faked for this process, mail and queue
 * are off (DemoSeeder). Re-runnable: telemetry is topped up to now on every
 * run (re-run it to make the health tiles fresh again); the history and the
 * submissions are written once, recognised by the `demo-seeder` deployments
 * and the DEMO-PF- references.
 */
class DemoIntelligenceSeeder extends DemoSeeder
{
    public const RECORDED_BY = 'demo-seeder';

    public const REFERENCE_PREFIX = 'DEMO-PF-';

    public const AI_PROVIDER = 'demo';

    private const TELEMETRY_DAYS = 30;

    private const FINE_HOURS = 72;

    private const LOCAL_TIMEZONE = 'Africa/Addis_Ababa';

    /** Office traffic by local hour, 1 = the busiest hour. */
    private const HOURLY_LOAD = [0.03, 0.02, 0.02, 0.02, 0.03, 0.06, 0.15, 0.40, 0.80, 1.00, 1.00, 0.95, 0.70, 0.85, 0.95, 0.90, 0.75, 0.45, 0.25, 0.15, 0.10, 0.07, 0.05, 0.04];

    /** Route group => [requests per 5 minutes at the busiest hour, average latency ms, 4xx share, share of traffic outside office hours]. */
    private const ROUTE_GROUPS = [
        'admin' => [210, 185, 0.035, 0.05],
        'employee_portal' => [160, 140, 0.03, 0.10],
        'public_feedback' => [75, 230, 0.05, 0.25],
        'cafeteria' => [120, 95, 0.02, 0.0],
        'daily_activity' => [60, 160, 0.02, 0.02],
        'performance' => [35, 270, 0.03, 0.0],
        'field_work' => [18, 150, 0.02, 0.05],
        'transfers' => [12, 210, 0.03, 0.0],
        'auth' => [55, 120, 0.08, 0.10],
        'api' => [95, 65, 0.015, 0.60],
    ];

    /** Integration provider => calls per 5 minutes at night (load adds six times as many). */
    private const PROVIDERS = ['sms_http' => 3, 'smtp_mail' => 5, 'national_id_registry' => 2];

    private const WEBHOOK_APPS = ['hr-payroll', 'city-portal'];

    /** Key => [working days ago, UTC hour, minute, change summary]. */
    private const RELEASES = [
        'transfers' => [29, 15, 30, 'Transfer approvals and the ID card print queue.'],
        'feedback_attachments' => [26, 7, 0, 'Public feedback: photo attachments on the complaint form.'],
        'feedback_hotfix' => [25, 14, 0, 'Hotfix: the attachment validator accepts HEIC photos; regression test added.'],
        'performance' => [19, 15, 0, 'Performance plans: weighted KPI scores.'],
        'notifications' => [13, 15, 30, 'Notifications: daily digest e-mails for supervisors.'],
        'cafeteria' => [6, 6, 50, 'Cafeteria: transaction list filters and export.'],
        'portal' => [3, 15, 0, 'Employee portal: dashboard cards.'],
        'org_tree' => [1, 15, 30, 'Organization tree: the assigned employee photo.'],
    ];

    private const STATUS_EVENTS = ['mitigated' => 'mitigated', 'resolved' => 'resolved', 'closed' => 'closed'];

    /** Questions people asked the Knowledge Assistant: [locale, question, days ago]. */
    private const QUESTIONS = [
        ['en', 'How do I record a deployment so that incidents are correlated with it?', 19],
        ['en', 'What happens to an incident when the condition stops?', 17],
        ['en', 'Who can see public complaints about an employee?', 15],
        ['en', 'How is the daily cafeteria subsidy calculated?', 12],
        ['en', 'How do I restore the database from a backup?', 9],
        ['en', 'What are the steps of the transfer approval workflow?', 7],
        ['am', 'የሠራተኛ መታወቂያ ካርድ እንዴት ይታተማል?', 6],
        ['am', 'ቅሬታ ለማቅረብ የሚያስፈልገው ሂደት ምንድን ነው?', 4],
        ['en', 'Which roles may open the system health pages?', 3],
        ['en', 'How are KPI scores weighted in a performance plan?', 2],
        ['en', 'How long are closed incidents and telemetry kept?', 1],
    ];

    /** Public submission texts by type. */
    private const COMMENTS = [
        'feedback' => [
            'አገልግሎቱ ፈጣንና ጥሩ ነበር። አመሰግናለሁ።',
            'ሠራተኛው በትህትና አስተናግዶኛል።',
            'ጉዳዬ በአንድ ቀን ተፈጽሟል።',
            'የሚያስፈልጉ ሰነዶች በግልጽ ተነግረውኛል።',
            'መጠበቂያ ቦታው ንጹሕና ምቹ ነው።',
            'አገልግሎቱ ጥሩ ቢሆንም ወረፋው ረጅም ነበር።',
        ],
        'complaint' => [
            'ከሦስት ሰዓት በላይ ተሰልፌ ጠብቄያለሁ።',
            'ለተመሳሳይ ጉዳይ ሦስት ጊዜ እንድመለስ ተደርጌያለሁ።',
            'ቢሮው በተገለጸው ሰዓት አልተከፈተም።',
            'የሚያስፈልጉ ሰነዶች ዝርዝር ግልጽ አልነበረም።',
            'ሠራተኛው ጥያቄዬን ሳያዳምጥ መለሰኝ።',
            'የተሽከርካሪ ወንበር ተጠቃሚዎች ወደ ሁለተኛ ፎቅ መድረስ አይችሉም።',
            'ማመልከቻዬ ከሁለት ሳምንት በላይ ምላሽ አላገኘም።',
        ],
        'suggestion' => [
            'የመስመር ላይ ቀጠሮ ቢዘጋጅ ወረፋውን ይቀንሳል።',
            'የሚያስፈልጉ ሰነዶች ዝርዝር በመግቢያው ላይ ቢለጠፍ ጥሩ ነው።',
            'ለአረጋውያንና ለአካል ጉዳተኞች የተለየ መስኮት ቢኖር።',
            'የአገልግሎት ሰዓት እስከ ምሽት ቢራዘም።',
            'የወረፋ ቁጥር ስርዓት ቢጀመር።',
        ],
    ];

    private const COMPLAINT_SUBJECTS = ['ረጅም ወረፋ', 'የአገልግሎት መዘግየት', 'ተደጋጋሚ ቀጠሮ', 'የሠራተኛ ባህሪ', 'ግልጽ ያልሆነ መረጃ', 'የተደራሽነት ችግር'];

    /** Category code weights by submission type. */
    private CarbonImmutable $now;

    /** @var array<string, CarbonImmutable> */
    private array $releases = [];

    /** @var array<string, array{0: CarbonImmutable, 1: CarbonImmutable}> */
    private array $episodes = [];

    private CarbonImmutable $backupFailedSince;

    private int $failedTotal = 0;

    public function run(
        SignalRepository $signals,
        IncidentManager $incidents,
        AlertEngine $alerts,
        KnowledgeRetriever $retriever,
        SystemContext $system,
        IntelligenceSettings $settings,
        ForecastSnapshotService $forecasts,
    ): void {
        $admin = $system->actor() ?? throw new RuntimeException('Intelligence demo data needs an active Super Admin to own, review and acknowledge.');

        mt_srand(20261027);
        Notification::fake();
        $this->now = CarbonImmutable::now()->startOfMinute();
        $this->timeline();

        $this->command?->info('Telemetry: '.$this->telemetry($signals).' samples written.');

        try {
            if (IntelligenceDeployment::query()->where('recorded_by', self::RECORDED_BY)->exists()) {
                $this->command?->line('Deployments, errors, incidents, AI runs and questions: already seeded, kept.');
            } else {
                $this->deployments();
                $fingerprints = $this->errorFingerprints();
                $raised = $this->incidents($incidents, $admin, $fingerprints);
                $this->rootCauseRuns($incidents, $raised, $admin);
                $this->reviewRecommendations($admin);
                $this->alertHistory($admin);
                $questions = $this->knowledgeQuestions($retriever, $admin);
                $this->command?->info(sprintf('History: %d deployments, %d error groups, %d incidents, %d recommendations, %d knowledge questions.',
                    count($this->releases), count($fingerprints), count($raised), IntelligenceRecommendation::query()->count(), $questions));
            }

            if (DB::table('employee_service_feedback')->where('reference_number', 'like', self::REFERENCE_PREFIX.'%')->exists()) {
                $this->command?->line('Public submissions and forecast back-tests: already seeded, kept.');
            } else {
                $submitted = $this->submissions($admin);
                $backtests = $this->forecastBacktests();
                $this->command?->info("Public submissions: {$submitted}; forecast back-test points: {$backtests}.");
            }
        } finally {
            $this->at(null);
        }

        $context = $system->cityWide($admin);
        if ($settings->forecastingEnabled() && $context !== null) {
            $snapshot = $forecasts->snapshot($context);
            $this->command?->info("Forecast snapshot: {$snapshot['written']} points.");
        }

        $summary = $alerts->run();
        $this->acknowledgeAlerts($admin);
        $this->command?->info('Alerts: '.json_encode($summary));
    }

    // ---------------------------------------------------------------- timeline

    private function timeline(): void
    {
        foreach (self::RELEASES as $key => [$daysAgo, $hour, $minute]) {
            $this->releases[$key] = $this->workday($daysAgo, $hour, $minute);
        }

        $window = fn (CarbonImmutable $from, int $minutes): array => [$from, $from->addMinutes($minutes)];
        $this->episodes = [
            'feedback_errors' => $window($this->releases['feedback_attachments']->addMinutes(18), 125),
            'db_slow' => $window($this->workday(19, 8), 180),
            'cache_down' => $window($this->now->subDays(16)->setTime(10, 5), 15),
            'notifications_backlog' => $window($this->workday(12, 6, 30), 200),
            'sms_failures' => $window($this->workday(8, 6), 170),
            'cafeteria_errors' => $window($this->releases['cafeteria']->addMinutes(35), 190),
            'scheduler_gap' => $window($this->now->subDays(4)->setTime(1, 0), 130),
            'cafeteria_latency' => $window($this->workday(1, 9), 120),
            'auth_attack' => $window($this->now->subHours(30)->startOfHour()->addMinutes(10), 45),
            'failed_jobs' => $window($this->now->subHours(8)->startOfHour(), 60),
        ];

        // The nightly backup (23:00 UTC) has failed since the first run at least 40 hours ago.
        $failed = $this->now->subHours(40)->setTime(23, 0);
        $this->backupFailedSince = $failed->gt($this->now->subHours(40)) ? $failed->subDay() : $failed;
    }

    /** A moment `$daysAgo` days back, moved to the previous Friday when it falls on an Addis Ababa weekend. */
    private function workday(int $daysAgo, int $hour, int $minute = 0): CarbonImmutable
    {
        $at = $this->now->subDays($daysAgo)->setTime($hour, $minute);
        while ($at->setTimezone(self::LOCAL_TIMEZONE)->isWeekend()) {
            $at = $at->subDay();
        }

        return $at;
    }

    /** How much of [$from, $from + $minutes) the episode covers, 0..1. */
    private function intensity(string $episode, CarbonImmutable $from, int $minutes): float
    {
        [$start, $end] = $this->episodes[$episode];
        $overlap = min($from->getTimestamp() + $minutes * 60, $end->getTimestamp()) - max($from->getTimestamp(), $start->getTimestamp());

        return $overlap <= 0 ? 0.0 : $overlap / ($minutes * 60);
    }

    // --------------------------------------------------------------- telemetry

    /** Fills every telemetry bucket of the window that no collector run (or earlier seed) wrote. */
    private function telemetry(SignalRepository $signals): int
    {
        $end = $this->now->subMinutes($this->now->minute % 5);
        $fine = $end->subHours(self::FINE_HOURS)->startOfHour();
        $from = $end->subDays(self::TELEMETRY_DAYS)->startOfHour();

        $taken = [];
        foreach (DB::table('intelligence_signals')->where('bucket_start', '>=', $from->toDateTimeString())->distinct()->pluck('bucket_start') as $bucket) {
            $at = CarbonImmutable::parse((string) $bucket);
            $taken[$at->lt($fine) ? $at->format('Y-m-d H') : $at->format('Y-m-d H:i')] = true;
        }
        $this->failedTotal = (int) (DB::table('intelligence_signals')->where('metric', Metrics::QUEUE_FAILED_TOTAL)->orderByDesc('bucket_start')->value('value') ?? 0);

        $written = 0;
        $buffer = [];
        $add = function (CarbonImmutable $at, int $minutes) use (&$buffer, &$written, $signals): void {
            array_push($buffer, ...$this->bucket($at, $minutes));
            if (count($buffer) >= 5000) {
                $written += $signals->write($buffer);
                $buffer = [];
            }
        };

        for ($at = $from; $at->lt($fine); $at = $at->addHour()) {
            if (! isset($taken[$at->format('Y-m-d H')])) {
                $add($at, 60);
            }
        }
        for ($at = $fine; $at->lte($end); $at = $at->addMinutes(5)) {
            if (! isset($taken[$at->format('Y-m-d H:i')])) {
                $add($at, 5);
            }
        }

        return $written + $signals->write($buffer);
    }

    /**
     * One collector run's samples for the bucket starting at `$at`. An hourly
     * bucket carries the hour's sums for counts and its typical value for
     * gauges, so day charts read the same from either grain.
     *
     * @return list<array<string, mixed>>
     */
    private function bucket(CarbonImmutable $at, int $minutes): array
    {
        $scale = $minutes / 5;
        $mid = $at->addMinutes(intdiv($minutes, 2));
        $load = $this->load($mid);
        $ageDays = max(0.0, ($this->now->getTimestamp() - $mid->getTimestamp()) / 86400);
        $growth = 1 + 0.12 * max(0.0, 1 - $ageDays / self::TELEMETRY_DAYS);
        // 1 now, 0 a day ago: the slow climbs the Predictive area watches.
        $lastDay = max(0.0, 1 - $ageDays);
        $episode = fn (string $key): float => $this->intensity($key, $at, $minutes);

        $out = [];
        $sample = function (string $metric, string $component, int|float $value, ?string $dimension = null, ?string $unit = null, int $count = 1) use ($at, &$out): void {
            $out[] = ['metric' => $metric, 'component' => $component, 'dimension' => $dimension, 'value' => $value, 'unit' => $unit, 'sample_count' => max(1, $count), 'bucket_start' => $at];
        };

        // application, per route group
        $attack = $episode('auth_attack');
        foreach (self::ROUTE_GROUPS as $group => [$peak, $latency, $clientShare, $flat]) {
            $shape = $group === 'cafeteria' ? $this->lunchLoad($mid) : $flat + (1 - $flat) * $load;
            $total = $this->units($peak * $shape * $growth * $scale * $this->jitter(0.12));
            $serverShare = 0.0015 + match ($group) {
                'public_feedback' => 0.07 * $episode('feedback_errors'),
                'cafeteria' => 0.05 * $episode('cafeteria_errors'),
                'employee_portal' => $mid->gte($this->releases['portal']) ? 0.002 : 0.0,
                default => 0.0,
            };
            $slowdown = 1 + match ($group) {
                'cafeteria' => 4.5 * $episode('cafeteria_latency') + 1.5 * $episode('cafeteria_errors'),
                'admin', 'employee_portal', 'public_feedback' => 2.5 * $episode('db_slow'),
                default => 0.0,
            };

            $s5xx = $this->units($total * $serverShare);
            $s4xx = $this->units($total * $clientShare);
            $s401 = $this->units($s4xx * 0.25);
            $s403 = $this->units($s4xx * 0.15);
            $s429 = $this->units($s4xx * 0.05);
            if ($group === 'auth' && $attack > 0) {
                $denied = $this->units(160 * $scale * $attack);
                $throttled = $this->units(60 * $scale * $attack);
                $s401 += $denied;
                $s429 += $throttled;
                $s4xx += $denied + $throttled;
                $total += $denied + $throttled;
            }
            $s3xx = $this->units($total * ($group === 'auth' ? 0.3 : 0.06));
            $counts = [
                Metrics::REQUEST_TOTAL => $total,
                Metrics::REQUEST_2XX => max(0, $total - $s3xx - $s4xx - $s5xx),
                Metrics::REQUEST_3XX => $s3xx,
                Metrics::REQUEST_4XX => $s4xx,
                Metrics::REQUEST_5XX => $s5xx,
                Metrics::REQUEST_401 => $s401,
                Metrics::REQUEST_403 => $s403,
                Metrics::REQUEST_429 => $s429,
                Metrics::REQUEST_SLOW => $this->units($total * (0.012 + 0.05 * ($slowdown - 1))),
            ];
            foreach ($counts as $metric => $value) {
                $sample($metric, Metrics::COMPONENT_APPLICATION, $value, $group, 'count', $total);
            }
            if ($total > 0) {
                $avg = round($latency * (1 + 0.25 * $load) * $slowdown * $this->jitter(0.08), 1);
                $sample(Metrics::REQUEST_LATENCY_AVG_MS, Metrics::COMPONENT_APPLICATION, $avg, $group, 'ms', $total);
                $sample(Metrics::REQUEST_LATENCY_P95_MS, Metrics::COMPONENT_APPLICATION, round($avg * 2.6 * $this->jitter(0.1)), $group, 'ms', $total);
                $sample(Metrics::REQUEST_ERROR_RATE, Metrics::COMPONENT_APPLICATION, round(100 * $s5xx / $total, 2), $group, 'percent', $total);
            }
        }
        $sample(Metrics::SESSIONS_ACTIVE, Metrics::COMPONENT_APPLICATION, (int) round((25 + 310 * $load) * $this->jitter(0.1)), null, 'count');

        // database
        $slowDb = $episode('db_slow');
        $active = (int) round(6 + 22 * $load * $this->jitter(0.15) + 16 * $lastDay + 30 * $slowDb);
        $deadlocks = 2 + ($mid->gte($this->episodes['db_slow'][0]) ? 1 : 0) + ($mid->gte($this->episodes['cafeteria_errors'][0]) ? 1 : 0);
        $sample(Metrics::DB_PING_MS, Metrics::COMPONENT_DATABASE, round((0.9 + 0.8 * $load) * $this->jitter(0.25) * (1 + 10 * $slowDb), 2), null, 'ms');
        $sample(Metrics::DB_CONNECTIONS_ACTIVE, Metrics::COMPONENT_DATABASE, $active, null, 'count');
        $sample(Metrics::DB_CONNECTIONS_MAX, Metrics::COMPONENT_DATABASE, 100, null, 'count');
        $sample(Metrics::DB_CONNECTIONS_PERCENT, Metrics::COMPONENT_DATABASE, (float) $active, null, 'percent');
        $sample(Metrics::DB_LONG_QUERIES, Metrics::COMPONENT_DATABASE, $slowDb > 0 ? mt_rand(3, 6) : ($this->chance(0.03 * $load) ? 1 : 0), null, 'count');
        $sample(Metrics::DB_WAITING_LOCKS, Metrics::COMPONENT_DATABASE, $slowDb > 0 ? mt_rand(1, 3) : ($this->chance(0.02) ? 1 : 0), null, 'count');
        $sample(Metrics::DB_DEADLOCKS_TOTAL, Metrics::COMPONENT_DATABASE, $deadlocks, null, 'count');

        // cache and redis
        $sample(Metrics::CACHE_AVAILABLE, Metrics::COMPONENT_CACHE, round(1 - $episode('cache_down'), 2));
        $sample(Metrics::CACHE_PING_MS, Metrics::COMPONENT_CACHE, round(0.4 + 0.9 * $load * $this->jitter(0.4), 2), null, 'ms');
        $sample(Metrics::REDIS_AVAILABLE, Metrics::COMPONENT_REDIS, 1);
        $sample(Metrics::REDIS_PING_MS, Metrics::COMPONENT_REDIS, round(0.5 + 1.1 * $load * $this->jitter(0.4), 2), null, 'ms');

        // queues
        $backlog = $episode('notifications_backlog');
        $pending = [
            'default' => $this->units(4 * $load * $this->jitter(0.6)),
            'notifications' => (int) round((8 + 20 * $load) * $this->jitter(0.2) + 45 * $lastDay + 700 * $backlog),
            'long' => $this->chance(0.1) ? mt_rand(1, 2) : 0,
        ];
        foreach ($pending as $queue => $jobs) {
            $oldest = match ($queue) {
                'default' => $jobs > 0 ? mt_rand(15, 240) : 0,
                'notifications' => (int) round((60 + 5 * $jobs) * $this->jitter(0.2) + 2400 * $backlog),
                default => $jobs > 0 ? mt_rand(60, 600) : 0,
            };
            $sample(Metrics::QUEUE_PENDING, Metrics::COMPONENT_QUEUE, $jobs, $queue, 'count');
            $sample(Metrics::QUEUE_RESERVED, Metrics::COMPONENT_QUEUE, $jobs > 0 ? min($jobs, $queue === 'notifications' ? 2 : 1) : 0, $queue, 'count');
            $sample(Metrics::QUEUE_OLDEST_AGE_SECONDS, Metrics::COMPONENT_QUEUE, $oldest, $queue, 'seconds');
        }
        $failed = ($this->chance(0.006 * $scale) ? 1 : 0) + $this->units(4 * $scale * $episode('failed_jobs') + 2 * $scale * $backlog);
        $this->failedTotal += $failed;
        $sample(Metrics::QUEUE_FAILED_5M, Metrics::COMPONENT_QUEUE, $failed, null, 'count');
        $sample(Metrics::QUEUE_FAILED_TOTAL, Metrics::COMPONENT_QUEUE, $this->failedTotal, null, 'count');

        // scheduler, storage, backups
        $beats = (int) round($scale * (1 - $episode('scheduler_gap')));
        if ($beats > 0) {
            $sample(Metrics::SCHEDULER_HEARTBEAT, Metrics::COMPONENT_SCHEDULER, $beats);
        }
        $sample(Metrics::STORAGE_WRITABLE, Metrics::COMPONENT_STORAGE, 1);
        $sample(Metrics::STORAGE_FREE_PERCENT, Metrics::COMPONENT_STORAGE, round(31 + 10.5 * min(1.0, $ageDays / self::TELEMETRY_DAYS) + 0.2 * ($this->jitter(1) - 1), 1), null, 'percent');
        $age = $this->backupAge($mid);
        $sample(Metrics::BACKUP_HEALTH, Metrics::COMPONENT_BACKUPS, Metrics::HEALTH_VALUES[$age <= 26 ? 'healthy' : ($age <= 96 ? 'degraded' : 'unhealthy')]);
        $sample(Metrics::BACKUP_AGE_HOURS, Metrics::COMPONENT_BACKUPS, $age, null, 'hours');

        // integrations
        foreach (self::PROVIDERS as $provider => $base) {
            $calls = $this->units(($base + 6 * $base * $load) * $scale * $this->jitter(0.2));
            $rate = 0.01 + ($provider === 'sms_http' ? 0.55 * $episode('sms_failures') : 0.0);
            $sample(Metrics::INTEGRATION_CALLS, Metrics::COMPONENT_INTEGRATIONS, $calls, $provider, 'count');
            $sample(Metrics::INTEGRATION_FAILURES, Metrics::COMPONENT_INTEGRATIONS, $this->units($calls * $rate), $provider, 'count');
        }
        $api = $this->units((30 + 60 * $load) * $scale * $this->jitter(0.15));
        $outbox = $this->units(5 * $load * $this->jitter(0.6));
        $sample(Metrics::INTEGRATION_API_REQUESTS, Metrics::COMPONENT_INTEGRATIONS, $api, null, 'count');
        $sample(Metrics::INTEGRATION_API_FAILURES, Metrics::COMPONENT_INTEGRATIONS, $this->units($api * 0.004), null, 'count');
        $sample(Metrics::INTEGRATION_OUTBOX_PENDING, Metrics::COMPONENT_INTEGRATIONS, $outbox, null, 'count');
        $sample(Metrics::INTEGRATION_OUTBOX_OLDEST_SECONDS, Metrics::COMPONENT_INTEGRATIONS, $outbox > 0 ? mt_rand(5, 90) : 0, null, 'seconds');
        $sample(Metrics::INTEGRATION_WEBHOOK_DEAD_TOTAL, Metrics::COMPONENT_INTEGRATIONS, ($mid->gte($this->episodes['sms_failures'][0]) ? 1 : 0) + ($ageDays < 2 ? 1 : 0), null, 'count');
        foreach (self::WEBHOOK_APPS as $app) {
            if ($this->chance(0.02 * $scale)) {
                $sample(Metrics::INTEGRATION_WEBHOOK_FAILURES, Metrics::COMPONENT_INTEGRATIONS, 1, $app, 'count');
            }
        }
        $sample(Metrics::INTEGRATION_BROADCAST_FAILURES_TOTAL, Metrics::COMPONENT_INTEGRATIONS, 0, null, 'count');

        // security
        $sample(Metrics::AUTH_MFA_FAILURES, Metrics::COMPONENT_SECURITY, $this->units(1.2 * $load * $scale * $this->jitter(0.5) + 25 * $scale * $attack), null, 'count');

        // transport: 15-minute windows are gauges
        $commute = $this->commute($mid);
        $offline = $this->units(12 * $commute * $this->jitter(0.5));
        $boardings = (int) round(420 * $commute * $this->jitter(0.15));
        $sample(Metrics::TRANSPORT_DEVICES_ACTIVE, Metrics::COMPONENT_TRANSPORT, 24, null, 'count');
        $sample(Metrics::TRANSPORT_DEVICES_SILENT, Metrics::COMPONENT_TRANSPORT, $ageDays < 1 ? 2 : ($this->chance(0.05) ? 1 : 0), null, 'count');
        $sample(Metrics::TRANSPORT_OFFLINE_PENDING, Metrics::COMPONENT_TRANSPORT, $offline, null, 'count');
        $sample(Metrics::TRANSPORT_OFFLINE_OLDEST_MINUTES, Metrics::COMPONENT_TRANSPORT, $offline > 0 ? mt_rand(2, 25) : 0, null, 'minutes');
        $sample(Metrics::TRANSPORT_STALE_OPEN_TRIPS, Metrics::COMPONENT_TRANSPORT, 0, null, 'count');
        $sample(Metrics::TRANSPORT_BOARDINGS_15M, Metrics::COMPONENT_TRANSPORT, $boardings, null, 'count');
        $sample(Metrics::TRANSPORT_REFUSALS_15M, Metrics::COMPONENT_TRANSPORT, $this->units($boardings * 0.025), null, 'count');

        return $out;
    }

    /** Office traffic at a moment, 0..1, weekends quieter. */
    private function load(CarbonImmutable $at): float
    {
        $local = $at->setTimezone(self::LOCAL_TIMEZONE);
        $hour = $local->hour + $local->minute / 60;
        $base = (int) floor($hour);
        $shape = self::HOURLY_LOAD[$base] + (self::HOURLY_LOAD[($base + 1) % 24] - self::HOURLY_LOAD[$base]) * ($hour - $base);

        return $shape * $this->dayFactor($local);
    }

    /** The cafeteria: a breakfast bump and the lunch hour that carries the day. */
    private function lunchLoad(CarbonImmutable $at): float
    {
        $local = $at->setTimezone(self::LOCAL_TIMEZONE);
        $hour = $local->hour + $local->minute / 60;

        return (0.03 + exp(-(($hour - 12.4) ** 2) / 0.98) + 0.25 * exp(-(($hour - 8.3) ** 2) / 0.5)) * $this->dayFactor($local);
    }

    /** Staff buses: the morning and evening commute. */
    private function commute(CarbonImmutable $at): float
    {
        $local = $at->setTimezone(self::LOCAL_TIMEZONE);
        $hour = $local->hour + $local->minute / 60;

        return (exp(-(($hour - 7.4) ** 2) / 1.28) + 0.85 * exp(-(($hour - 17.6) ** 2) / 0.72)) * ($local->dayOfWeekIso >= 6 ? 0.1 : 1.0);
    }

    private function dayFactor(CarbonImmutable $local): float
    {
        return match ($local->dayOfWeekIso) {
            6 => 0.35,
            7 => 0.12,
            default => 1.0,
        };
    }

    /** Hours since the last good nightly backup at a moment. */
    private function backupAge(CarbonImmutable $at): float
    {
        $run = $at->setTime(23, 0);
        if ($run->gt($at)) {
            $run = $run->subDay();
        }
        if ($run->gte($this->backupFailedSince)) {
            $run = $this->backupFailedSince->subDay();
        }

        return round(($at->getTimestamp() - $run->getTimestamp()) / 3600, 1);
    }

    // ------------------------------------------------------- releases & errors

    private function deployments(): void
    {
        foreach (self::RELEASES as $key => [, , , $notes]) {
            $at = $this->releases[$key];
            $this->at($at);
            IntelligenceDeployment::query()->create([
                'release' => $at->format('Y.m.d'),
                'commit_hash' => sha1('demo-release|'.$key),
                'environment' => (string) app()->environment(),
                'deployed_at' => $at,
                'change_reference' => 'CHG-'.$at->format('ymd'),
                'recorded_by' => self::RECORDED_BY,
                'notes' => $notes,
            ]);
        }
    }

    /** @return array<string, IntelligenceErrorFingerprint> */
    private function errorFingerprints(): array
    {
        $groups = [
            // key => [exception, raw message, route name, route group, file, line, status, occurrences, first seen, last seen, resolved]
            'feedback_attachments' => ['ErrorException', 'Undefined array key "mime" in attachment 4417', 'service-feedback.store', 'public_feedback', 'app/Services/ServiceFeedback/PublicEvidencePolicy.php', 88, 500, 312,
                $this->episodes['feedback_errors'][0]->addMinutes(1), $this->episodes['feedback_errors'][0]->addMinutes(41), $this->releases['feedback_hotfix']],
            'cafeteria_timeout' => ['Illuminate\\Database\\QueryException', 'SQLSTATE[57014]: Query canceled: 7 ERROR: canceling statement due to statement timeout (SQL: select * from "cafeteria_transactions" order by "created_at" desc limit 50 offset 0)', 'cafeteria.transactions.index', 'cafeteria', 'app/Services/Cafeteria/CafeteriaLedgerService.php', 214, 500, 186,
                $this->episodes['cafeteria_errors'][0]->addMinutes(2), $this->episodes['cafeteria_errors'][1]->subMinutes(4), null],
            'sms_timeout' => ['Illuminate\\Http\\Client\\ConnectionException', 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received', null, 'other', 'app/Services/Sms/HttpSmsGateway.php', 71, 500, 47,
                $this->episodes['sms_failures'][0]->addMinutes(3), $this->episodes['sms_failures'][1]->subMinutes(10), $this->episodes['sms_failures'][1]->addHours(2)],
            'performance_weights' => ['TypeError', 'App\\Services\\Performance\\EmployeeScoreCalculator::weighted(): Argument #2 ($weights) must be of type array, null given', 'performance.plans.show', 'performance', 'app/Services/Performance/EmployeeScoreCalculator.php', 132, 500, 23,
                $this->releases['performance']->addMinutes(26), $this->releases['performance']->addDays(2), $this->releases['performance']->addDays(2)->addHours(3)],
            'notification_attempts' => ['Illuminate\\Queue\\MaxAttemptsExceededException', 'App\\Notifications\\IntelligenceIncidentNotification has been attempted too many times.', null, 'other', 'app/Notifications/IntelligenceIncidentNotification.php', 41, 500, 18,
                $this->episodes['notifications_backlog'][0]->addMinutes(40), $this->episodes['notifications_backlog'][1], $this->episodes['notifications_backlog'][1]->addDay()],
            'activity_deadlock' => ['Illuminate\\Database\\QueryException', 'SQLSTATE[40P01]: Deadlock detected: 7 ERROR: deadlock detected (SQL: update "daily_activity_logs" set "status" = \'submitted\' where "id" = 91)', 'daily-activities.store', 'daily_activity', 'app/Services/DailyActivity/DailyActivityService.php', 377, 500, 4,
                $this->episodes['db_slow'][0]->addMinutes(50), $this->now->subDays(9)->setTime(8, 40), null],
            'mail_transport' => ['Symfony\\Component\\Mailer\\Exception\\TransportException', 'Connection could not be established with host "smtp.example.test:587"', null, 'other', 'app/Services/Communication/CommunicationBroadcaster.php', 152, 500, 31,
                $this->workday(14, 9, 10), $this->workday(10, 11, 45), $this->workday(10, 12)],
            'id_card_asset' => ['Illuminate\\Contracts\\Filesystem\\FileNotFoundException', 'File does not exist at path C:\\data\\id-cards\\templates\\back-2026.svg', 'id-cards.print', 'admin', 'app/Services/IdCards/IdCardAssetResolver.php', 63, 500, 9,
                $this->now->subDays(2)->setTime(7, 15), $this->now->subHours(5), null],
            'transfer_status' => ['ValueError', '"returned_for_correction" is not a valid backing value for enum App\\Enums\\TransferStatus', 'transfers.show', 'transfers', 'app/Services/Transfers/CanonicalTransferCreationService.php', 98, 500, 6,
                $this->workday(5, 8, 20), $this->now->subDays(1)->setTime(12, 5), null],
            'portal_dashboard' => ['Error', 'Call to a member function format() on null', 'employee.portal.dashboard', 'employee_portal', 'app/Http/Controllers/Web/EmployeePortalReviewController.php', 57, 500, 58,
                $this->releases['portal']->addMinutes(22), $this->now->subMinutes(35), null],
            'api_payload' => ['JsonException', 'Syntax error', 'api.v1.attendance.store', 'api', 'app/Services/Api/ApiEndpointCatalogService.php', 45, 500, 12,
                $this->workday(11, 13), $this->workday(4, 10, 30), null],
            'photo_directory' => ['RuntimeException', 'Unable to create a directory at C:\\data\\private\\employee-photos\\2026\\10.', 'employees.private-photo', 'admin', 'app/Services/Employees/EmployeePhotoService.php', 49, 500, 3,
                $this->workday(21, 9), $this->workday(21, 9, 40), $this->workday(20, 10)],
        ];

        $out = [];
        foreach ($groups as $key => [$class, $raw, $route, $group, $file, $line, $status, $occurrences, $first, $last, $resolved]) {
            $this->at($first);
            $out[$key] = IntelligenceErrorFingerprint::query()->create([
                'fingerprint' => sha1('demo|'.$key),
                'exception_class' => $class,
                'message_normalized' => ErrorFingerprinter::normalize($raw),
                'route_name' => $route,
                'route_group' => $group,
                'component' => $this->componentOf($file),
                'last_status' => $status,
                'last_error_id' => 'ERR-'.strtoupper(Str::random(12)),
                'sample_context' => ['file' => $file, 'line' => $line, 'frames' => [$class.'::__construct'], 'method' => $route === null ? null : 'GET', 'demo_dataset' => 'euisis-demo-v1'],
                'occurrences' => $occurrences,
                'first_seen_at' => $first,
                'last_seen_at' => $last,
                'resolved_at' => $resolved,
            ]);
        }

        return $out;
    }

    /** As ErrorFingerprinter::component(): the first two folders under app/. */
    private function componentOf(string $file): ?string
    {
        $parts = explode('/', substr($file, 4));
        array_pop($parts);

        return implode('/', array_slice($parts, 0, 2)) ?: null;
    }

    // ---------------------------------------------------------------- incidents

    /**
     * Incidents raised by the IncidentManager itself — opened, repeated,
     * escalated and correlated with a deployment as a detection run would —
     * in the order they happened, then moved by the Super Admin as the
     * incident page moves them.
     *
     * @param  array<string, IntelligenceErrorFingerprint>  $fingerprints
     * @return array<string, IntelligenceIncident>
     */
    private function incidents(IncidentManager $manager, User $admin, array $fingerprints): array
    {
        $e = fn (string $label, string|int|float|null $value): array => DetectionWindow::evidence($label, $value);
        $store = (string) config('cache.default');
        $cafeteria = $fingerprints['cafeteria_timeout'];
        $lastGoodBackup = $this->backupFailedSince->subDay();

        $httpErrors = fn (string $severity, float $rate, int $requests, int $failures): Finding => new Finding(
            rule: 'http_error_rate', component: Metrics::COMPONENT_APPLICATION, dimension: 'public_feedback', severity: $severity,
            params: ['group' => 'public_feedback', 'rate' => $rate, 'requests' => $requests, 'failures' => $failures, 'minutes' => 15],
            evidence: [$e('window', '15 min'), $e('requests', $requests), $e('failures', $failures), $e('rate_percent', $rate), $e('baseline_median_percent', 0.16), $e('baseline_samples', 2016), $e('limit_percent', 5)],
            signals: [Metrics::REQUEST_TOTAL, Metrics::REQUEST_5XX, Metrics::REQUEST_ERROR_RATE], endpoint: 'public_feedback', module: 'public_feedback',
        );
        $dbLatency = fn (string $severity, float $ping, int $long): Finding => new Finding(
            rule: 'db_latency', component: Metrics::COMPONENT_DATABASE, dimension: null, severity: $severity,
            params: ['ping' => $ping, 'baseline' => 1.3, 'long_queries' => $long],
            evidence: [$e('ping_ms', $ping), $e('baseline_median_ms', 1.3), $e('baseline_samples', 2016), $e('long_running_queries', $long), $e('limit_ms', 10)],
            signals: [Metrics::DB_PING_MS, Metrics::DB_LONG_QUERIES], module: 'database',
        );
        $cacheDown = new Finding('cache_unavailable', Metrics::COMPONENT_CACHE, null, 'critical', ['store' => $store], [$e('available', 0), $e('store', $store)], [Metrics::CACHE_AVAILABLE], module: 'cache');
        $backlog = fn (string $severity, int $pending, int $age): Finding => new Finding(
            rule: 'queue_backlog', component: Metrics::COMPONENT_QUEUE, dimension: 'notifications', severity: $severity,
            params: ['queue' => 'notifications', 'pending' => $pending, 'age_minutes' => $age],
            evidence: [$e('pending', $pending), $e('oldest_minutes', (float) $age), $e('baseline_median', 22), $e('baseline_samples', 2016), $e('limit_jobs', 500), $e('limit_age_minutes', 30)],
            signals: [Metrics::QUEUE_PENDING, Metrics::QUEUE_OLDEST_AGE_SECONDS], endpoint: 'notifications', module: 'queue:notifications',
        );
        $sms = fn (int $calls, int $failures): Finding => new Finding('integration_failure', Metrics::COMPONENT_INTEGRATIONS, 'sms_http', 'high',
            ['provider' => 'sms_http', 'calls' => $calls, 'failures' => $failures, 'minutes' => 60],
            [$e('provider', 'sms_http'), $e('calls', $calls), $e('failures', $failures), $e('window', '60 min')],
            [Metrics::INTEGRATION_CALLS, Metrics::INTEGRATION_FAILURES], endpoint: 'sms_http', module: 'integration:sms_http');
        $repeat = fn (string $severity, int $occurrences, CarbonImmutable $last): Finding => new Finding(
            rule: 'error_repeat', component: $cafeteria->component ?? Metrics::COMPONENT_APPLICATION, dimension: $cafeteria->fingerprint, severity: $severity,
            params: ['exception' => class_basename($cafeteria->exception_class), 'group' => $cafeteria->route_group, 'occurrences' => $occurrences],
            evidence: [$e('exception', $cafeteria->exception_class), $e('route_group', $cafeteria->route_group), $e('route_name', $cafeteria->route_name), $e('occurrences', $occurrences),
                $e('first_seen', CarbonImmutable::parse($cafeteria->first_seen_at)->toIso8601String()), $e('last_seen', $last->toIso8601String()), $e('last_error_id', $cafeteria->last_error_id), $e('limit_count', 10)],
            signals: ['error_fingerprints'], endpoint: $cafeteria->route_group, module: $cafeteria->component,
        );
        $scheduler = fn (string $severity, int $minutes): Finding => new Finding('scheduler_missed', Metrics::COMPONENT_SCHEDULER, null, $severity, ['minutes' => $minutes],
            [$e('last_heartbeat', $this->episodes['scheduler_gap'][0]->toIso8601String()), $e('age_minutes', $minutes)], [Metrics::SCHEDULER_HEARTBEAT], module: 'scheduler');
        $backup = fn (float $age): Finding => new Finding('backup', Metrics::COMPONENT_BACKUPS, null, 'medium', ['age_hours' => $age],
            [$e('backup_state', 'degraded'), $e('backup_age_hours', $age)], [Metrics::BACKUP_HEALTH, Metrics::BACKUP_AGE_HOURS], module: 'backups');
        $auth = fn (int $denied, int $throttled, int $mfa): Finding => new Finding('auth_failures', Metrics::COMPONENT_SECURITY, null, 'medium', ['count' => $denied + $throttled + $mfa, 'minutes' => 15],
            [$e('denied_401_403', $denied), $e('throttled_429', $throttled), $e('mfa_failures', $mfa), $e('window', '15 min')],
            [Metrics::REQUEST_401, Metrics::REQUEST_403, Metrics::REQUEST_429, Metrics::AUTH_MFA_FAILURES], module: 'security');
        $latency = fn (int $p95): Finding => new Finding(
            rule: 'http_latency', component: Metrics::COMPONENT_APPLICATION, dimension: 'cafeteria', severity: 'medium',
            params: ['group' => 'cafeteria', 'p95' => $p95, 'baseline' => 410],
            evidence: [$e('p95_ms', $p95), $e('baseline_median_ms', 410), $e('baseline_samples', 2016), $e('limit_ms', 1500)],
            signals: [Metrics::REQUEST_LATENCY_P95_MS], endpoint: 'cafeteria', module: 'cafeteria',
        );
        $failedJobs = fn (int $hourly, int $recent): Finding => new Finding(
            rule: 'failed_jobs', component: Metrics::COMPONENT_QUEUE, dimension: null, severity: 'medium',
            params: ['hourly' => $hourly, 'recent' => $recent],
            evidence: [$e('failed_last_hour', $hourly), $e('failed_last_5m', $recent), $e('baseline_median_5m', 0), $e('baseline_samples', 2016), $e('limit_per_hour', 20)],
            signals: [Metrics::QUEUE_FAILED_5M], module: 'queue',
        );

        $feedbackRelease = $this->releases['feedback_attachments']->format('Y.m.d');
        $cafeteriaStart = $this->episodes['cafeteria_errors'][0];

        // key => [detected at, findings by minutes after detection, people's steps]
        $scenarios = [
            'feedback' => [$this->episodes['feedback_errors'][0]->addMinutes(4),
                [0 => $httpErrors('high', 6.4, 1180, 76), 5 => $httpErrors('high', 7.1, 1214, 86), 10 => $httpErrors('critical', 9.8, 1236, 121)],
                [
                    [9, 'owner'],
                    [10, 'status', 'investigating', "The spike starts eighteen minutes after release {$feedbackRelease}; every failing request is the complaint form with a photo attached."],
                    [41, 'status', 'mitigated', 'Rolled back to the previous release; the complaint form accepts photos again.'],
                    [1500, 'resolution', "Release {$feedbackRelease} rejected HEIC phone photos with an unhandled ErrorException in the attachment validator. Rolled back within 40 minutes; the next-day hotfix validates HEIC and adds a regression test."],
                    [1500, 'status', 'resolved'],
                    [2900, 'verify', 'verified', 'No 5xx on public_feedback for a full day after the hotfix.'],
                    [2905, 'status', 'closed'],
                ]],
            'database' => [$this->episodes['db_slow'][0]->addMinutes(6),
                [0 => $dbLatency('medium', 12.6, 3), 5 => $dbLatency('medium', 14.8, 4), 10 => $dbLatency('high', 31.2, 6)],
                [
                    [12, 'owner'],
                    [13, 'status', 'investigating', 'pg_stat_activity shows the monthly complaints report scanning employee_service_feedback without an index on submitted_at.'],
                    [70, 'status', 'mitigated', 'Cancelled the report query and moved the report to 19:00.'],
                    [1440, 'resolution', 'Added an index on employee_service_feedback(submitted_at) and a statement timeout for report queries; database ping back to its 1–2 ms baseline.'],
                    [1440, 'status', 'resolved'],
                    [2880, 'verify', 'verified', 'Baseline held through two working days.'],
                    [2885, 'status', 'closed'],
                ]],
            'cache' => [$this->episodes['cache_down'][0]->addMinutes(2),
                [0 => $cacheDown, 5 => $cacheDown],
                [
                    [25, 'auto', 'mitigated', 'auto:condition_not_observed'],
                    [25 + 1440, 'auto', 'resolved', 'auto:no_recurrence'],
                    [25 + 1500, 'resolution', 'The cache store was unreachable for 15 minutes while the server rebooted for updates on a Sunday; it recovered without action.'],
                    [25 + 1501, 'verify', 'verified'],
                    [25 + 1502, 'status', 'closed'],
                ]],
            'notifications' => [$this->episodes['notifications_backlog'][0]->addMinutes(120),
                [0 => $backlog('medium', 410, 24), 5 => $backlog('medium', 520, 31), 10 => $backlog('high', 640, 38)],
                [
                    [15, 'owner'],
                    [16, 'status', 'investigating', 'The notifications worker stopped after the Windows update at 06:30; jobs keep arriving.'],
                    [45, 'status', 'mitigated', 'Restarted the notifications worker; the backlog drains at about 30 jobs a minute.'],
                    [1440, 'resolution', 'The worker now runs under the service supervisor with automatic restart, and the restart is on the patching checklist. The backlog drained in 25 minutes; 18 jobs exhausted their attempts and were retried by hand.'],
                    [1440, 'status', 'resolved'],
                    [1445, 'verify', 'verified'],
                    [4320, 'status', 'closed'],
                ]],
            'sms' => [$this->episodes['sms_failures'][0]->addMinutes(35),
                [0 => $sms(182, 104), 5 => $sms(196, 113)],
                [
                    [20, 'owner'],
                    [21, 'status', 'investigating', 'The SMS gateway answers HTTP 401 for every message since 09:00; e-mail and the ID registry are fine.'],
                    [140, 'status', 'mitigated', 'The provider rotated the API credentials without notice; the new key is installed.'],
                    [1440, 'resolution', 'Credential rotation by the SMS provider. The 412 messages that failed were re-sent from the outbox; the provider now announces rotations to the integration mailbox.'],
                    [1440, 'status', 'resolved'],
                ]],
            'cafeteria_errors' => [$cafeteriaStart->addMinutes(20),
                [0 => $repeat('medium', 14, $cafeteriaStart->addMinutes(19)), 5 => $repeat('high', 37, $cafeteriaStart->addMinutes(24)), 10 => $repeat('high', 52, $cafeteriaStart->addMinutes(29))],
                [
                    [10, 'owner'],
                    [11, 'status', 'investigating', 'Statement timeouts on the cafeteria transaction list since the morning release: the list lost its default 30-day date filter and scans the whole year.'],
                    [175, 'status', 'mitigated', 'Restored the default date filter by configuration; the timeouts stopped.'],
                    [180, 'note', 'Permanent fix (index on cafeteria_transactions(created_at) and a bounded export) is planned for the next release; keep the incident mitigated until then.'],
                ]],
            'scheduler' => [$this->episodes['scheduler_gap'][0]->addMinutes(20),
                [0 => $scheduler('high', 20), 45 => $scheduler('critical', 65)],
                [
                    [50, 'owner'],
                    [52, 'status', 'investigating', 'No schedule:run since 01:00; the Windows Task Scheduler entry is disabled.'],
                    [110, 'status', 'mitigated', 'Re-enabled the task; heartbeats resumed.'],
                    [180, 'resolution', 'An OS update disabled the scheduled task that runs schedule:run. Re-enabled, and the task state is now on the post-update checklist. Missed: telemetry and two alert evaluations; nothing user-facing.'],
                    [180, 'status', 'resolved'],
                    [185, 'verify', 'verified'],
                    [1440, 'status', 'closed'],
                ]],
            'backup' => [$lastGoodBackup->addHours(26)->addMinutes(5),
                [0 => $backup(26.1), 60 => $backup(27.1), 1440 => $backup(50.1)],
                []],
            'auth' => [$this->episodes['auth_attack'][0]->addMinutes(15),
                [0 => $auth(1210, 560, 70), 5 => $auth(1460, 680, 70), 10 => $auth(1580, 735, 75)],
                [
                    [25, 'owner'],
                    [26, 'status', 'investigating', 'Credential-stuffing pattern on /login from a narrow address range; the login throttle is holding and no account was opened.'],
                    [120, 'status', 'mitigated', 'Blocked the address range at the firewall; denied requests are back to normal.'],
                    [125, 'note', 'Keep it open for 48 hours in case the source moves, then close.'],
                ]],
            'cafeteria_latency' => [$this->episodes['cafeteria_latency'][0]->addMinutes(10),
                [0 => $latency(1880), 5 => $latency(2240), 10 => $latency(2380), 15 => $latency(2190), 20 => $latency(1960), 60 => $latency(1710)],
                [
                    [30, 'owner'],
                    [31, 'status', 'investigating', 'Lunch-hour peak at the main cafeteria: meal scans wait on the subsidy ledger. Measuring lock waits during the next lunch before changing anything.'],
                ]],
            'failed_jobs' => [$this->episodes['failed_jobs'][0]->addMinutes(25),
                [0 => $failedJobs(22, 4), 5 => $failedJobs(27, 5), 10 => $failedJobs(31, 4)],
                []],
        ];
        uasort($scenarios, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $raised = [];
        foreach ($scenarios as $key => [$detected, $findings, $steps]) {
            foreach ($findings as $minutes => $finding) {
                $at = $detected->addMinutes($minutes);
                $this->at($at);
                $manager->apply([$finding], [], $at);
            }
            $incident = IntelligenceIncident::query()->where('fingerprint', reset($findings)->fingerprint())->orderByDesc('detected_at')->firstOrFail();
            $this->handle($manager, $incident, $admin, $steps);
            $raised[$key] = $incident->refresh();
        }
        $fingerprints['feedback_attachments']->forceFill(['incident_id' => $raised['feedback']->id])->save();

        return $raised;
    }

    /** @param  list<array{0: int, 1: string, 2?: ?string, 3?: ?string}>  $steps  [minutes after detection, action, value, note] */
    private function handle(IncidentManager $manager, IntelligenceIncident $incident, User $admin, array $steps): void
    {
        $detected = CarbonImmutable::parse($incident->detected_at);
        foreach ($steps as $step) {
            [$minutes, $action, $value, $note] = $step + [2 => null, 3 => null];
            $at = $detected->addMinutes($minutes);
            $this->at($at);
            $incident->refresh();

            match ($action) {
                'owner' => $this->own($manager, $incident, $admin, $at),
                'status' => $manager->transition($incident, (string) $value, $admin, self::STATUS_EVENTS[$value] ?? 'status_changed', $at, $note),
                // What the engine does when the condition stops (IncidentManager::apply()).
                'auto' => $this->automatic($manager, $incident, (string) $value, $at, $note),
                'resolution' => $incident->forceFill(['resolution_summary' => $value])->save(),
                'verify' => $this->verify($manager, $incident, $admin, (string) $value, $at, $note),
                'note' => $manager->event($incident, 'note', null, null, $admin, $at, $note),
            };
        }
    }

    private function own(IncidentManager $manager, IntelligenceIncident $incident, User $admin, CarbonImmutable $at): void
    {
        $incident->forceFill(['owner_user_id' => $admin->id])->save();
        $manager->event($incident, 'owner_changed', null, (string) $admin->id, $admin, $at);
    }

    private function automatic(IncidentManager $manager, IntelligenceIncident $incident, string $to, CarbonImmutable $at, ?string $note): void
    {
        if ($to === 'resolved') {
            $incident->forceFill(['verification_status' => 'pending'])->save();
        }
        $manager->transition($incident, $to, null, $to, $at, $note);
    }

    private function verify(IncidentManager $manager, IntelligenceIncident $incident, User $admin, string $status, CarbonImmutable $at, ?string $note): void
    {
        $incident->forceFill(['verification_status' => $status])->save();
        $manager->event($incident, 'verified', null, $status, $admin, $at, $note);
    }

    // ------------------------------------------------------------- AI & reviews

    /**
     * Root-cause runs recorded as the RootCauseAssistant records them, from a
     * `demo` provider so nobody mistakes them for a model's answer.
     *
     * @param  array<string, IntelligenceIncident>  $raised
     */
    private function rootCauseRuns(IncidentManager $manager, array $raised, User $admin): void
    {
        $prompt = PromptRegistry::get('incident_root_cause');
        $feedbackRelease = $this->releases['feedback_attachments']->format('Y.m.d');
        $cafeteriaRelease = $this->releases['cafeteria']->format('Y.m.d');

        $assessments = [
            'feedback' => [
                'observed_symptom' => "5xx responses on public_feedback rose from a 0.16% baseline to 9.8% within 25 minutes of release {$feedbackRelease}; every failing request posts the complaint form with an attachment.",
                'likely_causes' => [
                    ['cause' => 'The new attachment validator throws on image types it does not recognise (HEIC photos from phones).', 'confidence' => 'high', 'why' => 'Failures begin 18 minutes after the deployment, are limited to posts with attachments and share one ErrorException fingerprint.'],
                    ['cause' => 'Attachment storage became unwritable during the deployment.', 'confidence' => 'low', 'why' => 'storage.writable stayed 1 and other uploads succeeded in the same window.'],
                ],
                'alternative_explanations' => ['A client-side change that sends a different multipart field name.'],
                'recommended_next_checks' => [
                    "Compare the failing requests' attachment types with the validator's allow-list.",
                    'Roll back to the previous release and confirm the 5xx rate returns to baseline.',
                    'Add a regression test that posts a HEIC photo to the complaint form.',
                ],
                'suggested_areas_to_inspect' => ['app/Services/ServiceFeedback/PublicEvidencePolicy.php', 'public_feedback 5xx by status code'],
                'overall_confidence' => 'high',
                'caveats' => 'Based on aggregated signals and one redacted error fingerprint; request bodies were not inspected.',
            ],
            'cafeteria_errors' => [
                'observed_symptom' => "Statement timeouts (QueryException) on the cafeteria transaction list, 52 in 15 minutes, starting 35 minutes after release {$cafeteriaRelease}.",
                'likely_causes' => [
                    ['cause' => 'The release removed the default date bound, so the list scans a full year of cafeteria_transactions.', 'confidence' => 'medium', 'why' => 'The SQL in the fingerprint has no date predicate and the errors start right after the release.'],
                    ['cause' => 'Lock contention with the subsidy ledger posting.', 'confidence' => 'low', 'why' => 'db.waiting_locks rose only briefly and other cafeteria pages stayed fast.'],
                ],
                'alternative_explanations' => ['Table growth crossing the statement timeout on its own.'],
                'recommended_next_checks' => [
                    'Run EXPLAIN on the transaction list query with and without a date bound.',
                    'Check whether cafeteria_transactions(created_at) is indexed.',
                    'Confirm the export endpoint applies the same bound.',
                ],
                'suggested_areas_to_inspect' => ['app/Services/Cafeteria/CafeteriaLedgerService.php', 'cafeteria_transactions indexes'],
                'overall_confidence' => 'medium',
                'caveats' => 'Query plans were not available to the assistant.',
            ],
            'cafeteria_latency' => [
                'observed_symptom' => 'p95 latency on the cafeteria route group reached 2,380 ms at lunch (baseline 410 ms) with no rise in 5xx.',
                'likely_causes' => [
                    ['cause' => 'Meal scans wait on a lock in the daily subsidy ledger while the noon posting runs.', 'confidence' => 'medium', 'why' => 'Latency rises only in the lunch window, with lock waits above zero and no errors.'],
                    ['cause' => 'Database connection pool saturation at peak.', 'confidence' => 'low', 'why' => 'db.connections_percent stayed well below the limit.'],
                ],
                'alternative_explanations' => ['Slow scanner network at the main cafeteria.'],
                'recommended_next_checks' => [
                    'Measure lock waits on the ledger table during the next lunch window.',
                    'Check when the subsidy posting is scheduled.',
                    'Compare p95 for scan requests with list requests.',
                ],
                'suggested_areas_to_inspect' => ['cafeteria latency by endpoint', 'subsidy ledger posting schedule'],
                'overall_confidence' => 'medium',
                'caveats' => 'One lunch window of evidence; nothing has been changed yet.',
            ],
        ];

        foreach ($assessments as $key => $assessment) {
            $incident = $raised[$key];
            $at = CarbonImmutable::parse($incident->detected_at)->addMinutes(14);
            $this->at($at);
            $run = $this->aiRun($prompt, 'ok', $incident, $admin, $at, $assessment);
            $top = $assessment['likely_causes'][0];
            $incident->forceFill([
                'probable_cause' => $assessment['observed_symptom'].' — '.$top['cause'],
                'cause_confidence' => $assessment['overall_confidence'],
                'recommendation' => ['ai_assessment' => $assessment, 'prompt_version' => $prompt['version'], 'model' => $run->model, 'generated_at' => $at->toIso8601String()],
                'ai_run_id' => $run->id,
            ])->save();
            foreach ($assessment['recommended_next_checks'] as $check) {
                IntelligenceRecommendation::query()->create([
                    'source_type' => 'incident', 'source_id' => $incident->reference, 'incident_id' => $incident->id, 'area' => 'incidents',
                    'category' => 'verification', 'risk' => 'safe_check', 'title' => Str::limit($check, 180), 'body' => $check,
                    'evidence' => ['from' => 'ai_assessment', 'overall_confidence' => $assessment['overall_confidence']],
                    'confidence' => $assessment['overall_confidence'], 'generated_by' => 'ai', 'prompt_version' => $prompt['version'],
                    'ai_run_id' => $run->id, 'generated_at' => $at, 'review_status' => 'pending',
                ]);
            }
            $manager->event($incident, 'ai_explained', null, $assessment['overall_confidence'], $admin, $at, null, ['run_id' => $run->id, 'model' => $run->model, 'prompt_version' => $prompt['version']]);
        }

        // Runs that did not produce an assessment: the page falls back to the rule recommendations.
        foreach (['sms' => ['invalid', 'schema: likely_causes[0].confidence is not one of high, medium, low'], 'failed_jobs' => ['unavailable', null]] as $key => [$status, $error]) {
            $incident = $raised[$key];
            $at = CarbonImmutable::parse($incident->detected_at)->addMinutes(12);
            $this->at($at);
            $run = $this->aiRun($prompt, $status, $incident, $admin, $at, null, $error);
            $manager->event($incident, 'ai_explain_failed', null, $status, $admin, $at, $error, ['run_id' => $run->id]);
        }
    }

    /** @param  array<string, mixed>  $prompt */
    private function aiRun(array $prompt, string $status, IntelligenceIncident $incident, User $admin, CarbonImmutable $at, ?array $output, ?string $error = null): IntelligenceAiRun
    {
        $chars = mt_rand(1800, 3400);

        return IntelligenceAiRun::query()->create([
            'feature' => $prompt['feature'],
            'prompt_key' => 'incident_root_cause',
            'prompt_version' => $prompt['version'],
            'provider' => self::AI_PROVIDER,
            'model' => $status === 'unavailable' ? null : 'demo-fixture',
            'status' => $status,
            'triggered_by_user_id' => $admin->id,
            'subject_type' => 'intelligence_incident',
            'subject_id' => $incident->id,
            'input_summary' => ['data_classes' => $prompt['data_classes'], 'chars' => $chars, 'variables' => [], 'locale' => 'en'],
            'output' => $output,
            'error' => $error,
            'input_chars' => $chars,
            'output_tokens' => $output === null ? null : mt_rand(420, 760),
            'duration_ms' => $status === 'unavailable' ? 0 : mt_rand(3200, 9400),
            'created_at' => $at,
        ]);
    }

    /** People's verdicts on the recommendations of incidents that are over; the active ones stay pending. */
    private function reviewRecommendations(User $admin): void
    {
        $verdicts = ['accepted' => 5, 'useful' => 4, 'not_useful' => 1, 'rejected' => 1];
        $notes = [
            'accepted' => 'Done as part of the fix.',
            'useful' => 'Helped narrow it down.',
            'not_useful' => 'Already covered by the runbook.',
            'rejected' => 'Too risky for the size of the problem.',
            'incorrect_diagnosis' => 'The cause turned out to be different.',
        ];
        $closed = IntelligenceIncident::query()->whereNotIn('status', IntelligenceIncident::ACTIVE_STATUSES)->pluck('id');

        foreach (IntelligenceRecommendation::query()->whereIn('incident_id', $closed)->orderBy('generated_at')->get() as $recommendation) {
            if ($this->chance(0.12)) {
                continue;
            }
            $verdict = $recommendation->generated_by === 'ai' && $this->chance(0.2) ? 'incorrect_diagnosis' : $this->pick($verdicts);
            $at = CarbonImmutable::parse($recommendation->generated_at)->addMinutes(mt_rand(90, 4000));
            if ($at->gt($this->now)) {
                continue;
            }
            $this->at($at);
            $recommendation->forceFill([
                'review_status' => $verdict, 'reviewed_by' => $admin->id, 'reviewed_at' => $at, 'review_note' => $notes[$verdict],
                'linked_action_reference' => $verdict === 'accepted' ? 'CHG-'.$at->format('ymd') : null,
            ])->save();
        }

        // On the mitigated cafeteria incident the AI's checks were read: one taken up, one judged useful.
        $ai = IntelligenceRecommendation::query()->where('generated_by', 'ai')->whereNotIn('incident_id', $closed)->orderBy('generated_at')->get();
        foreach ($ai->take(2)->values() as $i => $recommendation) {
            $at = CarbonImmutable::parse($recommendation->generated_at)->addMinutes(40);
            $this->at($at);
            $recommendation->forceFill(['review_status' => $i === 0 ? 'accepted' : 'useful', 'reviewed_by' => $admin->id, 'reviewed_at' => $at, 'review_note' => $notes[$i === 0 ? 'accepted' : 'useful']])->save();
        }
    }

    /** The alerts incidents that are over raised in their time: acknowledged by a person, resolved when the incident was. */
    private function alertHistory(User $admin): void
    {
        foreach (IntelligenceIncident::query()->whereNotIn('status', IntelligenceIncident::ACTIVE_STATUSES)->orderBy('detected_at')->get() as $incident) {
            $detected = CarbonImmutable::parse($incident->detected_at);
            $resolved = CarbonImmutable::parse($incident->resolved_at ?? $incident->closed_at ?? $incident->last_seen_at);
            $this->at($detected);
            $alert = new IntelligenceAlert;
            $alert->forceFill([
                'type' => 'incident', 'domain' => 'technical', 'source_key' => 'incident:'.$incident->fingerprint, 'severity' => $incident->severity,
                'status' => 'resolved', 'title' => $incident->title, 'params' => $incident->evidence['params'] ?? [], 'evidence' => $incident->evidence['rows'] ?? [],
                'recommendation' => null, 'href' => route('intelligence.incidents.show', $incident->id), 'organization_id' => null, 'area' => 'incidents',
                'occurrences' => $incident->occurrences, 'clear_runs' => 0,
                'first_detected_at' => $detected, 'last_detected_at' => $incident->last_seen_at,
                'acknowledged_by' => $incident->owner_user_id !== null ? $admin->id : null, 'acknowledged_at' => $incident->owner_user_id !== null ? $detected->addMinutes(12) : null,
                'resolved_at' => $resolved, 'cooldown_until' => $resolved->addMinutes(AlertEngine::COOLDOWN_MINUTES), 'last_notified_at' => $detected,
            ])->save();
        }
    }

    /** Acknowledges the active technical alerts someone is already working on, as the alerts page does. */
    private function acknowledgeAlerts(User $admin): void
    {
        $owned = IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->whereNotNull('owner_user_id')->pluck('fingerprint')
            ->map(fn (string $fingerprint): string => 'incident:'.$fingerprint);
        IntelligenceAlert::query()->where('status', 'open')->whereIn('source_key', $owned)
            ->update(['status' => 'acknowledged', 'acknowledged_by' => $admin->id, 'acknowledged_at' => now(), 'updated_at' => now()]);
    }

    /** Questions answered as the Knowledge Assistant answers without a model: the retriever's passages, cited. */
    private function knowledgeQuestions(KnowledgeRetriever $retriever, User $admin): int
    {
        $written = 0;
        foreach (self::QUESTIONS as [, $question, $daysAgo]) {
            $at = $this->workday($daysAgo, mt_rand(6, 13), mt_rand(0, 59));
            $chunks = $retriever->search($admin, $question, 6);
            $top = array_slice($chunks, 0, 3);
            $this->at($at);
            IntelligenceKnowledgeQuestion::query()->create([
                'user_id' => $admin->id,
                'question' => $question,
                'answer' => null,
                'grounded' => $top !== [],
                'citations' => array_map(fn (array $c, int $i): array => ['n' => $i + 1, 'document_id' => $c['document_id'], 'title' => $c['title'], 'chunk_id' => $c['chunk_id'], 'position' => $c['position'], 'heading' => $c['heading']], $top, array_keys($top)),
                'ai_run_id' => null,
                'mode' => 'extractive',
                'created_at' => $at,
            ]);
            $written++;
        }

        return $written;
    }

    // ------------------------------------------------------ public submissions

    /**
     * A year of public submissions on the existing organizations and
     * employees: growing volume, more complaints lately and a complaint spike
     * in the last nine days, each with the timestamps its status implies.
     */
    private function submissions(User $admin): int
    {
        $organizations = DB::table('organizations')->whereNull('deleted_at')->get(['id', 'code', 'name_en']);
        if ($organizations->isEmpty()) {
            $this->command?->warn('No organizations: public submissions SKIPPED.');

            return 0;
        }
        $orgWeights = $organizations->mapWithKeys(fn (object $o): array => [$o->id => mt_rand(1, 6)])->all();
        $organizationsById = $organizations->keyBy('id');
        $assignments = DB::table('employee_assignments')->where('is_current', true)->whereNull('effective_to')->whereNotNull('position_id')
            ->get(['id', 'employee_id', 'organization_id', 'organization_unit_id', 'position_id']);
        $units = DB::table('organization_units')->whereNull('deleted_at')->get(['id', 'organization_id', 'name_en', 'name_am', 'unit_type'])->groupBy('organization_id');
        $qrCodes = DB::table('organization_feedback_qr_codes')->where('status', 'active')->pluck('id', 'organization_id');
        $services = DB::table('position_services')->whereNull('deleted_at')->where('is_active', true)->get(['id', 'organization_id', 'service_no', 'name_en'])->groupBy('organization_id');

        $rows = [];
        $n = 0;
        $monthStart = $this->now->startOfMonth()->subMonths(11);
        for ($m = 0; $m < 12; $m++, $monthStart = $monthStart->addMonthNoOverflow()) {
            $monthEnd = $m === 11 ? $this->now : $monthStart->endOfMonth();
            $covered = ($monthEnd->getTimestamp() - $monthStart->getTimestamp()) / ($monthStart->endOfMonth()->getTimestamp() - $monthStart->getTimestamp());
            $volume = (int) round((52 + 4 * $m) * $this->jitter(0.1) * $covered);
            $complaintShare = 0.28 + 0.012 * $m;
            for ($i = 0; $i < $volume; $i++) {
                $type = $this->pick(['complaint' => $complaintShare, 'feedback' => 0.5 - $complaintShare / 2, 'suggestion' => 0.5 - $complaintShare / 2]);
                $rows[] = $this->submission(++$n, $type, $this->officeMoment($monthStart, $monthEnd), $admin, $orgWeights, $organizationsById, $assignments, $units, $qrCodes, $services);
            }
        }
        // The spike Public Voice should notice: a burst of complaints in the last nine days.
        for ($i = 0; $i < 55; $i++) {
            $rows[] = $this->submission(++$n, 'complaint', $this->officeMoment($this->now->subDays(9), $this->now), $admin, $orgWeights, $organizationsById, $assignments, $units, $qrCodes, $services);
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('employee_service_feedback')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * @param  array<string, int>  $orgWeights
     * @param  Collection<string, object>  $organizations
     * @param  Collection<int, object>  $assignments
     * @param  Collection<string, Collection<int, object>>  $units
     * @param  Collection<string, string>  $qrCodes
     * @param  Collection<string, Collection<int, object>>  $services
     * @return array<string, mixed>
     */
    private function submission(int $n, string $type, CarbonImmutable $submitted, User $admin, array $orgWeights, Collection $organizations, Collection $assignments,
        Collection $units, Collection $qrCodes, Collection $services): array
    {
        $life = $this->lifecycle($type, $submitted);
        $assignment = $assignments->isNotEmpty() && $this->chance(0.45) ? $assignments->random() : null;
        $organizationId = $assignment->organization_id ?? $this->pick($orgWeights);
        $organization = $organizations->get($organizationId);
        $unit = $assignment === null && $this->chance(0.4) ? ($units->get($organizationId)?->random()) : null;
        $service = $this->chance(0.5) ? ($services->get($organizationId)?->random()) : null;
        $channel = $this->pick(['WEB_QR' => 82, 'PHONE' => 9, 'WRITTEN' => 6, 'ORAL' => 3]);
        $comments = self::COMMENTS[$type];
        $touched = array_filter([$submitted, $life['acknowledged_at'], $life['assigned_at'], $life['reviewed_at'], $life['resolved_at'], $life['closed_at']]);

        return [
            'id' => (string) Str::uuid7(),
            'employee_id' => $assignment?->employee_id,
            'employee_assignment_id' => $assignment?->id,
            'organization_id' => $organizationId,
            'organization_unit_id' => $assignment->organization_unit_id ?? $unit?->id,
            'position_id' => $assignment?->position_id,
            'organization_target_id' => $assignment === null ? $organizationId : null,
            'source_organization_qr_id' => $assignment === null ? $qrCodes->get($organizationId) : null,
            'target_type' => $assignment === null ? 'organization' : 'employee',
            'source_type' => $assignment === null ? 'organization_feedback_qr' : 'employee_card_qr',
            'target_name_snapshot' => $assignment === null ? $organization?->name_en : null,
            'target_code_snapshot' => $assignment === null ? $organization?->code : null,
            'organization_unit_name_snapshot' => $unit?->name_en,
            'organization_unit_name_am_snapshot' => $unit?->name_am,
            'organization_unit_type_snapshot' => $unit?->unit_type,
            'position_service_id' => $service?->id,
            'service_no_snapshot' => $service?->service_no,
            'service_name_snapshot' => $service?->name_en,
            'type' => $type,
            'subject' => $type === 'complaint' ? self::COMPLAINT_SUBJECTS[array_rand(self::COMPLAINT_SUBJECTS)] : null,
            'comment' => $type === 'feedback' && $this->chance(0.25) ? null : $comments[array_rand($comments)],
            'rating' => match ($type) {
                'feedback' => (int) $this->pick([5 => 35, 4 => 35, 3 => 15, 2 => 10, 1 => 5]),
                'complaint' => $this->chance(0.35) ? mt_rand(1, 2) : null,
                default => null,
            },
            'reference_number' => self::REFERENCE_PREFIX.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'submitter_identity_mode' => 'optional',
            'intake_channel' => $channel,
            'user_agent' => 'EUISIS demo seeder',
            'status' => $life['status'],
            'submitted_at' => $submitted,
            'acknowledged_at' => $life['acknowledged_at'],
            'assigned_at' => $life['assigned_at'],
            'reviewed_at' => $life['reviewed_at'],
            'reviewed_by' => $life['reviewed_at'] !== null ? $admin->id : null,
            'resolved_at' => $life['resolved_at'],
            'closed_at' => $life['closed_at'],
            'service_date' => $submitted->toDateString(),
            'created_at' => $submitted,
            'updated_at' => max($touched),
        ];
    }

    /**
     * The status a submission of this type and age would have reached, with
     * the moments that status implies (never in the future).
     *
     * @return array{status: string, acknowledged_at: ?CarbonImmutable, assigned_at: ?CarbonImmutable, reviewed_at: ?CarbonImmutable, resolved_at: ?CarbonImmutable, closed_at: ?CarbonImmutable}
     */
    private function lifecycle(string $type, CarbonImmutable $submitted): array
    {
        $age = ($this->now->getTimestamp() - $submitted->getTimestamp()) / 86400;
        $later = fn (?CarbonImmutable $from, int $minMinutes, int $maxMinutes): ?CarbonImmutable => $from === null ? null
            : (($t = $from->addMinutes(mt_rand($minMinutes, $maxMinutes)))->lt($this->now) ? $t : null);
        $life = ['status' => 'pending', 'acknowledged_at' => null, 'assigned_at' => null, 'reviewed_at' => null, 'resolved_at' => null, 'closed_at' => null];

        if ($type === 'feedback') {
            $reviewed = $age < 5 && $this->chance(0.7) ? null : $later($submitted, 120, 5760);

            return $reviewed === null ? $life : ['status' => 'reviewed', 'reviewed_at' => $reviewed] + $life;
        }

        $target = $type === 'suggestion'
            ? ($age < 7 ? $this->pick(['unrouted' => 1, 'under_review' => 1]) : $this->pick(['accepted' => 35, 'not_adopted' => 25, 'under_review' => 10, 'closed' => 30]))
            : ($age < 3 ? $this->pick(['unrouted' => 4, 'assigned' => 6]) : ($age < 20 ? $this->pick(['assigned' => 25, 'under_review' => 30, 'resolved' => 45]) : $this->pick(['under_review' => 5, 'resolved' => 25, 'closed' => 70])));
        if ($target === 'unrouted') {
            return ['status' => 'unrouted'] + $life;
        }

        $life['acknowledged_at'] = $later($submitted, 60, 1440);
        $life['assigned_at'] = $later($life['acknowledged_at'], 60, 1440);
        $reached = $life['assigned_at'] === null ? 'unrouted' : 'assigned';
        if ($type === 'suggestion' && $reached === 'assigned') {
            $life['reviewed_at'] = $later($life['assigned_at'], 1440, 14400);
            $reached = $target === 'under_review' || $life['reviewed_at'] === null ? 'under_review' : $target;
            if ($reached === 'closed') {
                $life['closed_at'] = $later($life['reviewed_at'], 1440, 7200);
                $reached = $life['closed_at'] === null ? 'accepted' : 'closed';
            }
        } elseif ($reached === 'assigned' && $target !== 'assigned') {
            $life['reviewed_at'] = $later($life['assigned_at'], 240, 2880);
            $reached = $life['reviewed_at'] === null ? 'assigned' : 'under_review';
            if ($reached === 'under_review' && in_array($target, ['resolved', 'closed'], true)) {
                $life['resolved_at'] = $later($life['reviewed_at'], 1440, 21600);
                $reached = $life['resolved_at'] === null ? 'under_review' : 'resolved';
                if ($reached === 'resolved' && $target === 'closed') {
                    $life['closed_at'] = $later($life['resolved_at'], 1440, 7200);
                    $reached = $life['closed_at'] === null ? 'resolved' : 'closed';
                }
            }
        }
        $life['status'] = $reached;

        return $life;
    }

    /** A working-hours moment (Addis Ababa) between two instants. */
    private function officeMoment(CarbonImmutable $from, CarbonImmutable $to): CarbonImmutable
    {
        for ($try = 0; $try < 20; $try++) {
            $day = $from->addSeconds(mt_rand(0, max(0, $to->getTimestamp() - $from->getTimestamp())))->setTimezone(self::LOCAL_TIMEZONE);
            if ($day->dayOfWeekIso === 7 || ($day->dayOfWeekIso === 6 && ! $this->chance(0.3))) {
                continue;
            }
            $at = $day->setTime(8, 30)->addMinutes(mt_rand(0, $day->dayOfWeekIso === 6 ? 210 : 540))->utc();
            if ($at->gte($from) && $at->lt($to)) {
                return $at;
            }
        }

        return $to->subMinutes(mt_rand(30, 600));
    }

    // ---------------------------------------------------------------- forecasts

    /**
     * What the monthly snapshot would have said: the real Forecaster on the
     * complaint history known at the start of each of the last six months,
     * with the actual that arrived since and the error.
     */
    private function forecastBacktests(): int
    {
        $from = $this->now->startOfMonth()->subMonths(12);
        $counted = DB::table('employee_service_feedback')->where('type', 'complaint')->where('status', '!=', 'hidden')
            ->where('submitted_at', '>=', $from->toDateTimeString())
            ->selectRaw(SqlDateBucket::expression('submitted_at', SqlDateBucket::MONTH).' AS bucket, COUNT(*) AS total')
            ->groupBy('bucket')->pluck('total', 'bucket');
        $series = [];
        for ($month = $from; $month->lt($this->now->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $series[$month->format('Y-m')] = 0;
        }
        foreach ($counted as $bucket => $total) {
            $key = CarbonImmutable::parse((string) $bucket)->format('Y-m');
            if (array_key_exists($key, $series)) {
                $series[$key] += (int) $total;
            }
        }
        // Leading empty months are before the first record, not zero complaints.
        while ($series !== [] && reset($series) === 0) {
            array_shift($series);
        }
        $periods = array_keys($series);
        $values = array_values($series);

        $written = 0;
        for ($k = Forecaster::MIN_POINTS; $k < count($values); $k++) {
            $result = Forecaster::linear(array_slice($values, 0, $k), ForecastSnapshotService::HORIZON, $periods[$k - 1]);
            if (($result['insufficient'] ?? false) || ! isset($result['forecast'])) {
                continue;
            }
            $generated = CarbonImmutable::parse($periods[$k].'-01')->setTime(4, 10);
            $snapshot = (string) Str::uuid7();
            foreach ($result['forecast'] as $point) {
                $index = array_search($point['period'], $periods, true);
                $actual = $index === false ? null : $values[$index];
                IntelligenceForecast::query()->create([
                    'snapshot_id' => $snapshot,
                    'metric' => 'complaints_monthly',
                    'scope_key' => 'all',
                    'period' => $point['period'],
                    'value' => $point['value'],
                    'lower' => $point['lower'],
                    'upper' => $point['upper'],
                    'method' => $result['method'] ?? Forecaster::METHOD,
                    'model_version' => ForecastSnapshotService::MODEL_VERSION,
                    'confidence' => $result['confidence'] ?? null,
                    'points' => $result['points'],
                    'r2' => $result['r2'] ?? null,
                    'assumptions' => ['window_months' => $result['points'], 'data_through' => $periods[$k - 1], 'series_start' => $periods[0]],
                    'limitations' => $result['limitations'] ?? [],
                    'data_through' => $periods[$k - 1],
                    'generated_at' => $generated,
                    'actual' => $actual,
                    'abs_error' => $actual === null ? null : round(abs($actual - $point['value']), 4),
                    'pct_error' => $actual === null || $actual === 0 ? null : round(100 * abs($actual - $point['value']) / $actual, 2),
                    'evaluated_at' => $actual === null ? null : CarbonImmutable::parse($point['period'].'-01')->addMonthNoOverflow()->setTime(4, 10),
                    'created_at' => $generated,
                ]);
                $written++;
            }
        }

        return $written;
    }

    // ------------------------------------------------------------------ helpers

    /** Moves the clock the models and services read; null puts it back. */
    private function at(?CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function jitter(float $spread): float
    {
        return 1 + (mt_rand() / mt_getrandmax() * 2 - 1) * $spread;
    }

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    /** A whole number whose average is `$x`. */
    private function units(float $x): int
    {
        if ($x <= 0) {
            return 0;
        }
        $whole = (int) floor($x);

        return $whole + ($this->chance($x - $whole) ? 1 : 0);
    }

    /**
     * @template T of array-key
     *
     * @param  array<T, int|float>  $weights
     * @return T
     */
    private function pick(array $weights): int|string
    {
        $roll = mt_rand() / mt_getrandmax() * array_sum($weights);
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
