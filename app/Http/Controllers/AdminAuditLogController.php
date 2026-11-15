<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSessionRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAuditLogController extends Controller
{
    /** @var array<string, string> */
    private const ACTION_LABELS = [
        'auth.signed_in' => 'Signed in',
        'auth.signed_out' => 'Signed out',
        'auth.force_signed_out' => 'Forced sign-out',
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
            ->latest('created_at')->paginate(15)->withQueryString();

        $authActions = ['auth.signed_in', 'auth.signed_out'];
        $userMorphClass = (new User)->getMorphClass();
        $targetUserIds = $logs->getCollection()
            ->filter(fn (AuditLog $log): bool => $log->module === 'Authentication'
                && in_array($log->action, $authActions, true)
                && $log->auditable_type === $userMorphClass
                && $log->auditable_id !== null)
            ->pluck('auditable_id')
            ->unique()
            ->values();
        $targetUsers = User::withTrashed()
            ->whereIn('id', $targetUserIds)
            ->get(['id', 'name', 'first_name', 'last_name'])
            ->keyBy('id');

        $logs->through(function (AuditLog $log) use ($authActions, $targetUsers, $userMorphClass): array {
            $context = is_array($log->new_values) ? $log->new_values : [];
            $role = $log->actor?->role;
            $isAuthenticationEvent = $log->module === 'Authentication'
                && in_array($log->action, $authActions, true)
                && $log->auditable_type === $userMorphClass;
            $targetUser = $isAuthenticationEvent
                ? $targetUsers->get((int) $log->auditable_id)
                : null;
            $targetUserName = $targetUser
                ? trim((string) ($targetUser->name ?: trim(($targetUser->first_name ?? '').' '.($targetUser->last_name ?? ''))))
                : '';

            return [
                'id' => 'LOG-'.str_pad((string) $log->id, 6, '0', STR_PAD_LEFT),
                'record_id' => (int) $log->id,
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
                'subject_user_name' => $targetUserName !== ''
                    ? $targetUserName
                    : ($targetUser ? 'User #'.$targetUser->id : null),
                'can_force_logout' => $targetUser !== null,
                'description' => $log->description ?: 'Platform activity recorded for accountability.',
                'date' => $log->created_at?->format('M j, Y') ?? '—',
                'time' => $log->created_at?->format('g:i A') ?? '—',
                'ip' => $log->ip_address ?: '—',
                'severity' => str($log->severity)->title()->toString(),
                'has_device_context' => $log->module === 'Authentication'
                    && in_array($log->action, $authActions, true),
                'is_force_logout' => $log->action === 'auth.force_signed_out',
                'force_logout_scope' => ($context['scope'] ?? null) === 'all_devices'
                    ? 'All devices'
                    : null,
                'web_sessions_invalidated' => ($context['web_sessions_invalidated'] ?? false) === true,
                'mobile_api_tokens_revoked' => is_numeric($context['mobile_api_tokens_revoked'] ?? null)
                    ? (int) $context['mobile_api_tokens_revoked']
                    : null,
                'remembered_sign_ins_revoked' => is_numeric($context['remembered_sign_ins_revoked'] ?? null)
                    ? (int) $context['remembered_sign_ins_revoked']
                    : null,
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

    public function forceLogout(
        Request $request,
        AuditLog $auditLog,
        UserSessionRevocationService $sessionRevocations,
    ): RedirectResponse {
        $isAuthenticationEvent = $auditLog->module === 'Authentication'
            && in_array($auditLog->action, ['auth.signed_in', 'auth.signed_out'], true)
            && $auditLog->auditable_type === (new User)->getMorphClass()
            && $auditLog->auditable_id !== null;

        abort_unless($isAuthenticationEvent, 404);

        $target = User::withTrashed()->findOrFail($auditLog->auditable_id);
        $sessionRevocations->forceLogoutAll($target, $request->user(), $request);

        $targetName = trim((string) ($target->name ?: trim(($target->first_name ?? '').' '.($target->last_name ?? ''))));
        $targetName = $targetName !== '' ? $targetName : 'the selected account';

        return redirect()
            ->route('admin.audit-logs')
            ->with('success', "Signed out {$targetName} on all devices. Their web sessions, mobile tokens, and saved sign-ins were revoked.");
    }
}
