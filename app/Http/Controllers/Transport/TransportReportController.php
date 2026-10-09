<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\TransportTransaction;
use Inertia\Inertia;
use Inertia\Response;

class TransportReportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Transport/Reports/Index', [
            'summary' => [
                'total' => TransportTransaction::query()->count(),
                'accepted' => TransportTransaction::query()->where('status', 'accepted')->count(),
                'rejected' => TransportTransaction::query()->where('status', 'rejected')->count(),
            ],
        ]);
    }
}
