@php
$displayName = $recipientName ?: 'Recipient';
$safeSubject = $letter->subject ?: 'Letter';
@endphp

<div style="font-family: Arial, sans-serif; color: #0f172a; padding: 16px; background: #f8fafc;">
    <div style="max-width: 720px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(90deg, #f97316 0%, #fb923c 100%); color: #fff;">
            <div style="font-size: 14px; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9;">Approved Letter</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 4px;">{{ $caseNumber ?: 'Case' }}</div>
        </div>

        <div style="padding: 24px;">
            <p style="margin: 0 0 12px;">Hello {{ $displayName }},</p>
            <p style="margin: 0 0 16px; color: #475569;">
                A letter has been approved for your case. You can view it using the link below.
            </p>

            <div style="margin: 16px 0; padding: 16px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; line-height: 1.5;">
                <div style="font-size: 14px; font-weight: 700; color: #0f172a;">Subject: {{ $safeSubject }}</div>
                <div style="margin-top: 12px; font-size: 13px; color: #0f172a;">
                    Preview: <a href="{{ $previewUrl }}" style="color: #0ea5e9; text-decoration: none;">{{ $previewUrl }}</a>
                </div>
            </div>

            <p style="margin: 16px 0 0; color: #475569; font-size: 13px;">
                You may need to sign in to view the letter. No additional content is included in this email.
            </p>
        </div>

        <div style="padding: 16px 24px; background: #f1f5f9; border-top: 1px solid #e5e7eb; color: #475569; font-size: 12px;">
            This is an automated message from the court management system.
        </div>
    </div>
</div>
