@extends('layouts.seller')

@section('title', 'Publication Settings')
@section('page-title', 'Publication Settings')

@section('content')
<div class="page-heading storefront-page-heading">
    <div><span class="section-kicker">Store visibility</span><h2>Publication Settings</h2><p>Review store requirements before making your storefront visible to buyers.</p></div>
    <a class="seller-secondary-button" href="{{ route('seller.store.appearance') }}"><i data-lucide="eye"></i>Preview appearance</a>
</div>

<section class="publication-overview-card" data-publication-settings>
    <div class="publication-status-main">
        <span class="publication-status-icon"><i data-lucide="store"></i></span>
        <div><span class="section-kicker">Current status</span><h3 data-publication-title>{{ $store['published'] ? 'Store Published' : 'Store Not Published' }}</h3><p data-publication-copy>{{ $store['published'] ? 'Your storefront is currently visible to buyers.' : 'Complete the required information before publishing your storefront.' }}</p></div>
    </div>
    <span class="publication-state-badge {{ $store['published'] ? 'is-live' : '' }}" data-publication-badge><i></i>{{ $store['published'] ? 'Published' : 'Draft' }}</span>
</section>

<div class="publication-layout">
    <section class="storefront-panel publication-requirements" aria-labelledby="publication-checklist-title">
        <div class="publication-readiness-heading">
            <div><span class="section-kicker">Readiness</span><h3 id="publication-checklist-title">Publication Checklist</h3><p>Only requirements that affect buyer trust and store operation are included.</p></div>
            <div class="publication-progress-summary"><strong>{{ $completion }}%</strong><span>{{ $completeCount }} of {{ count($requirements) }} complete</span></div>
        </div>
        <div class="publication-progress" aria-label="Store publication completion: {{ $completion }} percent"><i style="width:{{ $completion }}%"></i></div>
        <div class="publication-requirement-list">
            @foreach ($requirements as $requirement)
                <article class="publication-requirement {{ $requirement['complete'] ? 'is-complete' : '' }}">
                    <span><i data-lucide="{{ $requirement['complete'] ? 'circle-check-big' : 'circle' }}"></i></span>
                    <div><strong>{{ $requirement['label'] }}</strong><small>{{ $requirement['detail'] }}</small></div>
                    <a href="{{ route($requirement['route']) }}">{{ $requirement['complete'] ? 'Review' : 'Complete' }}<i data-lucide="arrow-right"></i></a>
                </article>
            @endforeach
        </div>
    </section>

    <aside class="publication-side-column">
        <section class="storefront-panel publication-control-card">
            <span class="storefront-panel-icon"><i data-lucide="globe-2"></i></span>
            <h3>Buyer Visibility</h3>
            <p>Publishing makes your store profile and active product listings available to buyers.</p>
            <dl><div><dt>Current visibility</dt><dd data-publication-visibility>{{ $store['published'] ? 'Visible to buyers' : 'Hidden from buyers' }}</dd></div><div><dt>Store URL</dt><dd>bearly.test/store/juans-clothing</dd></div></dl>
            <button class="seller-primary-button publication-primary-action" type="button" data-publication-toggle data-published="{{ $store['published'] ? 'true' : 'false' }}" @disabled($completion < 100)><i data-lucide="{{ $store['published'] ? 'eye-off' : 'store' }}"></i><span>{{ $store['published'] ? 'Unpublish Store' : 'Publish Store' }}</span></button>
            @if ($completion < 100)<small class="publication-disabled-note"><i data-lucide="lock-keyhole"></i>Complete all requirements to enable publishing.</small>@endif
        </section>
        <section class="storefront-panel publication-information-card">
            <h3>What publishing changes</h3>
            <ul><li><i data-lucide="check"></i>Buyers can open your public store page.</li><li><i data-lucide="check"></i>Active products become discoverable.</li><li><i data-lucide="check"></i>Your verified store information is displayed.</li></ul>
            <p><i data-lucide="info"></i>Unpublishing hides the storefront but does not delete products, orders, or account data.</p>
        </section>
    </aside>
</div>
@endsection
