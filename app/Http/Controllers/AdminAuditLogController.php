<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAuditLogController extends Controller
{
    /** @var array<string, string> */
    private const ACTION_LABELS = [
        'auth.signed_in' => 'Signed in',
        'auth.signed_out' => 'Signed out',
    ];

    /** @var array<string, string> */
    private const ROLE_LABELS = [
        'admin' => 'Administrator',
        'buyer' => 'Buyer',
        'seller' => 'Seller',
        'logistics' => 'Logistics',
        'rider' => 'Rider',
        'guest' => 'Guest',
    ];

    /** @var array<string, string> */
    private const CHANNEL_LABELS = [
        'web_password' => 'Web (email and password)',
        'google' => 'Web (Google)',
        'mobile_api' => 'Mobile app (API token)',
        'web_session' => 'Web session',
    ];

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
            ->through(function (AuditLog $log): array {
                $context = is_array($log->new_values) ? $log->new_values : [];
                $role = $log->actor?->role;

                return [
                    'id' => 'LOG-'.str_pad((string) $log->id, 6, '0', STR_PAD_LEFT),
                    'admin' => $log->actor?->name ?? 'System',
                    'role' => is_string($role) && $role !== ''
                        ? (self::ROLE_LABELS[$role] ?? str($role)->replace('_', ' ')->title()->toString())
                        : 'System',
                    'action' => self::ACTION_LABELS[$log->action]
                        ?? str($log->action)->replace(['.', '_'], ' ')->title()->toString(),
                    'module' => str($log->module)->replace('_', ' ')->title()->toString(),
                    'target' => $log->auditable_type
                        ? class_basename($log->auditable_type).' #'.$log->auditable_id
                        : 'Platform',
                    'description' => $log->description ?: 'Platform activity recorded for accountability.',
                    'date' => $log->created_at?->format('M j, Y') ?? '—',
                    'time' => $log->created_at?->format('g:i A') ?? '—',
                    'ip' => $log->ip_address ?: '—',
                    'severity' => str($log->severity)->title()->toString(),
                    'is_authentication' => $log->module === 'Authentication',
                    'channel' => is_string($context['channel'] ?? null)
                        ? (self::CHANNEL_LABELS[$context['channel']]
                            ?? str($context['channel'])->replace('_', ' ')->title()->toString())
                        : null,
                    'device' => is_string($context['device'] ?? null) ? $context['device'] : null,
                    'browser' => is_string($context['browser'] ?? null) ? $context['browser'] : null,
                    'os' => is_string($context['os'] ?? null) ? $context['os'] : null,
                    'location' => is_string($context['location'] ?? null) ? $context['location'] : null,
                ];
            });

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
