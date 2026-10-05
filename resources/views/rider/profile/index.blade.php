@extends('rider.layouts.app')

@section('title', 'Account')
@section('page-title', 'Account')

@section('content')

<div class="page-header">
    <div>
        <p class="page-kicker">
            Profile and security
        </p>

        <h2>
            Rider account management
        </h2>

        <p>
            Maintain your personal details, registered vehicle,
            service address, and account security.
        </p>
    </div>
</div>

<section
    class="panel"
    style="margin-bottom: 18px"
>
    <div class="account-summary">
        <span class="avatar">
            {{ $rider['initials'] }}
        </span>

        <div>
            <h3>
                {{ $rider['name'] }}
            </h3>

            <p>
                {{ $rider['role'] }}
                ·
                {{ $rider['vehicle'] }}
                ·
                {{ $rider['plate'] }}
            </p>
        </div>

        <span
            class="status-badge {{ $profile['verification_status'] === 'approved' ? 'is-success' : '' }}"
            style="margin-left: auto"
        >
            {{ $profile['verification_label'] }} rider
        </span>
    </div>
</section>

<div data-tab-scope>

    <div
        class="tabs"
        data-tabs
    >
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
            data-tab="vehicle"
        >
            Vehicle
        </button>

        <button
            class="tab-button"
            type="button"
            data-tab="addresses"
        >
            Manage Addresses
        </button>

        <button
            class="tab-button"
            type="button"
            data-tab="security"
        >
            Password & Security
        </button>
    </div>

    {{-- Personal Profile --}}
    <section
        class="panel"
        data-tab-panel="profile"
        style="margin-top: 16px"
    >
        <div class="panel-header">
            <div>
                <h3>
                    Personal profile
                </h3>

                <p>
                    Information shared with Logistics dispatchers.
                </p>
            </div>
        </div>

        <form
            class="panel-body"
            method="POST"
            action="{{ route('rider.profile.update') }}"
        >
            @csrf
            @method('PATCH')

            <div class="field-grid">

                <div class="field">
                    <label for="rider-name">
                        Full name
                    </label>

                    <input
                        id="rider-name"
                        name="name"
                        value="{{ old('name', $rider['name']) }}"
                        required
                    >
                </div>

                <div class="field">
                    <label for="rider-sex">
                        Sex
                    </label>

                    @php
                        $selectedSex = old(
                            'sex',
                            $profile['sex']
                        );
                    @endphp

                    <select
                        id="rider-sex"
                        name="sex"
                        required
                    >
                        <option
                            value="Male"
                            @selected($selectedSex === 'Male')
                        >
                            Male
                        </option>

                        <option
                            value="Female"
                            @selected($selectedSex === 'Female')
                        >
                            Female
                        </option>

                        <option
                            value="Prefer not to say"
                            @selected($selectedSex === 'Prefer not to say')
                        >
                            Prefer not to say
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label for="rider-email">
                        Email address
                    </label>

                    <input
                        id="rider-email"
                        type="email"
                        value="{{ $rider['email'] }}"
                        readonly
                    >
                </div>

                <div class="field">
                    <label for="rider-contact">
                        Contact number
                    </label>

                    <input
                        id="rider-contact"
                        name="contact"
                        value="{{ old(
                            'contact',
                            $profile['contact']
                        ) }}"
                        required
                    >
                </div>

                <div class="field">
                    <label for="rider-birthday">
                        Birthday
                    </label>

                    <input
                        id="rider-birthday"
                        type="date"
                        name="birthday"
                        value="{{ old(
                            'birthday',
                            $profile['birthday']
                        ) }}"
                    >
                </div>

                <div class="field">
                    <label for="rider-emergency-contact-name">
                        Emergency contact name
                    </label>

                    <input
                        id="rider-emergency-contact-name"
                        name="emergency_contact_name"
                        value="{{ old(
                            'emergency_contact_name',
                            $profile['emergency_contact_name']
                        ) }}"
                    >
                </div>

                <div class="field">
                    <label for="rider-emergency-contact-phone">
                        Emergency contact number
                    </label>

                    <input
                        id="rider-emergency-contact-phone"
                        name="emergency_contact_phone"
                        value="{{ old(
                            'emergency_contact_phone',
                            $profile['emergency_contact_phone']
                        ) }}"
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

    {{-- Vehicle --}}
    <section
        class="panel"
        data-tab-panel="vehicle"
        hidden
        style="margin-top: 16px"
    >
        <div class="panel-header">
            <div>
                <h3>
                    Registered vehicle
                </h3>

                <p>
                    Used for capacity planning and rider assignment.
                </p>
            </div>

            <span
                class="status-badge {{ $profile['verification_status'] === 'approved' ? 'is-success' : '' }}"
            >
                {{ $profile['verification_label'] }}
            </span>
        </div>

        <form
            class="panel-body"
            data-preview-form="vehicle"
        >
            <div class="field-grid">

                <div class="field">
                    <label for="rider-vehicle-type">
                        Vehicle type
                    </label>

                    @php
                        $vehicleTypes = collect([
                            $profile['vehicle_type'],
                            'Motorcycle',
                            'Tricycle',
                            'E-bike',
                            'Van',
                        ])
                            ->filter()
                            ->unique()
                            ->values();
                    @endphp

                    <select
                        id="rider-vehicle-type"
                        name="vehicle"
                    >
                        @foreach ($vehicleTypes as $vehicleType)
                            <option
                                value="{{ $vehicleType }}"
                                @selected(
                                    $profile['vehicle_type']
                                    === $vehicleType
                                )
                            >
                                {{ $vehicleType }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="rider-plate">
                        Plate number
                    </label>

                    <input
                        id="rider-plate"
                        name="plate"
                        value="{{ $profile['plate_number'] }}"
                        required
                    >
                </div>

                <div class="field">
                    <label for="rider-vehicle-model">
                        Vehicle model
                    </label>

                    <input
                        id="rider-vehicle-model"
                        name="model"
                        value="{{ $profile['vehicle_model'] }}"
                    >
                </div>

                <div class="field">
                    <label for="rider-parcel-capacity">
                        Parcel capacity
                    </label>

                    <input
                        id="rider-parcel-capacity"
                        type="number"
                        name="capacity"
                        min="1"
                        value="{{ $profile['parcel_capacity'] }}"
                    >
                </div>

            </div>

            <div class="form-actions">
                <button
                    class="button button-primary"
                    type="submit"
                >
                    Save vehicle details
                </button>
            </div>
        </form>
    </section>

    {{-- Addresses --}}
    <section
        class="panel"
        data-tab-panel="addresses"
        hidden
        style="margin-top: 16px"
    >
        <div class="panel-header">
            <div>
                <h3>
                    Saved addresses
                </h3>

                <p>
                    Your home address and current service assignment.
                </p>
            </div>

            <button
                class="button button-small button-primary"
                type="button"
                data-modal-open="add-address"
            >
                <i data-lucide="plus"></i>
                Add address
            </button>
        </div>

        <div class="panel-body">

            <div class="address-card">
                <span>
                    <i data-lucide="house"></i>
                </span>

                <span>
                    <strong>
                        Home address
                    </strong>

                    <small>
                        {{ $profile['address'] }}
                    </small>
                </span>

                <button
                    class="button button-small"
                    type="button"
                    data-address-edit
                >
                    Edit
                </button>
            </div>

            <div class="address-card">
                <span>
                    <i data-lucide="map-pinned"></i>
                </span>

                <span>
                    <strong>
                        Preferred service area
                    </strong>

                    <small>
                        {{ $profile['preferred_area'] }}
                    </small>
                </span>

                <button
                    class="button button-small"
                    type="button"
                    data-address-edit
                >
                    Edit
                </button>
            </div>

        </div>
    </section>

    {{-- Security --}}
    <section
        class="panel"
        data-tab-panel="security"
        hidden
        style="margin-top: 16px"
    >
        <div class="panel-header">
            <div>
                <h3>
                    Change password
                </h3>

                <p>
                    Use at least eight characters.
                </p>
            </div>
        </div>

        <form
            class="panel-body"
            method="POST"
            action="{{ route('rider.profile.password.update') }}"
        >
            @csrf
            @method('PATCH')
            <div class="field-grid">

                <div class="field span-2">
                    <label for="current-password">
                        Current password
                    </label>

                    <input
                        id="current-password"
                        type="password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <div class="field">
                    <label for="new-password">
                        New password
                    </label>

                    <input
                        id="new-password"
                        type="password"
                        name="new_password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="field">
                    <label for="new-password-confirmation">
                        Confirm new password
                    </label>

                    <input
                        id="new-password-confirmation"
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

</div>

{{-- Add Address Modal --}}
<section
    class="modal"
    data-modal="add-address"
    hidden
>
    <div class="modal-header">
        <div>
            <h3>
                Add rider address
            </h3>

            <p>
                Save an additional rider address.
            </p>
        </div>

        <button
            class="icon-button"
            type="button"
            data-modal-close
        >
            <i data-lucide="x"></i>
        </button>
    </div>

    <form
        class="modal-body"
        data-preview-form="address"
    >
        <div class="field-grid">

            <div class="field">
                <label for="address-label">
                    Address label
                </label>

                <input
                    id="address-label"
                    name="label"
                    required
                    placeholder="e.g. Secondary home"
                >
            </div>

            <div class="field">
                <label for="address-province">
                    Province
                </label>

                <input
                    id="address-province"
                    name="province"
                    required
                    value="Laguna"
                >
            </div>

            <div class="field">
                <label for="address-city">
                    Municipality / City
                </label>

                <input
                    id="address-city"
                    name="city"
                    required
                    value="San Pablo City"
                >
            </div>

            <div class="field">
                <label for="address-barangay">
                    Barangay
                </label>

                <input
                    id="address-barangay"
                    name="barangay"
                    required
                >
            </div>

            <div class="field span-2">
                <label for="address-street">
                    Street / House number
                </label>

                <input
                    id="address-street"
                    name="street"
                    required
                >
            </div>

        </div>

        <div class="form-actions">
            <button
                class="button"
                type="button"
                data-modal-close
            >
                Cancel
            </button>

            <button
                class="button button-primary"
                type="submit"
            >
                Save address
            </button>
        </div>
    </form>
</section>

@endsection