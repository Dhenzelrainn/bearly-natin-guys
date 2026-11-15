@php
    $dashboardGreetingTime = $dashboardGreetingTime ?? now('Asia/Manila');
    $dashboardGreetingName = $dashboardGreetingName ?? auth()->user()?->name ?? '';
    $dashboardGreetingFirstName = str($dashboardGreetingName)->squish()->before(' ')->toString() ?: 'there';
    $dashboardGreetingText = match (true) {
        $dashboardGreetingTime->hour < 12 => 'Good morning',
        $dashboardGreetingTime->hour < 18 => 'Good afternoon',
        default => 'Good evening',
    };
@endphp

<span class="dashboard-greeting" data-dashboard-greeting data-first-name="{{ $dashboardGreetingFirstName }}">{{ $dashboardGreetingText }}, {{ $dashboardGreetingFirstName }}.</span>
