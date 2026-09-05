<a class="back" href="{{ route('home') }}">
    <i class="mi" aria-hidden="true">
        arrow_back
    </i>
    All categories
</a>
<div class="active-category">
    <i class="mi" aria-hidden="true">
        apparel
    </i>
    <strong>
        Men's Apparel
    </strong>
</div>
<details open class="subcategories">
    <summary>
        Subcategories
    </summary>
    <nav aria-label="Subcategories">
        @foreach($subcategories as $i => $sub)
        <button data-sub="{{ $sub }}" aria-pressed="false">
            <i class="mi" aria-hidden="true">
                {{ ['checkroom','apparel','checkroom','directions_run','steps','styler'][$i] }}
            </i>
            {{ $sub }}
        </button>
        @endforeach
    </nav>
</details>
