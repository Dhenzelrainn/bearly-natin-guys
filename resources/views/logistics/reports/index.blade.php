@extends('logistics.layouts.app')

@section('title', 'Reports')
@section('page-title', 'Reports')

@section('content')
    <div class="page-header">
        <div>
            <p class="page-kicker">
                Performance analytics
            </p>

            <h2>
                Logistics reports
            </h2>

            <p>
                Review parcel throughput, delivery outcomes,
                sorting performance, and rider productivity.
            </p>
        </div>

        <div class="page-actions">
            <button
                class="button button-primary"
                type="button"
                data-report-export
            >
                <i data-lucide="download"></i>
                Export CSV
            </button>
        </div>
    </div>

    <section
        class="panel"
        style="margin-bottom: 20px"
    >
        <form
            class="table-toolbar"
            method="GET"
            action="{{ route('logistics.reports.index') }}"
        >
            <div class="field">
                <label>
                    Report type
                </label>

                <select
                    class="filter-select"
                    disabled
                >
                    <option>
                        Operations summary
                    </option>
                </select>
            </div>

            <div class="field">
                <label for="report-from">
                    From
                </label>

                <input
                    id="report-from"
                    name="from"
                    type="date"
                    value="{{ $reportRange['from'] }}"
                >
            </div>

            <div class="field">
                <label for="report-to">
                    To
                </label>

                <input
                    id="report-to"
                    name="to"
                    type="date"
                    value="{{ $reportRange['to'] }}"
                >

                @error('to')
                    <small class="field-error">
                        {{ $message }}
                    </small>
                @enderror
            </div>

            <button
                class="button button-primary"
                type="submit"
            >
                <i data-lucide="filter"></i>
                Apply range
            </button>
        </form>
    </section>

    <section class="metric-strip">
        <article class="metric-item">
            <span class="metric-icon">
                <i data-lucide="package"></i>
            </span>

            <span class="metric-copy">
                <small>
                    Parcel throughput
                </small>

                <strong>
                    {{ number_format($summary['throughput']) }}
                </strong>

                <span>
                    Parcels received in selected period
                </span>
            </span>
        </article>

        <article class="metric-item">
            <span class="metric-icon">
                <i data-lucide="package-check"></i>
            </span>

            <span class="metric-copy">
                <small>
                    Delivered
                </small>

                <strong>
                    {{ number_format($summary['delivered']) }}
                </strong>

                <span>
                    Parcels completed in selected period
                </span>
            </span>
        </article>

        <article class="metric-item">
            <span class="metric-icon">
                <i data-lucide="badge-check"></i>
            </span>

            <span class="metric-copy">
                <small>
                    Success rate
                </small>

                <strong>
                    {{ $summary['success_rate'] }}%
                </strong>

                <span>
                    {{ number_format(
                        $summary['resolved_delivery_outcomes']
                    ) }}
                    resolved delivery outcomes
                </span>
            </span>
        </article>

        <article class="metric-item">
            <span class="metric-icon">
                <i data-lucide="timer"></i>
            </span>

            <span class="metric-copy">
                <small>
                    Average sort time
                </small>

                <strong>
                    {{ $summary['avg_sort_time'] }}
                </strong>

                <span>
                    Receipt to initial sorting
                </span>
            </span>
        </article>
    </section>

    <div class="content-grid equal">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Daily parcel throughput
                    </h3>

                    <p>
                        Packages processed over the selected
                        reporting period.
                    </p>
                </div>
            </div>

            <div class="panel-body">
                @php
                    $maxDailyVolume = max(
                        1,
                        collect($dailyVolumes)->max() ?? 0
                    );

                @endphp

                <div class="bar-chart">
                    @foreach($dailyVolumes as $index => $volume)
                        <div class="bar-column">
                            <strong>
                                {{ $volume }}
                            </strong>

                            <div
                                class="bar"
                                style="
                                    height:
                                    {{ round(
                                        ($volume / $maxDailyVolume)
                                        * 175
                                    ) }}px
                                "
                            ></div>

                            <small>
                                {{ $dailyLabels[$index] ?? '' }}
                            </small>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Delivery status breakdown
                    </h3>

                    <p>
                        Outcome distribution for the same period.
                    </p>
                </div>
            </div>

            <div class="panel-body breakdown-list">
                @foreach($statusBreakdown as $item)
                    <div class="breakdown-row">
                        <span>
                            {{ $item['label'] }}
                        </span>

                        <div class="breakdown-bar">
                            <span
                                style="
                                    width:
                                    {{ $item['share'] }}%
                                "
                            ></span>
                        </div>

                        <strong>
                            {{ $item['share'] }}%
                        </strong>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <section
        class="panel"
        style="margin-top: 20px"
    >
        <div class="panel-header">
            <div>
                <h3>
                    Rider performance
                </h3>

                <p>
                    Assigned parcels and delivery attempt
                    outcomes for the selected period.
                </p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>
                            Rider
                        </th>

                        <th>
                            Assigned
                        </th>

                        <th>
                            Delivered
                        </th>

                        <th>
                            Failed
                        </th>

                        <th>
                            Success rate
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($riderStats as $rider)
                        <tr data-report-row>
                            <td>
                                <strong>
                                    {{ $rider['name'] }}
                                </strong>
                            </td>

                            <td>
                                {{ $rider['assigned'] }}
                            </td>

                            <td>
                                {{ $rider['delivered'] }}
                            </td>

                            <td>
                                {{ $rider['failed'] }}
                            </td>

                            <td>
                                <span class="status-badge is-success">
                                    {{ $rider['rate'] }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="5"
                                class="table-empty"
                            >
                                No rider performance data
                                is available for this period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection