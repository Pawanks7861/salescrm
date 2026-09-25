<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only by design: there are no update or delete endpoints for audit logs. */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:64'],
            'module' => ['nullable', 'string', 'max:50'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->filter($filters)
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                ...$log->only('id', 'action', 'module', 'entity_type', 'entity_id', 'description', 'ip_address', 'request_method', 'route'),
                'user' => $log->user?->only('id', 'name'),
                'created_at' => $log->created_at?->toIso8601String(),
                'has_changes' => $log->old_values_json !== null || $log->new_values_json !== null,
            ]);

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => array_map(fn (AuditAction $a) => $a->value, AuditAction::cases()),
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module'),
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(AuditLog $auditLog): Response
    {
        $auditLog->load('user:id,name,email');

        return Inertia::render('Admin/AuditLogs/Show', [
            'log' => [
                ...$auditLog->only(
                    'id', 'action', 'module', 'entity_type', 'entity_id', 'description',
                    'old_values_json', 'new_values_json', 'ip_address', 'user_agent', 'route', 'request_method',
                ),
                'user' => $auditLog->user?->only('id', 'name', 'email'),
                'created_at' => $auditLog->created_at?->toIso8601String(),
            ],
        ]);
    }
}
