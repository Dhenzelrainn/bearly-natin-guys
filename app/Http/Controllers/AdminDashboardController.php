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
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $roleCounts = User::query()->selectRaw('role, count(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role');
        $pendingApplications = AccountApplication::query()->whereIn('status', ['submitted', 'under_review', 'needs_revision'])->count();
        $pendingViolations = ProductViolation::query()->whereIn('status', ['flagged', 'under_review', 'correction_requested'])->count();
        $adminOwned = Announcement::count() + Policy::count() + SystemSetting::count();

        $orders = Order::query()->whereYear('created_at', now()->year)->get(['id', 'total_minor', 'status', 'created_at']);
        $paidPayments = Payment::query()->where('status', 'paid')->whereYear('paid_at', now()->year)->get(['amount_minor', 'paid_at']);

        // Sales trend uses the Admin Finance Reports gross-sales basis: paid
        // orders counted once, grouped by the order's earliest paid payment
        // date, summed in integer centavos (orders.total_minor).
        $grossMinorByDay = $this->grossMinorByPaymentDay();
        $grossMinorByMonth = [];

        foreach ($grossMinorByDay as $day => $minor) {
            $month = substr($day, 0, 7);
            $grossMinorByMonth[$month] = ($grossMinorByMonth[$month] ?? 0) + $minor;
        }

        $salesTrend = [
            '12M' => $this->salesTrendView(
                $this->monthlyTrendBuckets($grossMinorByMonth, now()->startOfYear(), 12),
                'No paid sales recorded this year.',
            ),
            '3M' => $this->salesTrendView(
                $this->monthlyTrendBuckets($grossMinorByMonth, now()->subMonths(2)->startOfMonth(), 3),
                'No paid sales in the last three months.',
            ),
            '30D' => $this->salesTrendView(
                $this->dailyTrendBuckets($grossMinorByDay, now()->subDays(29)->startOfDay(), 30),
                'No paid sales in the last 30 days.',
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
                ['label' => 'Gross Sales', 'value' => '₱'.number_format($paidPayments->sum('amount_minor') / 100, 2), 'change' => $paidPayments->count().' paid transactions', 'trend' => 'up', 'icon' => 'chart-column'],
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
     * Gross minor per payment day using the Admin Finance Reports basis:
     * paid orders only, each order counted once at its earliest paid payment.
     *
     * @return array<string, int>
     */
    private function grossMinorByPaymentDay(): array
    {
        $paidOrders = Order::query()
            ->with(['payments' => fn ($query) => $query->where('status', 'paid')->whereNotNull('paid_at')])
            ->whereHas('payments', fn ($query) => $query->where('status', 'paid')->whereNotNull('paid_at'))
            ->get(['id', 'total_minor']);

        $grossMinorByDay = [];

        foreach ($paidOrders as $order) {
            $paidAt = $order->payments->sortBy('paid_at')->first()?->paid_at;

            if ($paidAt === null) {
                continue;
            }

            $day = $paidAt->toDateString();
            $grossMinorByDay[$day] = ($grossMinorByDay[$day] ?? 0) + (int) $order->total_minor;
        }

        return $grossMinorByDay;
    }

    /**
     * @param  array<string, int>  $grossMinorByMonth
     * @return array<int, array{label: string, short: string, value_minor: int}>
     */
    private function monthlyTrendBuckets(array $grossMinorByMonth, Carbon $firstMonth, int $months): array
    {
        $buckets = [];

        for ($index = 0; $index < $months; $index++) {
            $month = $firstMonth->copy()->addMonths($index);

            $buckets[] = [
                'label' => $month->format('M Y'),
                'short' => $month->format('M'),
                'value_minor' => $grossMinorByMonth[$month->format('Y-m')] ?? 0,
            ];
        }

        return $buckets;
    }

    /**
     * @param  array<string, int>  $grossMinorByDay
     * @return array<int, array{label: string, short: string, value_minor: int}>
     */
    private function dailyTrendBuckets(array $grossMinorByDay, Carbon $firstDay, int $days): array
    {
        $buckets = [];

        for ($index = 0; $index < $days; $index++) {
            $day = $firstDay->copy()->addDays($index);

            $buckets[] = [
                'label' => $day->format('M j, Y'),
                'short' => $day->format('j'),
                'value_minor' => $grossMinorByDay[$day->toDateString()] ?? 0,
            ];
        }

        return $buckets;
    }

    /**
     * @param  array<int, array{label: string, short: string, value_minor: int}>  $buckets
     * @return array{has_data: bool, axis: array<int, string>, empty_note: string, buckets: array<int, array{label: string, short: string, value: string, height: float}>}
     */
    private function salesTrendView(array $buckets, string $emptyNote): array
    {
        $intervals = 3;
        $values = array_map(fn (array $bucket): int => $bucket['value_minor'], $buckets);
        $axisTop = $this->niceAxisStep($values === [] ? 0 : max($values), $intervals) * $intervals;
        $axis = [];

        for ($index = 0; $index <= $intervals; $index++) {
            $axis[] = $this->formatAxisLabel((int) round($axisTop * ($intervals - $index) / $intervals));
        }

        return [
            'has_data' => array_sum($values) > 0,
            'axis' => $axis,
            'empty_note' => $emptyNote,
            'buckets' => array_map(fn (array $bucket): array => [
                'label' => $bucket['label'],
                'short' => $bucket['short'],
                'value' => '₱'.number_format($bucket['value_minor'] / 100, 2),
                'height' => $axisTop > 0 ? round($bucket['value_minor'] / $axisTop * 100, 1) : 0.0,
            ], $buckets),
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
        $peso = max(0, $minor) / 100;

        foreach ([[1_000_000, 'M'], [1_000, 'K']] as [$unit, $suffix]) {
            if ($peso >= $unit) {
                $scaled = round($peso / $unit, 1);

                return '₱'.rtrim(rtrim(number_format($scaled, 1), '0'), '.').$suffix;
            }
        }

        return '₱'.rtrim(rtrim(number_format(round($peso, 2), 2), '0'), '.');
    }
}
