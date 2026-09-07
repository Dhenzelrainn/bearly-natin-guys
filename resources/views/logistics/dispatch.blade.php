@extends('layouts.logistics')
@section('title','Delivery Assignment') @section('page-title','Delivery Assignment')
@section('content')
<div class="page-intro"><div><span class="eyebrow">Dispatch Control</span><h2>Assign Sorted Parcels</h2><p>Match ready parcel batches to active riders based on area and zone.</p></div></div>
<div class="zone-grid">@foreach($zones as $z)<article class="zone-card"><span class="eyebrow">{{ $z['zone'] }}</span><h4>{{ $z['area'] }}</h4><p>Ready for rider assignment</p><div class="zone-stats"><div><strong>{{ $z['ready'] }}</strong>Parcels</div><div><strong>{{ count($z['riders']) }}</strong>Available</div></div><select class="select" style="width:100%;margin-bottom:9px">@foreach($z['riders'] as $r)<option>{{ $r }}</option>@endforeach</select><button class="btn btn-primary" style="width:100%" data-dispatch><i data-lucide="send"></i>Create dispatch</button></article>@endforeach</div>
@endsection
