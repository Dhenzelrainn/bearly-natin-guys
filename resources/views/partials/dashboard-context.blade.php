@php($dashboardWeatherLocation = str(config('services.weatherapi.location', 'Manila, Philippines'))->before(',')->trim()->toString())
@php($dashboardContextTime = $dashboardContextTime ?? now('Asia/Manila'))

<section class="dashboard-context" role="group" aria-label="Local weather and Philippine time">
    <div class="dashboard-context-time" aria-label="Philippine time">
        <i data-lucide="clock" aria-hidden="true"></i>
        <div class="dashboard-context-time-copy">
            <span class="dashboard-context-label">Philippine Time</span>
            <span class="dashboard-context-time-value">
                <time data-ph-clock data-time-zone="Asia/Manila" datetime="{{ $dashboardContextTime->toIso8601String() }}">{{ $dashboardContextTime->format('g:i A') }}</time>
                <span class="dashboard-context-zone">PHT</span>
            </span>
        </div>
    </div>

    <span class="dashboard-context-divider" aria-hidden="true"></span>

    <div
        class="dashboard-context-weather"
        data-dashboard-weather
        data-endpoint="{{ route('dashboard.weather.current') }}"
        data-state="loading"
        aria-live="polite"
        aria-atomic="true"
    >
        <div class="dashboard-context-weather-main">
            <i class="dashboard-weather-illustration" data-lucide="cloud-moon" aria-hidden="true"></i>
            <div class="dashboard-context-weather-copy">
                <span class="dashboard-context-location" data-dashboard-weather-location>{{ $dashboardWeatherLocation }}</span>
                <div class="dashboard-context-weather-reading">
                    <span class="dashboard-weather-temperature" aria-label="Current temperature">
                        <span data-dashboard-weather-temperature>--</span><span class="dashboard-weather-unit">°C</span>
                    </span>
                    <span class="dashboard-weather-condition" data-dashboard-weather-reading>Loading current weather…</span>
                </div>
            </div>
        </div>

        <div class="dashboard-context-attribution">
            <span data-dashboard-weather-updated></span>
            <a class="dashboard-weather-attribution" href="https://www.weatherapi.com/" target="_blank" rel="noopener noreferrer">
                WeatherAPI.com
            </a>
        </div>
    </div>
</section>
