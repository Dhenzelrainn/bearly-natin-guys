@extends('layouts.admin')

@section('title', 'Seller Applications')
@section('page-title', 'Seller Applications')

@section('content')

<section class="page-hero">
    <div>
        <span class="eyebrow">Registration Management</span>
        <h1>Seller applications</h1>

        <p>
            Review Seller registrations, verify business information,
            registered product category, identity documents, and business permits
            before approving marketplace access.
        </p>
    </div>

    <div class="hero-summary-card">
        <span class="metric-icon">
            <i data-lucide="store"></i>
        </span>

        <div>
            <strong>{{ count($applications) }}</strong>
            <small>Seller applications</small>
        </div>
    </div>
</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">Seller registration queue</span>
            <h2>Applications for review</h2>
        </div>


        <div class="table-toolbar">

            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search seller..."
                    data-table-search="seller-applications-table"
                >
            </label>


            <select
                class="select-field"
                data-table-filter="seller-applications-table"
                data-filter-key="category"
            >
                <option value="">All categories</option>

                @foreach (collect($applications)->pluck('business_category')->unique() as $category)
                    <option value="{{ $category }}">
                        {{ $category }}
                    </option>
                @endforeach
            </select>


            <select
                class="select-field"
                data-table-filter="seller-applications-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option value="Pending">Pending</option>
                <option value="Needs Review">Needs Review</option>
            </select>

        </div>

    </div>


    <div class="table-wrap">

        <table class="admin-table" id="seller-applications-table">

            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Business</th>
                    <th>Category</th>
                    <th>Contact</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>


            <tbody>

                @foreach ($applications as $application)

                    <tr
                        data-table-row
                        data-category="{{ $application['business_category'] }}"
                        data-status="{{ $application['status'] }}"
                        data-search="{{
                            strtolower(
                                $application['name']
                                .' '
                                .$application['business_name']
                                .' '
                                .$application['business_category']
                                .' '
                                .$application['email']
                                .' '
                                .$application['id']
                            )
                        }}"
                    >

                        <td>

                            <div class="identity-cell">

                                <span class="avatar avatar-soft">
                                    {{
                                        collect(explode(' ', $application['name']))
                                            ->map(fn($part) => strtoupper(substr($part, 0, 1)))
                                            ->take(2)
                                            ->implode('')
                                    }}
                                </span>

                                <div>
                                    <strong>{{ $application['name'] }}</strong>

                                    <small>
                                        {{ $application['id'] }}
                                        •
                                        {{ $application['email'] }}
                                    </small>
                                </div>

                            </div>

                        </td>


                        <td>
                            <strong>{{ $application['business_name'] }}</strong>

                            <small>
                                {{ $application['municipality'] }},
                                {{ $application['province'] }}
                            </small>
                        </td>


                        <td>
                            {{ $application['business_category'] }}
                        </td>


                        <td>
                            <strong>{{ $application['contact'] }}</strong>
                            <small>Age {{ $application['age'] }}</small>
                        </td>


                        <td>
                            {{ $application['submitted'] }}
                        </td>


                        <td>

                            <span
                                class="status-badge
                                {{
                                    $application['status'] === 'Pending'
                                        ? 'badge-warning'
                                        : 'badge-info'
                                }}"
                            >
                                {{ $application['status'] }}
                            </span>

                        </td>


                        <td class="align-right">

                            <div class="row-actions">

                                <button
                                    type="button"
                                    class="button button-ghost button-small"
                                    data-open-modal="seller-application-{{ $loop->index }}"
                                >
                                    <i data-lucide="eye"></i>
                                    Review
                                </button>


                                <button
                                    type="button"
                                    class="icon-button table-more"
                                    data-registration-menu-toggle="seller-registration-menu-{{ $loop->index }}"
                                    aria-expanded="false"
                                >
                                    <i data-lucide="ellipsis"></i>
                                </button>


                                <div
                                    class="registration-action-menu"
                                    data-registration-menu="seller-registration-menu-{{ $loop->index }}"
                                    hidden
                                >

                                    <button
                                        type="button"
                                        data-open-modal="seller-application-{{ $loop->index }}"
                                    >
                                        <i data-lucide="eye"></i>
                                        <span>View application</span>
                                    </button>


                                    <button
                                        type="button"
                                        data-registration-needs-review
                                        data-application-id="{{ $application['id'] }}"
                                    >
                                        <i data-lucide="flag"></i>
                                        <span>Mark as needs review</span>
                                    </button>


                                    <button
                                        type="button"
                                        data-copy-application-id="{{ $application['id'] }}"
                                    >
                                        <i data-lucide="copy"></i>
                                        <span>Copy application ID</span>
                                    </button>

                                </div>

                            </div>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>


        <div
            class="table-empty"
            data-table-empty="seller-applications-table"
            hidden
        >
            <i data-lucide="search-x"></i>
            <strong>No Seller applications found</strong>
            <span>Try a different search or filter.</span>
        </div>

    </div>

</section>


@foreach ($applications as $application)

<div
    class="modal-shell"
    data-modal="seller-application-{{ $loop->index }}"
    hidden
>

    <button
        class="modal-backdrop"
        type="button"
        data-close-modal
    ></button>


    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
        aria-label="Review {{ $application['name'] }}"
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">
                    {{ $application['id'] }}
                </span>

                <h2>
                    Review Seller application
                </h2>
            </div>


            <button
                class="icon-button"
                type="button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="review-grid">

            <div class="review-profile">

                <span class="avatar avatar-large avatar-warm">
                    {{
                        collect(explode(' ', $application['name']))
                            ->map(fn($part) => strtoupper(substr($part, 0, 1)))
                            ->take(2)
                            ->implode('')
                    }}
                </span>


                <h3>
                    {{ $application['name'] }}
                </h3>

                <p>
                    {{ $application['email'] }}
                </p>

                <span class="role-badge role-seller">
                    Seller
                </span>

            </div>


            <div class="review-details">

                <h3 class="section-subtitle">
                    Personal information
                </h3>


                <div class="detail-grid">

                    <div>
                        <span>Last name</span>
                        <strong>{{ $application['last_name'] }}</strong>
                    </div>

                    <div>
                        <span>First name</span>
                        <strong>{{ $application['first_name'] }}</strong>
                    </div>

                    <div>
                        <span>Middle initial</span>
                        <strong>{{ $application['middle_initial'] }}</strong>
                    </div>

                    <div>
                        <span>Sex</span>
                        <strong>{{ $application['sex'] }}</strong>
                    </div>

                    <div>
                        <span>Email</span>
                        <strong>{{ $application['email'] }}</strong>
                    </div>

                    <div>
                        <span>Contact number</span>
                        <strong>{{ $application['contact'] }}</strong>
                    </div>

                    <div>
                        <span>Birthday</span>
                        <strong>{{ $application['birthday'] }}</strong>
                    </div>

                    <div>
                        <span>Age</span>
                        <strong>{{ $application['age'] }}</strong>
                    </div>

                </div>


                <h3 class="section-subtitle">
                    Business information
                </h3>


                <div class="detail-grid">

                    <div>
                        <span>Business name</span>
                        <strong>{{ $application['business_name'] }}</strong>
                    </div>

                    <div>
                        <span>Line of business</span>
                        <strong>{{ $application['business_category'] }}</strong>
                    </div>

                    <div>
                        <span>Province</span>
                        <strong>{{ $application['province'] }}</strong>
                    </div>

                    <div>
                        <span>Municipality / City</span>
                        <strong>{{ $application['municipality'] }}</strong>
                    </div>

                    <div>
                        <span>Barangay</span>
                        <strong>{{ $application['barangay'] }}</strong>
                    </div>

                    <div>
                        <span>Street</span>
                        <strong>{{ $application['street'] }}</strong>
                    </div>

                    <div>
                        <span>House number</span>
                        <strong>{{ $application['house_number'] }}</strong>
                    </div>

                    <div>
                        <span>Application submitted</span>
                        <strong>{{ $application['submitted'] }}</strong>
                    </div>

                </div>


                <h3 class="section-subtitle">
                    Verification documents
                </h3>


                <div class="document-preview-grid">

                    @foreach ($application['documents'] as $doc)

                        <button
                            type="button"
                            class="document-preview"
                            data-mock-action="{{ $doc }} preview opened."
                        >
                            <span>
                                <i data-lucide="file-text"></i>
                            </span>

                            <div>
                                <strong>{{ $doc }}</strong>
                                <small>Mock verification file • PDF/JPG</small>
                            </div>

                            <i data-lucide="maximize-2"></i>
                        </button>

                    @endforeach

                </div>

            </div>

        </div>


        <div class="modal-footer decision-footer">

            <button
                type="button"
                class="button button-danger-soft"
                data-close-modal
                data-open-modal="seller-email-decision"
                data-decision="Disapproved"
                data-applicant="{{ $application['name'] }}"
            >
                <i data-lucide="circle-x"></i>
                Disapprove
            </button>


            <button
                type="button"
                class="button button-primary"
                data-close-modal
                data-open-modal="seller-email-decision"
                data-decision="Approved"
                data-applicant="{{ $application['name'] }}"
            >
                <i data-lucide="circle-check"></i>
                Approve application
            </button>

        </div>

    </section>

</div>

@endforeach


<div
    class="modal-shell"
    data-modal="seller-email-decision"
    hidden
>

    <button
        class="modal-backdrop"
        type="button"
        data-close-modal
    ></button>


    <section
        class="modal-card"
        role="dialog"
        aria-modal="true"
        aria-label="Seller application decision email"
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">Mock email notification</span>
                <h2>Notify Seller applicant</h2>
            </div>


            <button
                class="icon-button"
                type="button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <label class="form-field">
            <span>Recipient</span>

            <input
                type="text"
                data-decision-recipient
                value="Applicant"
                readonly
            >
        </label>


        <label class="form-field">
            <span>Decision</span>

            <input
                type="text"
                data-decision-status
                value="Approved"
                readonly
            >
        </label>


        <label class="form-field">
            <span>Email message</span>

            <textarea rows="5" data-decision-message>Thank you for submitting your Bearly Seller registration. Your application and business requirements have been reviewed by the administrator.</textarea>
        </label>


        <div class="modal-footer">

            <button
                class="button button-secondary"
                type="button"
                data-close-modal
            >
                Cancel
            </button>


            <button
                class="button button-primary"
                type="button"
                data-close-modal
                data-mock-action="Seller registration decision saved and mock email notification sent."
            >
                <i data-lucide="send"></i>
                Confirm & send
            </button>

        </div>

    </section>

</div>

@endsection