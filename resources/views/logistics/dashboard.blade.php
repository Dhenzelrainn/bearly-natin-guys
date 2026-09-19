@extends('layouts.logistics')
@section('title','Dashboard')
@section('page-title','Dashboard')
@section('content')
<div class="page-header">
    <div><p class="page-kicker">Operations overview</p><h2>Good afternoon, {{ explode(' ', $operator['name'])[0] }}</h2><p>Monitor today’s rider approvals, parcel movement, sorting progress, and dispatch readiness.</p></div>
    <div class="page-actions"><a class="button" href="{{ route('logistics.incoming') }}"><i data-lucide="scan-line"></i>Log incoming parcel</a><a class="button button-primary" href="{{ route('logistics.dispatch') }}"><i data-lucide="send"></i>Open dispatch board</a></div>
</div>
<section class="metric-strip" aria-label="Today's logistics metrics">
    @foreach($metrics as $metric)
    <article class="metric-item"><span class="metric-icon"><i data-lucide="{{ $metric['icon'] }}"></i></span><span class="metric-copy"><small>{{ $metric['label'] }}</small><strong>{{ number_format($metric['value']) }}</strong><span>{{ $metric['trend'] }}</span></span></article>
    @endforeach
</section>
<div class="content-grid">
    <section class="panel">
        <div class="panel-header"><div><h3>Zone readiness</h3><p>Parcels currently staged for today’s delivery runs.</p></div><a class="text-button" href="{{ route('logistics.dispatch') }}">Manage assignments</a></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Delivery area</th><th>Parcels</th><th>Sorted & ready</th><th>Available riders</th><th>Readiness</th></tr></thead><tbody>
        @foreach($zones as $zone)
            @php($percentage = round(($zone['ready'] / $zone['parcels']) * 100))
            <tr><td><strong>{{ $zone['zone'] }}</strong></td><td>{{ $zone['parcels'] }}</td><td>{{ $zone['ready'] }}</td><td>{{ $zone['riders'] }}</td><td><div class="row-actions"><div class="progress-track"><span style="width:{{ $percentage }}%"></span></div><small>{{ $percentage }}%</small></div></td></tr>
        @endforeach
        </tbody></table></div>
    </section>
    <section class="panel">
        <div class="panel-header"><div><h3>Needs attention</h3><p>Items requiring an operator decision.</p></div></div>
        <div class="panel-body attention-list">
            <a class="attention-item" href="{{ route('logistics.riders') }}"><i data-lucide="user-round-check"></i><span><strong>3 rider applications</strong><small>Credentials are ready for review</small></span><i data-lucide="chevron-right"></i></a>
            <a class="attention-item" href="{{ route('logistics.pickups') }}"><i data-lucide="package-check"></i><span><strong>2 pickup requests</strong><small>Seller requests are awaiting verification</small></span><i data-lucide="chevron-right"></i></a>
            <a class="attention-item" href="{{ route('logistics.sorting') }}"><i data-lucide="triangle-alert"></i><span><strong>1 sorting exception</strong><small>Destination zone needs correction</small></span><i data-lucide="chevron-right"></i></a>
        </div>
    </section>
</div>
<div class="content-grid equal" style="margin-top:20px">
    <section class="panel"><div class="panel-header"><div><h3>Recent activity</h3><p>Latest movement across this facility.</p></div></div><div class="panel-body activity-list">
        @foreach($activity as $item)<div class="activity-item"><span class="activity-time">{{ $item['time'] }}</span><span class="activity-dot"></span><span class="activity-copy"><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small></span></div>@endforeach
    </div></section>
    <section class="panel"><div class="panel-header"><div><h3>Quick actions</h3><p>Jump into frequent operations.</p></div></div><div class="panel-body quick-actions">
        <a class="quick-action" href="{{ route('logistics.incoming') }}"><i data-lucide="package-plus"></i><span><strong>Receive parcels</strong><small>Log arrivals at the center</small></span></a>
        <a class="quick-action" href="{{ route('logistics.sorting') }}"><i data-lucide="arrow-down-up"></i><span><strong>Sort queue</strong><small>Group parcels by area</small></span></a>
        <a class="quick-action" href="{{ route('logistics.riders') }}"><i data-lucide="badge-check"></i><span><strong>Review riders</strong><small>Approve new applications</small></span></a>
        <a class="quick-action" href="{{ route('logistics.reports') }}"><i data-lucide="file-chart-column"></i><span><strong>Generate report</strong><small>Preview performance data</small></span></a>
    </div></section>
</div>
@endsection
