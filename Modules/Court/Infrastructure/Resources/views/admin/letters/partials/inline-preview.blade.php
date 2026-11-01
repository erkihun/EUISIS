@php
    $template = data_get($letter, 'template');
    if (!$template && class_exists(\Modules\Court\Domain\Models\LetterTemplate::class)) {
        $templateId = data_get($letter, 'letter_template_id') ?? data_get($letter, 'template_id');
        if (!empty($templateId)) {
            $template = \Modules\Court\Domain\Models\LetterTemplate::find($templateId);
        }
    }

    $systemSettings = isset($systemSettings) ? $systemSettings : \Modules\Court\Domain\Models\SystemSetting::query()->first();

    $resolvePublicUrl = static function ($path) {
        if (empty($path)) {
            return null;
        }

        $trimmed = ltrim($path);
        if (\Illuminate\Support\Str::startsWith($trimmed, ['http://', 'https://', 'data:'])) {
            return $trimmed;
        }

        $publicDiskPath = $trimmed;
        if (\Illuminate\Support\Str::startsWith($publicDiskPath, ['/storage/', 'storage/'])) {
            $publicDiskPath = preg_replace('#^/?storage/#', '', $publicDiskPath) ?? $publicDiskPath;
        } elseif (\Illuminate\Support\Str::startsWith($publicDiskPath, ['public/'])) {
            $publicDiskPath = substr($publicDiskPath, 7);
        }

        if (\Illuminate\Support\Facades\Storage::disk('court_public')->exists($publicDiskPath)) {
            $absolutePath = \Illuminate\Support\Facades\Storage::disk('court_public')->path($publicDiskPath);
            $mimeType = mime_content_type($absolutePath) ?: 'application/octet-stream';
            $contents = @file_get_contents($absolutePath);

            if ($contents !== false) {
                return 'data:' . $mimeType . ';base64,' . base64_encode($contents);
            }
        }

        $publicCandidates = [
            public_path(ltrim($trimmed, '/')),
            public_path('storage/' . ltrim($publicDiskPath, '/')),
        ];

        foreach ($publicCandidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $mimeType = mime_content_type($candidate) ?: 'application/octet-stream';
            $contents = @file_get_contents($candidate);
            if ($contents !== false) {
                return 'data:' . $mimeType . ';base64,' . base64_encode($contents);
            }
        }

        return null;
    };

    $headerImage = $resolvePublicUrl(optional($template)->header_image_path ?? null);
    $footerImage = $resolvePublicUrl(optional($template)->footer_image_path ?? null);
    $sealPath = $resolvePublicUrl(optional($systemSettings)->seal_path);

    $isApproved = ($letter->approval_status === 'approved');
    $displaySeal = $isApproved && !empty($sealPath);

    $authorName = \Illuminate\Support\Str::of(data_get($letter, 'author.name', ''))->trim();
    $authorSignature = $resolvePublicUrl(data_get($letter, 'author.signature_url'));
    $authorPosition = data_get($letter, 'author.position') ?? data_get($letter, 'author.title');
    $displayName = $isApproved ? ($letter->approved_by_name ?: $authorName) : '';
    $displayTitle = $isApproved ? ($letter->approved_by_title ?: $authorPosition ?? '') : '';
    $caseParty = null;
    $recipientName = trim((string) ($letter->recipient_name ?? ''));
    if (!empty($letter->case_number)) {
        $caseParty = \Illuminate\Support\Facades\DB::table('court_cases as c')
            ->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
            ->select(
                'c.respondent_name',
                'c.title as applicant_name'
            )
            ->where('c.case_number', $letter->case_number)
            ->first();
    }
    $applicantLabel = mb_strtolower(trim((string) __('court::letters.form.deliver_applicant')));
    $respondentLabel = mb_strtolower(trim((string) __('court::letters.form.deliver_respondent')));
    $parts = array_values(array_filter(array_map('trim', explode(',', mb_strtolower($recipientName)))));
    $isGenericRecipient = $recipientName !== '' && !empty($parts)
        && collect($parts)->every(fn($p) => in_array($p, [$applicantLabel, $respondentLabel, 'applicant', 'respondent'], true));
    if ($recipientName === '' || $isGenericRecipient) {
        $recipientTargets = [];
        if ((bool) ($letter->send_to_applicant ?? false)) {
            $recipientTargets[] = trim((string) ($caseParty->applicant_name ?? '')) ?: __('court::letters.form.deliver_applicant');
        }
        if ((bool) ($letter->send_to_respondent ?? false)) {
            $recipientTargets[] = trim((string) ($caseParty->respondent_name ?? '')) ?: __('court::letters.form.deliver_respondent');
        }
        $recipientName = implode(', ', $recipientTargets);
    }
    $recipientLines = array_values(array_filter(array_map('trim', explode(',', (string) $recipientName))));

    if ($isApproved && (empty($displayName) || empty($authorSignature) || empty($displayTitle))) {
        $authorId = data_get($letter, 'user_id') ?? data_get($letter, 'author_id');
        if ($authorId && class_exists(\Modules\Court\Domain\Models\CourtStaffMember::class)) {
            $authorModel = \Modules\Court\Domain\Models\CourtStaffMember::query()->find($authorId);
            if ($authorModel) {
                if (empty($displayName)) {
                    $displayName = $authorModel->name ?? $displayName;
                }
                if (empty($displayTitle)) {
                    $displayTitle = $authorModel->position ?? $authorModel->title ?? $displayTitle;
                }
                if (empty($authorSignature) && !empty($authorModel->signature_url)) {
                    $authorSignature = $resolvePublicUrl($authorModel->signature_url);
                }
            }
        }
    }

    $recipientCompanies = $letter->recipient_company
        ? array_filter(array_map('trim', explode(',', $letter->recipient_company)))
        : [];

    $ccRecipients = $letter->cc
        ? array_filter(array_map('trim', explode(',', $letter->cc)))
        : [];

    $safeBody = \Modules\Court\Application\Support\Purifier::clean($letter->body ?? '', 'default');

    if (class_exists(\Modules\Court\Application\Support\EthiopianDate::class)) {
        $letterDate = \Modules\Court\Application\Support\EthiopianDate::format($letter->created_at ?? now());
    } else {
        $letterDate = optional($letter->created_at)->toFormattedDateString() ?? now()->toFormattedDateString();
    }

    $qrPayload = implode('|', [
        'REF:' . \Illuminate\Support\Str::limit($letter->reference_number ?? 'NA', 20, ''),
        'CASE:' . \Illuminate\Support\Str::limit($letter->case_number ?? 'NA', 20, ''),
        'STATUS:' . ($letter->approval_status ?? 'pending'),
        'DATE:' . $letterDate,
    ]);

    $qrSvg = null;
    try {
        if (class_exists(\Modules\Court\Application\Support\QrCode::class)) {
            $qrSvg = \Modules\Court\Application\Support\QrCode::format('svg')
                ->encoding('UTF-8')
                ->size(120)
                ->errorCorrection('L')
                ->generate($qrPayload);
        } elseif (class_exists(\BaconQrCode\Writer::class)) {
            $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(120),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            );
            $writer = new \BaconQrCode\Writer($renderer);
            $qrSvg = $writer->writeString($qrPayload);
        }
    } catch (\Throwable $qrEx) {
        \Illuminate\Support\Facades\Log::debug('Letter QR generation failed for record preview', [
            'letter_id' => $letter->id ?? null,
            'error' => $qrEx->getMessage(),
        ]);
        $qrSvg = null;
    }

    $previewId = 'letter-preview-' . $letter->id;
@endphp

<div class="letter-preview-container letter-live-preview" data-letter-preview-id="{{ $previewId }}">

        <div id="preview-container-{{ $previewId }}" class="preview-wrapper"></div>

        <div id="raw-content-{{ $previewId }}" style="display:none;">
            <div class="content-block" data-role="before-body">
                <div class="meta-row content-meta">
                    <div>
                        <strong>{{ __('court::letters.preview.ref_no') }}</strong>
                        {{ $letter->reference_number ?? __('court::letters.cards.missing') }}
                    </div>
                    <div>
                        <strong>{{ __('court::letters.preview.date') }}</strong> {{ $letterDate }}
                    </div>
                </div>
            </div>

            <div class="content-block" data-role="before-body">
                <div class="recipient-block">
                    @foreach($recipientCompanies as $company)
                        <div class="pl-4"><span class="mr-3 font-bold">ሰ</span> {{ $company }}</div>
                    @endforeach
                </div>
                @if(!empty($recipientLines))
                    <ul class="list-disc ml-6 mt-1">
                        @foreach($recipientLines as $line)
                            <li><strong>{{ $line }}</strong></li>
                        @endforeach
                    </ul>
                @else
                    <strong style="text-decoration: underline;">—</strong>
                @endif
            </div>

            <div class="content-block" data-role="before-body">
                <div class="subject-line">
                    {{ __('court::letters.preview.subject') }}
                    {{ $letter->subject ?: ($template->title ?? __('court::letters.cards.untitled')) }}
                </div>
            </div>

            <div id="raw-body-content-{{ $previewId }}">
                {!! $safeBody !!}
            </div>

            <div class="content-block" data-role="after-body">
                <div class="closing-block">
                    @if($isApproved)
                        <div class="signature-space">
                            <div class="inline-flex items-end gap-6">
                                @if($authorSignature)
                                    <div class="flex flex-col items-center gap-1">
                                        <img src="{{ $authorSignature }}" alt="{{ __('court::letters.preview.author_signature') }}"
                                            style="max-height:50px; max-width:160px; object-fit:contain;">
                                    </div>
                                @endif
                            </div>
                        </div>
                        <p style="margin-top: 0.5rem;">
                            <strong>{{ $displayName }}</strong><br>
                            {{ $displayTitle }}
                        </p>
                    @endif
                </div>
            </div>

            @if(count($ccRecipients) > 0)
                <div class="content-block" data-role="after-body">
                    <div class="cc-block">
                        <strong>{{ __('court::letters.preview.cc_recipients') }}</strong>
                        <ul class="list-disc mt-1 ml-5">
                            @foreach($ccRecipients as $cc)
                                <li>{{ $cc }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>

        <template id="sheet-template-{{ $previewId }}">
            <div class="a4-sheet">
                <div class="letter-header">
                    @if($headerImage)
                        <img src="{{ $headerImage }}" crossorigin="anonymous" alt="{{ __('court::letters.preview.header_image_alt') }}">
                    @else
                        <div style="height: 20mm;"></div>
                    @endif
                </div>

                <div class="letter-body-container">
                    <div class="content-slot"></div>
                </div>

                @if($displaySeal)
                    <div class="page-seal-bottom">
                        <img src="{{ $sealPath }}" crossorigin="anonymous" alt="{{ __('court::letters.preview.official_seal_alt') }}">
                    </div>
                @endif

                <div class="letter-footer">
                    @if($footerImage)
                        <img src="{{ $footerImage }}" crossorigin="anonymous" alt="{{ __('court::letters.preview.footer_image_alt') }}">
                    @endif
                    @if($qrSvg)
                        <div class="qr-block">{!! $qrSvg !!}</div>
                    @endif
                </div>
            </div>
        </template>
  
</div>

