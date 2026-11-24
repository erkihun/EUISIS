<?php

declare(strict_types=1);
use Modules\Reporting\Infrastructure\Integration\ServiceMonitoringMetricSource;

/*
 * Institutional Reporting & Compliance — module configuration.
 *
 * Owned by the module and merged under the "reporting" key by its provider.
 * The values marked (setting) can be changed on the module's settings page;
 * what is saved there (table reporting_settings) takes precedence.
 */
return [
    // (setting) Maintenance switch: refuses every write and pauses scheduled work and queued jobs. Data stays readable.
    'maintenance' => (bool) env('REPORTING_MAINTENANCE', false),

    // Private disk for uploads and generated files. Never a public disk: every download is authorized by a controller.
    'disk' => env('REPORTING_DISK', 'local'),

    // (setting) Largest upload, in kilobytes.
    'max_upload_kb' => (int) env('REPORTING_MAX_UPLOAD_KB', 20480),

    // (setting) Default reminder stages; a schedule may carry its own.
    'reminder_rules' => ['days_before' => [7, 3, 1], 'on_due_date' => true, 'when_overdue' => true, 'escalate_after_working_days' => 3],

    // (setting) Also send notices by e-mail (the application's mailer must be configured).
    'email_notifications' => (bool) env('REPORTING_EMAIL_NOTIFICATIONS', false),

    // (setting) Font named in generated Word files; an Ethiopic-capable face (Nyala, Ebrima, Noto Sans Ethiopic, Abyssinica SIL).
    'document_font' => env('REPORTING_DOCUMENT_FONT', 'Nyala'),

    // Language of the files generated on the queue (am or en). On-demand downloads follow the reader's language.
    'document_language' => env('REPORTING_DOCUMENT_LANGUAGE', 'am'),

    // Optional logo for the document header (an absolute path to a PNG or JPEG).
    'logo_path' => env('REPORTING_LOGO_PATH'),

    // Optional malware scanner. `command` is the program and its arguments; the file path is appended.
    // Exit code 0 = clean, 1 = infected (the ClamAV convention). Example: REPORTING_SCANNER="clamdscan,--no-summary,--fdpass"
    'scanner' => [
        'command' => array_values(array_filter(explode(',', (string) env('REPORTING_SCANNER', '')))),
        'timeout' => (int) env('REPORTING_SCANNER_TIMEOUT', 60),
        // When true, a file is refused if the scanner cannot run.
        'required' => (bool) env('REPORTING_SCANNER_REQUIRED', false),
    ],

    // Optional PDF preview: the path of a LibreOffice `soffice` binary. Without it, Word download works unchanged.
    'pdf' => [
        'soffice' => env('REPORTING_SOFFICE_PATH'),
        'timeout' => (int) env('REPORTING_PDF_TIMEOUT', 120),
    ],

    // Optional indicator sources (classes implementing Application\Ports\MetricSource). Reporting works with none.
    'metric_sources' => [
        ServiceMonitoringMetricSource::class,
    ],
];
