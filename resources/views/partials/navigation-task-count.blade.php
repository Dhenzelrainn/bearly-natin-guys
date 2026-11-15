@if ((int) ($count ?? 0) > 0)
    <span class="{{ $class ?? 'nav-count' }}" aria-hidden="true">{{ number_format((int) $count) }}</span>
@endif
