<?php

namespace App\Http\Controllers;

use App\Models\AccountApplication;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Policy;
use App\Models\ProductViolation;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $roleCounts = User::query()->selectRaw('role, count(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role');
        $pendingApplications = AccountApplication::query()->whereIn('status', ['submitted', 'under_review', 'needs_revision'])->count();
        $pendingViolations = ProductViolation::query()->whereIn('status', ['flagged', 'under_review', 'correction_requested'])->count();
        $adminOwned = Announcement::count() + Policy::count() + SystemSetting::count();

        $orders = Order::query()->whereYear('created_at', now()->year)->get(['id','total_minor','status','created_at']);
        $paidPayments = Payment::query()->where('status','paid')->whereYear('paid_at',now()->year)->get(['amount_minor','paid_at']);
        $monthly = $paidPayments->groupBy(fn (Payment $payment) => $payment->paid_at->month)
            ->map(fn ($month) => (int) round($month->sum('amount_minor') / 10000));

        $activities = AuditLog::query()->with('actor')->latest('created_at')->limit(6)->get()
            ->map(fn (AuditLog $log) => [
                'title' => str($log->action)->replace(['.', '_'], ' ')->title()->toString(),
                'meta' => ($log->actor?->name ?? 'System').' • '.$log->created_at->diffForHumans(),
                'type' => match ($log->severity) { 'critical' => 'danger', 'warning' => 'warning', default => 'info' },
            ])->all();

        $admin = $request->user();
        return view('admin.dashboard', [
            'admin' => ['name' => $admin->name, 'role' => 'Admin', 'email' => $admin->email,
                'initials' => collect(explode(' ', $admin->name))->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('')],
            'topNotifications' => [],
            'kpis' => [
                ['label' => 'Total Users', 'value' => number_format(User::count()), 'change' => User::where('status', 'active')->count().' active', 'trend' => 'up', 'icon' => 'users'],
                ['label' => 'Buyers', 'value' => number_format((int) ($roleCounts['buyer'] ?? 0)), 'change' => 'Registered', 'trend' => 'up', 'icon' => 'shopping-bag'],
                ['label' => 'Sellers', 'value' => number_format((int) ($roleCounts['seller'] ?? 0)), 'change' => 'Registered', 'trend' => 'up', 'icon' => 'store'],
                ['label' => 'Logistics & Riders', 'value' => number_format((int) ($roleCounts['logistics'] ?? 0) + (int) ($roleCounts['rider'] ?? 0)), 'change' => ($roleCounts['logistics'] ?? 0).' / '.($roleCounts['rider'] ?? 0), 'trend' => 'up', 'icon' => 'truck'],
                ['label' => 'Pending Applications', 'value' => number_format($pendingApplications), 'change' => 'Needs review', 'trend' => $pendingApplications ? 'alert' : 'up', 'icon' => 'user-check'],
                ['label' => 'Restricted Accounts', 'value' => number_format(User::whereIn('status', ['suspended', 'deactivated'])->count()), 'change' => 'Suspended / deactivated', 'trend' => 'alert', 'icon' => 'shield-alert'],
                ['label' => 'Admin Records', 'value' => number_format($adminOwned), 'change' => 'Announcements / policies / settings', 'trend' => 'up', 'icon' => 'database'],
                ['label' => 'Gross Sales', 'value' => '₱'.number_format($paidPayments->sum('amount_minor') / 100, 2), 'change' => $paidPayments->count().' paid transactions', 'trend' => 'up', 'icon' => 'chart-column'],
                ['label' => 'Active Orders', 'value' => number_format($orders->whereNotIn('status',['completed','cancelled'])->count()), 'change' => $orders->count().' total orders', 'trend' => 'up', 'icon' => 'package'],
            ],
            'salesByMonth' => collect(range(1, 12))->map(fn ($month) => $monthly[$month] ?? 0)->all(),
            'activity' => $activities,
            'systemNotices' => [
                ['title' => 'Registration queue', 'text' => "{$pendingApplications} applications are awaiting an admin decision.", 'action' => 'Review now', 'route' => 'admin.registrations'],
                ['title' => 'Compliance review', 'text' => "{$pendingViolations} flagged listings require review.", 'action' => 'Open compliance', 'route' => 'admin.compliance'],
            ],
        ]);
    }
}
