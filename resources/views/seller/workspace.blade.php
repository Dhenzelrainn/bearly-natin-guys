@extends('layouts.seller')

@section('title', $page['title'])
@section('page-title', $page['title'])

@section('content')
<div class="page-heading seller-workspace-heading">
    <div><span class="section-kicker">Seller workspace</span><h2>{{ $page['title'] }}</h2><p>{{ $page['subtitle'] }}</p></div>
    <button class="seller-secondary-button" type="button" data-workspace-demo="{{ $page['title'] }} help opened."><i data-lucide="circle-help"></i>Page guide</button>
</div>

<section class="workspace-kpi-strip" aria-label="{{ $page['title'] }} summary">
    @foreach ($page['kpis'] as [$label, $value])
        <article><span>{{ $label }}</span><strong>{{ $value }}</strong></article>
    @endforeach
</section>

<section class="workspace-card" data-seller-workspace>
    <div class="workspace-toolbar">
        <label class="workspace-search"><i data-lucide="search"></i><span class="sr-only">Search {{ strtolower($page['title']) }}</span><input type="search" placeholder="Search records" data-workspace-search></label>
        <button type="button" data-workspace-demo="Filters opened."><i data-lucide="list-filter"></i>Filters</button>
        <button type="button" data-workspace-demo="{{ $page['title'] }} data exported."><i data-lucide="download"></i>Export</button>
    </div>
    <div class="workspace-table-wrap">
        <table class="workspace-table">
            <thead><tr>@foreach ($page['columns'] as $column)<th>{{ $column }}</th>@endforeach<th>Action</th></tr></thead>
            <tbody>
                @foreach ($page['rows'] as $row)
                    <tr data-workspace-row data-search="{{ strtolower(implode(' ', $row)) }}">
                        @foreach ($row as $index => $cell)
                            <td>@if ($index === count($row) - 1)<span class="workspace-status">{{ $cell }}</span>@elseif ($index === 0)<strong>{{ $cell }}</strong>@else{{ $cell }}@endif</td>
                        @endforeach
                        <td><button class="workspace-row-action" type="button" data-workspace-demo="{{ $page['action'] }} opened for {{ $row[0] }}.">{{ $page['action'] }}</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="workspace-empty" data-workspace-empty hidden><i data-lucide="search-x"></i><strong>No matching records</strong><span>Try another search term.</span></div>
    </div>
    <footer class="workspace-footer"><span>Showing <strong data-workspace-count>{{ count($page['rows']) }}</strong> frontend preview records</span><span>No database connected</span></footer>
</section>
@endsection
