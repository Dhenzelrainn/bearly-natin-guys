@extends('layouts.admin')

@section('title', 'Marketplace Taxonomy')
@section('page-title', 'Marketplace Taxonomy')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Catalog governance</span>
        <h1>Marketplace Taxonomy</h1>
        <p>Manage canonical Buyer subcategories and review Seller requests. Seller-approved business categories and Store sections remain separate.</p>
    </div>
    <div class="hero-context-stat">
        <span class="hero-context-icon"><i data-lucide="tags"></i></span>
        <div><strong>{{ $marketplaceCategories->sum(fn ($category) => $category->subcategories->count()) }}</strong><span>Canonical subcategories</span></div>
    </div>
</section>

@include('admin.partials.success-toast')

@if ($errors->any())
    <div class="panel" role="alert">
        <strong>Check the taxonomy changes.</strong>
        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<section class="panel marketplace-taxonomy-panel">
    <div class="panel-heading">
        <div><span class="eyebrow">Approved Buyer navigation</span><h2>Canonical subcategories</h2></div>
        <p>Unmapped Buyer categories are retained as navigation entries but cannot receive new Seller classifications until explicitly mapped.</p>
    </div>
    <div class="marketplace-taxonomy-list">
        @foreach ($marketplaceCategories as $category)
            @php($businessCategoryNames = $category->businessCategories->pluck('name')->values())
            <section class="marketplace-taxonomy-category" aria-labelledby="marketplace-category-{{ $category->id }}">
                <div class="marketplace-taxonomy-category-heading">
                    <div>
                        <h3 id="marketplace-category-{{ $category->id }}">{{ $category->name }}</h3>
                        <small>{{ $category->slug }}</small>
                    </div>
                    <div class="marketplace-taxonomy-mappings">
                        <span>Approved Seller categories</span>
                        <strong>{{ $businessCategoryNames->isNotEmpty() ? $businessCategoryNames->join(', ') : 'Not mapped' }}</strong>
                    </div>
                </div>

                @if ($businessCategoryNames->isNotEmpty())
                    <details class="marketplace-taxonomy-add">
                        <summary><i data-lucide="plus"></i>Add subcategory</summary>
                        <form method="POST" action="{{ route('admin.marketplace-taxonomy.subcategories.store') }}" class="marketplace-taxonomy-add-form">
                            @csrf
                            <input type="hidden" name="marketplace_category_id" value="{{ $category->id }}">
                            <label class="form-field">
                                <span>Subcategory name</span>
                                <input name="name" maxlength="120" minlength="2" required placeholder="e.g. Travel Accessories">
                            </label>
                            <label class="form-field marketplace-taxonomy-position-field">
                                <span>Display order</span>
                                <input type="number" name="position" min="0" max="10000" value="{{ $category->subcategories->max('position') + 1 }}">
                            </label>
                            <button class="button button-primary" type="submit">Add subcategory</button>
                        </form>
                    </details>
                @endif

                @if ($category->subcategories->isNotEmpty())
                    <div class="table-wrap">
                        <table class="admin-table marketplace-subcategory-table">
                            <thead><tr><th>Subcategory</th><th>Buyer-visible products</th><th>Status / order</th><th>Save</th></tr></thead>
                            <tbody>
                                @foreach ($category->subcategories as $subcategory)
                                    <tr>
                                        <td><strong>{{ $subcategory->name }}</strong><small class="table-subline">{{ $subcategory->slug }}</small></td>
                                        <td>{{ (int) $subcategory->visible_products_count }}</td>
                                        <td>
                                            <form id="marketplace-subcategory-{{ $subcategory->id }}" method="POST" action="{{ route('admin.marketplace-taxonomy.subcategories.update', $subcategory) }}">
                                                @csrf
                                                @method('PATCH')
                                                <label class="form-field marketplace-subcategory-edit-name">
                                                    <span class="sr-only">Name</span>
                                                    <input name="name" value="{{ $subcategory->name }}" maxlength="120" minlength="2" required>
                                                </label>
                                                <label class="form-field marketplace-subcategory-edit-position">
                                                    <span>Order</span>
                                                    <input type="number" name="position" min="0" max="10000" value="{{ $subcategory->position }}" required>
                                                </label>
                                                <input type="hidden" name="is_active" value="0">
                                                <label class="marketplace-subcategory-active-control">
                                                    <input type="checkbox" name="is_active" value="1" @checked($subcategory->is_active)>
                                                    <span>{{ $subcategory->is_active ? 'Active' : 'Inactive' }}</span>
                                                </label>
                                            </form>
                                        </td>
                                        <td><button class="button button-secondary" type="submit" form="marketplace-subcategory-{{ $subcategory->id }}">Save</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="marketplace-taxonomy-empty">No canonical subcategories yet.</p>
                @endif
            </section>
        @endforeach
    </div>
</section>

<section class="panel marketplace-taxonomy-requests">
    <div class="panel-heading">
        <div><span class="eyebrow">Seller requests</span><h2>Pending review</h2></div>
        <span class="status-badge badge-warning">{{ $requests->total() }} pending</span>
    </div>
    <div class="marketplace-request-list">
        @forelse ($requests as $subcategoryRequest)
            <article class="marketplace-request-row">
                <div class="marketplace-request-summary">
                    <div><span class="eyebrow">{{ $subcategoryRequest->store?->name ?? 'Store unavailable' }}</span><h3>{{ $subcategoryRequest->suggested_name }}</h3></div>
                    <dl>
                        <div><dt>Seller’s approved category</dt><dd>{{ $subcategoryRequest->businessCategory?->name ?? 'Unavailable' }}</dd></div>
                        <div><dt>Requested</dt><dd>{{ $subcategoryRequest->created_at?->format('M j, Y g:i A') ?? '—' }}</dd></div>
                        <div><dt>Seller’s reason</dt><dd>{{ $subcategoryRequest->reason }}</dd></div>
                    </dl>
                </div>
                <form method="POST" action="{{ route('admin.marketplace-taxonomy.requests.decide', $subcategoryRequest) }}" class="marketplace-request-decision" data-marketplace-request-decision>
                    @csrf
                    @method('PATCH')
                    <label class="form-field">
                        <span>Decision</span>
                        <select name="decision" data-marketplace-decision required>
                            <option value="approve">Approve and create</option>
                            @if ($subcategoryRequest->matchingSubcategories->isNotEmpty())<option value="reuse">Reuse matching entry</option>@endif
                            <option value="reject">Reject request</option>
                        </select>
                    </label>
                    <label class="form-field" data-marketplace-approval-category>
                        <span>Buyer category branch</span>
                        <select name="marketplace_category_id" data-marketplace-category-select required>
                            <option value="">Choose a mapped Buyer category</option>
                            @foreach ($subcategoryRequest->allowedMarketplaceCategories as $mappedCategory)
                                <option value="{{ $mappedCategory->id }}" @selected($subcategoryRequest->allowedMarketplaceCategories->count() === 1 && $loop->first)>{{ $mappedCategory->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($subcategoryRequest->matchingSubcategories->isNotEmpty())
                        <label class="form-field" data-marketplace-reuse-entry hidden>
                            <span>Matching canonical subcategory</span>
                            <select name="marketplace_subcategory_id" data-marketplace-reuse-select disabled>
                                <option value="">Select an existing matching entry</option>
                                @foreach ($subcategoryRequest->matchingSubcategories as $match)
                                    <option value="{{ $match->id }}">{{ $match->marketplaceCategory?->name }} · {{ $match->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label class="form-field" data-marketplace-decision-reason>
                        <span>Decision reason <small data-marketplace-reason-optional>Optional</small></span>
                        <textarea name="decision_reason" rows="2" maxlength="1000" data-marketplace-reason-input></textarea>
                    </label>
                    <button class="button button-primary" type="submit">Record decision</button>
                </form>
            </article>
        @empty
            <div class="empty-state"><strong>No pending subcategory requests.</strong><p>Seller requests will appear here for review.</p></div>
        @endforelse
    </div>
    @if ($requests->hasPages())<div class="panel-footer">{{ $requests->links() }}</div>@endif
</section>
@endsection

@push('scripts')
<script>
(() => {
    document.querySelectorAll('[data-marketplace-request-decision]').forEach((form) => {
        const decision = form.querySelector('[data-marketplace-decision]');
        const categoryField = form.querySelector('[data-marketplace-approval-category]');
        const categorySelect = form.querySelector('[data-marketplace-category-select]');
        const reuseField = form.querySelector('[data-marketplace-reuse-entry]');
        const reuseSelect = form.querySelector('[data-marketplace-reuse-select]');
        const reasonInput = form.querySelector('[data-marketplace-reason-input]');
        const reasonOptional = form.querySelector('[data-marketplace-reason-optional]');

        const sync = () => {
            const value = decision?.value || 'approve';
            const approving = value === 'approve';
            const reusing = value === 'reuse';
            if (categoryField) categoryField.hidden = !approving;
            if (categorySelect) {
                categorySelect.disabled = !approving;
                categorySelect.required = approving;
            }
            if (reuseField) reuseField.hidden = !reusing;
            if (reuseSelect) {
                reuseSelect.disabled = !reusing;
                reuseSelect.required = reusing;
            }
            if (reasonInput) reasonInput.required = value === 'reject';
            if (reasonOptional) reasonOptional.hidden = value === 'reject';
        };

        decision?.addEventListener('change', sync);
        sync();
    });
})();
</script>
@endpush
