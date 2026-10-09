<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $module = trim((string) $request->query('module'));
        $severity = trim((string) $request->query('severity'));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $logs = AuditLog::query()->with('actor')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('action', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('module', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%")
                ->orWhereHas('actor', fn ($actor) => $actor->where('name', 'like', "%{$search}%"))))
            ->when($module !== '', fn ($query) => $query->where('module', $module))
            ->when($severity !== '', fn ($query) => $query->where('severity', $severity))
            ->when($dateFrom, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->latest('created_at')->paginate(15)->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => 'LOG-'.str_pad((string) $log->id, 6, '0', STR_PAD_LEFT),
                'admin' => $log->actor?->name ?? 'System',
                'role' => $log->actor ? 'Administrator' : 'System',
                'action' => str($log->action)->replace(['.', '_'], ' ')->title()->toString(),
                'module' => str($log->module)->replace('_', ' ')->title()->toString(),
                'target' => $log->auditable_type
                    ? class_basename($log->auditable_type).' #'.$log->auditable_id
                    : 'Platform',
                'description' => $log->description ?: 'Administrative activity recorded by the platform.',
                'date' => $log->created_at?->format('M j, Y') ?? '—',
                'time' => $log->created_at?->format('g:i A') ?? '—',
                'ip' => $log->ip_address ?: '—',
                'severity' => str($log->severity)->title()->toString(),
            ]);

        $admin = $request->user();
        return view('admin.system.audit-logs', [
            'admin' => ['name' => $admin->name, 'role' => 'Admin', 'email' => $admin->email,
                'initials' => collect(explode(' ', $admin->name))->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('')],
            'topNotifications' => [],
            'logs' => $logs,
            'filters' => compact('search', 'module', 'severity', 'dateFrom', 'dateTo'),
        ]);
    }
}
