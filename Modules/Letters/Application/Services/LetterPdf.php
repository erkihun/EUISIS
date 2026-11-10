<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Support\Typography\DocumentFonts;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Letters\Domain\Models\Letter;

/**
 * Renders a composed letter on the institution's letterhead as PDF
 * (resources/views/letters/pdf.blade.php, Ethiopic fonts embedded through
 * pdf.partials.typography). The same rendering serves the preview, the
 * download and the copy frozen at dispatch.
 */
class LetterPdf
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly LetterVerification $verification,
        private readonly LetterheadResolver $letterheads,
        private readonly DocumentFonts $fonts,
    ) {}

    public function render(Letter $letter): string
    {
        $letter->loadMissing(['organization', 'unit', 'recipientOrganization', 'senderOrganization', 'signatory', 'registrations', 'attachments', 'copies.organization', 'inReplyTo']);
        $locale = $letter->language === 'en' ? 'en' : 'am';
        $context = $this->renderer->context($letter, $locale);

        $layout = $this->letterheads->resolve($this->letterheads->pick($letter->organization_id, $letter->letterhead_id), $letter->organization);

        return Pdf::loadView('letters.pdf', [
            'letter' => $letter,
            'locale' => $locale,
            'body' => $this->renderer->render($letter->body, $context),
            'subject' => $this->renderer->render($letter->subject, $context),
            'context' => $context,
            'qr' => $this->verification->qrDataUri($letter),
            'verifyUrl' => $this->verification->url($letter),
            'letterhead' => $layout,
            'fontCss' => LetterLayout::fontCss($this->fonts),
        ])->setPaper($layout['page']['paper'], $layout['page']['orientation'])->output();
    }

    public function fileName(Letter $letter): string
    {
        $number = $letter->referenceNumber();
        // Keep Ethiopic letters and digits in the file name; only separators are replaced.
        $base = $number !== null ? preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $number) : 'letter-'.substr($letter->id, 0, 8);

        return trim((string) $base, '-').'.pdf';
    }
}
