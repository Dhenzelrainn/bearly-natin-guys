@extends('layouts.logistics')
@section('title','Pickup Verification') @section('page-title','Parcel Pickup Verification')
@section('content')
<div class="page-intro"><div><span class="eyebrow">Seller Handover</span><h2>Pickup Requests</h2><p>Verify seller pickup requests, parcel quantities, pickup locations, and collection windows before rider assignment.</p></div></div>
<section class="card"><div class="card-head"><h3>Seller pickup queue</h3><input class="field" placeholder="Search seller or pickup ID..." data-filter="pickups"></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Pickup</th><th>Seller</th><th>Location</th><th>Parcels</th><th>Window</th><th>Status</th><th>Decision</th></tr></thead><tbody>
@foreach($pickups as $p)<tr data-state-row="{{ $p['id'] }}" data-filter-row="pickups"><td class="table-title">{{ $p['id'] }}</td><td>{{ $p['seller'] }}</td><td>{{ $p['location'] }}</td><td>{{ $p['parcels'] }}</td><td>{{ $p['window'] }}</td><td><span data-state-badge class="badge {{ $p['status']==='Verified'?'badge-success':'badge-warning' }}">{{ $p['status'] }}</span></td><td><div class="actions"><button class="btn btn-success" data-status-action="Verified">Verify & approve</button><button class="btn btn-danger" data-status-action="Rejected">Reject</button></div></td></tr>@endforeach
</tbody></table></div></section>
@endsection
