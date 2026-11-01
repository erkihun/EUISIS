<!doctype html>
<html>

<body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color:#0f172a;">
    <h2 style="margin:0 0 8px;">{{ __('court::notifications.mail.filing_receipt_heading') }}</h2>
    <p style="margin:0 0 12px;">
        {!! __('court::notifications.mail.filing_receipt_body', [
            'case' => '<strong>' . e($case->case_number) . '</strong>',
        ]) !!}
        @if(!empty($case->title)) &mdash; <em>{{ $case->title }}</em>@endif
    </p>

    <p style="margin:0 0 8px;">{{ __('court::notifications.mail.ignore_if_not_requested') }}</p>

    <p style="margin:16px 0 0;">{{ __('court::notifications.mail.thanks') }}<br>{{ config('app.name','Court-MS') }}</p>
</body>

</html>
