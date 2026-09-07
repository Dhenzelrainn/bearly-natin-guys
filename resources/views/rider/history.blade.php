@extends('layouts.rider')
@section('title','History') @section('page-title','Delivery History')
@section('content')
<div class="page-intro"><div><span class="eyebrow">Completed Work</span><h2>Delivery History</h2><p>Search delivered and returned jobs, filter by date, and review earnings attached to each delivery.</p></div></div>
<div class="filterbar"><input class="field" placeholder="Search ID, customer, area..." data-filter="history"><input class="date-field" type="date" value="2026-09-01"><input class="date-field" type="date" value="2026-09-06"><select class="select"><option>All statuses</option><option>Delivered</option><option>Returned</option></select></div>
<section class="card"><div class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Delivery</th><th>Customer</th><th>Area</th><th>Status</th><th>Earning</th></tr></thead><tbody>@foreach($history as $h)<tr data-filter-row="history"><td>{{ $h['date'] }}</td><td class="table-title">{{ $h['id'] }}</td><td>{{ $h['customer'] }}</td><td>{{ $h['area'] }}</td><td><span class="badge {{ $h['status']==='Delivered'?'badge-success':'badge-danger' }}">{{ $h['status'] }}</span></td><td>{{ $h['earning'] }}</td></tr>@endforeach</tbody></table></div></section>
@endsection
