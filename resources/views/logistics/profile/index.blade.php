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
                    </div>

                    <div class="field">
                        <label>Contact number</label>

                        <input
                            name="contact"
                            value="{{ old('contact', $facility['contact']) }}"
                            required
                        >
                    </div>

                    <div class="field span-2">
                        <label>Facility address</label>

                        <input
                            name="address"
                            value="{{ $facility['address'] }}"
                            readonly
                        >
                    </div>

                    <div class="field">
                        <label>Operating hours</label>

                        <input
                            name="operating_hours"
                            value="{{ old('operating_hours', $facility['operating_hours']) }}"
                            required
                        >
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