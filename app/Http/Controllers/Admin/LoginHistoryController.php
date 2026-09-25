<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'in:login,logout,failed,lockout'],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $histories = LoginHistory::query()
            ->with('user:id,name')
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q
                ->where('email', 'like', "%{$v}%")
                ->orWhere('ip_address', 'like', "%{$v}%")))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (LoginHistory $h) => [
                ...$h->only('id', 'email', 'event', 'successful', 'ip_address', 'browser', 'platform', 'device'),
                'user' => $h->user?->only('id', 'name'),
                'created_at' => $h->created_at?->toIso8601String(),
                'logged_in_at' => $h->logged_in_at?->toIso8601String(),
                'logged_out_at' => $h->logged_out_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/LoginHistory/Index', [
            'histories' => $histories,
            'filters' => $filters,
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
