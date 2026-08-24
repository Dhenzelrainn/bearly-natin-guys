@extends('layouts.admin')

@section('title', 'Platform Settings')
@section('page-title', 'Manage Platform Settings')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 08</span><h1>Manage platform settings</h1><p>Post announcements and preview marketplace policy updates from a front-end-only administrative editor.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-mock-action="Platform settings reset to preview defaults."><i data-lucide="rotate-ccw"></i> Reset preview</button></div>
</section>

<section class="settings-grid">
    <article class="panel settings-editor-panel">
        <div class="panel-heading"><div><span class="eyebrow">Announcements</span><h2>Post platform announcement</h2></div><span class="status-badge badge-info">Editor</span></div>
        <div class="form-grid two-column-form">
            <label class="form-field"><span>Announcement title</span><input type="text" value="Weekend Shipping Advisory" data-announcement-title></label>
            <label class="form-field"><span>Audience</span><select data-announcement-audience><option>All users</option><option>Buyers</option><option>Sellers</option><option>Couriers</option></select></label>
        </div>
        <label class="form-field"><span>Message</span><textarea rows="7" data-announcement-body>Courier pickup times may be slightly longer this weekend due to expected order volume. Sellers are encouraged to prepare parcels early.</textarea></label>
        <div class="editor-toolbar"><button type="button"><strong>B</strong></button><button type="button"><em>I</em></button><button type="button"><i data-lucide="list"></i></button><button type="button"><i data-lucide="link"></i></button><span></span><button type="button" data-mock-action="Announcement image picker opened."><i data-lucide="image-plus"></i></button></div>
        <div class="panel-footer-actions"><button class="button button-secondary" type="button" data-mock-action="Announcement saved as draft.">Save draft</button><button class="button button-primary" type="button" data-mock-action="Announcement published in preview."><i data-lucide="megaphone"></i> Publish announcement</button></div>
    </article>

    <aside class="panel preview-panel">
        <div class="panel-heading"><div><span class="eyebrow">Live preview</span><h2>Announcement card</h2></div></div>
        <div class="announcement-preview"><div class="announcement-preview-icon"><i data-lucide="megaphone"></i></div><span class="status-badge badge-warning" data-preview-audience>All users</span><h3 data-preview-title>Weekend Shipping Advisory</h3><p data-preview-body>Courier pickup times may be slightly longer this weekend due to expected order volume. Sellers are encouraged to prepare parcels early.</p><small>Published by Bearly Admin • Just now</small></div>
        <div class="recent-announcements"><span class="section-label">Recent announcements</span>@foreach($announcements as $announcement)<div><strong>{{ $announcement['title'] }}</strong><small>{{ $announcement['audience'] }} • {{ $announcement['date'] }}</small><span class="status-badge {{ $announcement['status'] === 'Published' ? 'badge-success' : 'badge-info' }}">{{ $announcement['status'] }}</span></div>@endforeach</div>
    </aside>
</section>

<section class="panel policy-panel">
    <div class="panel-heading"><div><span class="eyebrow">Platform policies</span><h2>Terms & marketplace policy editor</h2><p>Last mock update: {{ $policy['updated'] }}</p></div><button class="button button-secondary button-small" type="button" data-mock-action="Policy version history opened."><i data-lucide="history"></i> Version history</button></div>
    <div class="policy-editor-grid">
        <div><label class="form-field"><span>Policy title</span><input type="text" value="{{ $policy['title'] }}"></label><label class="form-field"><span>Policy content</span><textarea rows="12" data-policy-editor>{{ $policy['body'] }}</textarea></label><div class="panel-footer-actions"><button class="button button-secondary" type="button" data-mock-action="Policy draft saved.">Save draft</button><button class="button button-primary" type="button" data-mock-action="Policy update published in preview.">Publish update</button></div></div>
        <div class="policy-preview"><span class="section-label">Rich-text preview</span><h3>{{ $policy['title'] }}</h3><p data-policy-preview>{{ $policy['body'] }}</p><div class="policy-note"><i data-lucide="info"></i><span>This is a static preview. No policy is actually being changed.</span></div></div>
    </div>
</section>
@endsection
