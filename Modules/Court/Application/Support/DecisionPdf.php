<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\DecisionTemplate;
use Modules\Court\Domain\Models\SystemSetting;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class DecisionPdf
{
    /**
     * Build the decision PDF using the default template layout. Stamps the
     * official seal when the decision is approved. Returns a dompdf instance.
     */
    public static function render(Decision $decision, ?DecisionTemplate $template = null)
    {
        $decision->loadMissing('courtCase.judge');

        $template = $template ?? DecisionTemplate::default();

        $body = $template
            ? self::fillPlaceholders($template->body, $decision)
            : (string) ($decision->decision_content ?? '');

        $sealPath = null;
        if ($decision->isApproved()) {
            $seal = SystemSetting::current()->seal_path ?? null;
            if ($seal && Storage::disk('court_public')->exists($seal)) {
                $sealPath = public_path('storage/' . $seal);
            }
        }

        $displayPanel = $decision->panelJudgesAuthorFirst();
        $signaturePaths = self::resolveJudgeSignaturePaths($decision, $displayPanel);

        return Pdf::loadView('court::pdf.decision-output', [
            'decision' => $decision,
            'template' => $template,
            'body'     => $body,
            'sealPath' => $sealPath,
            'displayPanel' => $displayPanel,
            'signaturePaths' => $signaturePaths,
        ])->setPaper('a4');
    }

    public static function filename(Decision $decision): string
    {
        $safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($decision->case_number ?: $decision->id));

        return 'decision-' . trim($safeNumber, '-') . '.pdf';
    }

    /**
     * Replace {{placeholder}} tokens in a template body with decision values.
     */
    public static function fillPlaceholders(string $body, Decision $decision): string
    {
        $panel = $decision->panelJudgesAuthorFirst();

        $judgeName = static fn (array $panel, int $i): string => (string) ($panel[$i]['admin_user_name'] ?? '');
        $judgeVote = static fn (array $panel, int $i): string => (string) ($panel[$i]['vote'] ?? '');

        $replacements = [
            'applicant_name'     => (string) ($decision->applicant_full_name ?? ''),
            'respondent_name'    => (string) ($decision->respondent_full_name ?? ''),
            'case_number'        => (string) ($decision->case_number ?? ''),
            'case_file_number'   => (string) ($decision->case_file_number ?? ''),
            'judge_name'         => (string) ($decision->courtCase?->judge?->name ?? ''),
            'decision_date'      => EthiopianDate::format($decision->decision_date, fallback: ''),
            'decision_content'   => (string) ($decision->decision_content ?? ''),
            'decision_name'      => (string) ($decision->name ?? ''),
            'judge_one'          => $judgeName($panel, 0),
            'judge_two'          => $judgeName($panel, 1),
            'judge_three'        => $judgeName($panel, 2),
            'judge_one_vote'     => $judgeVote($panel, 0),
            'judge_two_vote'     => $judgeVote($panel, 1),
            'judge_three_vote'   => $judgeVote($panel, 2),
        ];

        foreach ($replacements as $key => $value) {
            $body = preg_replace('/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/', $value, $body);
        }

        return $body;
    }

    /**
     * Resolve uploaded panel signatures for a final decision PDF.
     *
     * @return array<int, string|null>
     */
    private static function resolveJudgeSignaturePaths(Decision $decision, array $panel): array
    {
        if (! $decision->isPublished()) {
            return [];
        }

        $judgeIds = collect($panel)
            ->take(3)
            ->pluck('admin_user_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $judges = User::query()
            ->whereKey($judgeIds)
            ->get(['id', 'signature_path'])
            ->keyBy('id');
        $paths = [];

        foreach (array_slice($panel, 0, 3) as $index => $panelJudge) {
            $judge = $judges->get((int) ($panelJudge['admin_user_id'] ?? 0));
            $signaturePath = $judge?->signature_path;

            $paths[$index] = $signaturePath && Storage::disk('court_public')->exists($signaturePath)
                ? Storage::disk('court_public')->path($signaturePath)
                : null;
        }

        return $paths;
    }
}
