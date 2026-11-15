@vite('resources/js/theme.js')
<button
    class="theme-switch"
    type="button"
    role="switch"
    aria-label="Dark mode"
    aria-checked="false"
    title="Toggle dark mode"
    data-theme-toggle
>
    <span class="theme-switch-track" aria-hidden="true">
        <svg class="theme-switch-sun" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="3.7" />
            <path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" />
        </svg>
        <svg class="theme-switch-moon" viewBox="0 0 24 24" fill="none">
            <path d="M20.2 15.2A8.6 8.6 0 0 1 8.8 3.8 8.7 8.7 0 1 0 20.2 15.2Z" />
        </svg>
        <span class="theme-switch-thumb"></span>
    </span>
    <span class="theme-switch-label" data-theme-toggle-label>Dark mode</span>
</button>
