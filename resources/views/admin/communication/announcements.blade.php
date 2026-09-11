@extends('layouts.admin')

@section('title', 'Announcements')
@section('page-title', 'Announcements')

@section('content')

<section class="page-hero">

    <div>
        <span class="eyebrow">Communication</span>
        <h1>Platform announcements</h1>

        <p>
            Create, schedule, publish, and manage platform-wide announcements
            for Buyers, Sellers, Logistics Centers, Riders, or all users.
        </p>
    </div>


    <div class="hero-summary-card">
        <span class="metric-icon">
            <i data-lucide="megaphone"></i>
        </span>

        <div>
            <strong>{{ count($announcements) }}</strong>
            <small>Announcement records</small>
        </div>
    </div>

</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">Announcement management</span>
            <h2>Published & scheduled notices</h2>
        </div>


        <div class="table-toolbar">

            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search announcement..."
                    data-table-search="announcements-table"
                >
            </label>


            <select
                class="select-field"
                data-table-filter="announcements-table"
                data-filter-key="audience"
            >
                <option value="">All audiences</option>
                <option value="All Users">All Users</option>
                <option value="Buyers">Buyers</option>
                <option value="Sellers">Sellers</option>
                <option value="Logistics Centers">Logistics Centers</option>
                <option value="Riders / Couriers">Riders / Couriers</option>
            </select>


            <select
                class="select-field"
                data-table-filter="announcements-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option value="Published">Published</option>
                <option value="Scheduled">Scheduled</option>
                <option value="Draft">Draft</option>
            </select>


            <button
                type="button"
                class="button button-primary"
                data-open-modal="create-announcement"
            >
                <i data-lucide="plus"></i>
                New Announcement
            </button>

        </div>

    </div>


    <div class="table-wrap">

        <table class="admin-table" id="announcements-table">

            <thead>
                <tr>
                    <th>Announcement</th>
                    <th>Audience</th>
                    <th>Author</th>
                    <th>Publish Date</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>


            <tbody>

                @foreach ($announcements as $announcement)

                    <tr
                        data-table-row
                        data-audience="{{ $announcement['audience'] }}"
                        data-status="{{ $announcement['status'] }}"
                        data-search="{{
                            strtolower(
                                $announcement['id'].' '.
                                $announcement['title'].' '.
                                $announcement['audience'].' '.
                                $announcement['author'].' '.
                                $announcement['message']
                            )
                        }}"
                    >

                        <td>

                            <div class="identity-cell">

                                <span class="avatar avatar-soft">
                                    <i data-lucide="megaphone"></i>
                                </span>


                                <div>
                                    <strong>{{ $announcement['title'] }}</strong>

                                    <small>
                                        {{ $announcement['id'] }}
                                    </small>
                                </div>

                            </div>

                        </td>


                        <td>
                            {{ $announcement['audience'] }}
                        </td>


                        <td>
                            {{ $announcement['author'] }}
                        </td>


                        <td>

                            <strong>{{ $announcement['publish_date'] }}</strong>

                            @if ($announcement['publish_time'] !== '—')
                                <small>{{ $announcement['publish_time'] }}</small>
                            @endif

                        </td>


                        <td>

                            <span
                                class="status-badge
                                {{
                                    $announcement['status'] === 'Published'
                                        ? 'badge-success'
                                        : ($announcement['status'] === 'Scheduled'
                                            ? 'badge-warning'
                                            : '')
                                }}"
                            >
                                {{ $announcement['status'] }}
                            </span>

                        </td>


                        <td class="align-right">

                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="announcement-{{ $loop->index }}"
                            >
                                <i data-lucide="eye"></i>
                                View
                            </button>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>


        <div
            class="table-empty"
            data-table-empty="announcements-table"
            hidden
        >
            <i data-lucide="search-x"></i>
            <strong>No announcements found</strong>
            <span>Try another search or filter.</span>
        </div>

    </div>

</section>


@foreach ($announcements as $announcement)

<div
    class="modal-shell"
    data-modal="announcement-{{ $loop->index }}"
    hidden
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
    ></button>


    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">{{ $announcement['id'] }}</span>
                <h2>{{ $announcement['title'] }}</h2>
            </div>


            <button
                type="button"
                class="icon-button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="review-details">

            <h3 class="section-subtitle">
                Announcement information
            </h3>


            <div class="detail-grid">

                <div>
                    <span>Audience</span>
                    <strong>{{ $announcement['audience'] }}</strong>
                </div>

                <div>
                    <span>Status</span>
                    <strong>{{ $announcement['status'] }}</strong>
                </div>

                <div>
                    <span>Author</span>
                    <strong>{{ $announcement['author'] }}</strong>
                </div>

                <div>
                    <span>Publish date</span>
                    <strong>{{ $announcement['publish_date'] }}</strong>
                </div>

                <div>
                    <span>Publish time</span>
                    <strong>{{ $announcement['publish_time'] }}</strong>
                </div>

            </div>


            <div class="detail-note">
                <span>Announcement message</span>

                <p>
                    {{ $announcement['message'] }}
                </p>
            </div>

        </div>


        <div class="modal-footer decision-footer">

            @if ($announcement['status'] !== 'Published')

                <button
                    type="button"
                    class="button button-secondary"
                    data-mock-action="{{ $announcement['title'] }} published."
                >
                    <i data-lucide="send"></i>
                    Publish Now
                </button>

            @endif


            <button
                type="button"
                class="button button-ghost"
                data-mock-action="{{ $announcement['title'] }} opened for editing."
            >
                <i data-lucide="pencil"></i>
                Edit
            </button>


            <button
                type="button"
                class="button button-danger-soft"
                data-mock-action="{{ $announcement['title'] }} archived."
            >
                <i data-lucide="archive"></i>
                Archive
            </button>

        </div>

    </section>

</div>

@endforeach



<div
    class="modal-shell"
    data-modal="create-announcement"
    hidden
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
    ></button>


    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">Communication</span>
                <h2>Create announcement</h2>
            </div>


            <button
                type="button"
                class="icon-button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="review-details">

            <div class="detail-grid">

                <label>
                    <span>Announcement title</span>

                    <input
                        type="text"
                        class="text-field"
                        placeholder="Enter announcement title"
                    >
                </label>


                <label>
                    <span>Audience</span>

                    <select class="select-field">
                        <option>All Users</option>
                        <option>Buyers</option>
                        <option>Sellers</option>
                        <option>Logistics Centers</option>
                        <option>Riders / Couriers</option>
                    </select>
                </label>


                <label>
                    <span>Publish date</span>

                    <input
                        type="date"
                        class="text-field"
                    >
                </label>


                <label>
                    <span>Publish time</span>

                    <input
                        type="time"
                        class="text-field"
                    >
                </label>

            </div>


            <label class="detail-note">
                <span>Announcement message</span>

                <textarea
                    class="text-field"
                    rows="6"
                    placeholder="Write the announcement..."
                ></textarea>
            </label>

        </div>


        <div class="modal-footer decision-footer">

            <button
                type="button"
                class="button button-ghost"
                data-mock-action="Announcement saved as draft."
            >
                <i data-lucide="save"></i>
                Save Draft
            </button>


            <button
                type="button"
                class="button button-secondary"
                data-mock-action="Announcement scheduled."
            >
                <i data-lucide="clock"></i>
                Schedule
            </button>


            <button
                type="button"
                class="button button-primary"
                data-mock-action="Announcement published successfully."
            >
                <i data-lucide="send"></i>
                Publish Now
            </button>

        </div>

    </section>

</div>

@endsection