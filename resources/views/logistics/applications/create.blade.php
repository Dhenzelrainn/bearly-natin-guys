<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Apply as a Logistics Partner | Bearly Marketplace</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    @include('partials.theme-preload')
    @vite([
        'resources/css/logistics.css',
        'resources/js/logistics.js',
        'resources/js/application-pending.js',
    ])
    @include('partials.session-safety')
</head>

<body class="registration-body" data-module="logistics">
@include('partials.registration-application-header', [
    'registrationHomeUrl' => route('logistics.landing'),
    'registrationLabel' => 'Logistics partner application',
])
<main class="registration-shell">
    @if (session('registration_pending') || session('logistics_application') || ($applicationReview ?? null))
        @php
            $review = $applicationReview ?? null;
            $state = $review['state'] ?? 'pending';
            $approved = $state === 'approved';
            $final = (bool) ($review['final'] ?? false);
            $statusLabel = $review['status_label'] ?? 'Pending review';
            $heading = $review['heading'] ?? 'Application received';
            $message = $review['message'] ?? 'Your Logistics partner application has been received. Bearly Admin will review your identity and business documents before dashboard access is activated.';
            $businessName = $review['company'] ?? session('logistics_application.business_name', 'Submitted logistics facility');
            $authority = $review['authority'] ?? 'Bearly Administrator';
            $reviewNote = $review['review_note'] ?? null;
        @endphp
        <section
            class="registration-card"
            style="display:block"
            data-application-status
            data-final="{{ $final ? '1' : '0' }}"
            data-poll-interval="12000"
            @if($review) data-status-url="{{ route('application.pending.status') }}" @endif
        >
            <div class="pending-state">
                <span class="metric-icon">
                    <i data-lucide="{{ $approved ? 'badge-check' : 'clock-3' }}"></i>
                </span>

                <p class="page-kicker">{{ $approved ? 'Account Verified' : 'Application submitted' }}</p>
                <h2>{{ $heading }}</h2>

                <p>{{ $message }}</p>

                <div
                    class="detail-list"
                    style="max-width:560px;margin:22px auto;text-align:left"
                >
                    <div class="detail-item">
                        <small>Business</small>
                        <strong>{{ $businessName }}</strong>
                    </div>

                    <div class="detail-item">
                        <small>Status</small>
                        <strong>{{ $statusLabel }}</strong>
                    </div>

                    <div class="detail-item">
                        <small>Reviewed by</small>
                        <strong>{{ $authority }}</strong>
                    </div>

                    @if(!empty($review['submitted_at']))
                        <div class="detail-item">
                            <small>Submitted</small>
                            <strong>{{ $review['submitted_at'] }}</strong>
                        </div>
                    @endif
                </div>

                @if($reviewNote)
                    <p role="note"><strong>{{ $state === 'needs_revision' ? 'What needs updating' : 'Decision note' }}:</strong> {{ $reviewNote }}</p>
                @endif

                @if(! $final && $review)
                    <p data-application-live aria-live="polite">Checking for a review decision automatically.</p>
                @endif

                <a
                    class="button button-primary"
                    href="{{ route('login') }}"
                >
                    {{ $approved ? 'Proceed to Sign In' : 'Return to main login' }}
                </a>
            </div>
        </section>
    @else
        <section class="registration-card">
            <aside class="registration-aside">
                <span class="status-badge is-warning">
                    Partner onboarding
                </span>

                <h1>Operate a Bearly sorting center</h1>

                <p>
                    Complete the application below. Your account remains
                    locked until Bearly Admin approves the submitted
                    credentials.
                </p>

                <div class="step-list">
                    <div
                        class="step-marker is-active"
                        data-step-marker="1"
                    >
                        <span>1</span>
                        <span>
                            <strong>Representative</strong>
                            <small>Personal details</small>
                        </span>
                    </div>

                    <div class="step-marker" data-step-marker="2">
                        <span>2</span>
                        <span>
                            <strong>Facility</strong>
                            <small>Address and business</small>
                        </span>
                    </div>

                    <div class="step-marker" data-step-marker="3">
                        <span>3</span>
                        <span>
                            <strong>Documents</strong>
                            <small>Identity and permit</small>
                        </span>
                    </div>
                </div>
            </aside>

            <div class="registration-main">
                <h2>Logistics application</h2>
                <p>
                    All required fields must be completed before submission.
                </p>

                @if ($errors->any())
                    <div class="flash-banner is-danger">
                        <i data-lucide="circle-alert"></i>
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                <form
                    action="{{ route('logistics.register.submit') }}"
                    method="POST"
                    enctype="multipart/form-data"
                    data-registration-form
                    data-email-send="{{ route('register.email.send') }}"
                    data-email-check="{{ route('register.email.check') }}"
                    data-email-status="{{ route('register.email.status') }}"
                    data-postal-url="{{ route('postal.lookup') }}"
                    data-draft-save-url="{{ route('register.draft.save', 'logistics') }}"
                    data-draft-step="{{ $registrationDraft['step'] ?? 1 }}"
                    data-has-old-input="{{ session()->hasOldInput() ? 'true' : 'false' }}"
                    novalidate
                >
                    @csrf
                    <script type="application/json" data-registration-draft>@json($registrationDraft['fields'] ?? [])</script>
                    <p class="registration-draft-notice" role="status" aria-live="polite">
                        @if ($registrationDraft['has_draft'] ?? false)
                            Your saved application was restored. Fields save on this browser for 7 days; passwords, codes, uploads, and consent are not saved.
                        @else
                            Fields save on this browser for 7 days. Passwords, codes, uploads, and consent are not saved.
                        @endif
                        <span data-registration-draft-status></span>
                    </p>

                    <section class="form-step" data-form-step="1">
                        <div class="field-grid three">
                            <div class="field">
                                <label for="logistics-first-name">
                                    First name <span>*</span>
                                </label>
                                <input
                                    id="logistics-first-name"
                                    name="first_name"
                                    value="{{ old('first_name') }}"
                                    minlength="2"
                                    maxlength="50"
                                    autocomplete="given-name"
                                    placeholder="e.g. Juan"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="logistics-middle-initial">
                                    Middle initial
                                </label>
                                <input
                                    id="logistics-middle-initial"
                                    name="middle_initial"
                                    value="{{ old('middle_initial') }}"
                                    maxlength="2"
                                    pattern="[A-Za-z][.]?"
                                    title="Enter one letter. Bearly will capitalize it and add the period automatically."
                                    autocomplete="additional-name"
                                    data-middle-initial
                                    placeholder="P."
                                >
                            </div>

                            <div class="field">
                                <label for="logistics-last-name">
                                    Last name <span>*</span>
                                </label>
                                <input
                                    id="logistics-last-name"
                                    name="last_name"
                                    value="{{ old('last_name') }}"
                                    minlength="2"
                                    maxlength="50"
                                    autocomplete="family-name"
                                    placeholder="e.g. Dela Cruz"
                                    required
                                >
                            </div>

                            <div class="field span-3">
                                <label for="logistics-profile-photo">
                                    Profile picture <span>(Optional)</span>
                                </label>
                                <label class="upload-field">
                                    <input
                                        id="logistics-profile-photo"
                                        type="file"
                                        name="profile_photo"
                                        accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
                                        data-file-input
                                        data-file-help="JPEG, PNG, or WebP up to 10 MB. If upload fails, choose a copy saved on your device."
                                    >
                                    <i data-lucide="user-round"></i>
                                    <strong>Choose a profile picture</strong>
                                    <small data-file-name>JPEG, PNG, or WebP up to 10 MB</small>
                                </label>
                                <small class="field-help">If a phone or cloud-provider file cannot be uploaded, save a copy on the device and select it again.</small>
                            </div>

                            <div class="field">
                                <label for="logistics-sex">
                                    Sex <span>*</span>
                                </label>
                                <select id="logistics-sex" name="sex" required>
                                    <option value="">Select</option>
                                    @foreach (['Male', 'Female', 'Prefer not to say'] as $sex)
                                        <option @selected(old('sex') === $sex)>
                                            {{ $sex }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="field">
                                <label for="logistics-birthday">
                                    Birthday <span>*</span>
                                </label>
                                <input
                                    id="logistics-birthday"
                                    type="date"
                                    name="birthday"
                                    max="{{ now()->subDay()->format('Y-m-d') }}"
                                    value="{{ old('birthday') }}"
                                    autocomplete="bday"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="logistics-age">Age</label>
                                <input
                                    id="logistics-age"
                                    data-age
                                    readonly
                                    placeholder="Auto-calculated"
                                >
                            </div>

                            <div class="field">
                                <label for="logistics-email">
                                    Email address <span>*</span>
                                </label>
                                <input
                                    id="logistics-email"
                                    type="email"
                                    name="email"
                                    value="{{ old('email') }}"
                                    autocomplete="email"
                                    placeholder="name@example.com"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="logistics-contact-number">
                                    Contact number <span>*</span>
                                </label>
                                <input
                                    id="logistics-contact-number"
                                    name="contact_number"
                                    value="{{ old('contact_number') }}"
                                    pattern="[0-9+() -]{7,20}"
                                    autocomplete="tel"
                                    placeholder="0917 123 4567"
                                    required
                                >
                            </div>

                            <div
                                class="field span-3 registration-email-verification"
                                data-email-verification
                            >
                                <div class="verification-heading">
                                    <div>
                                        <strong>Verify your email</strong>
                                        <small>
                                            We’ll send a 6-digit code to the
                                            email address above.
                                        </small>
                                    </div>

                                    <button
                                        type="button"
                                        class="button"
                                        data-email-send
                                    >
                                        Send code
                                    </button>
                                </div>

                                <p
                                    class="verification-status"
                                    data-email-status
                                    role="status"
                                    aria-live="polite"
                                >
                                    Verify your email address to continue.
                                </p>

                                <div
                                    class="verification-code-row"
                                    data-email-code-entry
                                    hidden
                                >
                                    <label for="logistics-email-code">
                                        Verification code
                                    </label>

                                    <input
                                        id="logistics-email-code"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        maxlength="6"
                                        placeholder="6-digit code"
                                        data-email-code
                                    >

                                    <button
                                        type="button"
                                        class="button button-primary"
                                        data-email-check
                                    >
                                        Verify email
                                    </button>
                                </div>
                            </div>

                            <div class="field">
                                <label for="logistics-password">
                                    Password <span>*</span>
                                </label>

                                <div class="registration-password-wrap">
                                    <input
                                        id="logistics-password"
                                        type="password"
                                        name="password"
                                        minlength="8"
                                        pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}"
                                        title="Use at least 8 characters with one uppercase letter, one lowercase letter, and one number."
                                        autocomplete="new-password"
                                        aria-describedby="logistics-password-status logistics-password-requirements"
                                        data-registration-password
                                        placeholder="Create password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="registration-password-toggle"
                                        data-toggle-password="logistics-password"
                                        aria-label="Show password"
                                        aria-pressed="false"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="field">
                                <label for="logistics-password-confirmation">
                                    Confirm password <span>*</span>
                                </label>

                                <div class="registration-password-wrap">
                                    <input
                                        id="logistics-password-confirmation"
                                        type="password"
                                        name="password_confirmation"
                                        autocomplete="new-password"
                                        aria-describedby="logistics-password-match"
                                        data-registration-password-confirmation
                                        placeholder="Confirm password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="registration-password-toggle"
                                        data-toggle-password="logistics-password-confirmation"
                                        aria-label="Show password"
                                        aria-pressed="false"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div
                                class="field span-3 registration-password-guidance"
                                data-password-guidance
                            >
                                <div class="password-guidance-heading">
                                    <strong>Password requirements</strong>
                                    <span data-password-strength-label>Not set</span>
                                </div>

                                <div
                                    class="registration-password-meter"
                                    aria-hidden="true"
                                >
                                    <i></i>
                                </div>

                                <ul
                                    id="logistics-password-requirements"
                                    class="registration-password-rules"
                                >
                                    <li data-password-rule="length">
                                        At least 8 characters
                                    </li>
                                    <li data-password-rule="uppercase">
                                        At least 1 uppercase letter
                                    </li>
                                    <li data-password-rule="lowercase">
                                        At least 1 lowercase letter
                                    </li>
                                    <li data-password-rule="number">
                                        At least 1 number
                                    </li>
                                </ul>

                                <p
                                    id="logistics-password-status"
                                    class="registration-password-status"
                                    data-password-status
                                    role="status"
                                    aria-live="polite"
                                >
                                    Meet all four requirements to continue.
                                </p>

                                <p
                                    id="logistics-password-match"
                                    class="registration-password-match"
                                    data-password-match
                                    role="status"
                                    aria-live="polite"
                                ></p>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a class="button" href="{{ route('logistics.landing') }}">
                                Cancel
                            </a>

                            <button
                                class="button button-primary"
                                type="button"
                                data-step-next
                            >
                                Continue to facility
                            </button>
                        </div>
                    </section>

                    <section class="form-step" data-form-step="2" hidden>
                        <div class="registration-section-heading">
                            <div>
                                <span class="registration-section-kicker">
                                    Facility address
                                </span>
                                <h3>Where will this sorting center operate?</h3>
                            </div>
                            <p>
                                Search the Philippine address lists below.
                                Postal code is filled after you choose a municipality.
                            </p>
                        </div>

                        <div class="field-grid">
                            <div class="field span-2">
                                <label for="logistics-business-name">
                                    Business / sorting facility name
                                    <span>*</span>
                                </label>

                                <input
                                    id="logistics-business-name"
                                    name="business_name"
                                    value="{{ old('business_name') }}"
                                    autocomplete="organization"
                                    placeholder="Registered facility or business name"
                                    required
                                >
                            </div>
                        </div>

                        <div class="registration-address-grid">
                            <div class="field location-field">
                                <label for="logistics-region">
                                    Region <span>*</span>
                                </label>
                                <select
                                    id="logistics-region"
                                    name="region"
                                    data-old-value="{{ old('region') }}"
                                    data-region
                                    data-location-select="region"
                                    data-searchable-location
                                    data-search-placeholder="Search region"
                                    required
                                >
                                    <option value="">Loading regions…</option>
                                </select>
                            </div>

                            <div class="field location-field">
                                <label for="logistics-province">
                                    Province <span>*</span>
                                </label>
<select
                                    id="logistics-province"
                                    name="province"
                                    data-old-value="{{ old('province') }}"
                                    data-province
                                    data-location-select="province"
                                    data-searchable-location
                                    data-search-placeholder="Search province"
                                    required
                                >
                                    <option value="">Select region first</option>
                                </select>
                            </div>

                            <div class="field location-field">
                                <label for="logistics-city">
                                    Municipality / City <span>*</span>
                                </label>
<select
                                    id="logistics-city"
                                    name="municipality"
                                    data-old-value="{{ old('municipality') }}"
                                    data-city
                                    data-location-select="city"
                                    data-searchable-location
                                    data-search-placeholder="Search municipality"
                                    required
                                    disabled
                                >
                                    <option value="">Select province first</option>
                                </select>
                            </div>

                            <div class="field location-field">
                                <label for="logistics-barangay">
                                    Barangay <span>*</span>
                                </label>
<select
                                    id="logistics-barangay"
                                    name="barangay"
                                    data-old-value="{{ old('barangay') }}"
                                    data-barangay
                                    data-location-select="barangay"
                                    data-searchable-location
                                    data-search-placeholder="Search barangay"
                                    required
                                    disabled
                                >
                                    <option value="">Select city first</option>
                                </select>
                            </div>

                            <div class="field postal-code-field">
                                <label for="logistics-postal-code">
                                    Postal code <span>*</span>
                                </label>

                                <input
                                    id="logistics-postal-code"
                                    name="postal_code"
                                    value="{{ old('postal_code') }}"
                                    inputmode="numeric"
                                    pattern="[0-9]{4}"
                                    maxlength="4"
                                    placeholder="Auto-generated"
                                    data-postal-code
                                    readonly
                                    required
                                >

                                <select
                                    data-postal-choices
                                    aria-label="Choose postal area"
                                    hidden
                                ></select>

                                <small data-postal-help>
                                    Select a municipality to fill this automatically.
                                </small>

                                <input
                                    type="hidden"
                                    name="region_code"
                                    value="{{ old('region_code') }}"
                                >
                                <input
                                    type="hidden"
                                    name="city_code"
                                    value="{{ old('city_code') }}"
                                >
                                <input
                                    type="hidden"
                                    name="postal_manual"
                                    value="{{ old('postal_manual', '0') }}"
                                >
                            </div>

                            <div class="field">
                                <label for="logistics-street">
                                    Street / Purok <span>*</span>
                                </label>

                                <input
                                    id="logistics-street"
                                    name="street"
                                    value="{{ old('street') }}"
                                    autocomplete="address-line1"
                                    placeholder="Street or purok"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="logistics-house-number">
                                    House / Building number <span>*</span>
                                </label>

                                <input
                                    id="logistics-house-number"
                                    name="house_number"
                                    value="{{ old('house_number') }}"
                                    autocomplete="address-line2"
                                    placeholder="House or unit no."
                                    required
                                >
                            </div>
                        </div>

                        <div class="form-actions">
                            <button class="button" type="button" data-step-back>
                                Back
                            </button>

                            <button
                                class="button button-primary"
                                type="button"
                                data-step-next
                            >
                                Continue to documents
                            </button>
                        </div>
                    </section>

                    <section class="form-step" data-form-step="3" hidden>
                        <div class="field-grid">
                            <div class="field">
                                <label>
                                    Valid government ID <span>*</span>
                                </label>

                                <label class="upload-field">
                                    <input
                                        type="file"
                                        name="valid_id"
                                        accept=".jpg,.jpeg,.png,.pdf"
                                        required
                                    >
                                    <i data-lucide="upload-cloud"></i>
                                    <strong>Upload a valid ID</strong>
                                    <small data-file-name>
                                        Choose a JPG, PNG, or PDF up to 5 MB
                                    </small>
                                </label>
                            </div>

                            <div class="field">
                                <label>
                                    Business / DTI permit <span>*</span>
                                </label>

                                <label class="upload-field">
                                    <input
                                        type="file"
                                        name="business_permit"
                                        accept=".jpg,.jpeg,.png,.pdf"
                                        required
                                    >
                                    <i data-lucide="file-check-2"></i>
                                    <strong>Upload business permit</strong>
                                    <small data-file-name>
                                        Choose a JPG, PNG, or PDF up to 5 MB
                                    </small>
                                </label>
                            </div>
                        </div>

                        <label class="toggle-row">
                            <span class="toggle-copy">
                                <strong>
                                    I certify that the submitted details are
                                    accurate.
                                </strong>
                                <small>
                                    Bearly Admin will review the submitted
                                    credentials before activation.
                                </small>
                            </span>

                            <span class="switch">
                                <input type="checkbox" required>
                                <span></span>
                            </span>
                        </label>


                        <div class="registration-consents">
                            <label class="registration-consent">
                                <input
                                    type="checkbox"
                                    name="terms"
                                    value="1"
                                    @checked(old('terms'))
                                    required
                                >
                                <span>
                                    I agree to the
                                    <a
                                        href="{{ route('terms') }}"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Terms of Service
                                    </a>
                                    and acknowledge the
                                    <a
                                        href="{{ route('privacy') }}"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Privacy Policy
                                    </a>.
                                </span>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button class="button" type="button" data-step-back>
                                Back
                            </button>

                            <button class="button button-primary" type="submit">
                                <i data-lucide="send"></i>
                                Submit application
                            </button>
                        </div>
                    </section>
                </form>
            </div>
        </section>
    @endif
</main>

<div class="toast-stack" data-toast-stack></div>
<script src="https://unpkg.com/lucide@latest"></script>
</body>
</html>
