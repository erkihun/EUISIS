{{ __('court::notifications.mail.hearing_scheduled_heading') }}

{{ __('court::notifications.mail.case_label') }}: {{ $case->case_number ?? '-' }} @if(!empty($case->title)) - {{ $case->title }} @endif
{{ __('court::notifications.mail.date_time_label') }}: {{ \Modules\Court\Application\Support\EthiopianDate::format($hearing->hearing_at, withTime: true) }}
@if(!empty($hearing->type)){{ __('court::notifications.mail.type_label') }}: {{ $hearing->type }}@endif

{{ __('court::notifications.mail.hearing_arrive_early') }}

- {{ config('app.name','Court-MS') }}
