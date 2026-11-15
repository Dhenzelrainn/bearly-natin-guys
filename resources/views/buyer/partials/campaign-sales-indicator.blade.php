@php
    $campaignSoldUnits = max(0, (int) ($soldUnits ?? 0));
    $campaignStockLimit = isset($stockLimit) && (int) $stockLimit > 0
        ? (int) $stockLimit
        : null;
    $campaignProgressUnits = $campaignStockLimit !== null
        ? min($campaignSoldUnits, $campaignStockLimit)
        : null;
    $campaignProgressPercent = $campaignStockLimit !== null
        ? (int) round(($campaignProgressUnits / $campaignStockLimit) * 100)
        : null;
@endphp

@if ($campaignStockLimit !== null)
    <div
        class="campaign-sales-indicator campaign-sales-indicator--limited"
        role="progressbar"
        aria-label="{{ number_format($campaignSoldUnits) }} of {{ number_format($campaignStockLimit) }} campaign units sold"
        aria-valuemin="0"
        aria-valuemax="{{ $campaignStockLimit }}"
        aria-valuenow="{{ $campaignProgressUnits }}"
    >
        <span class="campaign-sales-indicator-fill" style="--campaign-sales-progress: {{ $campaignProgressPercent }}%" aria-hidden="true"></span>
        <span class="campaign-sales-indicator-label">
            @if ($campaignSoldUnits > 0)
                <span class="material-symbols-outlined" aria-hidden="true">local_fire_department</span>
            @endif
            {{ number_format($campaignSoldUnits) }} SOLD
        </span>
    </div>
@else
    <div class="campaign-sales-indicator campaign-sales-indicator--unlimited">
        @if ($campaignSoldUnits > 0)
            <span class="material-symbols-outlined" aria-hidden="true">local_fire_department</span>
        @endif
        {{ number_format($campaignSoldUnits) }} SOLD
    </div>
@endif
