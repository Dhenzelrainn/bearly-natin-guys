<tbody data-campaign-participation-rows>
    @forelse ($campaign->participations as $participation)
        <tr data-campaign-participation="{{ (int) $participation->id }}">
            <td>
                <strong>{{ $participation->store?->name }}</strong>
                <br><small>{{ $participation->submitter?->name }}</small>
            </td>
            <td>
                @foreach ($participation->items as $item)
                    <div class="campaign-participation-item">
                        <strong>{{ $item->product?->name }}</strong>
                        <small>{{ $item->variant?->sku ?: ($item->product_variant_id === null ? 'All active SKUs' : 'SKU unavailable') }} · {{ $isPisoSale ? 'Fixed price · ' : ($item->discount_percent !== null ? ((int) $item->discount_percent).'% off · ' : '') }}{{ $formatCampaignMinor($isPisoSale ? \App\Models\PlatformPromotion::PISO_SALE_PRICE_MINOR : ($item->campaign_price_minor !== null ? (int) $item->campaign_price_minor : null)) }} · stock {{ $item->stock_limit ?? '∞' }}</small>
                        @if ($budgetEstimate['applies'] && isset($budgetEstimate['item_estimates'][$item->id]))
                            @php
                                $itemBudgetEstimate = $budgetEstimate['item_estimates'][$item->id];
                            @endphp
                            <small class="campaign-budget-item-note">
                                {{ $participation->status === 'pending_review' ? 'If approved, estimated exposure:' : 'Remaining exposure:' }} {{ $formatCampaignMinor($itemBudgetEstimate['exposure_minor']) }} for {{ $itemBudgetEstimate['estimated_units'] }} unit(s).
                                @if (! $itemBudgetEstimate['product_visible'])
                                    Not currently Buyer-visible; zero inventory exposure estimated.
                                @elseif (! $itemBudgetEstimate['price_available'])
                                    No campaign price is currently available; no Bearly subsidy is estimated.
                                @else
                                    {{ $itemBudgetEstimate['basis_label'] }}.
                                @endif
                            </small>
                        @endif
                    </div>
                @endforeach
            </td>
            <td>{{ $participation->submitted_at?->format('M j, Y g:i A') ?? '—' }}</td>
            <td>
                @php
                    $participationStatusClass = match ($participation->status) {
                        'approved' => 'badge-success',
                        'pending_review' => 'badge-warning',
                        'rejected' => 'badge-danger',
                        default => 'badge-neutral',
                    };
                @endphp
                <span class="status-badge {{ $participationStatusClass }}">{{ $participation->statusLabel() }}</span>
            </td>
            <td>
                @if ($participation->status === 'pending_review' && $budgetEstimate['applies'] && isset($budgetEstimate['pending_participations'][$participation->id]))
                    @php
                        $pendingBudgetEstimate = $budgetEstimate['pending_participations'][$participation->id];
                    @endphp
                    <div class="campaign-review-budget-estimate" role="note">
                        <strong>If approved: additional estimated exposure {{ $formatCampaignMinor($pendingBudgetEstimate['additional_exposure_minor']) }}</strong>
                        <small>Projected total if approved: {{ $formatCampaignMinor($pendingBudgetEstimate['projected_total_if_approved_minor']) }}</small>
                        @if ($budgetEstimate['budget_minor'] === null)
                            <small>No budget cap. This is an estimate, not a reserved amount.</small>
                        @elseif ($pendingBudgetEstimate['would_exceed_budget'])
                            <small class="is-danger">Would exceed the configured budget by {{ $formatCampaignMinor(abs($pendingBudgetEstimate['remaining_headroom_if_approved_minor'])) }} if approved. Estimate only; approval remains available.</small>
                        @else
                            <small class="is-success">Would remain within the configured budget if approved. Estimate only; no amount is reserved.</small>
                        @endif
                        @if ($pendingBudgetEstimate['inventory_based_items_count'] > 0)
                            <small>Includes current-inventory estimates without campaign stock limits; these can change.</small>
                        @endif
                    </div>
                @endif
                @if ($participation->status === 'pending_review')
                    <form method="POST" action="{{ route('admin.campaigns.participations.review', $participation) }}" class="campaign-review-form" data-campaign-review-form>
                        @csrf
                        @method('PATCH')
                        <label>
                            <span class="sr-only">Review note</span>
                            <input class="text-field" name="reason" maxlength="1000" placeholder="Review note (optional)">
                        </label>
                        <button class="button button-primary" type="submit" name="decision" value="approve">Approve</button>
                        <button class="button button-danger" type="submit" name="decision" value="reject">Reject</button>
                    </form>
                @else
                    <small>{{ $participation->review_reason ?: 'Reviewed' }}@if ($participation->reviewer) · {{ $participation->reviewer->name }} @endif</small>
                @endif
            </td>
        </tr>
    @empty
        <tr data-campaign-participations-empty>
            <td colspan="5" class="table-empty">No Seller participations yet.</td>
        </tr>
    @endforelse
</tbody>
