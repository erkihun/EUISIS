<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ __('court::notifications.mail.hearing_scheduled_heading') }}</title>
</head>

<body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color:#0f172a;">
    <h2 style="margin:0 0 10px;">{{ __('court::notifications.mail.hearing_scheduled_heading') }}</h2>

    <p style="margin:0 0 12px;">
        {!! __('court::notifications.mail.hearing_scheduled_body', [
            'case' => '<strong>' . e($case->case_number ?? __('court::notifications.mail.your_case')) . '</strong>',
        ]) !!}
        @if(!empty($case->title)) &mdash; <em>{{ $case->title }}</em>@endif
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse; width:100%; max-width:560px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
        <tr>
            <td style="padding:12px 14px;">
                <p style="margin:0 0 8px;">
                    <strong>{{ __('court::notifications.mail.date_time_label') }}:</strong>
                    {{ \Modules\Court\Application\Support\EthiopianDate::format($hearing->hearing_at, withTime: true) }}
                </p>
                @if(!empty($hearing->type))
                <p style="margin:0 0 8px;"><strong>{{ __('court::notifications.mail.type_label') }}:</strong> {{ $hearing->type }}</p>
                @endif
            </td>
        </tr>
    </table>

    <p style="margin:16px 0 0;">{{ __('court::notifications.mail.hearing_arrive_early') }}</p>
    <p style="margin:16px 0 0;">&mdash; {{ config('app.name','Court-MS') }}</p>
</body>

</html>
