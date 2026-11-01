@extends('layouts.admin')

@section('title', 'Returns & Refunds')
@section('page-title', 'Returns & Refunds')

@section('content')

<div class="returns-refunds-page">
@if (session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="alert alert-danger" role="alert"><strong>Action could not be completed.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="page-hero">

    <div>
        <span class="eyebrow">Compliance & Disputes</span>
        <h1>Returns & refunds</h1>

        <p>
            Review Seller responses, decide claims, and record external payouts after the required return steps are complete.
        </p>
    </div>


    <div class="hero-context-stat">
        <span class="hero-context-icon">
            <i data-lucide="rotate-ccw"></i>
        </span>

        <div>
            <strong>{{ count($cases) }}</strong>
            <span>Return & refund cases</span>
        </div>
    </div>

</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">Case management</span>
            <h2>Return and refund cases</h2>
            <p>Review both parties’ information and take the next action allowed for each case.</p>
        </div>


        <div class="table-toolbar">

            <label class="field-with-icon compact-field">
                <i data-lucide="search" aria-hidden="true"></i>

                <input
                    type="search"
                    placeholder="Search case..."
                    aria-label="Search return and refund cases"
                    data-table-search="returns-table"
                >
            </label>


            <select
                class="select-field"
                aria-label="Filter by request type"
                data-table-filter="returns-table"
                data-filter-key="type"
            >
                <option value="">All request types</option>
                <option value="Return & Refund">Return & Refund</option>
                <option value="Partial Refund">Partial Refund</option>
                <option value="Refund Only">Refund Only</option>
            </select>


            <select
                class="select-field"
                aria-label="Filter by case status"
                data-table-filter="returns-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option value="Under Review">Under Review</option>
                <option value="Awaiting Seller">Awaiting Seller</option>
                <option value="Awaiting Evidence">Awaiting Evidence</option>
                <option value="Return Pickup Requested">Return Pickup Requested</option>
                <option value="Buyer Drop-off Pending">Buyer Drop-off Pending</option>
                <option value="Return In Transit">Return In Transit</option>
                <option value="Awaiting Seller Inspection">Awaiting Seller Inspection</option>
                <option value="Refund Payout Pending">Refund Payout Pending</option>
                <option value="Rejected">Rejected</option>
                <option value="Resolved">Resolved</option>
            </select>

        </div>

    </div>


    <div class="table-wrap">

        <table class="admin-table" id="returns-table">

            <thead>
                <tr>
                    <th>Case / order</th>
                    <th>Buyer</th>
                    <th>Seller / store</th>
                    <th>Request</th>
                    <th>Requested refund</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>


            <tbody>

                @foreach ($cases as $case)

                    <tr
                        data-table-row
                        data-return-id="{{ $case['id'] }}"
                        data-type="{{ $case['type'] }}"
                        data-status="{{ $case['status'] }}"
                        data-search="{{
                            strtolower(
                                $case['id'].' '.
                                $case['order'].' '.
                                $case['buyer'].' '.
                                $case['seller'].' '.
                                $case['reason']
                            )
                        }}"
                    >

                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $case['id'] }}</strong>
                                <small>
                                    {{ $case['order'] }} · Submitted {{ $case['requested'] }}
                                </small>
                            </div>
                        </td>

                        <td>
                            {{ $case['buyer'] }}
                        </td>

                        <td>
                            {{ $case['seller'] }}
                        </td>

                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $case['type'] }}</strong>
                                <small>{{ $case['reason'] }}</small>
                            </div>
                        </td>

                        <td>
                            <strong>{{ $case['amount'] }}</strong>
                        </td>

                        <td>
                            <span
                                class="status-badge
                                {{
                                    $case['status'] === 'Resolved'
                                        ? 'badge-success'
                                         : (in_array($case['status'], ['Rejected'])
                                            ? 'badge-danger'
                                             : ($case['status'] === 'Under Review'
                                                 ? 'badge-info'
                                                 : 'badge-warning'))
                                }}"
                            >
                                {{ $case['status'] }}
                            </span>
                        </td>

                        <td class="align-right">

                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="return-case-{{ $loop->index }}"
                                aria-haspopup="dialog"
                                aria-controls="return-case-{{ $loop->index }}"
                                aria-label="Review case {{ $case['id'] }}"
                            >
                                <i data-lucide="eye" aria-hidden="true"></i>
                                Review case
                            </button>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>


        <div
            class="table-empty"
            data-table-empty="returns-table"
            @if (count($cases) > 0) hidden @endif
        >
            <i data-lucide="search-x"></i>
            <strong>No cases found</strong>
            <span>No return or refund cases match the current filters.</span>
        </div>

    </div>

</section>


@foreach ($cases as $case)

<div
    class="modal-shell"
    id="return-case-{{ $loop->index }}"
    data-modal="return-case-{{ $loop->index }}"
    data-return-modal
    data-return-id="{{ $case['id'] }}"
    hidden
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
        aria-label="Close return case review"
    ></button>


    <section
        class="modal-card modal-wide return-review-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="return-review-title-{{ $loop->index }}"
        aria-describedby="return-review-description-{{ $loop->index }}"
        tabindex="-1"
    >

        <header class="modal-heading return-review-header">
            <div>
                <div class="return-review-id-row">
                    <span class="eyebrow">{{ $case['id'] }}</span>
                    <span class="status-badge {{ $case['status'] === 'Resolved' ? 'badge-success' : ($case['status'] === 'Rejected' ? 'badge-danger' : ($case['status'] === 'Under Review' ? 'badge-info' : 'badge-warning')) }}">{{ $case['status'] }}</span>
                </div>
                <h2 id="return-review-title-{{ $loop->index }}">Review return request</h2>
                <p id="return-review-description-{{ $loop->index }}">Compare the Buyer’s request, Seller’s response, and submitted evidence before taking action.</p>
            </div>
            <button
                type="button"
                class="icon-button"
                data-close-modal
                aria-label="Close return case review"
            >
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </header>


        <div class="return-review-content">
        <div class="review-details return-review-main">

            <div class="return-review-case-info-heading">
                <h3><span class="return-review-case-info-icon" aria-hidden="true"><i data-lucide="clipboard-list"></i></span><span>Case information</span></h3>
                <div class="return-refund-summary return-refund-summary-compact">
                    <span class="return-refund-summary-icon" aria-hidden="true"><i data-lucide="dollar-sign"></i></span>
                    <span class="return-refund-summary-copy"><small>Refund requested by the Buyer</small><strong>{{ $case['amount'] }}</strong><small>Total for {{ count($case['items']) }} {{ count($case['items']) === 1 ? 'item' : 'items' }}</small></span>
                </div>
            </div>


            <div class="detail-grid">

                <div>
                    <span>Case ID</span>
                    <strong>{{ $case['id'] }}</strong>
                </div>

                <div>
                    <span>Order ID</span>
                    <strong>{{ $case['order'] }}</strong>
                </div>

                <div>
                    <span>Buyer</span>
                    <strong>{{ $case['buyer'] }}</strong>
                </div>

                <div>
                    <span>Seller</span>
                    <strong>{{ $case['seller'] }}</strong>
                </div>

                <div>
                    <span>Request type</span>
                    <strong>{{ $case['type'] }}</strong>
                </div>

                <div>
                    <span>Reason</span>
                    <strong>{{ $case['reason'] }}</strong>
                </div>

                <div>
                    <span>Date requested</span>
                    <strong>{{ $case['requested'] }}</strong>
                </div>

            </div>


            <div class="detail-note return-party-note return-party-note-buyer">
                <span>Buyer request</span>
                <p>{{ $case['buyer_request'] }}</p>
            </div>

            @if ($case['type'] === 'Return & Refund' && $case['return_delivery_method'])
                <div class="detail-note return-items-note">
                    <span>Preferred return delivery</span>
                    <p>{{ $case['return_delivery_method'] === 'buyer_dropoff' ? 'Buyer drop-off at the active Logistics center in the order city' : 'Rider pickup from the Buyer order address' }}. The method starts only after Admin approval. The 40/60 return-route fee follows the Seller/Platform responsibility decision below.</p>
                </div>
            @endif


            <div class="detail-note return-party-note return-party-note-seller">
                <span>Seller response</span>
                <p>{{ $case['seller_response'] }}</p>
            </div>

            <div class="detail-note return-items-note">
                <span>Selected items and requested value</span>
                @if (count($case['items']))
                    <div class="return-items-table-wrap">
                        <table class="return-items-table" aria-label="Items and quantities in this return request">
                            <thead><tr><th>Item</th><th>Quantity requested</th><th>Refund requested</th></tr></thead>
                            <tbody>
                                @foreach ($case['items'] as $item)
                                    <tr>
                                        <td>
                                            <div class="admin-return-item-product">
                                                <span class="admin-return-item-image">
                                                    @if(filled($item['image_url']))
                                                        <img src="{{ $item['image_url'] }}" alt="" loading="lazy" decoding="async">
                                                    @else
                                                        <i data-lucide="package" aria-hidden="true"></i>
                                                    @endif
                                                </span>
                                                <span><strong>{{ $item['name'] }}</strong><small>{{ $item['variant'] }} · SKU {{ $item['sku'] }}</small></span>
                                            </div>
                                        </td>
                                        <td>{{ $item['quantity'] }}</td>
                                        <td><strong>{{ $item['amount'] }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p>No line-item details are available.</p>
                @endif
            </div>

            <div class="detail-note return-evidence-note">
                <span>Private evidence files</span>
                @forelse ($case['evidence_files'] as $evidence)
                    <div class="return-evidence-row">
                        @if ($evidence['is_image'])
                            <button class="return-evidence-thumb" type="button" data-return-evidence-preview data-preview-url="{{ $evidence['preview_url'] }}" data-preview-name="{{ $evidence['name'] }}" aria-label="Preview {{ \Illuminate\Support\Str::headline($evidence['type']) }} image {{ $evidence['name'] }}">
                                <img src="{{ $evidence['preview_url'] }}" alt="" loading="lazy" decoding="async">
                            </button>
                        @else
                            <span class="return-evidence-icon" aria-hidden="true"><i data-lucide="file-text"></i></span>
                        @endif
                        <span class="return-evidence-copy"><strong>{{ $evidence['name'] }}</strong><small>{{ \Illuminate\Support\Str::headline($evidence['type']) }} · {{ number_format($evidence['size'] / 1024, 0) }} KB</small></span>
                        <span class="return-evidence-actions">
                            @if ($evidence['is_image'])
                                <button class="return-evidence-preview-button" type="button" data-return-evidence-preview data-preview-url="{{ $evidence['preview_url'] }}" data-preview-name="{{ $evidence['name'] }}" aria-label="Preview {{ \Illuminate\Support\Str::headline($evidence['type']) }} image {{ $evidence['name'] }}">Preview</button>
                            @endif
                            <a class="return-evidence-download" href="{{ $evidence['url'] }}" aria-label="Download {{ $evidence['name'] }}">Download</a>
                        </span>
                    </div>
                @empty
                    <p>No evidence files have been submitted.</p>
                @endforelse
            </div>

            @if (count($case['events']))
                <div class="detail-note return-history-note">
                    <span>Case history</span>
                    <ol class="return-history-list">
                        @foreach ($case['events'] as $event)
                            <li>
                                <span class="return-history-marker" aria-hidden="true"></span>
                                <div>
                                    <strong>{{ \Illuminate\Support\Str::headline($event['type']) }}</strong>
                                    <small>{{ $event['actor'] }} · {{ $event['at'] }}</small>
                                    @if ($event['note'])<p>{{ $event['note'] }}</p>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

        </div>
        <aside class="return-review-rail" aria-label="Review summary and Admin decision">
            @include('admin.compliance.partials.return-review-actions', ['case' => $case, 'caseIndex' => $loop->index])
            <section class="return-review-rail-section">
                <h3>Current status</h3>
                <p><span class="status-badge {{ $case['status'] === 'Resolved' ? 'badge-success' : ($case['status'] === 'Rejected' ? 'badge-danger' : ($case['status'] === 'Under Review' ? 'badge-info' : 'badge-warning')) }}" data-return-modal-status>{{ $case['status'] }}</span></p>
            </section>
            @if ($case['return_waybill'])
                <section class="return-review-rail-section">
                    <h3>Return shipment</h3>
                    <dl>
                        <div><dt>Waybill</dt><dd>{{ $case['return_waybill'] }}</dd></div>
                        <div><dt>Status</dt><dd>{{ \Illuminate\Support\Str::headline($case['return_shipment_status']) }}</dd></div>
                        @if ($case['return_delivery_method'])<div><dt>Return method</dt><dd>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $case['return_delivery_method'])) }}</dd></div>@endif
                        @if ($case['return_dropoff_center'])<div><dt>Assigned drop-off</dt><dd>{{ $case['return_dropoff_center'] }}</dd></div>@endif
                        @if ($case['return_shipping_fee_minor'] !== null)<div><dt>Return delivery fee</dt><dd>₱{{ number_format($case['return_shipping_fee_minor'] / 100, 2) }} · {{ ucfirst((string) $case['return_shipping_fee_payer']) }} responsible</dd></div>@endif
                        @if ($case['dropoff_due_at'])<div><dt>Buyer drop-off deadline</dt><dd>{{ $case['dropoff_due_at'] }}</dd></div>@endif
                        @if ($case['return_shipping_settlement_status'])<div><dt>Return fee remittance</dt><dd>{{ \Illuminate\Support\Str::headline($case['return_shipping_settlement_status']) }} · {{ $case['return_shipping_settlement_no'] }}</dd></div>@endif
                    </dl>
                </section>
            @endif
            @if ($case['refund_id'])
                <section class="return-review-rail-section">
                    <h3>Refund record</h3>
                    <dl>
                        <div><dt>Refund ID</dt><dd>{{ $case['refund_no'] }}</dd></div>
                        <div><dt>Refund amount</dt><dd>{{ $case['refund_amount'] }}</dd></div>
                        <div><dt>Status</dt><dd>{{ \Illuminate\Support\Str::headline($case['refund_status']) }}</dd></div>
                        <div><dt>Financially responsible</dt><dd>{{ $case['financially_responsible_party'] ? \Illuminate\Support\Str::headline($case['financially_responsible_party']) : 'Not recorded' }}</dd></div>
                        @if ($case['financial_responsibility_reason'])<div><dt>Responsibility reason</dt><dd>{{ $case['financial_responsibility_reason'] }}</dd></div>@endif
                        @if ($case['payout_date'])<div><dt>Externally paid</dt><dd>{{ $case['payout_date'] }} · {{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $case['payout_method'])) }}</dd></div>@endif
                        @if ($case['payout_reference'])<div><dt>Buyer payout reference</dt><dd>{{ $case['payout_reference'] }}</dd></div>@endif
                        @if ($case['payout_amount'])<div><dt>Actual amount paid</dt><dd>{{ $case['payout_amount'] }}</dd></div>@endif
                        @if ($case['payout_receipt_url'])<div><dt>Payout proof</dt><dd><a href="{{ $case['payout_receipt_url'] }}">Download receipt</a></dd></div>@endif
                    </dl>
                    @if ($case['seller_recovery'])
                        <div class="return-recovery-summary">
                            <strong>Seller recoverable balance</strong>
                            <span>Refund proceeds: ₱{{ number_format($case['seller_recovery']['refund_recovery_minor'] / 100, 2) }} assigned · ₱{{ number_format($case['seller_recovery']['refund_outstanding_minor'] / 100, 2) }} outstanding.</span>
                            <span>Return delivery fee: ₱{{ number_format($case['seller_recovery']['return_shipping_fee_minor'] / 100, 2) }} assigned · ₱{{ number_format($case['seller_recovery']['return_shipping_outstanding_minor'] / 100, 2) }} outstanding. It is not automatically deducted from Seller Wallet.</span>
                            @foreach ($case['seller_recovery']['events'] as $recoveryEvent)
                                <small>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $recoveryEvent['component'])) }} · {{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $recoveryEvent['method'])) }} · ₱{{ number_format($recoveryEvent['amount_minor'] / 100, 2) }} · {{ $recoveryEvent['reference'] }} · {{ $recoveryEvent['recovered_on'] }}</small>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </aside>
        </div>


        <footer class="modal-footer return-review-footer">
            <button type="button" class="button button-ghost" data-close-modal>Cancel</button>
        </footer>

<dialog class="return-evidence-image-dialog" data-return-evidence-dialog aria-labelledby="return-evidence-preview-title-{{ $loop->index }}">
            <header>
                <h3 id="return-evidence-preview-title-{{ $loop->index }}" data-return-evidence-title>Evidence preview</h3>
                <button type="button" data-return-evidence-close aria-label="Close evidence preview"><i data-lucide="x" aria-hidden="true"></i></button>
            </header>
            <div><img data-return-evidence-full alt=""></div>
        </dialog>

    </section>

</div>

@endforeach

</div>

@endsection
