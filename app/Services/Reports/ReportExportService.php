<?php

namespace App\Services\Reports;

use App\Enums\AuditAction;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Support\LeadValue;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CSV exports of report tables. The rows come from the very same report
 * definition (same scope, same filters) as the screen. Files are written to
 * the private `local` disk, expire after report.export_retention_hours and
 * are only downloadable by their owner through an authenticated route.
 * Audit entries carry report name, filters and row count — never the data.
 */
class ReportExportService
{
    public const DISK = 'local';

    public const DIRECTORY = 'report-exports';

    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportRegistry $registry,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    /** @throws AuthorizationException */
    public function request(User $user, string $slug, string $section, array $input): ReportExport
    {
        $this->authorize($user, $slug, $section);

        $q = $this->reports->queries($user, $input);
        $report = $this->registry->resolve($slug, $q->scope);
        $count = $report->exportCount($q, new ReportLinks($q), $section) ?? throw new NotFoundHttpException;

        $export = new ReportExport([
            'report' => $slug,
            'section' => $section,
            'format' => 'csv',
            'filters_json' => $q->filters->toQuery(),
            'status' => ReportExport::QUEUED,
            'row_count' => $count,
        ]);
        $export->uuid = (string) Str::uuid();
        $export->user_id = $user->id;
        $export->save();

        if ($count <= $this->threshold()) {
            $this->generate($export);
        } else {
            GenerateReportExport::dispatch($export->id);
        }

        return $export->refresh();
    }

    public function generate(ReportExport $export): void
    {
        $export->forceFill(['status' => ReportExport::PROCESSING])->save();

        try {
            $user = $export->user()->firstOrFail();
            if (! $user->is_active || ! $user->hasPermission(Permissions::REPORT_EXPORT)) {
                throw new AuthorizationException('Export permission was removed before the file was generated.');
            }

            $q = $this->reports->queries($user, $export->filters_json ?? []);
            $report = $this->registry->resolve($export->report, $q->scope);
            $table = LeadValue::table($report->exportTable($q, new ReportLinks($q), $export->section) ?? throw new NotFoundHttpException);

            $path = self::DIRECTORY.'/'.$export->uuid.'.csv';
            $rows = $this->writeCsv($path, $table['columns'], $table['rows']);

            $export->forceFill([
                'status' => ReportExport::READY,
                'disk' => self::DISK,
                'path' => $path,
                'row_count' => $rows,
                'file_size' => Storage::disk(self::DISK)->size($path),
                'completed_at' => now(),
                'expires_at' => now()->addHours($this->retentionHours()),
            ])->save();

            $this->audit->log(AuditAction::ReportExported, 'reports', $export, "Exported report {$export->report} / {$export->section}", null, [
                'report' => $export->report,
                'section' => $export->section,
                'format' => $export->format,
                'filters' => $export->filters_json,
                'row_count' => $rows,
            ], $user->id);
        } catch (\Throwable $e) {
            $export->forceFill(['status' => ReportExport::FAILED, 'error' => Str::limit(class_basename($e).': '.$e->getMessage(), 250)])->save();
            Log::warning('Report export failed', ['export_id' => $export->id, 'report' => $export->report, 'error' => class_basename($e)]);

            if (! app()->runningInConsole() || app()->runningUnitTests()) {
                throw $e;
            }
        }
    }

    /** @throws AuthorizationException */
    public function download(ReportExport $export, User $user): StreamedResponse
    {
        if ($export->user_id !== $user->id || ! $user->hasPermission(Permissions::REPORT_EXPORT)) {
            throw new AuthorizationException('You cannot download this export.');
        }
        if (! $export->isDownloadable() || ! Storage::disk($export->disk)->exists($export->path)) {
            throw new NotFoundHttpException('This export has expired or is not ready.');
        }

        $export->forceFill(['downloaded_at' => now()])->save();
        $this->audit->log(AuditAction::ReportExportDownloaded, 'reports', $export, "Downloaded report export {$export->report} / {$export->section}", null, [
            'report' => $export->report,
            'section' => $export->section,
            'row_count' => $export->row_count,
        ]);

        $name = 'report-'.$export->report.'-'.$export->section.'-'.$export->created_at->format('Ymd-His').'.csv';

        return Storage::disk($export->disk)->download($export->path, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return array<int, array> the user's recent exports (no paths). */
    public function recent(User $user): array
    {
        return ReportExport::query()->where('user_id', $user->id)->latest('id')->limit(8)->get()
            ->map(fn (ReportExport $e) => [
                'uuid' => $e->uuid,
                'report' => $e->report,
                'section' => $e->section,
                'status' => $e->isDownloadable() ? ReportExport::READY : ($e->status === ReportExport::READY ? ReportExport::EXPIRED : $e->status),
                'row_count' => $e->row_count,
                'created_at' => $e->created_at?->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
                'download_url' => $e->isDownloadable() ? route('reports.exports.download', $e) : null,
            ])->all();
    }

    /** Deletes expired files; the metadata row is kept (status expired) for the audit trail. */
    public function prune(): int
    {
        $count = 0;
        ReportExport::query()
            ->where(fn ($q) => $q->where('expires_at', '<', now())
                ->orWhere(fn ($w) => $w->whereIn('status', [ReportExport::QUEUED, ReportExport::PROCESSING, ReportExport::FAILED])->where('created_at', '<', now()->subDay())))
            ->where('status', '!=', ReportExport::EXPIRED)
            ->chunkById(200, function ($exports) use (&$count) {
                foreach ($exports as $export) {
                    if ($export->path && Storage::disk($export->disk ?: self::DISK)->exists($export->path)) {
                        Storage::disk($export->disk ?: self::DISK)->delete($export->path);
                    }
                    $export->forceFill(['status' => ReportExport::EXPIRED, 'path' => null])->save();
                    $count++;
                }
            });

        return $count;
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, string $slug, string $section): void
    {
        if (ReportScope::tierFor($user) !== null && $user->hasPermission(Permissions::REPORT_EXPORT)) {
            return;
        }

        $this->audit->log(AuditAction::ExportAttempted, 'reports', null, "Blocked report export attempt ({$slug})", null, [
            'report' => mb_substr($slug, 0, 40),
            'section' => mb_substr($section, 0, 40),
        ]);

        throw new AuthorizationException('You do not have permission to export reports.');
    }

    /** Streams rows into a CSV file; returns the number of data rows. */
    private function writeCsv(string $path, array $columns, iterable $rows): int
    {
        $currency = (string) $this->settings->get('general.currency', 'INR');
        $tmp = fopen('php://temp', 'w+b');
        fwrite($tmp, "\xEF\xBB\xBF");
        fputcsv($tmp, array_map(fn ($c) => $c['label'].match ($c['format'] ?? 'number') {
            'percent' => ' (%)',
            'currency' => " ({$currency})",
            'duration' => ' (minutes)',
            default => '',
        }, $columns));

        $count = 0;
        foreach ($rows as $row) {
            fputcsv($tmp, array_map(fn ($c) => $this->cell($row[$c['key']] ?? null, $c['format'] ?? 'number'), $columns));
            $count++;
        }

        rewind($tmp);
        Storage::disk(self::DISK)->put($path, $tmp);
        fclose($tmp);

        return $count;
    }

    private function cell(mixed $value, string $format): string
    {
        if ($value === null) {
            return '';
        }

        if ($format === 'duration') {
            return (string) round(((float) $value) / 60, 1);
        }

        $value = (string) $value;

        // Neutralise spreadsheet formula injection in text cells.
        if ($format === 'text' && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function threshold(): int
    {
        return max(100, (int) $this->settings->get('report.export_queue_threshold', 2000));
    }

    private function retentionHours(): int
    {
        return max(1, (int) $this->settings->get('report.export_retention_hours', 24));
    }
}
