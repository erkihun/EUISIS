<!DOCTYPE html>
<html lang="{{ $am ? 'am' : 'en' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $letter->reference_number ?? 'DRAFT' }}</title>
    {{-- Abyssinica SIL (formal variant) embedded server-side; see config/typography.php. --}}
    @include('pdf.partials.typography', ['variant' => 'formal'])
    <style>
        @page { margin: 28mm 20mm 24mm 22mm; }
        body { font-size: 11.5pt; color: #111; line-height: 1.55; }
        table { border-collapse: collapse; width: 100%; }
        .letterhead td { vertical-align: middle; }
        .letterhead .logo { width: 70px; }
        .letterhead .logo img { width: 62px; height: auto; }
        .org { text-align: center; }
        .org .line { font-size: 10pt; color: #333; }
        .org .name { font-size: 14pt; font-weight: bold; color: #122170; margin: 2px 0; }
        .contacts { font-size: 8.5pt; color: #444; text-align: center; border-bottom: 2px solid #122170; padding-bottom: 6px; margin-bottom: 14px; }
        .meta td { font-size: 10pt; padding: 1px 0; }
        .meta .label { color: #555; width: 34%; }
        .recipient { margin: 16px 0 12px; }
        .subject { font-weight: bold; margin: 12px 0; text-decoration: underline; }
        .body { text-align: justify; white-space: normal; }
        .signature { margin-top: 36px; width: 60%; }
        .signature img.sig { height: 48px; width: auto; }
        .signature .name { font-weight: bold; margin-top: 4px; }
        .signature .method { font-size: 8pt; color: #555; }
        .seal { position: relative; }
        .seal img { width: 110px; height: auto; opacity: 0.9; }
        .section-title { font-size: 9.5pt; font-weight: bold; margin-top: 18px; color: #333; }
        .small { font-size: 9.5pt; }
        .draft { position: fixed; top: 38%; left: 8%; font-size: 72pt; color: #d33; opacity: 0.12; transform: rotate(-30deg); }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 8pt; color: #666; text-align: center; }
    </style>
</head>
<body>
    @if ($preview)
        <div class="draft">{{ $am ? 'ረቂቅ' : 'DRAFT' }}</div>
    @endif

    <table class="letterhead">
        <tr>
            <td class="logo">@if ($logo)<img src="{{ $logo }}" alt="">@endif</td>
            <td class="org">
                @if ($headerLine)<div class="line">{{ $headerLine }}</div>@endif
                <div class="name">{{ $am ? ($organization?->name_am ?: $organization?->name_en) : ($organization?->name_en ?: $organization?->name_am) }}</div>
                @if ($am && $organization?->name_en)<div class="line">{{ $organization->name_en }}</div>@endif
            </td>
            <td class="logo"></td>
        </tr>
    </table>
    @if ($letterhead)
        <div class="contacts">
            {{ $am ? ($letterhead->address_am ?: $letterhead->address_en) : ($letterhead->address_en ?: $letterhead->address_am) }}
            @if ($letterhead->po_box) · {{ $am ? 'ፖ.ሳ.ቁ' : 'P.O. Box' }} {{ $letterhead->po_box }} @endif
            @if ($letterhead->phone) · {{ $am ? 'ስልክ' : 'Tel' }} {{ $letterhead->phone }} @endif
            @if ($letterhead->email) · {{ $letterhead->email }} @endif
            @if ($letterhead->website) · {{ $letterhead->website }} @endif
        </div>
    @else
        <div class="contacts">&nbsp;</div>
    @endif

    <table class="meta">
        <tr>
            <td></td>
            <td style="width: 45%;">
                <table>
                    <tr><td class="label">{{ $am ? 'ቁጥር' : 'Ref. No.' }}</td><td><strong>{{ $letter->reference_number ?? '—' }}</strong></td></tr>
                    <tr><td class="label">{{ $am ? 'ቀን' : 'Date' }}</td><td>{{ $am ? ($dateEthiopian ?? '—') : ($dateGregorian ?? '—') }}@if ($am && $dateGregorian) <span class="small">({{ $dateGregorian }})</span>@endif</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @php($to = $letter->recipients->where('kind', \App\Enums\Grievance\GrievanceRecipientKind::To))
    @php($cc = $letter->recipients->where('kind', \App\Enums\Grievance\GrievanceRecipientKind::Cc))
    <div class="recipient">
        @foreach ($to as $recipient)
            <div>{{ $am ? 'ለ' : 'To:' }} {{ $recipient->name }}</div>
            @if ($recipient->position_title)<div class="small">{{ $recipient->position_title }}</div>@endif
            @if ($recipient->organization_name)<div class="small">{{ $recipient->organization_name }}</div>@endif
            @if ($recipient->address)<div class="small">{{ $recipient->address }}</div>@endif
        @endforeach
    </div>

    <div class="subject">{{ $am ? 'ጉዳዩ፡-' : 'Subject:' }} {{ $letter->subject }}</div>

    {{-- Plain text only: escaped, line breaks preserved. --}}
    <div class="body">{!! nl2br(e($letter->body)) !!}</div>

    <table class="signature">
        <tr>
            <td>
                @if ($signature)<img class="sig" src="{{ $signature }}" alt="">@endif
                @if ($signatoryName)
                    <div class="name">{{ $signatoryName }}</div>
                    @if ($signatoryPosition)<div>{{ $signatoryPosition }}</div>@endif
                    <div class="method">
                        @if ($letter->signature_method?->value === 'electronic_approval')
                            {{ $am ? 'በኤሌክትሮኒክ ማጽደቅ የተፈረመ' : 'Signed by electronic approval' }} · {{ $letter->signed_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                        @elseif ($letter->signature_method?->value === 'signature_image')
                            {{ $am ? 'የፊርማ ምስል' : 'Signature image' }} · {{ $letter->signed_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                        @endif
                    </div>
                @endif
            </td>
            <td class="seal" style="width: 130px;">@if ($seal)<img src="{{ $seal }}" alt="">@endif</td>
        </tr>
    </table>

    @if ($letter->attachments->isNotEmpty())
        <div class="section-title">{{ $am ? 'አባሪዎች' : 'Attachments' }}</div>
        <ol class="small">
            @foreach ($letter->attachments as $attachment)
                <li>{{ $attachment->title }}</li>
            @endforeach
        </ol>
    @endif

    @if ($cc->isNotEmpty())
        <div class="section-title">{{ $am ? 'ግልባጭ' : 'CC' }}</div>
        <ul class="small">
            @foreach ($cc as $recipient)
                <li>{{ $recipient->name }}@if ($recipient->organization_name) — {{ $recipient->organization_name }}@endif</li>
            @endforeach
        </ul>
    @endif

    <div class="footer">
        {{ $letter->grievance?->reference_number }}@if ($letterhead && ($am ? $letterhead->footer_am : $letterhead->footer_en)) · {{ $am ? $letterhead->footer_am : $letterhead->footer_en }}@endif
    </div>
</body>
</html>
