<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report CSV exports. The service enforces report.export (403 + audit for
 * anyone else, whatever the UI shows) and owner-only, time-limited downloads.
 */
class ReportExportController extends Controller
{
    public function __construct(private readonly ReportExportService $exports) {}

    public function store(Request $request, string $report): RedirectResponse
    {
        $data = $request->validate([
            'section' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'filters' => ['nullable', 'array'],
        ]);

        $export = $this->exports->request($request->user(), $report, $data['section'], $data['filters'] ?? []);

        if ($export->isDownloadable()) {
            return back()->with('success', 'Your export is ready.')->with('download', route('reports.exports.download', $export));
        }

        return back()->with('success', 'Your export is being prepared. It will appear under "My exports" when ready.');
    }

    public function download(Request $request, ReportExport $export): StreamedResponse
    {
        return $this->exports->download($export, $request->user());
    }
}
