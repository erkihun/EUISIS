<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Letters\Application\Services\LetterVerification;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Models\Letter;

/**
 * The public check of a printed letter (no sign-in): the QR code on the
 * letter leads here. It confirms the issuing institution, number, date and
 * that the letter was dispatched; it never shows the text, the recipient or
 * any file. Unknown codes answer the same page with "not found", so the code
 * space cannot be probed for anything useful.
 */
final class VerificationController extends Controller
{
    public function __invoke(Request $request, string $code): View
    {
        $code = LetterVerification::normalise($code);
        $letter = strlen($code) === 12
            ? Letter::query()->with(['organization:id,code,name_en,name_am', 'registrations'])->where('verification_code', $code)->where('status', LetterStatus::Dispatched->value)->first()
            : null;
        $locale = in_array($request->query('lang'), ['am', 'en'], true) ? (string) $request->query('lang') : ($letter?->language === 'en' ? 'en' : 'am');

        return view('letters.verify', [
            'code' => $code,
            'locale' => $locale,
            'letter' => $letter,
            'number' => $letter?->referenceNumber(),
        ]);
    }
}
