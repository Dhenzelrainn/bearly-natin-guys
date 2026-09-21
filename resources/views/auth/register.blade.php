@extends('layouts.auth')

@section('title', 'Create Account | Bearly')

@section('header-action')
    <p class="header-signin">
        Already have an account?
        <a href="{{ route('login') }}">Sign in</a>
    </p>
@endsection

@section('content')
<section class="register-shell" data-registration>
    <aside class="register-aside">
        <img
            src="{{ asset('images/bearly-register-bear.png') }}"
            alt="Bearly bear holding a shopping bag"
            class="register-bear"
        >

        <h1>Create your<br>Bearly account</h1>
        <p>Create one Bearly account to shop on Bearly.</p>

        <ol class="step-list" aria-label="Registration progress">
            <li class="active" data-step-marker="1">
                <span>1</span>
                <b>Personal Information</b>
            </li>
            <li data-step-marker="2">
                <span>2</span>
                <b>Address &amp; Verification</b>
            </li>
            <li data-step-marker="3">
                <span>3</span>
                <b>Review &amp; Submit</b>
            </li>
        </ol>
    </aside>

    <form
        class="register-form"
        data-demo-register
        enctype="multipart/form-data"
        novalidate
    >
        <div class="mobile-progress">
            <span data-mobile-step>Step 1 of 3</span>
            <div><i data-progress-bar></i></div>
        </div>

        {{-- STEP 1 --}}
        <section class="form-step active" data-step="1">
            <div class="section-heading" style="margin-top: 0;">
                <h2 style="font-size: 27px;">Create your Bearly account</h2>
                <p style="font-size: 14px;">Start with your personal information.</p>
            </div>

            <div class="form-grid three">
                <label>
                    First name
                    <input
                        type="text"
                        name="first_name"
                        value="{{ old('first_name') }}"
                        pattern="[A-Za-zÀ-ÿ.' -]+"
                        placeholder="Enter your first name"
                        autocomplete="given-name"
                        required
                    >
                </label>

                <label>
                    Last name
                    <input
                        type="text"
                        name="last_name"
                        value="{{ old('last_name') }}"
                        pattern="[A-Za-zÀ-ÿ.' -]+"
                        placeholder="Enter your last name"
                        autocomplete="family-name"
                        required
                    >
                </label>

                <label>
                    Middle initial <em>(Optional)</em>
                    <input
                        type="text"
                        name="middle_initial"
                        value="{{ old('middle_initial') }}"
                        maxlength="2"
                        pattern="[A-Za-z][.]?"
                        placeholder="P."
                        autocomplete="off"
                        title="Enter one letter, optionally followed by a period."
                    >
                </label>
            </div>

            <div class="form-grid two-wide">
                <label>
                    Sex
                    <select name="sex" required>
                        <option value="">Select sex</option>
                        <option value="female" @selected(old('sex') === 'female')>Female</option>
                        <option value="male" @selected(old('sex') === 'male')>Male</option>
                        <option value="prefer_not_to_say" @selected(old('sex') === 'prefer_not_to_say')>
                            Prefer not to say
                        </option>
                    </select>
                </label>

                <label>
                    Email address
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        placeholder="Enter your email address"
                        autocomplete="email"
                        required
                    >
                </label>
            </div>

            <div class="form-grid three">
                <label>
                    Contact number
                    <input
                        type="tel"
                        name="contact_number"
                        value="{{ old('contact_number') }}"
                        inputmode="numeric"
                        maxlength="13"
                        pattern="(?:\+639|09)\d{9}"
                        placeholder="09XXXXXXXXX"
                        autocomplete="tel"
                        required
                    >
                </label>

                <label>
                    Birthday
                    <input
                        id="birthday"
                        type="date"
                        name="birthday"
                        value="{{ old('birthday') }}"
                        max="{{ now()->toDateString() }}"
                        autocomplete="bday"
                        required
                    >
                </label>

                <label>
                    Age <em>(Auto-generated)</em>
                    <input
                        id="age"
                        name="age"
                        value="--"
                        readonly
                        tabindex="-1"
                        aria-readonly="true"
                    >
                </label>
            </div>

            <div class="form-grid two password-row">
                <label>
                    Password
                    <div class="password-wrap">
                        <input
                            id="register-password"
                            type="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                        <button
                            type="button"
                            data-toggle-password="register-password"
                            aria-label="Show password"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </label>

                <label>
                    Confirm password
                    <div class="password-wrap">
                        <input
                            id="password-confirmation"
                            type="password"
                            name="password_confirmation"
                            placeholder="Confirm your password"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                        <button
                            type="button"
                            data-toggle-password="password-confirmation"
                            aria-label="Show password"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </label>
            </div>

            <div class="password-meter"><i></i></div>

            <p style="margin: -3px 0 14px; color: #7d726b; font-size: 12px; line-height: 1.45;">
                Use at least 8 characters, including uppercase, lowercase, and a number.
            </p>

            <div class="info-box" style="margin: 0 0 14px; padding: 15px 18px;">
                <strong style="display: block; margin-bottom: 3px; color: #4a2c20;">
                    Your account will be created as a Bearly shopping account.
                </strong>
                <span style="font-size: 12.5px;">
                    You can apply for Seller, Rider, or Logistics features later using the same account.
                </span>
            </div>

            <label class="check-label terms">
                <input type="checkbox" name="terms" value="1" @checked(old('terms')) required>
                <span>
                    I agree to the
                    <a href="/terms">Terms of Service</a>
                    and
                    <a href="/privacy">Privacy Policy</a>
                </span>
            </label>
        </section>

        {{-- STEP 2 --}}
        <section class="form-step" data-step="2">
            <div class="section-heading" style="margin-top: 0; margin-bottom: 20px;">
                <h2 style="font-size: 27px;">Address &amp; verification</h2>
                <p style="font-size: 14px;">Add your primary address and upload your ID.</p>
            </div>

            <div class="info-box" style="margin-bottom: 18px; padding: 14px 18px;">
                <strong style="color: #4a2c20;">
                    Your address will be saved as your default delivery address.
                </strong>
            </div>

            <div class="form-grid three" data-address-fields>
                <label for="province">
                    Province
                    <select
                        id="province"
                        name="province"
                        data-province-select
                        data-searchable-address
                        data-search-placeholder="Search province"
                        data-old-value="{{ old('province') }}"
                        required
                        disabled
                    >
                        <option value="">Loading provinces...</option>
                    </select>
                </label>

                <label for="city">
                    Municipality / City
                    <select
                        id="city"
                        name="city"
                        data-city-select
                        data-searchable-address
                        data-search-placeholder="Search municipality or city"
                        data-old-value="{{ old('city') }}"
                        required
                        disabled
                    >
                        <option value="">Select province first</option>
                    </select>
                </label>

                <label for="barangay">
                    Barangay
                    <select
                        id="barangay"
                        name="barangay"
                        data-barangay-select
                        data-searchable-address
                        data-search-placeholder="Search barangay"
                        data-old-value="{{ old('barangay') }}"
                        required
                        disabled
                    >
                        <option value="">Select city first</option>
                    </select>
                </label>
            </div>

            <div class="form-grid three">
                <label>
                    Street name
                    <input
                        type="text"
                        name="street_name"
                        value="{{ old('street_name') }}"
                        placeholder="Enter street name"
                        autocomplete="address-line1"
                        required
                    >
                </label>

                <label>
                    House / Unit no.
                    <input
                        type="text"
                        name="house_number"
                        value="{{ old('house_number') }}"
                        placeholder="e.g. 123, Unit 4B"
                        autocomplete="address-line2"
                        required
                    >
                </label>

                <label>
                    Postal code <em>(Auto-generated)</em>
                    <input
                        id="postal-code"
                        type="text"
                        name="postal_code"
                        value="{{ old('postal_code') }}"
                        placeholder="Select municipality first"
                        autocomplete="postal-code"
                        readonly
                        required
                    >
                </label>
            </div>

            <div class="address-service" data-address-service>
                <p
                    class="address-service-message"
                    data-address-message
                    role="status"
                    aria-live="polite"
                >
                    Loading Philippine address data...
                </p>

                <div class="address-actions">
                    <button
                        type="button"
                        class="secondary-button"
                        data-address-retry
                        hidden
                    >
                        Retry address service
                    </button>

                    <button
                        type="button"
                        class="secondary-button"
                        data-address-manual
                    >
                        Enter address manually
                    </button>
                </div>
            </div>

            <div class="documents-heading" style="margin-top: 22px;">
                <div>
                    <h2 style="font-size: 18px;">Upload ID</h2>
                    <p>Upload a clear copy of your valid government-issued ID.</p>
                </div>

                <div class="document-security-note">
                    <img
                        src="{{ asset('images/security.png') }}"
                        alt=""
                        class="document-security-icon"
                        aria-hidden="true"
                    >
                    <span>Visible only to authorized reviewers.</span>
                </div>
            </div>

            <div class="upload-grid" style="grid-template-columns: 1fr;">
                <article class="document-upload" data-upload-card>
                    <div class="document-upload__header">
                        <span class="document-icon" aria-hidden="true">
                            <img src="{{ asset('images/id.png') }}" alt="">
                        </span>

                        <div>
                            <span class="required-badge">Required</span>
                            <h3>Valid government ID</h3>
                            <p>
                                Passport, driver's license, national ID,
                                or another government-issued ID.
                            </p>
                            <small>PNG, JPG, JPEG, or PDF · Max 5 MB</small>
                        </div>
                    </div>

                    <label class="upload-dropzone" data-drop-zone>
                        <input
                            name="valid_id"
                            type="file"
                            accept=".png,.jpg,.jpeg,.pdf"
                            data-file-preview
                            required
                        >

                        <img
                            src="{{ asset('images/cloud.png') }}"
                            alt=""
                            class="upload-icon"
                            aria-hidden="true"
                        >

                        <span>
                            <strong>Choose file</strong>
                            or drag and drop
                        </span>
                    </label>

                    <div class="file-status" data-file-status hidden>
                        <span class="file-status__icon" aria-hidden="true">✓</span>

                        <span class="file-status__details">
                            <strong data-file-name></strong>
                            <small data-file-meta></small>
                        </span>

                        <div class="file-status__actions">
                            <button type="button" data-file-action="preview">Preview</button>
                            <button type="button" data-file-action="replace">Replace</button>
                            <button type="button" data-file-action="remove">Remove</button>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        {{-- STEP 3 --}}
        <section class="form-step documents-step" data-step="3">
            <div class="documents-heading" style="align-items: flex-start;">
                <div>
                    <h2>Review &amp; submit</h2>
                    <p>Check your details before creating your account.</p>
                </div>
            </div>

            <article class="application-summary">
                <div class="application-summary__header">
                    <div>
                        <span class="summary-eyebrow">Registration summary</span>
                        <h3>Your Bearly account</h3>
                    </div>
                </div>

                <div class="review-groups" data-review-summary></div>
            </article>

            <div class="approval-callout">
                <span class="approval-callout__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="m5 12 4 4L19 6"/>
                    </svg>
                </span>

                <div>
                    <strong>Administrator approval required</strong>
                    <p>
                        After submitting your registration, please wait for the
                        administrator's approval. The decision will be sent to
                        your registered email address.
                    </p>
                    <p style="margin-top: 3px;">
                        Once approved, you can sign in and start shopping on Bearly.
                    </p>
                </div>
            </div>

            <p class="demo-message" data-register-message hidden>
                Registration preview completed.
                Backend submission will be connected during the authentication integration.
            </p>
        </section>

        <div class="form-actions">
            <button
                type="button"
                class="secondary-button"
                data-back
                disabled
            >
                Back
            </button>

            <button
                type="button"
                class="primary-button"
                data-next
            >
                Continue
            </button>

            <button
                type="submit"
                class="primary-button"
                data-submit
                hidden
            >
                Submit registration
            </button>
        </div>
    </form>
</section>
@endsection
