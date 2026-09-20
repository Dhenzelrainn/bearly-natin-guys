@extends('layouts.seller')

@section('title', 'My Store')
@section('page-title', 'My Store')

@section('content')
<div class="store-profile-page">
    <section class="page-heading store-profile-heading">
        <div>
            <span class="section-kicker">Store management</span>
            <h2>Store Profile</h2>
            <p>Review your registered business information and manage buyer-facing contact details.</p>
        </div>
    </section>

    <form
        class="store-profile-form"
        method="POST"
        action="{{ route('seller.store.save') }}"
        enctype="multipart/form-data"
        data-store-form
    >
        @csrf

        <div class="store-profile-layout store-profile-layout-focused">
            <section class="store-information-card">
                <div class="store-profile-section">
                    <div class="store-profile-section-heading">
                        <span class="store-profile-section-icon" aria-hidden="true">
                            <i data-lucide="store"></i>
                        </span>

                        <div>
                            <h3>Registered Business Information</h3>
                            <p>These details were submitted during registration and can only be changed after administrator review.</p>
                        </div>
                    </div>

                    @if ($errors->any())
                        <div class="form-alert">
                            <i data-lucide="circle-alert" aria-hidden="true"></i>
                            <span>{{ $errors->first() }}</span>
                        </div>
                    @endif

                    <div class="store-profile-business-grid">
                        <label class="seller-field">
                            <span>Business / Store Name</span>
                            <span class="locked-input">
                                <input value="{{ $store['name'] }}" readonly aria-readonly="true">
                                <i data-lucide="lock-keyhole" aria-hidden="true"></i>
                            </span>
                        </label>

                        <label class="seller-field">
                            <span>Line of Business / Category</span>
                            <span class="locked-input">
                                <input value="{{ $store['category'] }}" readonly aria-readonly="true">
                                <i data-lucide="lock-keyhole" aria-hidden="true"></i>
                            </span>
                        </label>

                        <label class="seller-field store-location-field">
                            <span>Store Location</span>
                            <span class="locked-input">
                                <input value="{{ $store['location'] }}" readonly aria-readonly="true">
                                <i data-lucide="lock-keyhole" aria-hidden="true"></i>
                            </span>
                            <small>Your exact registered address is kept private from buyers.</small>
                        </label>
                    </div>

                    <div class="store-registration-divider" aria-hidden="true"></div>

                    <div class="store-registration-heading">
                        <div>
                            <h4>Registration Documents</h4>
                            <p>Documents required during seller registration.</p>
                        </div>
                    </div>

                    <div class="store-registration-grid">
                        <div class="store-document-status">
                            <span aria-hidden="true">
                                <i data-lucide="badge-check"></i>
                            </span>
                            <div>
                                <strong>Valid ID</strong>
                                <small><i data-lucide="circle-check"></i> Submitted</small>
                            </div>
                        </div>

                        <div class="store-document-status">
                            <span aria-hidden="true">
                                <i data-lucide="file-check-2"></i>
                            </span>
                            <div>
                                <strong>Business Permit</strong>
                                <small><i data-lucide="circle-check"></i> Submitted</small>
                            </div>
                        </div>

                        <div class="store-admin-note">
                            <i data-lucide="info" aria-hidden="true"></i>
                            <p>Changes to registered business details or submitted documents require administrator review.</p>
                        </div>
                    </div>
                </div>

                <div class="store-profile-section store-contact-section">
                    <div class="store-profile-section-heading">
                        <span class="store-profile-section-icon" aria-hidden="true">
                            <i data-lucide="contact-round"></i>
                        </span>

                        <div>
                            <h3>Store Contact Information</h3>
                            <p>These details are used by buyers for store communication.</p>
                        </div>
                    </div>

                    <div class="store-profile-contact-grid">
                        <label class="seller-field">
                            <span>Store Contact Email</span>
                            <span class="store-contact-input">
                                <i data-lucide="mail" aria-hidden="true"></i>
                                <input
                                    type="email"
                                    name="email"
                                    value="{{ old('email', $store['email']) }}"
                                    autocomplete="email"
                                    required
                                >
                            </span>
                        </label>

                        <label class="seller-field">
                            <span>Store Contact Number</span>
                            <span class="store-contact-input">
                                <i data-lucide="phone" aria-hidden="true"></i>
                                <input
                                    type="tel"
                                    name="phone"
                                    value="{{ old('phone', $store['phone']) }}"
                                    autocomplete="tel"
                                    required
                                >
                            </span>
                        </label>
                    </div>

                    <div class="store-profile-actions">
                        <button
                            class="seller-primary-button"
                            type="submit"
                            name="intent"
                            value="draft"
                        >
                            <i data-lucide="save" aria-hidden="true"></i>
                            Save Contact Details
                        </button>
                    </div>
                </div>
            </section>

            <aside class="store-side-column" aria-label="Store management">
                <section class="store-management-panel">
                    <div class="store-management-heading">
                        <span aria-hidden="true">
                            <i data-lucide="settings-2"></i>
                        </span>

                        <div>
                            <h3>Store Management</h3>
                            <p>Manage how your store appears and is visible to buyers.</p>
                        </div>
                    </div>

                    <a class="store-management-link" href="{{ route('seller.store.appearance') }}">
                        <span aria-hidden="true">
                            <i data-lucide="image"></i>
                        </span>

                        <div>
                            <strong>Store Appearance</strong>
                            <small>Manage description, profile photo, cover, and buyer preview.</small>
                        </div>

                        <i data-lucide="chevron-right" aria-hidden="true"></i>
                    </a>

                    <a class="store-management-link" href="{{ route('seller.store.publication') }}">
                        <span aria-hidden="true">
                            <i data-lucide="store"></i>
                        </span>

                        <div>
                            <strong>Publication Settings</strong>
                            <small>Review publishing requirements and control buyer visibility.</small>
                        </div>

                        <i data-lucide="chevron-right" aria-hidden="true"></i>
                    </a>
                </section>
            </aside>
        </div>
    </form>
</div>
@endsection
