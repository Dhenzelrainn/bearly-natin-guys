@extends('logistics.layouts.app')

@section('title', 'Account')
@section('page-title', 'Account')

@section('content')
    <div class="page-header">
        <div>
            <p class="page-kicker">Settings</p>

            <h2>Account and facility settings</h2>

            <p>
                Maintain your operator profile, password, and sorting center information.
            </p>
        </div>
    </div>

    <div data-tab-scope>
        <div class="tabs" data-tabs>
            <button
                class="tab-button is-active"
                type="button"
                data-tab="profile"
            >
                Profile
            </button>

            <button
                class="tab-button"
                type="button"
                data-tab="security"
            >
                Password & security
            </button>

            <button
                class="tab-button"
                type="button"
                data-tab="payouts"
            >
                Payout destinations
            </button>

            <button
                class="tab-button"
                type="button"
                data-tab="facility"
            >
                Facility
            </button>
        </div>

        {{-- Operator Profile --}}
        <section
            class="panel"
            data-tab-panel="profile"
            style="margin-top: 16px"
        >
            <div class="panel-header">
                <div>
                    <h3>Operator profile</h3>

                    <p>
                        Information displayed throughout Logistics operations.
                    </p>
                </div>
            </div>

            <form
                class="panel-body"
                method="POST"
                action="{{ route('logistics.profile.update') }}"
            >
                @csrf
                @method('PATCH')

                <div class="field-grid">
                    <div class="field">
                        <label>Full name</label>

                        <input
                            name="name"
                            value="{{ old('name', $operator['name']) }}"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>Role</label>

                        <input
                            name="role"
                            value="{{ $operator['role'] }}"
                            readonly
                        >
                    </div>

                    <div class="field">
                        <label>Email address</label>

                        <input
                            type="email"
                            name="email"
                            value="{{ $operator['email'] }}"
                            readonly
                        >
                    </div>

                    <div class="field">
                        <label>Contact number</label>

                        <input
                            name="contact"
                            value="{{ old('contact', $operator['contact']) }}"
                            required
                        >
                    </div>
                </div>

                <div class="form-actions">
                    <button
                        class="button button-primary"
                        type="submit"
                    >
                        Save profile
                    </button>
                </div>
            </form>
        </section>

        {{-- Password & Security --}}
        <section
            class="panel"
            data-tab-panel="security"
            hidden
            style="margin-top: 16px"
        >
            <div class="panel-header">
                <div>
                    <h3>Change password</h3>

                    <p>
                        Use at least eight characters for your new password.
                    </p>
                </div>
            </div>

            <form
                class="panel-body"
                method="POST"
                action="{{ route('logistics.profile.password.update') }}"
            >
                @csrf
                @method('PATCH')

                <div class="field-grid">
                    <div class="field span-2">
                        <label>Current password</label>

                        <input
                            type="password"
                            name="current_password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>New password</label>

                        <input
                            type="password"
                            name="new_password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>Confirm new password</label>

                        <input
                            type="password"
                            name="new_password_confirmation"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                    </div>
                </div>

                <div class="form-actions">
                    <button
                        class="button button-primary"
                        type="submit"
                    >
                        Update password
                    </button>
                </div>
            </form>
        </section>

        {{-- Payout destinations --}}
        <section
            class="panel"
            data-tab-panel="payouts"
            hidden
            style="margin-top: 16px"
            id="payouts"
        >
            <div class="panel-header">
                <div>
                    <h3>Bank / GCash payout destinations</h3>
                    <p>Finance manually sends eligible shipping settlements outside Bearly. New destinations must be verified by Finance before use. Full account or mobile numbers are encrypted and only masked here.</p>
                </div>
            </div>

            <div class="panel-body">
                <form method="POST" action="{{ route('logistics.profile.payout-accounts.store') }}">
                    @csrf
                    <div class="field-grid">
                        <div class="field">
                            <label for="payout-channel">Payout method</label>
                            <select id="payout-channel" name="channel" required data-logistics-payout-channel>
                                <option value="bank_transfer" @selected(old('channel', 'bank_transfer') === 'bank_transfer')>Bank transfer</option>
                                <option value="gcash" @selected(old('channel') === 'gcash')>GCash</option>
                            </select>
                        </div>
                        <div class="field" data-bank-provider-field>
                            <label for="payout-provider-name">Bank name</label>
                            <input id="payout-provider-name" type="text" name="provider_name" value="{{ old('provider_name') }}" maxlength="120" data-bank-provider-input>
                        </div>
                        <div class="field">
                            <label for="payout-account-name">Account holder name</label>
                            <input id="payout-account-name" type="text" name="account_name" value="{{ old('account_name') }}" maxlength="160" autocomplete="name" required>
                        </div>
                        <div class="field">
                            <label for="payout-destination-value" data-payout-destination-label>Bank account number</label>
                            <input id="payout-destination-value" type="text" name="destination_value" value="{{ old('destination_value') }}" maxlength="42" autocomplete="off" inputmode="numeric" required data-payout-destination-input>
                            <small data-gcash-number-hint hidden>Enter a valid Philippine GCash mobile number.</small>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">Submit destination for review</button>
                    </div>
                </form>

                <div class="panel-header" style="margin-top: 22px">
                    <div>
                        <h3>Saved destinations</h3>
                        <p>Account changes create a new record so prior settlement history remains accurate.</p>
                    </div>
                </div>
                <div class="cash-custody-table-wrap" role="region" aria-label="Saved Logistics payout destinations" tabindex="0">
                    <table class="cash-custody-table">
                        <thead>
                            <tr><th>Method / provider</th><th>Account holder</th><th>Destination</th><th>Status / review</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            @forelse($payoutAccounts as $payoutAccount)
                                <tr>
                                    <td>{{ $payoutAccount->channel === 'gcash' ? 'GCash' : 'Bank transfer' }}<small class="table-subline">{{ $payoutAccount->provider_name }}</small></td>
                                    <td>{{ $payoutAccount->account_name }}</td>
                                    <td>{{ $payoutAccount->maskedDestination() }}</td>
                                    <td>
                                        <span class="status-badge" data-status="{{ $payoutAccount->status }}">{{ str($payoutAccount->status)->replace('_', ' ')->title() }}</span>
                                        @if($payoutAccount->review_note)
                                            <small class="table-subline">{{ $payoutAccount->review_note }}</small>
                                        @elseif($payoutAccount->status === 'pending_review')
                                            <small class="table-subline">Waiting for Finance verification.</small>
                                        @endif
                                    </td>
                                    <td>
                                        @if(in_array($payoutAccount->status, ['pending_review', 'verified'], true))
                                            <form method="POST" action="{{ route('logistics.profile.payout-accounts.deactivate', $payoutAccount->id) }}" onsubmit="return confirm('Deactivate this payout destination? Existing settlement history will remain unchanged.')">
                                                @csrf
                                                <button class="button button-secondary button-small" type="submit">Deactivate</button>
                                            </form>
                                        @else
                                            <span class="empty-copy">No action</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><p class="empty-copy">No payout destination has been added. Add a bank or GCash destination for Finance review.</p></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Sorting Facility --}}
        <section
            class="panel"
            data-tab-panel="facility"
            hidden
            style="margin-top: 16px"
        >
            <div class="panel-header">
                <div>
                    <h3>Sorting facility</h3>

                    <p>
                        Information used in pickup, intake, sorting, and dispatch operations.
                    </p>
                </div>
            </div>

            <form
                class="panel-body"
                method="POST"
                action="{{ route('logistics.profile.facility.update') }}"
                data-logistics-facility-form
            >
                @csrf
                @method('PATCH')

                <div class="field-grid">
                    <div class="field">
                        <label>Business / facility name</label>

                        <input
                            name="business_name"
                            value="{{ old('business_name', $facility['business_name']) }}"
                            required
                        >
                        @error('business_name')
                            <small class="facility-form-error" role="alert">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="field">
                        <label>Contact number</label>

                        <input
                            name="contact"
                            value="{{ old('contact', $facility['contact']) }}"
                            required
                        >
                        @error('contact')
                            <small class="facility-form-error" role="alert">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="field span-2">
                        <label>Facility address</label>

                        <input
                            name="address"
                            value="{{ $facility['address'] }}"
                            readonly
                        >
                    </div>

                    <div class="field span-2">
                        @if ($facility['has_map_pin_controls'])
                            <div class="address-map-picker-section">
                                <div class="address-map-picker-heading">
                                    <div>
                                        <strong>Facility map pin</strong>
                                        <small>Use “Find facility location” to draft a facility pin; only a fix at reported accuracy of ±100 m or better can set it. Coarser fixes show an approximate area and never change a saved pin. This one-time pin check stays on this page. The saved facility pin supplements the registered address and stays fixed. The top-bar “Share location” control is separate: it shares an approximate live device marker with your own Logistics maps for up to 3 minutes after the last update, and never moves the saved facility pin. Clear the facility pin and save facility settings to remove it. Pickup-map guide lines are approximate straight-line guides, not road routes.</small>
                                    </div>
                                </div>

                                <div
                                    id="logistics-facility-map"
                                    class="address-map"
                                    data-logistics-facility-map
                                    data-latitude="{{ $facility['latitude'] ?? '' }}"
                                    data-longitude="{{ $facility['longitude'] ?? '' }}"
                                    data-map-tile-url="{{ config('maps.tile_url') }}"
                                    data-map-attribution="{{ config('maps.tile_attribution') }}"
                                    data-reverse-geocode-url="{{ route('maps.reverse-geocode') }}"
                                    aria-label="Saved facility pin and provider-only live device location map"
                                ></div>

                                <div
                                    class="facility-location-control"
                                    data-facility-location-status
                                    role="status"
                                    aria-live="polite"
                                    hidden
                                >
                                    <span class="facility-location-dot" data-facility-location-dot aria-hidden="true"></span>
                                    <span class="facility-location-copy">
                                        <strong data-facility-location-label>Location check is off</strong>
                                        <small data-facility-location-help>Device location is checked only while this page is open.</small>
                                    </span>
                                    <button
                                        class="button button-small"
                                        type="button"
                                        data-stop-facility-location
                                        hidden
                                    >
                                        Stop location check
                                    </button>
                                </div>

                                <input type="hidden" name="latitude" value="{{ $facility['latitude'] ?? '' }}">
                                <input type="hidden" name="longitude" value="{{ $facility['longitude'] ?? '' }}">

                                <p
                                    id="logistics-facility-map-status"
                                    class="address-map-status"
                                    data-logistics-facility-map-status
                                    role="status"
                                    aria-live="polite"
                                >
                                    @if ($facility['latitude'] !== null && $facility['longitude'] !== null)
                                        Saved facility pin loaded. The blue live-device marker, when shared, is separate; adjust the facility pin and save facility settings.
                                    @else
                                        No facility pin saved. Tap the map to place one, or find a sufficiently accurate device fix, then save facility settings.
                                    @endif
                                </p>

                                @error('latitude')
                                    <small class="facility-form-error" role="alert">{{ $message }}</small>
                                @enderror
                                @error('longitude')
                                    <small class="facility-form-error" role="alert">{{ $message }}</small>
                                @enderror

                                <div class="address-map-picker-actions">
                                    <button
                                        class="address-map-location"
                                        type="button"
                                        data-detect-facility-location
                                        aria-controls="logistics-facility-map"
                                        aria-describedby="logistics-facility-map-status"
                                    >
                                        <i data-lucide="locate-fixed" aria-hidden="true"></i>
                                        Find facility location
                                    </button>
                                    <button
                                        class="address-map-location"
                                        type="button"
                                        data-place-facility-pin-manually
                                        aria-controls="logistics-facility-map"
                                        aria-describedby="logistics-facility-map-status"
                                        aria-pressed="false"
                                    >
                                        <i data-lucide="map-pin" aria-hidden="true"></i>
                                        Place pin manually
                                    </button>
                                    <button
                                        class="address-map-clear"
                                        type="button"
                                        data-clear-facility-pin
                                        aria-describedby="logistics-facility-map-status"
                                        @if ($facility['latitude'] === null || $facility['longitude'] === null) hidden @endif
                                    >
                                        Clear saved pin
                                    </button>
                                </div>
                            </div>
                        @else
                            <p class="address-map-status" role="status">
                                A facility pin can be saved when this account has an active Sorting Center with a registered address.
                            </p>
                        @endif
                    </div>

                    <div class="field">
                        <label>Operating hours</label>

                        <input
                            name="operating_hours"
                            value="{{ old('operating_hours', $facility['operating_hours']) }}"
                            required
                        >
                        @error('operating_hours')
                            <small class="facility-form-error" role="alert">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="field">
                        <label>Daily parcel capacity</label>

                        <input
                            type="number"
                            min="1"
                            name="daily_capacity"
                            value="{{ old('daily_capacity', $facility['daily_capacity']) }}"
                            required
                        >
                        @error('daily_capacity')
                            <small class="facility-form-error" role="alert">{{ $message }}</small>
                        @enderror
                    </div>
                </div>

                <div class="form-actions">
                    <button
                        class="button button-primary"
                        type="submit"
                    >
                        Save facility settings
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
