@extends('layouts.rider')
@section('title','Deliveries') @section('page-title','Items for Delivery')
@section('content')
<div class="page-intro"><div><span class="eyebrow">Active Route</span><h2>Delivery Dashboard</h2><p>See assigned packages, delivery notifications, payment collection requirements, and begin each drop-off workflow.</p></div></div>
<div class="metric-grid"><article class="metric-card"><strong>3</strong><p>Items for delivery</p></article><article class="metric-card"><strong>1</strong><p>In transit</p></article><article class="metric-card"><strong>2</strong><p>Next stops</p></article><article class="metric-card"><strong>₱210</strong><p>Route earnings</p></article></div>
<section class="card"><div class="card-head"><h3>Assigned deliveries</h3><input class="field" placeholder="Search customer or waybill..." data-filter="rider-deliveries"></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Delivery</th><th>Waybill</th><th>Customer</th><th>Address</th><th>Zone</th><th>Payment</th><th>Status</th><th>Action</th></tr></thead><tbody>
@foreach($deliveries as $d)<tr data-filter-row="rider-deliveries"><td class="table-title">{{ $d['id'] }}</td><td>{{ $d['waybill'] }}</td><td>{{ $d['customer'] }}</td><td>{{ $d['address'] }}</td><td>{{ $d['zone'] }}</td><td>{{ $d['cod'] }}</td><td><span class="badge {{ $d['status']==='In Transit'?'badge-warning':'badge-info' }}">{{ $d['status'] }}</span></td><td><a class="btn btn-primary" href="{{ route('rider.deliver',$d['id']) }}">Open delivery</a></td></tr>@endforeach
</tbody></table></div></section>
@endsection
