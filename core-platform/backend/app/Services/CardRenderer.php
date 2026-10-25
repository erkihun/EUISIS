<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ManageDigitalCredentials;
use App\Models\EmployeeDocument;
use App\Models\IdCard;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

/**
 * The one card renderer (ADR 0010): the template preview, the card preview
 * and the print sheet all draw a card here, as SVG at CR80 proportions
 * (1012×638 at 300 dpi). Every text is escaped; images are embedded only as
 * data URIs of the employee's own current photo and signature and of the QR,
 * which carries the check address and the credential token — no personal data.
 */
final class CardRenderer
{
    public function __construct(private readonly CoreSettings $settings, private readonly ManageDigitalCredentials $credentials) {}

    /** @param array<string, mixed> $config @param array<string, ?string> $data */
    public function svg(array $config, array $data): string
    {
        $landscape = ($config['orientation'] ?? 'landscape') === 'landscape';
        [$w, $h] = $landscape ? [1012, 638] : [638, 1012];
        $c = $config['colors'] ?? [];
        $show = array_flip((array) ($config['fields'] ?? []));
        $e = fn (?string $value): string => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $font = "font-family=\"'Noto Sans Ethiopic','Nyala','Segoe UI',sans-serif\"";
        $headerEn = ($config['header_en'] ?? '') !== '' ? $config['header_en'] : (string) $this->settings->get('id_cards.city_name_en');
        $headerAm = ($config['header_am'] ?? '') !== '' ? $config['header_am'] : (string) $this->settings->get('id_cards.city_name_am');

        $parts = [
            "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$w}\" height=\"{$h}\" viewBox=\"0 0 {$w} {$h}\" role=\"img\" aria-label=\"{$e(($data['full_name'] ?? '').' '.($data['card_number'] ?? ''))}\">",
            "<rect width=\"{$w}\" height=\"{$h}\" rx=\"28\" fill=\"{$e($c['background'] ?? '#FFFFFF')}\"/>",
            "<rect width=\"{$w}\" height=\"112\" rx=\"28\" fill=\"{$e($c['primary'] ?? '#1F4E79')}\"/><rect y=\"84\" width=\"{$w}\" height=\"28\" fill=\"{$e($c['primary'] ?? '#1F4E79')}\"/>",
            "<text x=\"{$this->half($w)}\" y=\"48\" text-anchor=\"middle\" fill=\"#FFFFFF\" font-size=\"30\" font-weight=\"700\" {$font}>{$e($headerAm)}</text>",
            "<text x=\"{$this->half($w)}\" y=\"88\" text-anchor=\"middle\" fill=\"#FFFFFF\" font-size=\"24\" {$font}>{$e($headerEn)}</text>",
        ];

        [$px, $py, $pw, $ph] = $landscape ? [40, 140, 240, 300] : [199, 140, 240, 300];
        if (isset($show['photo'])) {
            $parts[] = ($data['photo'] ?? null) !== null
                ? "<image href=\"{$e($data['photo'])}\" x=\"{$px}\" y=\"{$py}\" width=\"{$pw}\" height=\"{$ph}\" preserveAspectRatio=\"xMidYMid slice\"/>"
                : "<rect x=\"{$px}\" y=\"{$py}\" width=\"{$pw}\" height=\"{$ph}\" fill=\"#E5E7EB\"/>";
            $parts[] = "<rect x=\"{$px}\" y=\"{$py}\" width=\"{$pw}\" height=\"{$ph}\" fill=\"none\" stroke=\"{$e($c['accent'] ?? '#C9A227')}\" stroke-width=\"4\"/>";
        }

        $x = $landscape ? 310 : 50;
        $y = $landscape ? 170 : 490;
        $line = function (string $field, string $text, int $size, bool $bold = false) use (&$parts, &$y, $show, $x, $e, $font, $c): void {
            if (isset($show[$field]) && $text !== '') {
                $weight = $bold ? ' font-weight="700"' : '';
                $parts[] = "<text x=\"{$x}\" y=\"{$y}\" fill=\"{$e($c['text'] ?? '#111827')}\" font-size=\"{$size}\"{$weight} {$font}>{$e($text)}</text>";
                $y += (int) round($size * 1.45);
            }
        };
        $line('full_name', (string) ($data['full_name'] ?? ''), 32, true);
        $line('position', (string) ($data['position'] ?? ''), 24);
        $line('organization', (string) ($data['organization'] ?? ''), 24);
        $line('employee_number', 'መለያ / ID: '.($data['employee_number'] ?? ''), 24);
        $line('card_number', 'ቁጥር / No: '.($data['card_number'] ?? ''), 22);
        $line('issued_at', 'የተሰጠበት / Issued: '.($data['issued_at'] ?? '—'), 20);
        $line('expires_at', 'የሚያገለግለው እስከ / Valid until: '.($data['expires_at'] ?? '—'), 20);

        if (isset($show['signature']) && ($data['signature'] ?? null) !== null) {
            [$sx, $sy] = $landscape ? [310, 500] : [50, 860];
            $parts[] = "<image href=\"{$e($data['signature'])}\" x=\"{$sx}\" y=\"{$sy}\" width=\"200\" height=\"60\" preserveAspectRatio=\"xMidYMid meet\"/>";
        }
        if (isset($show['qr'])) {
            [$qx, $qy, $qs] = $landscape ? [790, 370, 190] : [420, 800, 180];
            $parts[] = ($data['qr_payload'] ?? null) !== null
                ? "<image href=\"data:image/svg+xml;base64,{$this->qr((string) $data['qr_payload'], $qs)}\" x=\"{$qx}\" y=\"{$qy}\" width=\"{$qs}\" height=\"{$qs}\"/>"
                : "<rect x=\"{$qx}\" y=\"{$qy}\" width=\"{$qs}\" height=\"{$qs}\" fill=\"none\" stroke=\"#9CA3AF\" stroke-dasharray=\"8 6\"/>";
        }

        $footer = trim(($config['footer_am'] ?? '').'  '.($config['footer_en'] ?? ''));
        if ($footer !== '') {
            $parts[] = '<rect y="'.($h - 44)."\" width=\"{$w}\" height=\"44\" fill=\"{$e($c['accent'] ?? '#C9A227')}\"/>";
            $parts[] = "<text x=\"{$this->half($w)}\" y=\"".($h - 15)."\" text-anchor=\"middle\" fill=\"#FFFFFF\" font-size=\"18\" {$font}>{$e($footer)}</text>";
        }
        $parts[] = '</svg>';

        return implode('', $parts);
    }

    /**
     * What a card shows: from the card's snapshot and the employee's record
     * now, with the employee's own current photo and signature, and the QR of
     * its active credential.
     *
     * @return array<string, ?string>
     */
    public function dataFor(IdCard $card): array
    {
        $card->loadMissing('employee.currentAssignment.organization', 'employee.currentAssignment.position');
        $employee = $card->employee;
        $assignment = $employee?->currentAssignment;
        $token = $this->credentials->tokenFor($card);

        return [
            'full_name' => $employee?->full_name,
            'position' => $assignment?->position?->title_en,
            'organization' => $assignment?->organization?->name_en,
            'employee_number' => $employee?->employee_number,
            'card_number' => $card->card_number,
            'issued_at' => $card->issued_at?->toDateString(),
            'expires_at' => $card->expires_at?->toDateString(),
            'photo' => $employee === null ? null : $this->image((string) $employee->id, EmployeeDocument::PHOTO),
            'signature' => $employee === null ? null : $this->image((string) $employee->id, EmployeeDocument::SIGNATURE),
            'qr_payload' => $token === null ? null : route('id-checker.show', $token),
        ];
    }

    /** @return array<string, string> made-up values for a template preview */
    public function sample(): array
    {
        return [
            'full_name' => 'Sample Employee', 'position' => 'Senior Officer', 'organization' => 'Sample Bureau',
            'employee_number' => 'EMP-000000', 'card_number' => 'CARD-0000-000000', 'issued_at' => now()->toDateString(), 'expires_at' => now()->addYears(2)->toDateString(),
            'qr_payload' => route('id-checker.index'),
        ];
    }

    private function image(string $employeeId, string $kind): ?string
    {
        $document = EmployeeDocument::query()->where('employee_id', $employeeId)->where('kind', $kind)->where('is_current', true)
            ->where('status', EmployeeDocument::VERIFIED)->first(['storage_disk', 'storage_path', 'mime_type']);
        if ($document === null || ! in_array($document->mime_type, ['image/jpeg', 'image/png'], true)) {
            return null;
        }
        try {
            $bytes = Storage::disk($document->storage_disk)->get($document->storage_path);
        } catch (\Throwable) {
            return null;
        }

        return $bytes === null ? null : 'data:'.$document->mime_type.';base64,'.base64_encode($bytes);
    }

    private function qr(string $payload, int $size): string
    {
        return base64_encode((new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($payload));
    }

    private function half(int $width): int
    {
        return intdiv($width, 2);
    }
}
