@php
    /**
     * Server-rendered sent/delivered/seen receipt. The client-side
     * chat-receipts.js helper replaces this node when a receipt event
     * arrives, so keep the data-chat-receipt contract in sync.
     */
    $receipt = $receipt ?? null;
    $status = $receipt['status'] ?? null;
    $label = $receipt['label'] ?? ($status ? ucfirst($status) : '');
    $detail = $receipt['detail'] ?? $label;
@endphp
@if ($receipt && $status)
    <span
        class="chat-receipt is-{{ $status }}{{ ! empty($receipt['is_group']) ? ' is-group' : '' }}"
        data-chat-receipt
        title="{{ $detail }}"
        aria-label="{{ $detail }}"
    >
        <svg class="chat-receipt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            @if ($status === 'sent')
                <path d="M5 12.5l4 4L19 7"/>
            @else
                <path d="M2.5 12.5l4 4L15.5 7"/><path d="M9.5 12.5l4 4L22.5 7"/>
            @endif
        </svg>
        <span class="chat-receipt-label">{{ $label }}</span>
    </span>
@endif
