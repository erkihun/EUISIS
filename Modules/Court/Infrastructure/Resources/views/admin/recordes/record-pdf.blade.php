<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('court::recordes.titles.pdf') }}</title>

    <script src="{{ asset('vendor/html2pdf/html2pdf.bundle.min.js') }}"></script>
    <script src="{{ asset('vendor/pdfjs/pdf.min.js') }}"></script>

    <style>
        @font-face {
            font-family: 'Abyssinica';
            font-style: normal;
            font-weight: 400;
            src: url('{{ asset('fonts/AbyssinicaSIL-Regular.ttf') }}') format('truetype');
        }

        :root {
            --a4-width: 210mm;
            --a9-width: 250mm;
            --a4-height: 297mm;
             --a4-height: 297mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #e5e7eb;
            min-height: 100vh;
            font-family: 'Abyssinica', 'Nyala', 'DejaVu Serif', serif;
            color: #0f172a;
        }

        .pdf-toolbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .pdf-toolbar h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .toolbar-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            border: 1px solid #93c5fd;
            border-radius: 6px;
            padding: 8px 14px;
            background: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, border-color 0.2s;
            color: #1d4ed8;
        }

        .btn:hover {
            background: #eff6ff;
            border-color: #60a5fa;
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .page-wrapper {
            padding: 20px;
            display: flex;
            justify-content: center;
        }
        body.pdf-export .page-wrapper {
            padding: 5mm 0;
        }

        .record-page {
            width: var(--a4-width);
            min-height: var(--a4-height);
            background: #fff;
            box-shadow: 0 4px 6px rgba(15, 23, 42, 0.1);
            padding: 22mm 18mm;
            font-size: 14px;
            line-height: 1.7;
            position: relative;
        }
        body.pdf-export .record-page {
            padding: 12mm 14mm 14mm;
            box-shadow: none;
        }

        .record-page h1 {
            font-size: 26px;
            margin-bottom: 10px;
        }

        .record-page h2 {
            font-size: 20px;
            margin: 20px 0 10px;
        }

        .record-page h3 {
            font-size: 16px;
            margin: 14px 0 8px;
        }

        .section {
            margin-bottom: 18px;
            width: 100%;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .page-counter-overlay {
            position: fixed;
            bottom: 20px;
            right: 30px;
            background: rgba(15, 23, 42, 0.85);
            color: #fff;
            font-size: 13px;
            padding: 6px 12px;
            border-radius: 999px;
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.2);
            pointer-events: none;
            z-index: 1000;
        }

        .page-preview-footer {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            font-size: 12px;
            color: #475569;
            background: rgba(255, 255, 255, 0.9);
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid #cbd5f5;
            pointer-events: none;
        }

        body.pdf-export .page-preview-footer {
            display: none !important;
        }

        body.pdf-export .page-counter-overlay {
            display: none !important;
        }

        @media print {
            .page-preview-footer,
            .page-counter-overlay {
                display: none !important;
            }
        }

        .section > * {
            width: 100%;
        }

        .card {
            border: 1px solid #dbeafe;
            width: 100%;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            box-sizing: border-box;
        }

        .meta {
            font-size: 14px;
            color: #475569;
            word-break: break-word;
        }

        .content {
            margin-top: 8px;
            text-align: justify;
            word-break: break-word;
        }

        .muted {
            font-size: 12px;
            color: #64748b;
        }

        .pdf-preview {
            margin-top: 10px;
            border: 1px solid #cbd5f5;
            border-radius: 6px;
            overflow: hidden;
            background: #f8fafc;
        }

        .pdf-rendered-viewer {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 12px;
        }

        .pdf-preview iframe.fallback-pdf-frame {
            border: none;
            width: 100%;
            min-height: 480px;
        }

        .pdf-rendered-viewer canvas {
            width: 100% !important;
            height: auto !important;
            border-radius: 4px;
            background: #fff;
            box-shadow: 0 2px 4px rgba(15, 23, 42, 0.08);
        }

        .pdf-rendered-viewer .loading-text {
            font-size: 13px;
            color: #475569;
            text-align: center;
            padding: 40px 0;
        }

        .pdf-preview iframe {
            width: 100%;
            height: 380px;
            border: none;
            background: #f8fafc;
        }

        .letter-full-preview {
            margin-top: 16px;
            border: 1px solid #dbeafe;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.1);
            page-break-inside: avoid;
        }

        .letter-full-preview + .letter-full-preview {
            page-break-before: always;
        }

        .letter-preview-container {
            margin-top: 10px;
     
            
        }

        .preview-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2rem;
            margin: 0;
        }

        .a4-sheet {
            width: var(--a4-width);
            min-height: var(--a4-height);
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding-top: 2mm;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .a4-sheet .letter-header img,
        .a4-sheet .letter-footer img {
            width: 100%;
            height: auto;
            display: block;
        }

        .a4-sheet .letter-body-container {
            flex: 1;
            padding: 0 20mm 0 20mm;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .a4-sheet .content-slot {
            width: 100%;
        }

        .letter-preview-container .a4-sheet {
            width: 100%;
            min-height: calc(var(--a4-height) * 0.8);
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
            border: 1px solid #e2e8f0;
        }

        .letter-preview-container .letter-header img,
        .letter-preview-container .letter-footer img {
            width: 100%;
            height: auto;
            display: block;
        }

        .letter-preview-container .letter-body-container {
            flex: 1;
            padding: 0 20mm 0 20mm;
            font-size: 13px;
            color: #0f172a;
            line-height: 1.65;
            display: flex;
            flex-direction: column;
        }

        .letter-preview-container .meta-row {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            font-size: 12px;
            color: #475569;
            gap: 8px;
            margin-bottom: 12px;
        }

        .letter-preview-container .recipient-block {
            margin-bottom: 10px;
            font-weight: 600;
        }

        .letter-preview-container .subject-line {
            text-transform: uppercase;
            text-align: center;
            font-weight: 700;
            margin: 16px 0;
            letter-spacing: 0.04em;
        }

        .letter-preview-container .letter-body-content {
            text-align: justify;
        }

        .letter-preview-container .closing-block {
            margin-top: 5mm;
            margin-bottom: 5mm;
            text-align: right;
            color: #5539CC;
        }

        .letter-preview-container .closing-block img {
            max-height: 60px;
            max-width: 200px;
            display: inline-block;
            margin-bottom: 6px;
        }

        .letter-preview-container .page-seal-bottom {
            position: absolute;
            bottom: min(30mm, 12vw);
            right: min(32mm, 12vw);
            z-index: 5;
            width: clamp(70px, 14vw, 120px);
            height: clamp(70px, 14vw, 120px);
            display: flex;
            justify-content: center;
            align-items: center;
            pointer-events: none;
        }

        .letter-preview-container .page-seal-bottom img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            opacity: 0.85;
        }

        .letter-preview-container .letter-footer {
            position: relative;
            height: 34mm;
            flex-shrink: 0;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            padding: 0 8mm;
            gap: 6mm;
            overflow: hidden;
        }

        .letter-preview-container .letter-footer img {
            width: calc(100% - clamp(50px, 6vw, 80px) - 6mm);
            height: auto;
        }

        .letter-preview-container .qr-block {
            width: clamp(50px, 6vw, 80px);
            height: clamp(50px, 6vw, 80px);
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            border-radius: 8px;
            border: 1px solid rgba(148, 163, 184, 0.5);
            padding: 4px;
            flex-shrink: 0;
        }

        .letter-preview-container .qr-block svg {
            width: 100%;
            height: 100%;
            display: block;
        }

        .letter-preview-container .cc-block {
            padding: 0 26px 24px;
            font-size: 12px;
            color: #475569;
        }

        .letter-preview-container .signature-space {
            height: 10mm;
        }

        .letter-preview-container .recipient-list-item {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .letter-preview-container ul {
            margin: 4px 0 0 16px;
        }

        .letter-preview-container .pl-4 { padding-left: 1rem; }
        .letter-preview-container .mr-3 { margin-right: 0.75rem; }
        .letter-preview-container .font-bold { font-weight: 700; }
        .letter-preview-container .inline-flex { display: inline-flex; }
        .letter-preview-container .items-end { align-items: flex-end; }
        .letter-preview-container .gap-6 { gap: 1.5rem; }
        .letter-preview-container .flex { display: flex; }
        .letter-preview-container .flex-col { flex-direction: column; }
        .letter-preview-container .items-center { align-items: center; }
        .letter-preview-container .gap-1 { gap: 0.25rem; }
        .letter-preview-container .list-disc { list-style-type: disc; }
        .letter-preview-container .mt-1 { margin-top: 0.25rem; }
        .letter-preview-container .ml-5 { margin-left: 1.25rem; }
        .bench-judges-panel {
            margin-top: 18px;
            border: 1px solid #dbeafe;
            border-radius: 10px;
            padding: 12px;
            background: #f8fafc;
        }

        .bench-judges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }

        .bench-judge-card {
            border: 1px dashed #cbd5f5;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            background: #fff;
        }

        .bench-judge-name {
            font-weight: 600;
        }

        .bench-judge-date {
            font-size: 12px;
            color: #475569;
            margin-top: 4px;
        }

        .bench-signatures {
            display: flex;
            gap: 30px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .bench-signature-line {
            flex: 1;
            min-width: 220px;
        }

        .bench-signature-placeholder {
            height: 45px;
            border-bottom: 1px solid #94a3b8;
            margin-bottom: 6px;
        }

        .bench-signature-label {
            font-size: 13px;
            color: #475569;
            text-align: center;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .pdf-toolbar {
                display: none;
            }
            .page-wrapper {
                padding: 0;
            }
            .record-page {
                width: auto;
                min-height: auto;
                box-shadow: none;
                padding: 0 15mm;
            }
        }
    </style>
</head>
<body>
    <div class="pdf-toolbar">
        <h1>{{ __('court::recordes.titles.pdf') }}</h1>
        <div class="toolbar-actions">
            <button class="btn" onclick="window.print()">{{ __('court::recordes.buttons.print') }}</button>
            <button id="download-pdf" class="btn btn-primary">{{ __('court::recordes.buttons.download_pdf') }}</button>
        </div>
    </div>

    <div class="page-wrapper" id="page-wrapper">
        <div id="record-document" class="record-page">
            <div class="section">
                <h1>{{ __('court::recordes.titles.record') }}</h1>
                <div class="meta">
                    {{ __('court::recordes.labels.generated') }} 
                    @php
                        $generatedDate = now();
                        $ethiopianGenerated = class_exists(\Modules\Court\Application\Support\EthiopianDate::class)
                            ? \Modules\Court\Application\Support\EthiopianDate::format($generatedDate, withTime: true)
                            : $generatedDate->toDayDateTimeString();
                    @endphp
                    {{ $generatedDate->toDayDateTimeString() }} ({{ $ethiopianGenerated }})
                    <br>
                    {{ __('court::recordes.labels.case_number') }} {{ (string) ($case->case_number ?? 'N/A') }} {{ __('court::recordes.labels.title_pipe') }} {{ (string) ($case->title ?? '') }}
                </div>

                <div class="card">
                    <div class="meta">
                        {{ __('court::recordes.labels.status_label') }} {{ (string) ($case->status ?? 'N/A') }}
                        @if(!empty($case->caseType?->name))
                            | {{ __('court::recordes.labels.type') }} {{ $case->caseType->name }}
                        @endif
                        | {{ __('court::recordes.labels.opened') }} {{ optional($case->filing_date)->toDateString() ?? 'N/A' }}
                        | {{ __('court::recordes.labels.closed') }} {{ $closedAt ? \Illuminate\Support\Carbon::parse($closedAt)->toDateString() : __('court::recordes.labels.not_closed') }}
                    </div>

                    <div class="meta">
                        {{ __('court::recordes.labels.assigned_to') }} {{ $assignedUser->name ?? 'Unassigned' }}
                        @if(!empty($case->assigned_at))
                            | {{ __('court::recordes.labels.last_assigned') }} {{ optional($case->assigned_at)->toDayDateTimeString() }}
                        @endif
                    </div>

                    @if(($assignedTeams ?? collect())->isNotEmpty())
                        <div class="meta">{{ __('court::recordes.labels.teams') }} {{ $assignedTeams->join(', ') }}</div>
                    @endif

                    @if(!empty($case->judge_id))
                        <div class="meta">{{ __('court::recordes.labels.judge_id') }} {{ $case->judge_id }}</div>
                    @endif

                    @if(!empty($case->applicant))
                        <div class="meta">
                            {{ __('court::recordes.labels.applicant') }}
                            {{ trim(($case->applicant->first_name ?? '').' '.($case->applicant->middle_name ?? '').' '.($case->applicant->last_name ?? '')) }}
                            | {{ __('court::recordes.labels.email') }} {{ $case->applicant->email ?? 'N/A' }}
                            | {{ __('court::recordes.labels.phone') }} {{ $case->applicant->phone ?? 'N/A' }}
                        </div>
                    @endif

                    <div class="meta">
                        {{ __('court::recordes.labels.respondent') }} {{ (string) ($case->respondent_name ?? 'N/A') }}
                        | {{ __('court::recordes.labels.address') }} {{ (string) ($case->respondent_address ?? 'N/A') }}
                    </div>
                </div>
            </div>

            <div class="section">
                <h2>{{ __('court::recordes.labels.case_submission') }}</h2>
                <div class="meta">
                    {{ __('court::recordes.labels.case_number') }} {{ (string) ($case->case_number ?? 'N/A') }} |
                    {{ __('court::recordes.labels.status_label') }} {{ (string) ($case->status ?? 'N/A') }} |
                    {{ __('court::recordes.labels.filed') }}: {{ optional($case->filing_date)->toDateString() ?? 'N/A' }}
                    @if(!empty($case->caseType?->name))
                        | {{ __('court::recordes.labels.type') }} {{ $case->caseType->name }}
                    @endif
                </div>

                @if(!empty($case->applicant))
                    <div class="meta">
                        {{ __('court::recordes.labels.applicant') }}
                        {{ trim(($case->applicant->first_name ?? '').' '.($case->applicant->middle_name ?? '').' '.($case->applicant->last_name ?? '')) }}
                        | {{ __('court::recordes.labels.email') }} {{ $case->applicant->email ?? 'N/A' }}
                        | {{ __('court::recordes.labels.phone') }} {{ $case->applicant->phone ?? 'N/A' }}
                    </div>
                @endif
            </div>

            @php
                $firstEvidence = ($evidences ?? collect())->first();
                $firstEvidenceEmbedData = data_get($firstEvidenceEmbed ?? null, 'data');
                $firstEvidenceEmbedMime = data_get($firstEvidenceEmbed ?? null, 'mime', 'application/pdf');
                $normalizeSignerPayload = static function ($value) {
                    if ($value instanceof \Illuminate\Support\Collection) {
                        return $value;
                    }

                    if (is_string($value)) {
                        $decoded = json_decode($value, true);
                        return collect(is_array($decoded) ? $decoded : []);
                    }

                    if (is_array($value)) {
                        return collect($value);
                    }

                    return collect();
                };
            @endphp

            @if(!empty($case->description))
                <div class="section">
                    <h2>{{ __('court::recordes.labels.case_details') }}</h2>
                    <div class="content">{!! $case->description !!}</div>
                </div>
            @endif

            @if(!empty($case->relief_requested))
                <div class="section">
                <h2>{{ __('court::recordes.labels.relief_requested') }}</h2>
                    <div class="content">{!! $case->relief_requested !!}</div>
                </div>
            @endif

            <div class="section">
                <h2>{{ __('court::recordes.labels.witnesses') }}</h2>
                @forelse($witnesses ?? [] as $wit)
                    <div class="card">
                        <div><strong>{{ $wit->full_name ?? __('court::recordes.labels.witness') }}</strong></div>
                        <div class="meta">
                            {{ __('court::recordes.labels.phone') }} {{ $wit->phone ?? 'N/A' }} |
                            {{ __('court::recordes.labels.email') }} {{ $wit->email ?? 'N/A' }} |
                            {{ __('court::recordes.labels.address') }} {{ $wit->address ?? 'N/A' }}
                        </div>
                    </div>
                @empty
                    <div class="meta">{{ __('court::recordes.messages.no_witnesses') }}</div>
                @endforelse
            </div>

            <div class="section">
                <h2>{{ __('court::recordes.labels.submitted_documents') }}</h2>
                @forelse($files ?? [] as $file)
                    <div class="card">
                        <div><strong>{{ $file->label ?? __('court::recordes.labels.document') }}</strong></div>
                        <div class="meta">
                            {{ optional($file->created_at)->toDayDateTimeString() }}
                            @if(!empty($file->mime)) | {{ __('court::recordes.labels.mime') }} {{ $file->mime }} @endif
                            @if(!empty($file->size)) | {{ __('court::recordes.labels.size') }} {{ $file->size }} {{ __('court::recordes.labels.bytes') }} @endif
                        </div>
                    </div>
                @empty
                    <div class="meta">{{ __('court::recordes.messages.no_files') }}</div>
                @endforelse
            </div>

            @if($firstEvidence)
                <div class="section">
                    <h2>{{ __('court::recordes.labels.applicant_initial_pdf') }}</h2>
                    <div class="card">
                        <div><strong>{{ $firstEvidence->title ?? __('court::recordes.labels.document') }}</strong></div>
                        <div class="meta">
                            {{ optional($firstEvidence->created_at)->toDayDateTimeString() ?? 'N/A' }}
                            @if(!empty($firstEvidence->mime)) | {{ __('court::recordes.labels.mime') }} {{ $firstEvidence->mime }} @endif
                            @if(!empty($firstEvidence->size)) | {{ __('court::recordes.labels.size') }} {{ $firstEvidence->size }} {{ __('court::recordes.labels.bytes') }} @endif
                        </div>
                        @if(!empty($firstEvidenceEmbedData))
                            <div class="pdf-preview">
                                <div id="applicant-pdf-viewer" class="pdf-rendered-viewer" aria-live="polite" style="max-height:none;">
                                    <div class="loading-text">{{ __('court::recordes.descriptions.applicant_pdf_loading') }}</div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="section">
                <h2>{{ __('court::recordes.labels.applicant_evidence') }}</h2>
                @forelse($evidences ?? [] as $ev)
                    <div class="card">
                        <div><strong>{{ $ev->title ?? __('court::recordes.labels.document') }}</strong></div>
                        <div class="meta">{{ optional($ev->created_at)->toDayDateTimeString() }}</div>
                        @if(!empty($ev->description))
                            <div class="content">{{ $ev->description }}</div>
                        @endif
                        <div class="meta">
                            {{ __('court::recordes.labels.type') }} {{ $ev->type ?? 'document' }}
                            @if(!empty($ev->mime)) | {{ __('court::recordes.labels.mime') }} {{ $ev->mime }} @endif
                            @if(!empty($ev->size)) | {{ __('court::recordes.labels.size') }} {{ $ev->size }} {{ __('court::recordes.labels.bytes') }} @endif
                        </div>
                    </div>
                @empty
                    <div class="meta">{{ __('court::recordes.messages.no_evidence') }}</div>
                @endforelse
            </div>

            <div class="section">
                <h2>{{ __('court::recordes.labels.letters_section') }}</h2>
                @php
                    $systemSettings = $systemSettings ?? \Modules\Court\Domain\Models\SystemSetting::query()->first();
                @endphp
                @forelse($letters ?? [] as $letter)
                    @include('admin.letters.partials.inline-preview', [
                        'letter' => $letter,
                        'systemSettings' => $systemSettings
                    ])
                @empty
                    <div class="meta">{{ __('court::recordes.messages.no_letters') }}</div>
                @endforelse
            </div>

            <div class="section">
                <h2>{{ __('court::recordes.labels.respondent_responses') }}</h2>
                @php $responsePdfEmbeds = []; @endphp
                @forelse($respondentResponses ?? [] as $resp)
                    <div class="card">
                        <div><strong>{{ $resp->title ?? __('court::recordes.labels.response') }}</strong></div>
                        <div class="meta">{{ optional($resp->created_at)->toDayDateTimeString() }}</div>
                        @if(!empty($resp->description))
                            <div class="content">{{ $resp->description }}</div>
                        @endif
                        @if(!empty(data_get($resp, 'pdf_embed.data')))
                            @php
                                $viewerId = 'response-pdf-viewer-' . $loop->index;
                                $responsePdfEmbeds[] = [
                                    'id' => $viewerId,
                                    'data' => data_get($resp, 'pdf_embed.data'),
                                    'mime' => data_get($resp, 'pdf_embed.mime', 'application/pdf'),
                                    'title' => $resp->title ?? 'Response PDF',
                                ];
                            @endphp
                            <div class="pdf-preview" style="margin-top:12px;">
                                <div id="{{ $viewerId }}" class="pdf-rendered-viewer" aria-live="polite">
                                    <div class="loading-text">{{ __('court::recordes.descriptions.applicant_pdf_loading') }}</div>
                                </div>
                            </div>
                        @elseif(!empty($resp->download_url))
                            <div class="meta">
                                <a href="{{ $resp->download_url }}" target="_blank" rel="noreferrer">
                                    {{ __('court::recordes.buttons.download_pdf') }}
                                </a>
                            </div>
                        @else
                            <div class="meta">{{ __('court::recordes.messages.no_files') }}</div>
                        @endif
                    </div>
                @empty
                    <div class="meta">{{ __('court::recordes.messages.no_responses') }}</div>
                @endforelse
            </div>

            <div class="section">
                <h2>{{ __('court::recordes.labels.hearings') }}</h2>
                @forelse($hearings ?? [] as $hearing)
                    @php
                        $hearingMoment = !empty($hearing->hearing_at)
                            ? \Illuminate\Support\Carbon::parse($hearing->hearing_at)
                            : null;
                        $hearingFormatted = $hearingMoment
                            ? $hearingMoment->format('F j, Y g:i A')
                            : __('court::recordes.labels.hearing_unknown');
                        $hearingEthiopian = $hearingMoment
                            ? \Modules\Court\Application\Support\EthiopianDate::format($hearingMoment, true)
                            : null;
                    @endphp
                    <div class="card">
                        <div>
                            <strong>{{ __('court::recordes.labels.hearing_at') }} {{ $hearingFormatted }}</strong>
                            @if($hearingEthiopian)
                                <div class="meta">{{ $hearingEthiopian }}</div>
                            @endif
                        </div>
                        <div class="meta">{{ __('court::recordes.labels.location') }} {{ $hearing->location ?? 'N/A' }}</div>
                        @if(!empty($hearing->notes))
                            <div class="content">
                                <strong>{{ __('court::recordes.labels.hearing_notes') }}</strong>
                                {!! \Modules\Court\Application\Support\Purifier::clean($hearing->notes, 'default') !!}
                            </div>
                        @endif
                        @if(!empty($hearing->judge_notes))
                            <div class="content">
                                <strong>{{ __('court::recordes.labels.judge_notes') }}</strong>
                                {!! \Modules\Court\Application\Support\Purifier::clean($hearing->judge_notes, 'default') !!}
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="meta">{{ __('court::recordes.messages.no_hearings') }}</div>
                @endforelse
            </div>

            <div class="section">
                <h2>{{ __('court::recordes.labels.bench_notes') }}</h2>
                @forelse($benchNotes ?? [] as $note)
                    @php
                        $resolveSignature = function ($path) {
                            if (empty($path)) {
                                return null;
                            }
                            $trimmed = ltrim($path);
                            if (\Illuminate\Support\Str::startsWith($trimmed, ['http://', 'https://', 'data:'])) {
                                return $trimmed;
                            }
                            if (\Illuminate\Support\Str::startsWith($trimmed, ['/storage/', 'storage/'])) {
                                return asset(ltrim($trimmed, '/'));
                            }
                            if (\Illuminate\Support\Str::startsWith($trimmed, ['public/'])) {
                                $trimmed = substr($trimmed, 7);
                            }

                            return asset('storage/' . ltrim($trimmed, '/'));
                        };

                        $manualJudges = collect([
                            [
                                'name' => $note->judge_one_name ?? null,
                                'date' => optional($note->created_at)->toDayDateTimeString() ?? '',
                                'title' => $note->judge_one_title ?? __('court::recordes.labels.judge'),
                                'signature' => $resolveSignature($note->judge_one_signature ?? null),
                            ],
                            [
                                'name' => $note->judge_two_name ?? null,
                                'date' => optional($note->created_at)->toDayDateTimeString() ?? '',
                                'title' => $note->judge_two_title ?? __('court::recordes.labels.judge'),
                                'signature' => $resolveSignature($note->judge_two_signature ?? null),
                            ],
                            [
                                'name' => $note->judge_three_name ?? null,
                                'date' => optional($note->created_at)->toDayDateTimeString() ?? '',
                                'title' => $note->judge_three_title ?? __('court::recordes.labels.judge'),
                                'signature' => $resolveSignature($note->judge_three_signature ?? null),
                            ],
                        ])->filter(fn ($judge) => !empty($judge['name']));

                        // Display all judge names/dates, but only render signature block for judge 1
                        $judges = $manualJudges;
                        $signers = $manualJudges->take(1);
                    @endphp
                    <div class="card bench-note-entry">
                        @php
                            $benchCreated = !empty($note->created_at) ? \Illuminate\Support\Carbon::parse($note->created_at) : null;
                            $benchCreatedFormatted = $benchCreated ? $benchCreated->toDayDateTimeString() : '';
                            $benchCreatedEth = $benchCreated && class_exists(\Modules\Court\Application\Support\EthiopianDate::class)
                                ? \Modules\Court\Application\Support\EthiopianDate::format($benchCreated, withTime: true)
                                : $benchCreatedFormatted;
                        @endphp
                        <div class="meta" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:6px;">
                            <div style="font-weight:600;">{{ $note->title ?? __('court::recordes.labels.bench_notes') }}</div>
                            <div style="text-align:right;">
                                <strong>{{ __('court::recordes.labels.created') }}</strong> {{ $benchCreatedFormatted }}
                                @if(!empty($benchCreatedEth))
                                    <div style="font-size:12px;color:#475569;">({{ $benchCreatedEth }})</div>
                                @endif
                            </div>
                        </div>
                        @if($judges->isNotEmpty())
                            <div class="bench-judges-grid" style="margin-bottom:12px;">
                                @foreach($judges as $index => $judge)
                                    <div class="bench-judge-card" style="text-align:left;">
                                        <div class="bench-judge-name">{{ ($index + 1) }}. {{ $judge['name'] }}</div>
                                        @if(!empty($judge['date']))
                                            <div class="bench-judge-date">{{ $judge['date'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div class="content">{!! \Modules\Court\Application\Support\Purifier::clean($note->note ?? $note->body ?? '', 'default') !!}</div>
                        @if($signers->isNotEmpty())
                            <div class="bench-signatures" style="display:flex;gap:20px;flex-wrap:nowrap;justify-content:center;">
                                @foreach($signers as $signer)
                                    <div class="bench-signature-line" style="flex:1;">
                                        <div class="bench-signature-placeholder">
                                            @if(!empty($signer['signature']))
                                                <img src="{{ $signer['signature'] }}" alt="{{ $signer['name'] }}" style="max-height:45px;max-width:160px;object-fit:contain;">
                                            @endif
                                        </div>
                                        <div class="bench-signature-label">
                                            {{ $signer['name'] ?? __('court::recordes.labels.judge') }}
                                            @if(!empty($signer['title']))
                                                <br><span style="font-size:12px;">{{ $signer['title'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="meta">{{ __('court::recordes.messages.no_bench_notes') }}</div>
                @endforelse
            </div>

            <div class="section">
                <h2>{{ __('court::recordes.labels.final_judgment') }}</h2>
                @if(!empty($decision))
                    @php
                        $resolveDecisionSignature = function ($path) {
                            if (empty($path)) {
                                return null;
                            }
                            $trimmed = ltrim($path);
                            if (\Illuminate\Support\Str::startsWith($trimmed, ['http://', 'https://', 'data:'])) {
                                return $trimmed;
                            }
                            if (\Illuminate\Support\Str::startsWith($trimmed, ['/storage/', 'storage/'])) {
                                return asset(ltrim($trimmed, '/'));
                            }
                            if (\Illuminate\Support\Str::startsWith($trimmed, ['public/'])) {
                                $trimmed = substr($trimmed, 7);
                            }

                            return asset('storage/' . ltrim($trimmed, '/'));
                        };

                        $decisionSigners = $normalizeSignerPayload($decision->signatures ?? [])
                            ->map(fn ($signer) => [
                                'name' => data_get($signer, 'name') ?? data_get($signer, 'judge_name') ?? __('court::recordes.labels.judge'),
                                'title' => data_get($signer, 'title') ?? __('court::recordes.labels.judge'),
                                'signature' => $resolveDecisionSignature(data_get($signer, 'signature')),
                            ])
                            ->filter(fn ($signer) => !empty($signer['name']))
                            ->take(3);

                        $decisionDate = !empty($decision->created_at) ? \Illuminate\Support\Carbon::parse($decision->created_at) : null;
                        $decisionDateFormatted = $decisionDate ? $decisionDate->toDayDateTimeString() : '';
                        $decisionDateEth = $decisionDate && class_exists(\Modules\Court\Application\Support\EthiopianDate::class)
                            ? \Modules\Court\Application\Support\EthiopianDate::format($decisionDate, withTime: true)
                            : $decisionDateFormatted;
                    @endphp
                    <div class="card bench-note-entry">
                        <div class="meta" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:6px;">
                            <div style="font-weight:600;">{{ $decision->title ?? __('court::recordes.labels.decision') }}</div>
                            <div style="text-align:right;">
                                <strong>{{ __('court::recordes.labels.created') }}</strong> {{ $decisionDateFormatted }}
                                @if(!empty($decisionDateEth))
                                    <div style="font-size:12px;color:#475569;">({{ $decisionDateEth }})</div>
                                @endif
                            </div>
                        </div>
                        @php
                            $decisionContent = $decision->decision_content ?? $decision->body ?? '';
                        @endphp
                        <div class="content">{!! \Modules\Court\Application\Support\Purifier::clean($decisionContent, 'default') !!}</div>
                        @if($decisionSigners->isNotEmpty())
                            <div class="bench-signatures" style="display:flex;gap:20px;flex-wrap:nowrap;">
                                @foreach($decisionSigners as $signer)
                                    <div class="bench-signature-line" style="flex:1;">
                                        <div class="bench-signature-placeholder">
                                            @if(!empty($signer['signature']))
                                                <img src="{{ $signer['signature'] }}" alt="{{ $signer['name'] }}" style="max-height:45px;max-width:160px;object-fit:contain;">
                                            @endif
                                        </div>
                                        <div class="bench-signature-label">
                                            {{ $signer['name'] ?? __('court::recordes.labels.judge') }}
                                            @if(!empty($signer['title']))
                                                <br><span style="font-size:12px;">{{ $signer['title'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    <div class="meta">{{ __('court::recordes.messages.no_decision') }}</div>
                @endif
            </div>

        </div>
    </div>

    <script>
        const downloadBtn = document.getElementById('download-pdf');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => generatePdf());
        }

        const pageWrapper = document.getElementById('page-wrapper');
        const pageCounterOverlay = document.createElement('div');
        pageCounterOverlay.className = 'page-counter-overlay';
        pageCounterOverlay.style.display = 'none';
        document.body.appendChild(pageCounterOverlay);

        const recordDocument = document.getElementById('record-document');
        let previewFooters = [];
        let pageHeightPx = measurePageHeightPx();
        let exporting = false;

        function measurePageHeightPx() {
            const ruler = document.createElement('div');
            ruler.style.height = '297mm';
            ruler.style.visibility = 'hidden';
            ruler.style.position = 'absolute';
            ruler.style.pointerEvents = 'none';
            document.body.appendChild(ruler);
            const height = ruler.getBoundingClientRect().height;
            ruler.remove();
            return height;
        }

        const updatePageCounter = () => {
            if (exporting) return;
            if (!pageWrapper || !pageCounterOverlay) return;
            const pageHeight = pageWrapper.clientHeight || pageHeightPx;
            const scrollTop = pageWrapper.scrollTop || window.scrollY;
            const scrollSize = pageWrapper.scrollHeight || document.documentElement.scrollHeight;
            const totalPages = Math.max(1, Math.ceil(scrollSize / pageHeight));
            const currentPage = Math.min(totalPages, Math.floor(scrollTop / pageHeight) + 1);
            if (totalPages <= 1) {
                pageCounterOverlay.style.display = 'none';
                return;
            }
            pageCounterOverlay.style.display = 'block';
            pageCounterOverlay.textContent = `Page ${currentPage} of ${totalPages}`;
        };

        function renderPreviewFooters() {
            if (exporting) return;
            if (!recordDocument) return;
            previewFooters.forEach((footer) => footer.remove());
            previewFooters = [];
            const totalPages = Math.max(1, Math.ceil(recordDocument.scrollHeight / pageHeightPx));
            for (let i = 1; i <= totalPages; i++) {
                const footer = document.createElement('div');
                footer.className = 'page-preview-footer';
                footer.textContent = `Page ${i}`;
                footer.style.top = `${pageHeightPx * i - 30}px`;
                recordDocument.appendChild(footer);
                previewFooters.push(footer);
            }
        }

        const scheduleFooterRender = () => {
            pageHeightPx = measurePageHeightPx();
            renderPreviewFooters();
            updatePageCounter();
        };

        const cleanupPreviewArtifacts = () => {
            document.querySelectorAll('.page-preview-footer').forEach(el => el.remove());
            previewFooters = [];
            if (pageCounterOverlay) {
                pageCounterOverlay.style.display = 'none';
            }
        };

        if (pageWrapper) {
            pageWrapper.addEventListener('scroll', updatePageCounter);
        } else {
            window.addEventListener('scroll', updatePageCounter);
        }
        window.addEventListener('resize', scheduleFooterRender);
        document.addEventListener('DOMContentLoaded', scheduleFooterRender);
        scheduleFooterRender();

        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                event.preventDefault();
                generatePdf();
            }
        });

        function generatePdf() {
            const element = document.getElementById('record-document');
            const filename = "{{ $pdfFilename ?? 'case-record.pdf' }}";

            const opt = {
                margin: 0,
                filename,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, scrollY: 0, willReadFrequently: true, logging: false },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: {
                    mode: ['css', 'legacy'],
                    avoid: ['.section']
                }
            };

            const btn = document.getElementById('download-pdf');
            const originalText = btn ? btn.innerText : '';
            if (btn) {
                btn.innerText = '{{ __('court::recordes.messages.generating') }}';
                btn.disabled = true;
            }

            exporting = true;
            document.body.classList.add('pdf-export');
            cleanupPreviewArtifacts();

            // Build a detached, hidden export tree to avoid MutationObserver conflicts
            const exportHost = document.createElement('div');
            exportHost.id = 'record-export-root';
            exportHost.style.position = 'fixed';
            exportHost.style.left = '-99999px';
            exportHost.style.top = '0';
            exportHost.style.width = '1px';
            exportHost.style.height = '1px';
            const exportNode = element.cloneNode(true);
            exportNode.id = 'record-document-export';
            exportHost.appendChild(exportNode);
            document.body.appendChild(exportHost);

            const worker = html2pdf()
                .set(opt)
                .from(exportNode);

            worker.toPdf().get('pdf').then((pdf) => {
                const totalPages = pdf.internal.getNumberOfPages();
                const pageWidth = pdf.internal.pageSize.getWidth();
                const pageHeight = pdf.internal.pageSize.getHeight();
                pdf.setFontSize(10);
                for (let i = 1; i <= totalPages; i++) {
                    pdf.setPage(i);
                    pdf.text(
                        `Page ${i} of ${totalPages}`,
                        pageWidth / 2,
                        pageHeight - 10,
                        { align: 'center' }
                    );
                }
            });

            worker.save().catch((e) => {
                console.error('html2pdf failed', e);
            }).finally(() => {
                exportHost.remove();
                if (btn) {
                    btn.innerText = originalText;
                    btn.disabled = false;
                }
                exporting = false;
                document.body.classList.remove('pdf-export');
                scheduleFooterRender();
            });
        }

        const applicantPdfData = @json($firstEvidenceEmbedData);
        const responsePdfEmbeds = @json($responsePdfEmbeds ?? []);

        function renderApplicantPdfFallback(container) {
            if (!applicantPdfData) {
                container.innerHTML = '<div class="loading-text">{{ __('court::recordes.descriptions.applicant_pdf_missing') }}</div>';
                scheduleFooterRender();
                return;
            }

            const iframe = document.createElement('iframe');
            iframe.className = 'fallback-pdf-frame';
            iframe.title = '{{ __('court::recordes.labels.applicant_initial_pdf') }}';
            iframe.scrolling = 'no';
            iframe.src = 'data:{{ $firstEvidenceEmbedMime }};base64,' + applicantPdfData + '#toolbar=0&navpanes=0&scrollbar=0';
            container.innerHTML = '';
            container.appendChild(iframe);
            scheduleFooterRender();
        }

        async function renderApplicantPdf() {
            const container = document.getElementById('applicant-pdf-viewer');

            if (!container || !applicantPdfData) {
                return;
            }

            if (!window.pdfjsLib) {
                renderApplicantPdfFallback(container);
                return;
            }

            try {
                const pdfBytes = Uint8Array.from(atob(applicantPdfData), char => char.charCodeAt(0));

                if (!pdfjsLib.GlobalWorkerOptions.workerSrc) {
                    pdfjsLib.GlobalWorkerOptions.workerSrc = "{{ asset('vendor/pdfjs/pdf.worker.min.js') }}";
                }

                const pdf = await pdfjsLib.getDocument({ data: pdfBytes }).promise;

                container.innerHTML = '';

                for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                    const page = await pdf.getPage(pageNum);
                    const viewport = page.getViewport({ scale: 1.3 });
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    await page.render({ canvasContext: context, viewport }).promise;
                    container.appendChild(canvas);
                }
                scheduleFooterRender();
            } catch (error) {
                console.error('Applicant PDF render failed', error);
                renderApplicantPdfFallback(container);
            }
        }

        if (applicantPdfData) {
            window.addEventListener('load', renderApplicantPdf);
        }

        document.addEventListener('DOMContentLoaded', () => {
            initializeInlineLetterPreviews();
        });

        function renderResponsePdfFallback(container, embed) {
            if (!container) return;
            const iframe = document.createElement('iframe');
            iframe.className = 'fallback-pdf-frame';
            iframe.title = embed.title || '{{ __('court::recordes.labels.respondent_responses') }}';
            iframe.scrolling = 'no';
            iframe.src = `data:${embed.mime || 'application/pdf'};base64,${embed.data}#toolbar=0&navpanes=0&scrollbar=0`;
            container.innerHTML = '';
            container.appendChild(iframe);
        }

        async function renderResponsePdf(embed) {
            const container = document.getElementById(embed.id);
            if (!container || !embed.data) {
                return;
            }

            if (!window.pdfjsLib) {
                renderResponsePdfFallback(container, embed);
                return;
            }

            try {
                const pdfBytes = Uint8Array.from(atob(embed.data), char => char.charCodeAt(0));
                if (!pdfjsLib.GlobalWorkerOptions.workerSrc) {
                    pdfjsLib.GlobalWorkerOptions.workerSrc = "{{ asset('vendor/pdfjs/pdf.worker.min.js') }}";
                }
                const pdf = await pdfjsLib.getDocument({ data: pdfBytes }).promise;
                container.innerHTML = '';
                for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                    const page = await pdf.getPage(pageNum);
                    const viewport = page.getViewport({ scale: 1.2 });
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    await page.render({ canvasContext: context, viewport }).promise;
                    container.appendChild(canvas);
                }
            } catch (error) {
                console.error('Response PDF render failed', error);
                renderResponsePdfFallback(container, embed);
            } finally {
                scheduleFooterRender();
            }
        }

        function renderAllResponsePdfs() {
            if (!responsePdfEmbeds.length) {
                return;
            }
            (async () => {
                for (const embed of responsePdfEmbeds) {
                    await renderResponsePdf(embed);
                }
            })();
        }

        if (responsePdfEmbeds.length) {
            window.addEventListener('load', renderAllResponsePdfs);
        }

        function initializeInlineLetterPreviews() {
            const wrappers = document.querySelectorAll('[data-letter-preview-id]');
            if (!wrappers.length) {
                return;
            }

            wrappers.forEach(wrapper => renderLetterPreview(wrapper));
        }

        function renderLetterPreview(wrapper) {
            const previewId = wrapper.getAttribute('data-letter-preview-id');
            if (!previewId) return;

            const previewContainer = document.getElementById(`preview-container-${previewId}`);
            const sheetTemplate = document.getElementById(`sheet-template-${previewId}`);
            const rawContent = document.getElementById(`raw-content-${previewId}`);
            const rawBody = document.getElementById(`raw-body-content-${previewId}`);

            if (!previewContainer || !sheetTemplate || !rawContent || !rawBody) {
                return;
            }

            function splitTextNode(node) {
                if (node.nodeType !== Node.ELEMENT_NODE || ['TABLE', 'IMG', 'UL', 'OL'].includes(node.tagName)) {
                    return [node.cloneNode(true)];
                }

                const text = node.innerText || '';
                const words = text.trim().split(/\s+/);
                if (words.length < 50) {
                    return [node.cloneNode(true)];
                }

                const chunks = [];
                let currentChunk = [];

                words.forEach((word, index) => {
                    currentChunk.push(word);
                    if (currentChunk.length >= 40 || index === words.length - 1) {
                        const p = document.createElement('p');
                        p.className = node.className;
                        p.style.cssText = node.style.cssText;
                        p.style.marginBottom = '0';
                        p.style.textAlign = 'justify';
                        p.innerText = currentChunk.join(' ');
                        chunks.push(p);
                        currentChunk = [];
                    }
                });

                return chunks;
            }

            const contentQueue = [];

            rawContent.querySelectorAll('.content-block[data-role="before-body"]').forEach(el => {
                contentQueue.push(el.cloneNode(true));
            });

            Array.from(rawBody.children).forEach(child => {
                splitTextNode(child).forEach(part => contentQueue.push(part));
            });

            rawContent.querySelectorAll('.content-block[data-role="after-body"]').forEach(el => {
                contentQueue.push(el.cloneNode(true));
            });

            function createNewPage() {
                const clone = sheetTemplate.content.cloneNode(true);
                const sheet = clone.querySelector('.a4-sheet');
                const contentSlot = clone.querySelector('.content-slot');
                previewContainer.appendChild(sheet);
                return { sheet, contentSlot };
            }

            function getAvailableHeight(sheetElement) {
                const header = sheetElement.querySelector('.letter-header');
                const footer = sheetElement.querySelector('.letter-footer');
                const bodyContainer = sheetElement.querySelector('.letter-body-container');
                if (!bodyContainer) {
                    return sheetElement.clientHeight;
                }
                const style = window.getComputedStyle(bodyContainer);
                const padding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
                return sheetElement.clientHeight - header.offsetHeight - footer.offsetHeight - padding - 5;
            }

            let currentPage = createNewPage();
            let maxHeight = getAvailableHeight(currentPage.sheet);

            contentQueue.forEach(node => {
                const clone = node.cloneNode(true);
                currentPage.contentSlot.appendChild(clone);

                if (currentPage.contentSlot.offsetHeight > maxHeight) {
                    currentPage.contentSlot.removeChild(clone);
                    currentPage = createNewPage();
                    maxHeight = getAvailableHeight(currentPage.sheet);
                    currentPage.contentSlot.appendChild(clone);
                }
            });
        }

    </script>
</body>
</html>
