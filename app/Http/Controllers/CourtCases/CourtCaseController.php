<?php

declare(strict_types=1);

namespace App\Http\Controllers\CourtCases;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entry point of the planned Court Cases module (docs/court-cases.md).
 *
 * The page states that the module is planned. It reads no data: court case
 * records, parties, hearings and decisions do not exist until the module is
 * designed.
 */
class CourtCaseController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('court_cases.view'), 403);

        return Inertia::render('CourtCases/Index');
    }
}
