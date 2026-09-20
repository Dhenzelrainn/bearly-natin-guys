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


    <div class="hero-context-stat">

        <span class="hero-context-icon">
            <i data-lucide="megaphone"></i>
        </span>

        <div>
            <strong>
                {{ count($announcements) }}
            </strong>

            <span>
                Announcement records
            </span>
        </div>

    </div>

</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">
                Announcement management
            </span>

            <h2>
                Announcement records
            </h2>
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
                <option value="">
                    All audiences
                </option>

                <option value="All Users">
                    All Users
                </option>

                <option value="Buyers">
                    Buyers
                </option>

                <option value="Sellers">
                    Sellers
                </option>

                <option value="Logistics Centers">
                    Logistics Centers
                </option>

                <option value="Riders">
                    Riders
                </option>
            </select>


            <select
                class="select-field"
                data-table-filter="announcements-table"
                data-filter-key="status"
            >
                <option value="">
                    All statuses
                </option>

                <option value="Published">
                    Published
                </option>

                <option value="Scheduled">
                    Scheduled
                </option>

                <option value="Draft">
                    Draft
                </option>

                <option value="Archived">
                    Archived
                </option>
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

        <table
            class="admin-table"
            id="announcements-table"
        >

            <thead>
                <tr>
                    <th>Announcement</th>
                    <th>Audience</th>
                    <th>Author</th>
                    <th>Publish Date</th>
                    <th>Status</th>

                    <th class="align-right">
                        Actions
                    </th>
                </tr>
            </thead>


            <tbody data-record-rows="announcement">

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


                                <div class="table-primary-secondary">

                                    <strong>
                                        {{ $announcement['title'] }}
                                    </strong>

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

                            <div class="table-primary-secondary">

                                <strong>
                                    {{ $announcement['publish_date'] }}
                                </strong>

                                @if ($announcement['publish_time'] !== '—')
                                    <small>
                                        {{ $announcement['publish_time'] }}
                                    </small>
                                @endif

                            </div>

                        </td>


                        <td>

                            <span
                                class="status-badge
                                {{
                                    $announcement['status'] === 'Published'
                                        ? 'badge-success'
                                        : ($announcement['status'] === 'Scheduled'
                                            ? 'badge-warning'
                                            : ($announcement['status'] === 'Archived'
                                                ? 'badge-neutral'
                                                : 'badge-info'))
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

            <strong>
                No announcements found
            </strong>

            <span>
                No announcement records match the current search or filters.
            </span>
        </div>

    </div>

</section>


{{-- Existing announcement review modals --}}
@foreach ($announcements as $announcement)

<div
    class="modal-shell"
    data-modal="announcement-{{ $loop->index }}"
    data-announcement-modal
    data-announcement-id="{{ $announcement['id'] }}"
    hidden
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
        aria-label="Close announcement"
    ></button>


    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="announcement-title-{{ $loop->index }}"
    >

        <div class="modal-heading">

            <div>

                <span class="eyebrow">
                    {{ $announcement['id'] }}
                </span>

                <h2
                    id="announcement-title-{{ $loop->index }}"
                    data-announcement-title
                >
                    {{ $announcement['title'] }}
                </h2>

            </div>


            <button
                type="button"
                class="icon-button"
                data-close-modal
                aria-label="Close"
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

                    <strong data-announcement-audience>
                        {{ $announcement['audience'] }}
                    </strong>
                </div>


                <div>
                    <span>Status</span>

                    <strong data-announcement-status>
                        {{ $announcement['status'] }}
                    </strong>
                </div>


                <div>
                    <span>Author</span>

                    <strong data-announcement-author>
                        {{ $announcement['author'] }}
                    </strong>
                </div>


                <div>
                    <span>Publish date</span>

                    <strong data-announcement-date>
                        {{ $announcement['publish_date'] }}
                    </strong>
                </div>


                <div>
                    <span>Publish time</span>

                    <strong data-announcement-time>
                        {{ $announcement['publish_time'] }}
                    </strong>
                </div>

            </div>


            <div class="detail-note">

                <span>
                    Announcement message
                </span>

                <p data-announcement-message>
                    {{ $announcement['message'] }}
                </p>

            </div>

        </div>


        <div
            class="modal-footer decision-footer"
            data-announcement-actions
        >

            <button
                type="button"
                class="button button-primary"
                data-announcement-action="publish"
                data-mock-action="{{ $announcement['title'] }} published."
            >
                <i data-lucide="send"></i>
                Publish Now
            </button>


            <button
                type="button"
                class="button button-secondary"
                data-announcement-action="edit"
                data-mock-action="{{ $announcement['title'] }} opened for editing."
            >
                <i data-lucide="pencil"></i>
                Edit
            </button>


            <button
                type="button"
                class="button button-danger-soft"
                data-announcement-action="archive"
                data-mock-action="{{ $announcement['title'] }} archived."
            >
                <i data-lucide="archive"></i>
                Archive
            </button>


            <button
                type="button"
                class="button button-secondary"
                data-announcement-state-indicator
                hidden
                disabled
            >
                <i data-lucide="circle-check"></i>

                <span data-announcement-state-label>
                    Status updated
                </span>
            </button>

        </div>

    </section>

</div>

@endforeach


{{-- Create / edit announcement modal --}}
<div
    class="modal-shell"
    data-modal="create-announcement"
    hidden
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
        aria-label="Close announcement editor"
    ></button>


    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="announcement-editor-title"
    >

        <div class="modal-heading">

            <div>

                <span class="eyebrow">
                    Communication
                </span>

                <h2 id="announcement-editor-title">
                    Create announcement
                </h2>

            </div>


            <button
                type="button"
                class="icon-button"
                data-close-modal
                aria-label="Close"
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="announcement-form">

            <div class="form-grid two-column-form">

                <label class="form-field">

                    <span>
                        Announcement title
                    </span>

                    <input
                        type="text"
                        class="text-field"
                        data-record-field="title"
                        placeholder="Enter announcement title"
                    >

                </label>


                <label class="form-field">

                    <span>
                        Audience
                    </span>

                    <select
                        class="select-field"
                        data-record-field="audience"
                    >
                        <option value="All Users">
                            All Users
                        </option>

                        <option value="Buyers">
                            Buyers
                        </option>

                        <option value="Sellers">
                            Sellers
                        </option>

                        <option value="Logistics Centers">
                            Logistics Centers
                        </option>

                        <option value="Riders">
                            Riders
                        </option>
                    </select>

                </label>


                <label class="form-field">

                    <span>
                        Publish date
                    </span>

                    <input
                        type="date"
                        class="text-field"
                        data-record-field="date"
                    >

                </label>


                <label class="form-field">

                    <span>
                        Publish time
                    </span>

                    <input
                        type="time"
                        class="text-field"
                        data-record-field="time"
                    >

                </label>

            </div>


            <label class="form-field announcement-message-field">

                <span>
                    Announcement message
                </span>

                <textarea
                    class="text-field"
                    rows="7"
                    data-record-field="message"
                    placeholder="Write the announcement..."
                ></textarea>

            </label>

        </div>


        <div class="modal-footer decision-footer">

            <button
                type="button"
                class="button button-ghost"
                data-mock-action="Announcement saved as draft."
                data-save-record="announcement"
                data-record-status="Draft"
            >
                <i data-lucide="save"></i>
                Save Draft
            </button>


            <button
                type="button"
                class="button button-secondary"
                data-mock-action="Announcement scheduled."
                data-save-record="announcement"
                data-record-status="Scheduled"
            >
                <i data-lucide="clock"></i>
                Schedule
            </button>


            <button
                type="button"
                class="button button-primary"
                data-mock-action="Announcement published successfully."
                data-save-record="announcement"
                data-record-status="Published"
            >
                <i data-lucide="send"></i>
                Publish Now
            </button>

        </div>

    </section>

</div>

@endsection