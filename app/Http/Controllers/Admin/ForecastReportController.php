<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Forecast\ForecastReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForecastReportController extends Controller
{
    public function index(Request $request, ForecastReport $report): JsonResponse
    {
        $validated = $request->validate(['minimum_probability' => ['sometimes', 'numeric', 'between:0,100']]);
        $minimum = (float) ($validated['minimum_probability'] ?? 60);

        return response()->json(['minimum_probability' => $minimum, 'groups' => $report->build($minimum)]);
    }
}
