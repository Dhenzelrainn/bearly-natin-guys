    @extends('layouts.admin')

    @section('title', 'Compliance Overview')
    @section('page-title', 'Compliance Overview')

    @section('content')
    <section class="page-hero">
        <div><span class="eyebrow">Product Compliance</span><h1>Compliance overview</h1><p>Review recent rule-based product scans. Use Category Reviews for image or category review, and Product Violations for formal cases and Admin decisions.</p></div>
        <div class="hero-context-stat">
            <span class="hero-context-icon">
                <i data-lucide="shield-alert"></i>
            </span>

            <div>
                <strong>{{ $openViolationCount }}</strong>
                <span>Open product violations</span>
            </div>
        </div>
    </section>

    <section class="dashboard-grid dashboard-grid-main compliance-grid">
        <article class="panel panel-large">
            <div class="panel-heading panel-heading-wrap">
                <div>
                    <span class="eyebrow">Text/rule-based scan history</span>
                    <h2>Recent product checks</h2>
                    <p class="table-subtext">A clear text scan is not image approval. See <a href="{{ route('admin.compliance.category-reviews') }}">Category Reviews</a> for image results and the current listing status.</p>
                    <p class="table-subtext">
                        @if ($imageSafetyEnforced)
                            Image publish gate enabled. New publish requests stay hidden until every image passes or an Admin approves them.
                            @if (! $imageCheckEnabled)
                                Automated image checks are disabled; held listings require manual Admin review.
                            @endif
                        @elseif ($imageCheckEnabled)
                            Image checks are advisory; publishing is not held for image review.
                        @else
                            Automated image checks and the image publish gate are disabled.
                        @endif
                    </p>
                </div>
                <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" placeholder="Search products..." data-table-search="compliance-table"></label>
            </div>
            <div class="table-wrap">
                <table class="admin-table" id="compliance-table">
                    <thead><tr><th>Product</th><th>Seller</th><th>Registered category</th><th>Listed category</th><th>Text risk</th><th>Text scan result</th></tr></thead>
                    <tbody>
                    @foreach ($audits as $audit)
                        <tr
                            data-table-row
                            data-search="{{ strtolower($audit['product'].' '.$audit['seller'].' '.$audit['id']) }}"
                        >

                            <td>
                                <div class="identity-cell">
                                    @include('admin.compliance.partials.product-image', [
                                        'imageUrl' => $audit['image_url'],
                                        'imageAlt' => $audit['image_alt'],
                                    ])
                                    <div class="table-primary-secondary">
                                        <strong>{{ $audit['product'] }}</strong>
                                        <small>Product #{{ $audit['product_id'] }} · {{ $audit['id'] }}</small>
                                    </div>
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
                                    class="status-badge js-compliance-status {{ $audit['status_class'] }}"
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

        <aside class="panel compliance-summary-panel">
            <div class="panel-heading">
                <div>
                    <span class="eyebrow">Formal enforcement</span>
                    <h2>Product Violations</h2>
                </div>
                <span class="status-badge badge-neutral">{{ $openViolationCount }} open</span>
            </div>
            <p class="table-subtext">Review violation history and record decisions in one place.</p>
            <a class="button button-primary button-small" href="{{ route('admin.compliance.violations') }}">
                <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                Open Product Violations
            </a>
        </aside>
    </section>
    @endsection
