<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only report pages. Every figure is computed server-side through
 * ReportScope; the page only renders the returned sections.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportExportService $exports,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Reports/Index', $this->reports->index($request->user()));
    }

    public function show(Request $request, string $report): Response
    {
        $user = $request->user();
        $page = $this->reports->page($user, $report, $request->query());

        return Inertia::render('Reports/Show', [
            ...$page,
            'exports' => $page['context']['can_export'] ? $this->exports->recent($user) : [],
        ]);
    }
}
