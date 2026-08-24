@extends('layouts.admin')

@section('title', 'Complaints & Disputes')
@section('page-title', 'Manage Complaints & Disputes')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 05</span><h1>Manage complaints and disputes</h1><p>Review complaint details and evidence, then coordinate with Buyer, Seller, and Courier from one static resolution workspace.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-mock-action="Dispute queue refreshed."><i data-lucide="refresh-cw"></i> Refresh cases</button></div>
</section>

<section class="dispute-layout">
    <aside class="panel dispute-list-panel">
        <div class="panel-heading"><div><span class="eyebrow">Open cases</span><h2>Resolution queue</h2></div><span class="status-badge badge-warning">{{ count($disputes) }} open</span></div>
        <label class="field-with-icon"><i data-lucide="search"></i><input type="search" placeholder="Search case ID or subject..." data-dispute-search></label>
        <div class="case-list">
            @foreach ($disputes as $index => $dispute)
                <button type="button" class="case-card {{ $index === 0 ? 'is-active' : '' }}" data-case-card data-case-search="{{ strtolower($dispute['id'].' '.$dispute['subject'].' '.$dispute['buyer'].' '.$dispute['seller']) }}">
                    <div class="case-card-top"><span>{{ $dispute['id'] }}</span><span class="priority-dot priority-{{ strtolower($dispute['priority']) }}">{{ $dispute['priority'] }}</span></div>
                    <strong>{{ $dispute['subject'] }}</strong><small>{{ $dispute['buyer'] }} vs {{ $dispute['seller'] }}</small>
                    <div class="case-card-bottom"><span>{{ $dispute['opened'] }}</span><span>{{ $dispute['status'] }}</span></div>
                </button>
            @endforeach
        </div>
    </aside>

    <div class="dispute-workspace">
        @php $active = $disputes[0]; @endphp
        <article class="panel dispute-detail-card">
            <div class="panel-heading">
                <div><span class="eyebrow">{{ $active['id'] }}</span><h2>{{ $active['subject'] }}</h2><p>{{ $active['status'] }} • {{ $active['opened'] }}</p></div>
                <div class="row-actions"><span class="status-badge badge-danger">{{ $active['priority'] }} priority</span><button class="button button-primary button-small" type="button" data-mock-action="Case marked resolved in preview."><i data-lucide="circle-check"></i> Resolve case</button></div>
            </div>
            <div class="detail-grid dispute-summary-grid">
                <div><span>Buyer</span><strong>{{ $active['buyer'] }}</strong></div><div><span>Seller</span><strong>{{ $active['seller'] }}</strong></div><div><span>Courier</span><strong>{{ $active['courier'] }}</strong></div><div><span>Order value</span><strong>{{ $active['amount'] }}</strong></div>
            </div>
            <div class="complaint-copy"><span class="section-label">Complaint summary</span><p>The buyer reported visible package damage and product defects after delivery. The seller requested additional evidence, while the courier submitted delivery proof. This mock workspace shows how the admin can compare evidence before deciding the outcome.</p></div>
        </article>

        <div class="dashboard-grid dashboard-grid-secondary dispute-subgrid">
            <article class="panel">
                <div class="panel-heading"><div><span class="eyebrow">Supporting evidence</span><h2>Case files</h2></div></div>
                <div class="evidence-grid">
                    @foreach ($evidence as $item)
                        <button type="button" class="evidence-card" data-mock-action="{{ $item['label'] }} preview opened."><span><i data-lucide="{{ $item['type'] === 'Image' ? 'image' : 'file-text' }}"></i></span><div><strong>{{ $item['label'] }}</strong><small>{{ $item['meta'] }}</small></div><i data-lucide="external-link"></i></button>
                    @endforeach
                </div>
            </article>
            <article class="panel">
                <div class="panel-heading"><div><span class="eyebrow">Case timeline</span><h2>Recent updates</h2></div></div>
                <div class="timeline-list">
                    @foreach ($timeline as $item)
                        <div class="timeline-row"><span>{{ $item['time'] }}</span><div><i></i><p>{{ $item['text'] }}</p></div></div>
                    @endforeach
                </div>
            </article>
        </div>

        <article class="panel coordination-panel">
            <div class="panel-heading"><div><span class="eyebrow">Multi-party coordination</span><h2>Contact participants</h2></div><span class="status-badge badge-info">Static messaging preview</span></div>
            <div class="coordination-grid">
                @foreach ([['Buyer',$active['buyer'],'shopping-bag'],['Seller',$active['seller'],'store'],['Courier',$active['courier'],'bike']] as $party)
                    <div class="party-card"><span class="party-icon"><i data-lucide="{{ $party[2] }}"></i></span><div><small>{{ $party[0] }}</small><strong>{{ $party[1] }}</strong></div><button type="button" class="button button-ghost button-small" data-mock-action="Message panel opened for {{ $party[1] }}."><i data-lucide="message-circle"></i> Message</button></div>
                @endforeach
            </div>
            <label class="form-field"><span>Internal resolution note</span><textarea rows="4" placeholder="Document the admin decision or next coordination step..."></textarea></label>
            <div class="panel-footer-actions"><button class="button button-secondary" type="button" data-mock-action="Internal note saved in preview.">Save note</button><button class="button button-primary" type="button" data-mock-action="Participant update sent in preview."><i data-lucide="send"></i> Send case update</button></div>
        </article>
    </div>
</section>
@endsection
