const initDashboardContext = () => {
    const greeting = document.querySelector('[data-dashboard-greeting]');
    const clock = document.querySelector('[data-ph-clock]');

    if (greeting || clock) {
        const timeZone = clock?.dataset.timeZone || 'Asia/Manila';
        const clockFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone,
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        });
        const hourFormatter = new Intl.DateTimeFormat('en-GB', {
            timeZone,
            hour: '2-digit',
            hourCycle: 'h23',
        });

        const updateManilaTime = () => {
            const currentTime = new Date();
            const hour = Number(hourFormatter.formatToParts(currentTime).find((part) => part.type === 'hour')?.value);

            if (clock) {
                clock.textContent = clockFormatter.format(currentTime);
                clock.dateTime = currentTime.toISOString();
            }

            if (greeting && Number.isInteger(hour)) {
                const message = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
                greeting.textContent = `${message}, ${greeting.dataset.firstName || 'there'}.`;
            }
        };

        updateManilaTime();
        window.setInterval(updateManilaTime, 1000);
    }

    const weatherWidget = document.querySelector('[data-dashboard-weather]');
    if (!weatherWidget) return;

    const reading = weatherWidget.querySelector('[data-dashboard-weather-reading]');
    const temperatureElement = weatherWidget.querySelector('[data-dashboard-weather-temperature]');
    const locationElement = document.querySelector('[data-dashboard-weather-location]');
    const updated = weatherWidget.querySelector('[data-dashboard-weather-updated]');
    let requestInFlight = false;

    const loadWeather = async () => {
        if (requestInFlight || document.hidden) return;

        requestInFlight = true;
        weatherWidget.dataset.state = 'loading';
        if (reading) reading.textContent = 'Loading current weather…';
        if (temperatureElement) temperatureElement.textContent = '--';
        if (updated) updated.textContent = '';

        try {
            const response = await fetch(weatherWidget.dataset.endpoint, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const payload = await response.json();
            const weather = payload?.data;
            const temperature = Number(weather?.temperature_c);

            if (
                !response.ok
                || !weather
                || typeof weather.location !== 'string'
                || typeof weather.condition !== 'string'
                || !Number.isFinite(temperature)
            ) {
                throw new Error('Weather unavailable');
            }

            const temperatureLabel = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 1 }).format(temperature);
            const location = weather.location.split(',')[0].trim() || weather.location;
            if (locationElement) locationElement.textContent = location;
            if (temperatureElement) temperatureElement.textContent = temperatureLabel;
            if (reading) reading.textContent = weather.condition;
            if (updated && typeof weather.updated_at === 'string' && weather.updated_at) {
                updated.textContent = `Updated ${weather.updated_at} ·`;
            }
            weatherWidget.dataset.state = 'ready';
        } catch {
            if (reading) reading.textContent = 'Weather unavailable right now';
            if (temperatureElement) temperatureElement.textContent = '--';
            weatherWidget.dataset.state = 'unavailable';
        } finally {
            requestInFlight = false;
        }
    };

    loadWeather();
    window.setInterval(loadWeather, 15 * 60 * 1000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) loadWeather();
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardContext, { once: true });
} else {
    initDashboardContext();
}
