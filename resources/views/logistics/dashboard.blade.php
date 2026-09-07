@extends('layouts.logistics')
@section('title','Dashboard') @section('page-title','Operations Dashboard')
@section('content')
<div class="page-intro"><div><span class="eyebrow">Logistics Overview</span><h2>Sorting Center Operations</h2><p>Monitor rider approvals, parcel intake, active delivery movement, and shipments waiting for dispatch.</p></div></div>
<div class="metric-grid">@foreach($metrics as $m)<article class="metric-card"><div class="metric-top"><span class="metric-icon"><i data-lucide="{{ $m['icon'] }}"></i></span><small>{{ $m['trend'] }}</small></div><strong>{{ $m['value'] }}</strong><p>{{ $m['label'] }}</p></article>@endforeach</div>
<div class="grid-2"><section class="card"><div class="card-head"><h3>Zone readiness</h3><a class="btn btn-soft" href="{{ route('logistics.dispatch') }}">Open dispatch</a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Area</th><th>Parcels</th><th>Ready</th><th>Active riders</th></tr></thead><tbody>@foreach($zones as $z)<tr><td class="table-title">{{ $z['zone'] }}</td><td>{{ $z['parcels'] }}</td><td>{{ $z['ready'] }}</td><td>{{ $z['riders'] }}</td></tr>@endforeach</tbody></table></div></section>
<section class="card"><div class="card-head"><h3>Recent activity</h3></div><div class="card-body activity-list">@foreach($activity as $a)<div class="activity-item"><time>{{ $a['time'] }}</time><div><strong>{{ $a['title'] }}</strong><p>{{ $a['detail'] }}</p></div></div>@endforeach</div></section></div>
@endsection
