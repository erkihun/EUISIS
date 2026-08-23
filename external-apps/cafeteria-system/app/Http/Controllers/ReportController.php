<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Controllers;

use CafeteriaSystem\Services\CafeteriaReportService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly CafeteriaReportService $reports) {}

    public function index(Request $request): Response
    {
        [$from, $to, $granularity] = $this->range($request);
        $providerId = (string) $request->user('cafeteria')->provider_id;

        return Inertia::render('Reports/Index', [
            'summary' => $this->reports->summary($providerId, $from, $to, $granularity),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'granularity' => $granularity,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $rows = $this->reports->exportRows((string) $request->user('cafeteria')->provider_id, $from, $to);
        $filename = 'cafeteria-report-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'Transaction', 'Employee No', 'Employee', 'Organization',
                'Card Status', 'Status', 'Meal', 'Subsidy', 'Payable', 'Served At',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }

            fclose($handle);
        }, $filename);
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface, 2: string} */
    private function range(Request $request): array
    {
        return [
            $request->date('from') ?? now()->startOfMonth(),
            $request->date('to') ?? now()->endOfDay(),
            $request->string('granularity')->toString() ?: 'day',
        ];
    }
}
