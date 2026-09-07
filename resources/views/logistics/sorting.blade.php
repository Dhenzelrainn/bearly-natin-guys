@extends('layouts.logistics')
@section('title','Sorting Center') @section('page-title','Incoming Parcels & Sorting')
@section('content')
<div class="page-intro"><div><span class="eyebrow">Parcel Intake</span><h2>Sorting Center Ledger</h2><p>Register incoming packages and classify them by destination, zone, size, and sorting status.</p></div></div>
<div class="metric-grid"><article class="metric-card"><strong>146</strong><p>Inside facility</p></article><article class="metric-card"><strong>118</strong><p>Sorted</p></article><article class="metric-card"><strong>23</strong><p>Awaiting sorting</p></article><article class="metric-card"><strong>5</strong><p>Exceptions</p></article></div>
<section class="card"><div class="card-head"><h3>Parcel intake ledger</h3><input class="field" placeholder="Search waybill, seller, destination..." data-filter="sorting"></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Waybill</th><th>Seller</th><th>Destination</th><th>Size</th><th>Zone assignment</th><th>Status</th></tr></thead><tbody>
@foreach($parcels as $p)<tr data-state-row="{{ $p['waybill'] }}" data-filter-row="sorting"><td class="table-title">{{ $p['waybill'] }}</td><td>{{ $p['seller'] }}</td><td>{{ $p['destination'] }}</td><td>{{ $p['size'] }}</td><td><select class="select" data-sort-zone><option>{{ $p['zone'] }}</option><option>SP-N1</option><option>SP-N2</option><option>SP-S2</option><option>PILA-1</option><option>CAL-1</option><option>STC-2</option></select></td><td><span data-state-badge class="badge {{ $p['status']==='Sorted'?'badge-success':($p['status']==='Exception'?'badge-danger':'badge-info') }}">{{ $p['status'] }}</span></td></tr>@endforeach
</tbody></table></div></section>
@endsection
