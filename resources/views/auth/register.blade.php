@extends('layouts.auth')

@section('title', 'Create Account | Bearly')

@section('header-action')
    <p class="header-signin">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
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
        <p>Join thousands of happy users shopping, selling, and delivering with Bearly.</p>

        <ol class="step-list" aria-label="Registration progress">
            <li class="active" data-step-marker="1"><span>1</span><b>Personal Information</b></li>
            <li data-step-marker="2"><span>2</span><b>Address</b></li>
            <li data-step-marker="3"><span>3</span><b data-role-step-label>Business Details</b></li>
            <li data-step-marker="4"><span>4</span><b>Documents &amp; Review</b></li>
        </ol>
    </aside>

    <form class="register-form" data-demo-register novalidate>

        <div class="mobile-progress"><span data-mobile-step>Step 1 of 4</span><div><i data-progress-bar></i></div></div>

        <section class="form-step active" data-step="1">
            <h2>Choose how you'll use Bearly</h2>
            <div class="role-grid">
                <label class="role-card">
                    <input type="radio" name="role" value="buyer">
                    <span class="role-check">✓</span>
                    <img
                        src="{{ asset('images/icon-buyer.png') }}"
                        alt=""
                        class="role-icon"
                        aria-hidden="true"
                    >
                    <strong>Buyer</strong>
                    <small>Shop products from<br>trusted sellers</small>
                </label>
                <label class="role-card selected">
                    <input type="radio" name="role" value="seller" checked>
                    <span class="role-check">✓</span>
                    <img
                        src="{{ asset('images/icon-seller.png') }}"
                        alt=""
                        class="role-icon"
                        aria-hidden="true"
                    >
                    <strong>Seller</strong>
                    <small>Grow your business<br>on Bearly</small>
                </label>
                <label class="role-card">
                    <input type="radio" name="role" value="courier">
                    <span class="role-check">✓</span>
                    <img
                        src="{{ asset('images/icon-courier.png') }}"
                        alt=""
                        class="role-icon"
                        aria-hidden="true"
                    >
                    <strong>Courier</strong>
                    <small>Deliver orders<br>and earn</small>
                </label>
            </div>

            <div class="section-heading"><h3>Personal Information</h3><p>Tell us a little about yourself</p></div>
            <div class="form-grid three">
                <label>First name<input name="first_name" value="{{ old('first_name') }}" pattern="[A-Za-zÀ-ÿ.' -]+" placeholder="Enter your first name" required></label>
                <label>Last name<input name="last_name" value="{{ old('last_name') }}" pattern="[A-Za-zÀ-ÿ.' -]+" placeholder="Enter your last name" required></label>
                <label>
                    Middle initial <em>(Optional)</em>
                    <input
                        type="text"
                        name="middle_initial"
                        value="{{ old('middle_initial') }}"
                        maxlength="2"
                        pattern="[A-Za-z][.]?"
                        title="Enter one letter, optionally followed by a period, such as P or P."
                        placeholder="P."
                    >
                </label>
            </div>
            <div class="form-grid two-wide">
                <label>Sex<select name="sex" required><option value="">Select sex</option><option value="female">Female</option><option value="male">Male</option><option value="prefer_not_to_say">Prefer not to say</option></select></label>
                <label>Email address<input type="email" name="email" value="{{ old('email') }}" placeholder="Enter your email address" required></label>
            </div>
            <div class="form-grid three">
                <label>Contact number<input name="contact_number" value="{{ old('contact_number') }}" inputmode="numeric" maxlength="13" pattern="(?:\+639|09)\d{9}" placeholder="09XXXXXXXXX" required></label>
                <label>Birthday<input id="birthday" type="date" name="birthday" value="{{ old('birthday') }}" max="{{ now()->toDateString() }}" required></label>
                <label>Age <em>(Auto-generated)</em><input id="age" name="age" value="{{ old('age', '--') }}" readonly required></label>
            </div>
            <div class="form-grid two password-row">
                <label>Password<div class="password-wrap"><input id="register-password" type="password" name="password" placeholder="Enter your password" required><button type="button" data-toggle-password="register-password" aria-label="Show password"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></label>
                <label>Confirm password<div class="password-wrap"><input id="password-confirmation" type="password" name="password_confirmation" placeholder="Confirm your password" required><button type="button" data-toggle-password="password-confirmation" aria-label="Show password"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></label>
            </div>
            <div class="password-meter"><i></i><span>Use 8+ characters with uppercase, lowercase, and a number.</span></div>
            <label class="check-label terms"><input type="checkbox" name="terms" value="1" @checked(old('terms')) required><span>I agree to the <a href="/terms">Terms of Service</a> and <a href="/privacy">Privacy Policy</a></span></label>
        </section>

        <section class="form-step" data-step="2">
            <div class="section-heading"><h2>Your address</h2><p>Tell us where you are located.</p></div>
            <div class="form-grid three" data-address-fields>
                <label for="province">Province<select id="province" name="province" data-province-select data-searchable-address data-search-placeholder="Search province" data-old-value="{{ old('province') }}" required disabled><option value="">Loading provinces...</option></select></label>
                <label for="city">City / Municipality<select id="city" name="city" data-city-select data-searchable-address data-search-placeholder="Search city or municipality" data-old-value="{{ old('city') }}" required disabled><option value="">Select province first</option></select></label>
                <label for="barangay">Barangay<select id="barangay" name="barangay" data-barangay-select data-searchable-address data-search-placeholder="Search barangay" data-old-value="{{ old('barangay') }}" required disabled><option value="">Select city first</option></select></label>
            </div>
            <div class="address-service" data-address-service>
                <p class="address-service-message" data-address-message role="status" aria-live="polite">Loading Philippine address data...</p>
                <div class="address-actions">
                    <button type="button" class="secondary-button" data-address-retry hidden>Retry address service</button>
                    <button type="button" class="secondary-button" data-address-manual>Enter address manually</button>
                </div>
            </div>
            <div class="form-grid three"><label>Street name<input name="street_name" value="{{ old('street_name') }}" required></label><label>House / Unit no.<input name="house_number" value="{{ old('house_number') }}" required></label><label>Postal code<input name="postal_code" value="{{ old('postal_code') }}" inputmode="numeric" pattern="\d{4}" maxlength="4" required></label></div>
        </section>

        <section class="form-step role-details-step" data-step="3">
            <div data-role-fields="seller" class="role-fields">
                <div class="section-heading compact-heading">
                    <h2>Business details</h2>
                    <p>Provide the information used to verify your seller account.</p>
                </div>

                <div class="form-grid two compact-business-grid">
                    <label>
                        Business name
                        <input
                            name="business_name"
                            value="{{ old('business_name') }}"
                            pattern="[A-Za-zÀ-ÿ&.,' -]+"
                            placeholder="Enter your registered business name"
                        >
                    </label>
                    <label>
                        Line of business
                        <select name="business_category">
                            <option value="">Select category</option>
                            <option value="Pet Supplies" @selected(old('business_category') === 'Pet Supplies')>Pet Supplies</option>
                            <option value="Electronics and Gadgets" @selected(old('business_category') === 'Electronics and Gadgets')>Electronics and Gadgets</option>
                            <option value="Women's Apparel" @selected(old('business_category') === "Women's Apparel")>Women's Apparel</option>
                            <option value="Men's Apparel" @selected(old('business_category') === "Men's Apparel")>Men's Apparel</option>
                            <option value="Kids and Baby" @selected(old('business_category') === 'Kids and Baby')>Kids and Baby</option>
                            <option value="Home and Garden" @selected(old('business_category') === 'Home and Garden')>Home and Garden</option>
                            <option value="Sports and Outdoors" @selected(old('business_category') === 'Sports and Outdoors')>Sports and Outdoors</option>
                            <option value="Health and Beauty" @selected(old('business_category') === 'Health and Beauty')>Health and Beauty</option>
                            <option value="Books and Media" @selected(old('business_category') === 'Books and Media')>Books and Media</option>
                            <option value="Food and Gourmet" @selected(old('business_category') === 'Food and Gourmet')>Food and Gourmet</option>
                            <option value="Furniture and Office Equipment" @selected(old('business_category') === 'Furniture and Office Equipment')>Furniture and Office Equipment</option>
                            <option value="Jewelry and Watches" @selected(old('business_category') === 'Jewelry and Watches')>Jewelry and Watches</option>
                        </select>
                    </label>
                </div>

                <div class="info-box compact-info-box">
                    <strong>Admin review required.</strong>
                    We’ll email the decision before Seller Dashboard access is enabled.
                </div>
            </div>

            <div data-role-fields="courier" class="role-fields">
                <div class="section-heading compact-heading">
                    <h2>Vehicle details</h2>
                    <p>Provide the vehicle information used for deliveries.</p>
                </div>
                <div class="form-grid two">
                    <label>Vehicle type<select name="vehicle_type"><option value="">Select vehicle</option><option>Motorcycle</option><option>Car</option><option>Van</option><option>Truck</option></select></label>
                    <label>Vehicle model<input name="vehicle_model" value="{{ old('vehicle_model') }}"></label><label>Plate number<input name="plate_number" value="{{ old('plate_number') }}" data-uppercase placeholder="ABC 1234"></label><label>Driver’s license number<input name="drivers_license_number" value="{{ old('drivers_license_number') }}"></label>
                </div>
                <div class="info-box compact-info-box">Your application will be reviewed before delivery access is enabled.</div>
            </div>
        </section>

        <section class="form-step documents-step" data-step="4">
            <div class="documents-heading">
                <div>
                    <h2>Documents &amp; review</h2>
                    <p>Upload the required documents and confirm your application.</p>
                </div>
                <div class="document-security-note">
                    <img src="{{ asset('images/security.png') }}" alt="" class="document-security-icon" aria-hidden="true">
                    <span>Your documents are encrypted and visible only to authorized reviewers.</span>
                </div>
            </div>

            <div class="upload-grid" data-upload-grid>
                <article class="document-upload" data-upload-card>
                    <div class="document-upload__header">
                        <span class="document-icon" aria-hidden="true">
                            <img src="{{ asset('images/id.png') }}" alt="">
                        </span>
                        <div>
                            <span class="required-badge">Required</span>
                            <h3 data-valid-id-label>Valid government ID</h3>
                            <p data-valid-id-help>Passport, driver’s license, national ID, or another government-issued ID.</p>
                            <small>PNG, JPG, or PDF · Max 5 MB</small>
                        </div>
                    </div>

                    <label class="upload-dropzone" data-drop-zone>
                        <input name="valid_id" type="file" accept=".png,.jpg,.jpeg,.pdf" data-file-preview required>
                        <img src="{{ asset('images/cloud.png') }}" alt="" class="upload-icon" aria-hidden="true">
                        <span><strong>Choose file</strong> or drag and drop</span>
                    </label>

                    <div class="file-status" data-file-status hidden>
                        <span class="file-status__icon" aria-hidden="true">✓</span>
                        <span class="file-status__details"><strong data-file-name></strong><small data-file-meta></small></span>
                        <div class="file-status__actions">
                            <button type="button" data-file-action="preview">Preview</button>
                            <button type="button" data-file-action="replace">Replace</button>
                            <button type="button" data-file-action="remove">Remove</button>
                        </div>
                    </div>
                </article>

                <article class="document-upload" data-upload-card data-seller-document>
                    <div class="document-upload__header">
                        <span class="document-icon" aria-hidden="true">
                            <img src="{{ asset('images/permit.png') }}" alt="">
                        </span>
                        <div>
                            <span class="required-badge">Required</span>
                            <h3>Business permit</h3>
                            <p>Upload a clear and current copy of your business permit.</p>
                            <small>PNG, JPG, or PDF · Max 5 MB</small>
                        </div>
                    </div>

                    <label class="upload-dropzone" data-drop-zone>
                        <input name="business_permit" type="file" accept=".png,.jpg,.jpeg,.pdf" data-file-preview>
                        <img src="{{ asset('images/cloud.png') }}" alt="" class="upload-icon" aria-hidden="true">
                        <span><strong>Choose file</strong> or drag and drop</span>
                    </label>

                    <div class="file-status" data-file-status hidden>
                        <span class="file-status__icon" aria-hidden="true">✓</span>
                        <span class="file-status__details"><strong data-file-name></strong><small data-file-meta></small></span>
                        <div class="file-status__actions">
                            <button type="button" data-file-action="preview">Preview</button>
                            <button type="button" data-file-action="replace">Replace</button>
                            <button type="button" data-file-action="remove">Remove</button>
                        </div>
                    </div>
                </article>

                <article class="document-upload" data-upload-card data-courier-document>
                    <div class="document-upload__header">
                        <span class="document-icon" aria-hidden="true">
                            <img src="{{ asset('images/permit.png') }}" alt="">
                        </span>
                        <div>
                            <span class="required-badge">Required</span>
                            <h3>OR / CR</h3>
                            <p>Upload a clear copy of the vehicle’s current OR and CR.</p>
                            <small>PNG, JPG, or PDF · Max 5 MB</small>
                        </div>
                    </div>

                    <label class="upload-dropzone" data-drop-zone>
                        <input name="or_cr" type="file" accept=".png,.jpg,.jpeg,.pdf" data-file-preview>
                        <img src="{{ asset('images/cloud.png') }}" alt="" class="upload-icon" aria-hidden="true">
                        <span><strong>Choose file</strong> or drag and drop</span>
                    </label>

                    <div class="file-status" data-file-status hidden>
                        <span class="file-status__icon" aria-hidden="true">✓</span>
                        <span class="file-status__details"><strong data-file-name></strong><small data-file-meta></small></span>
                        <div class="file-status__actions">
                            <button type="button" data-file-action="preview">Preview</button>
                            <button type="button" data-file-action="replace">Replace</button>
                            <button type="button" data-file-action="remove">Remove</button>
                        </div>
                    </div>
                </article>
            </div>

            <section class="application-summary" aria-labelledby="application-summary-title">
                <div class="application-summary__header">
                    <div>
                        <span class="summary-eyebrow">Final check</span>
                        <h3 id="application-summary-title">Application summary</h3>
                    </div>
                    <button type="button" class="summary-edit" data-edit-step="1">Edit personal details</button>
                </div>
                <div class="review-groups" data-review-summary></div>
            </section>

            <div class="approval-callout">
                <span class="approval-callout__icon" aria-hidden="true">
                    <img src="{{ asset('images/security.png') }}" alt="">
                </span>
                <div><strong>What happens next?</strong><p data-approval-notice></p></div>
            </div>
        </section>

        <div class="form-actions">
            <button type="button" class="secondary-button" data-back>Back</button>
            <button type="button" class="primary-button" data-next>Continue</button>
            <button type="button" class="primary-button" data-submit>Submit application <span aria-hidden="true">→</span></button>
        </div>
        <div class="demo-message" data-register-message hidden role="status">Registration preview completed. No information was saved.</div>
    </form>
</section>
@endsection