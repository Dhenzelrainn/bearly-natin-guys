@extends('layouts.rider')
@section('title','Pickup Jobs') @section('page-title','Items for Pickup')
@section('content')
<div class="page-intro"><div><span class="eyebrow">Rider Pickup Queue</span><h2>Items for Pickup</h2><p>Review seller notifications, assigned pickup locations, and nearby pickup jobs.</p></div></div>
<div class="metric-grid"><article class="metric-card"><strong>1</strong><p>Assigned pickup</p></article><article class="metric-card"><strong>2</strong><p>Available nearby</p></article><article class="metric-card"><strong>13</strong><p>Parcels expected</p></article><article class="metric-card"><strong>₱150</strong><p>Potential pickup earnings</p></article></div>
<section class="card"><div class="card-head"><h3>Pickup opportunities</h3><input class="field" placeholder="Search seller or location..." data-filter="rider-pickups"></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Pickup</th><th>Seller</th><th>Location</th><th>Distance</th><th>Parcels</th><th>Window</th><th>Status</th><th>Action</th></tr></thead><tbody>
@foreach($pickups as $p)<tr data-filter-row="rider-pickups"><td class="table-title">{{ $p['id'] }}</td><td>{{ $p['seller'] }}</td><td>{{ $p['address'] }}</td><td>{{ $p['distance'] }}</td><td>{{ $p['parcels'] }}</td><td>{{ $p['window'] }}</td><td><span class="badge {{ $p['status']==='Assigned'?'badge-info':'badge-warning' }}">{{ $p['status'] }}</span></td><td>@if($p['status']==='Assigned')<a class="btn btn-primary" href="{{ route('rider.pickup',$p['id']) }}">Start pickup</a>@else<button class="btn btn-secondary" data-mock-action="{{ $p['id'] }} accepted as a front-end preview.">Accept job</button>@endif</td></tr>@endforeach
</tbody></table></div></section>
@endsection
