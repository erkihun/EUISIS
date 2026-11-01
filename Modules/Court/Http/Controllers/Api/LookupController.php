<?php

namespace Modules\Court\Http\Controllers\Api;

use Modules\Court\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class LookupController extends Controller
{
    public function caseTypes()
    {
        $types = DB::table('court_case_types')
            ->select('id', 'name', 'prefix')
            ->orderBy('name')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $types,
        ]);
    }
}
