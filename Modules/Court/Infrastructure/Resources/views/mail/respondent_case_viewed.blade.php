<!doctype html>
<html>

<body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color:#0f172a;">
    <h2 style="margin-bottom: 8px">{{ __('court::app.admin_notifications.respondent_viewed_case_title') }}</h2>
    <p style="margin:0 0 12px;">
        {{ __('court::app.admin_notifications.case_label') }} <strong>{{ $case->case_number ?? __('court::app.admin_notifications.unknown_case') }}</strong>
        {{ !empty($case->title) ? ' – ' . $case->title : '' }}
    </p>
    <p style="margin:0 0 12px;">
        {{ __('court::app.admin_notifications.opened_case_at', ['name' => $respondentName, 'time' => $timestamp]) }}
    </p>
    <p style="margin:0;">
        <a href="{{ $caseUrl }}" style="color:#0ea5e9; text-decoration:none;">{{ __('court::app.admin_notifications.open_case_details') }}</a>
    </p>
</body>

</html>
