@extends('layouts.logistics')
@section('title','Delivery Monitoring') @section('page-title','Delivery Monitoring')
@section('content')
<div class="page-intro"><div><span class="eyebrow">Live Operations</span><h2>Assigned Delivery Monitoring</h2><p>Track dispatches across Assigned, In Transit, Delivered, and Failed stages.</p></div></div>
<section class="card"><div class="card-head"><h3>Current dispatches</h3><input class="field" placeholder="Search rider, dispatch, zone..." data-filter="monitoring"></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Dispatch</th><th>Rider</th><th>Zone</th><th>Parcels</th><th>Progress</th><th>Status</th><th>Last update</th></tr></thead><tbody>
@foreach($deliveries as $d)<tr data-filter-row="monitoring"><td class="table-title">{{ $d['id'] }}</td><td>{{ $d['rider'] }}</td><td>{{ $d['zone'] }}</td><td>{{ $d['parcels'] }}</td><td style="min-width:150px"><div class="progress"><span style="width:{{ $d['progress'] }}%"></span></div><span class="table-sub">{{ $d['progress'] }}% complete</span></td><td><span class="badge {{ $d['status']==='Delivered'?'badge-success':($d['status']==='Failed'?'badge-danger':($d['status']==='Assigned'?'badge-info':'badge-warning')) }}">{{ $d['status'] }}</span></td><td>{{ $d['last'] }}</td></tr>@endforeach
</tbody></table></div></section>
@endsection
