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
use App\Services\SellerEarningsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, SellerEarningsService $earnings): View
    {
        $roleCounts = User::query()->selectRaw('role, count(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role');
        $pendingApplications = AccountApplication::query()->whereIn('status', ['submitted', 'under_review', 'needs_revision'])->count();
        $pendingViolations = ProductViolation::query()->whereIn('status', ['flagged', 'under_review', 'correction_requested'])->count();
        $adminOwned = Announcement::count() + Policy::count() + SystemSetting::count();

        $orders = Order::query()->whereYear('created_at', now()->year)->get(['id', 'total_minor', 'status', 'created_at']);
        $paidPayments = Payment::query()->where('status', 'paid')->whereYear('paid_at', now()->year)->get(['amount_minor', 'paid_at']);

        // Sales trend uses the Admin Financial Reports Sales Summary basis:
        // completed Seller Orders grouped by completed_at, product amounts from
        // order-item snapshots (shipping excluded), net of item discounts and
        // completed refunds. One marketplace order may split across Seller
        // Orders, but each snapshot item is counted exactly once.
        $today = CarbonImmutable::now();
        $currentMonth = $today->startOfMonth();

        $salesTrend = [
            '12M' => $this->salesTrendView(
                $earnings->marketplaceNetSalesTrendFor($currentMonth->subMonths(11), $currentMonth->endOfMonth(), 'month'),
                'No net product sales recorded in the last 12 months.',
            ),
            '3M' => $this->salesTrendView(
                $earnings->marketplaceNetSalesTrendFor($currentMonth->subMonths(2), $currentMonth->endOfMonth(), 'month'),
                'No net product sales recorded in the last 3 months.',
            ),
            '30D' => $this->salesTrendView(
                $earnings->marketplaceNetSalesTrendFor($today->startOfDay()->subDays(29), $today->endOfDay(), 'day'),
                'No net product sales recorded in the last 30 days.',
            ),
        ];

        $activities = AuditLog::query()->with('actor')->latest('created_at')->limit(6)->get()
            ->map(fn (AuditLog $log) => [
                'title' => str($log->action)->replace(['.', '_'], ' ')->title()->toString(),
                'meta' => ($log->actor?->name ?? 'System').' • '.$log->created_at->diffForHumans(),
                'type' => match ($log->severity) {
                    'critical' => 'danger', 'warning' => 'warning', default => 'info'
                },
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
                ['label' => 'Paid Collections', 'value' => '₱'.number_format($paidPayments->sum('amount_minor') / 100, 2), 'change' => $paidPayments->count().' payments marked paid', 'trend' => 'up', 'icon' => 'chart-column'],
                ['label' => 'Active Orders', 'value' => number_format($orders->whereNotIn('status', ['completed', 'cancelled'])->count()), 'change' => $orders->count().' total orders', 'trend' => 'up', 'icon' => 'package'],
            ],
            'hasSalesData' => $salesTrend['12M']['has_data'] || $salesTrend['3M']['has_data'] || $salesTrend['30D']['has_data'],
            'salesTrend' => $salesTrend,
            'activity' => $activities,
            'systemNotices' => [
                ['title' => 'Registration queue', 'text' => "{$pendingApplications} applications are awaiting an admin decision.", 'action' => 'Review now', 'route' => 'admin.registrations'],
                ['title' => 'Compliance review', 'text' => "{$pendingViolations} flagged listings require review.", 'action' => 'Open compliance', 'route' => 'admin.compliance'],
            ],
        ]);
    }

    /**
     * Convert service trend buckets into chart values, axis labels, and
     * zero-based bar heights. A negative period uses a symmetric axis around a
     * visible zero baseline instead of clamping the value.
     *
     * @param  array<int, array<string, mixed>>  $buckets
     * @return array{has_data: bool, has_negative: bool, zero_percent: float, axis: array<int, string>, empty_note: string, buckets: array<int, array{label: string, short: string, value: string, value_minor: int, negative: bool, height: float}>}
     */
    private function salesTrendView(array $buckets, string $emptyNote): array
    {
        $values = array_map(fn (array $bucket): int => (int) $bucket['net_minor'], $buckets);
        $hasData = false;

        foreach ($buckets as $bucket) {
            if ((int) ($bucket['seller_orders'] ?? 0) > 0 || (int) ($bucket['refunds_minor'] ?? 0) !== 0) {
                $hasData = true;
                break;
            }
        }

        $minValue = $values === [] ? 0 : min($values);
        $maxValue = $values === [] ? 0 : max($values);

        if ($minValue < 0) {
            $extent = $this->niceAxisStep(max(abs($minValue), abs($maxValue)), 2) * 2;
            $axisTop = $extent;
            $axisBottom = -$extent;
            $axisIntervals = 4;
        } else {
            $axisTop = $this->niceAxisStep($maxValue, 3) * 3;
            $axisBottom = 0;
            $axisIntervals = 3;
        }

        $range = $axisTop - $axisBottom;
        $zeroPercent = $range > 0 ? (0 - $axisBottom) / $range * 100 : 0.0;
        $axis = [];

        for ($index = 0; $index <= $axisIntervals; $index++) {
            $axis[] = $this->formatAxisLabel((int) round($axisTop - $range * $index / $axisIntervals));
        }

        return [
            'has_data' => $hasData,
            'has_negative' => $minValue < 0,
            'zero_percent' => round($zeroPercent, 2),
            'axis' => $axis,
            'empty_note' => $emptyNote,
            'buckets' => array_map(function (array $bucket) use ($range): array {
                $valueMinor = (int) $bucket['net_minor'];

                return [
                    'label' => $bucket['label'],
                    'short' => $bucket['short'],
                    'value_minor' => $valueMinor,
                    'value' => ($valueMinor < 0 ? '−' : '').'₱'.number_format(abs($valueMinor) / 100, 2),
                    'negative' => $valueMinor < 0,
                    'height' => $range > 0 ? round(abs($valueMinor) / $range * 100, 1) : 0.0,
                ];
            }, $buckets),
        ];
    }

    private function niceAxisStep(int $maxMinor, int $intervals): int
    {
        if ($maxMinor <= 0) {
            return 100;
        }

        $raw = $maxMinor / max(1, $intervals);
        $magnitude = 10 ** (int) floor(log10(max(1, $raw)));

        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            $candidate = (int) round($magnitude * $factor);

            if ($candidate >= $raw) {
                return $candidate;
            }
        }

        return (int) round($magnitude * 10);
    }

    private function formatAxisLabel(int $minor): string
    {
        $negative = $minor < 0;
        $peso = abs($minor) / 100;
        $prefix = $negative ? '−' : '';

        foreach ([[1_000_000, 'M'], [1_000, 'K']] as [$unit, $suffix]) {
            if ($peso >= $unit) {
                $scaled = round($peso / $unit, 1);

                return $prefix.'₱'.rtrim(rtrim(number_format($scaled, 1), '0'), '.').$suffix;
            }
        }

        return $prefix.'₱'.rtrim(rtrim(number_format(round($peso, 2), 2), '0'), '.');
    }
}
