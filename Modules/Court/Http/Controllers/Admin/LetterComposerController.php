<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\LetterTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LetterComposerController extends Controller
{
    /**
     * The compose page (React, Court/Letters/Compose): the templates to load,
     * the case the letter is for (`case_id`), the reference it will get, and
     * the fields as last submitted when a save was refused.
     */
    public function create(Request $request): Response
    {
        $templates = LetterTemplate::query()->orderBy('title')->get();

        $selectedTemplate = null;
        if ($request->filled('template_id')) {
            $selectedTemplate = $templates->firstWhere('id', (int) $request->input('template_id'));
        }

        // Preload the case number when coming from a case view.
        $caseNumber = null;
        if ($request->filled('case_id')) {
            $caseNumber = DB::table('court_cases')->where('id', (int) $request->input('case_id'))->value('case_number');
        }
        $caseNumber = $caseNumber !== null ? (string) $caseNumber : null;

        $body = old('body');
        if ($body === null && $selectedTemplate) {
            $body = $selectedTemplate->body;
        }

        $formCaseNumber = (string) old('case_number', $caseNumber ?? '');

        return Inertia::render('Court/Letters/Compose', [
            'templates' => $templates->map(fn (LetterTemplate $template): array => $this->templateChoice($template))->values()->all(),
            'selectedTemplateId' => $selectedTemplate ? (int) $selectedTemplate->id : null,
            'caseNumber' => $caseNumber,
            'nextReference' => $this->nextReference($formCaseNumber !== '' ? $formCaseNumber : $caseNumber),
            'initial' => [
                'recipient_name' => (string) old('recipient_name', ''),
                'recipient_title' => (string) old('recipient_title', ''),
                'recipient_company' => (string) old('recipient_company', ''),
                'case_number' => $formCaseNumber,
                'cc' => (string) old('cc', ''),
                'send_to_applicant' => filter_var(old('send_to_applicant', '1'), FILTER_VALIDATE_BOOLEAN),
                'send_to_respondent' => filter_var(old('send_to_respondent', '1'), FILTER_VALIDATE_BOOLEAN),
                'subject' => (string) old('subject', ''),
                'body' => (string) ($body ?? ''),
            ],
        ]);
    }

    /** @return array{id: int, title: string, body: string, category: ?string, placeholders: list<string>} */
    private function templateChoice(LetterTemplate $template): array
    {
        $placeholders = $template->placeholders ?? [];
        if (is_string($placeholders)) {
            $placeholders = json_decode($placeholders, true) ?: [];
        }

        return [
            'id' => (int) $template->id,
            'title' => (string) $template->title,
            'body' => (string) ($template->body ?? ''),
            'category' => $template->category ?: null,
            'placeholders' => array_values(array_map('strval', is_array($placeholders) ? $placeholders : [])),
        ];
    }

    /** "<case number>/<nn>": the reference the next letter of this case will carry (LetterController::store assigns the final one). */
    private function nextReference(?string $caseNumber): ?string
    {
        $caseNumber = trim((string) $caseNumber);
        if ($caseNumber === '') {
            return null;
        }

        $last = DB::table('court_letters')->where('case_number', $caseNumber)->orderBy('id', 'desc')->first();
        $nextSeq = ($last && preg_match('/\/(\d{2})$/', (string) $last->reference_number, $m)) ? intval($m[1]) + 1 : 1;

        return $caseNumber.'/'.str_pad((string) $nextSeq, 2, '0', STR_PAD_LEFT);
    }
}
