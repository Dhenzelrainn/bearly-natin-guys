<header class="registration-header" data-registration-header>
    <div class="registration-header-inner">
        <a
            class="registration-brand registration-brand-compact"
            href="{{ $registrationHomeUrl }}"
            aria-label="Bearly home"
        >
            @include('partials.theme-aware-logo', ['themeLogoAlt' => 'Bearly'])
            <span class="registration-brand-divider" aria-hidden="true"></span>
            <span class="registration-brand-context">{{ $registrationLabel }}</span>
        </a>

        <div class="registration-theme-toggle">
            @include('partials.theme-toggle')
        </div>
    </div>
</header>
