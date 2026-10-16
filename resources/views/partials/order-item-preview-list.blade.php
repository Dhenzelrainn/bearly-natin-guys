<div class="order-item-preview-list" role="list">
    @forelse($items as $item)
        <div class="order-item-preview" role="listitem">
            <span class="order-item-preview-image">
                @if(filled($item['image_url']))
                    <img src="{{ $item['image_url'] }}" alt="" loading="lazy" decoding="async">
                @else
                    <span class="order-item-preview-placeholder" role="img" aria-label="No product image available">
                        <i data-lucide="package" aria-hidden="true"></i>
                        <small>No image</small>
                    </span>
                @endif
            </span>
            <span class="order-item-preview-copy">
                <strong>{{ $item['name'] }}</strong>
                <small>{{ $item['variant'] ?: 'Variant not specified' }} · Qty {{ $item['quantity'] }}@if(filled($item['price'] ?? null)) · {{ $item['price'] }}@endif</small>
            </span>
        </div>
    @empty
        <span class="order-item-preview-empty">No product details recorded.</span>
    @endforelse
</div>
