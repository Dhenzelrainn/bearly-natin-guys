    @extends('layouts.admin')

    @section('title', 'Seller Compliance')
    @section('page-title', 'Monitor Seller Compliance')

    @section('content')
    <section class="page-hero">
        <div><span class="eyebrow">Compliance & Disputes</span><h1>Monitor seller compliance</h1><p>Verify whether products belong to registered seller categories, review flagged listings, and simulate warnings or suspensions.</p></div>
        <div class="hero-context-stat">
            <span class="hero-context-icon">
                <i data-lucide="shield-alert"></i>
            </span>

            <div>
                <strong data-compliance-flag-count>{{ count($flagged) }}</strong>
                <span>Flagged items require review</span>
            </div>
        </div>
    </section>

    <section class="dashboard-grid dashboard-grid-main compliance-grid">
        <article class="panel panel-large">
            <div class="panel-heading panel-heading-wrap">
                <div><span class="eyebrow">Category audit</span><h2>Product compliance table</h2></div>
                <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" placeholder="Search products..." data-table-search="compliance-table"></label>
            </div>
            <div class="table-wrap">
                <table class="admin-table" id="compliance-table">
                    <thead><tr><th>Product</th><th>Seller</th><th>Registered category</th><th>Listed category</th><th>Risk</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($audits as $audit)
                        <tr
                            data-table-row
                            data-search="{{ strtolower($audit['product'].' '.$audit['seller'].' '.$audit['id']) }}"
                            data-compliance-product="{{ $audit['product'] }}"
                        >

                            <td>
                                <div class="table-primary-secondary">
                                    <strong>{{ $audit['product'] }}</strong>
                                    <small>{{ $audit['id'] }}</small>
                                </div>
                            </td>

                            <td>
                                {{ $audit['seller'] }}
                            </td>

                            <td>
                                {{ $audit['registered'] }}
                            </td>

                            <td>
                                {{ $audit['listed'] }}
                            </td>

                            <td>
                                <span class="risk-badge risk-{{ strtolower($audit['risk']) }}">
                                    {{ $audit['risk'] }}
                                </span>
                            </td>

                            <td>
                                <span
                                    class="status-badge js-compliance-status
                                    {{
                                        $audit['status'] === 'Compliant'
                                            ? 'badge-success'
                                            : ($audit['status'] === 'Flagged'
                                                ? 'badge-danger'
                                                : 'badge-warning')
                                    }}"
                                >
                                    {{ $audit['status'] }}
                                </span>
                            </td>

                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="table-empty" data-table-empty="compliance-table" hidden><i data-lucide="search-x"></i><strong>No products found</strong></div>
            </div>
        </article>

        <aside class="panel flagged-panel">

            <div class="panel-heading">

                <div>
                    <span class="eyebrow">Manual review</span>
                    <h2>Flagged items</h2>
                </div>

                <span class="status-badge badge-neutral">
                    {{ count($flagged) }} items
                </span>

            </div>

            <div class="flagged-list">

                @foreach ($flagged as $item)

                    <button
                        type="button"
                        class="flag-card"
                        data-open-drawer="compliance-drawer"
                        data-flag-card
                        data-flag-id="{{ $item['id'] }}"
                        data-flag-product="{{ $item['product'] }}"
                        data-flag-seller="{{ $item['seller'] }}"
                        data-flag-reason="{{ $item['reason'] }}"
                        data-flag-risk="{{ $item['risk'] }}"
                        data-flag-warnings="{{ $item['warnings'] }}"
                        data-decision-url="{{ $item['decision_url'] }}"
                    >

                        <span class="flag-icon">
                            <i data-lucide="triangle-alert"></i>
                        </span>

                        <span class="flag-copy">
                            <strong>{{ $item['product'] }}</strong>

                            <small>
                                {{ $item['seller'] }} • {{ $item['id'] }}
                            </small>

                            <em>
                                {{ $item['reason'] }}
                            </em>
                        </span>

                        <span class="risk-badge risk-{{ strtolower($item['risk']) }}">
                            {{ $item['risk'] }}
                        </span>

                    </button>

                @endforeach

            </div>

        </aside>
    </section>

    <div class="drawer-shell" data-drawer="compliance-drawer" hidden>
        <button class="drawer-backdrop" type="button" data-close-drawer></button>
        <aside class="drawer-card">
            <div class="drawer-heading"><div><span class="eyebrow">Compliance action</span><h2 data-drawer-product>Flagged product</h2></div><button class="icon-button" type="button" data-close-drawer><i data-lucide="x"></i></button></div>
            <div class="drawer-section"><span>Seller</span><strong data-drawer-seller>Seller</strong></div>
            <div class="drawer-section"><span>Risk level</span><strong data-drawer-risk>High</strong></div>
            <div class="drawer-section"><span>Existing warnings</span><strong><span data-drawer-warnings>0</span> warning(s)</strong></div>
            <div class="drawer-callout"><i data-lucide="shield-alert"></i><div><strong>Reason for review</strong><p data-drawer-reason>Reason</p></div></div>
            <form method="POST" data-compliance-decision-form>
                @csrf
                <label class="form-field"><span>Admin notes</span><textarea name="note" rows="5" required placeholder="Add a clear explanation for the seller..." data-compliance-notes></textarea><small data-compliance-note-error hidden>Please add admin notes before recording a decision.</small></label>
                <div class="drawer-actions">
                    <button class="button button-secondary" type="submit" name="decision" value="dismiss"><i data-lucide="shield-check"></i> Dismiss</button>
                    <button class="button button-warning" type="submit" name="decision" value="request_correction"><i data-lucide="rotate-ccw"></i> Request correction</button>
                    <button class="button button-warning" type="submit" name="decision" value="confirm"><i data-lucide="triangle-alert"></i> Confirm violation</button>
                    <button class="button button-danger-soft" type="submit" name="decision" value="warn_seller"><i data-lucide="badge-alert"></i> Issue warning</button>
                    <button class="button button-danger" type="submit" name="decision" value="remove_product"><i data-lucide="ban"></i> Remove product</button>
                </div>
            </form>
        </aside>
    </div>
    @endsection
