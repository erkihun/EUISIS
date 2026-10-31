<!doctype html>
<html>

<body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color:#0f172a;">
    <h2 style="margin:0 0 8px">{{ __('court::notifications.mail.case_message_heading') }}</h2>
    <p style="margin:0 0 12px;">
        {{ __('court::notifications.mail.case_label') }} <strong>{{ $caseRow->case_number }}</strong>
        @if(!empty($caseRow->title)) &mdash; <em>{{ $caseRow->title }}</em>@endif
    </p>

    <p style="margin:0 0 8px;">{{ __('court::notifications.mail.from_label') }}: <strong>{{ $sender }}</strong></p>
    <blockquote style="margin:0; padding:10px 12px; background:#f8fafc; border-left:4px solid #64748b;">
        {{ $bodyPreview }}
    </blockquote>

    <p style="margin:16px 0 0;">{{ __('court::notifications.mail.open_case_to_reply') }}</p>
</body>

</html>
