<?php

declare(strict_types=1);

/*
 * Addis One Intelligence — the shared dictionary of its pages
 * (App\Services\Intelligence\IntelligenceLabels). Area-specific panels,
 * KPIs and insights live in intelligence_business.php and
 * intelligence_system.php. Wording rules: a figure is what was MEASURED or
 * RECEIVED, never a finding; an AI statement is advisory and says how sure
 * it is; nothing is called a ranking of people.
 */
return [
    'title' => 'Addis One Intelligence',

    'ui' => [
        'insights' => 'Insights',
        'no_insights' => 'Nothing abnormal in this view for the selected period and scope.',
        'current' => 'Now',
        'baseline' => 'Baseline',
        'change' => 'Change',
        'recommendation' => 'Recommended next step',
        'view_evidence' => 'View evidence',
        'open_detail' => 'Open detail',
        'source' => 'Source',
        'confidence' => 'Confidence',
        'generated_at' => 'Generated',
        'data_through' => 'Data through',
    ],

    'severity' => [
        'critical' => 'Critical',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
        'info' => 'Information',
    ],

    'confidence' => [
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
        'high_help' => 'Several independent signals agree and the data is complete for the window.',
        'medium_help' => 'The signals agree but the window is short or one source is missing.',
        'low_help' => 'A single signal or a short window: treat as a hypothesis to check.',
    ],

    'health' => [
        'healthy' => 'Healthy',
        'degraded' => 'Degraded',
        'unhealthy' => 'Unhealthy',
        'unknown' => 'Unknown',
    ],

    'reasons' => [
        'TELEMETRY_OFF' => ['title' => 'Telemetry is off', 'description' => 'Platform telemetry is switched off in System Settings › Intelligence, so nothing is collected for this figure.'],
        'UNKNOWN' => ['title' => 'Not measured', 'description' => 'This installation does not expose this signal, so its state is unknown rather than assumed healthy.'],
        'NOT_CONFIGURED' => ['title' => 'Not configured', 'description' => 'Set a limit for this rule in System Settings › Intelligence. Until then the rule relies on the baseline only.'],
        'SLO_CONFIGURATION_REQUIRED' => ['title' => 'No objective configured', 'description' => 'No latency, error-rate or availability objective is defined for this installation, so compliance is not shown.'],
        'FORECAST_DATA_INSUFFICIENT' => ['title' => 'Not enough history to forecast', 'description' => 'A forecast needs at least six complete monthly points; this series has fewer, so none is shown.'],
        'NEEDS_DEPLOYMENT_CONFIGURATION' => ['title' => 'No deployment recorded', 'description' => 'Releases are recorded with `php artisan intelligence:record-deployment` from the release script. None has been recorded, so nothing is correlated and nothing is guessed.'],
        'AI_UNAVAILABLE' => ['title' => 'AI explanation unavailable', 'description' => 'The AI provider is off or did not answer in time. The figures stand on their own.'],
    ],

    'sources' => [
        'telemetry' => 'Platform telemetry (intelligence_signals)',
        'request_counters' => 'HTTP request counters',
        'error_fingerprints' => 'Error fingerprints',
        'queue' => 'Queue tables',
        'audit_log' => 'Audit log',
        'backups' => 'Backup status',
        'integrations' => 'Integration usage records',
        'deployments' => 'Recorded deployments',
        'situation_room' => 'Situation Room aggregates',
        'workforce' => 'Positions, establishments and assignments',
        'public_submissions' => 'Public submissions',
        'epms' => 'Performance management (EPMS)',
        'daily_activity' => 'Daily Activity Register',
        'data_quality' => 'Data quality checks',
        'forecasts' => 'Forecast snapshots',
    ],

    'flash' => [
        'alert_updated' => 'Alert updated.',
        'incident_updated' => 'Incident updated.',
        'recommendation_reviewed' => 'Review recorded.',
        'explain_requested' => 'AI assessment requested; it appears on this page once the background queue has run it.',
        'ai_unavailable' => 'AI explanations are not available right now (disabled, not configured, or paused after failures).',
    ],

    'notifications' => [
        'open_alerts' => 'Open alerts',
        'alert' => ['title' => 'New :severity business alert', 'message' => 'A city-wide alert was opened in :area. Review its evidence and recommendation.'],
        'open' => 'Open incident',
        'opened' => ['title' => 'Incident :reference opened (:severity)', 'message' => 'A technical incident was detected on :component. Review its evidence and recommendations.'],
        'escalated' => ['title' => 'Incident :reference escalated to :severity', 'message' => 'The condition on :component worsened since detection.'],
    ],

    'data_classes' => ['aggregates' => 'Aggregates', 'structured_signals' => 'Structured signals', 'redacted_logs' => 'Redacted error signatures', 'knowledge_documents' => 'Knowledge documents', 'public_content' => 'Published public content'],
    'sources_short' => ['rule' => 'Deterministic rules', 'ai' => 'AI assistant'],
    'forecast_metrics' => ['complaints_monthly' => 'Complaints per month', 'vacancies_monthly' => 'Vacant positions per month'],
    'domain' => ['business' => 'Business', 'technical' => 'Technical'],
    'alert_type' => ['incident' => 'Incident', 'insight' => 'Insight', 'data_quality' => 'Data quality', 'forecast' => 'Forecast'],
    'alert_status' => ['open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved'],
    'areas_short' => ['workforce' => 'Workforce', 'public_voice' => 'Public Voice', 'performance' => 'Performance', 'data_quality' => 'Data Quality', 'incidents' => 'Incidents', 'executive' => 'Executive', 'service' => 'Service', 'forecasting' => 'Forecasting'],

    'evidence' => [
        'window' => 'Window',
        'samples' => 'Samples',
        'baseline_window' => 'Baseline window',
        'threshold' => 'Configured limit',
        'count' => 'Count',
        'previous' => 'Previous period',
        'first_seen' => 'First seen',
        'last_seen' => 'Last seen',
        'component' => 'Component',
        'first_value' => 'First value', 'last_value' => 'Last value', 'slope_per_step' => 'Slope per step', 'rising_steps_share' => 'Share of rising steps',
        'release' => 'Release', 'deployed_at' => 'Deployed at', 'detected_at' => 'Detected at',
        'route' => 'Route group',
    ],
];
