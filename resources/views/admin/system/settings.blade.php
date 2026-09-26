@extends('layouts.admin')

@section('title', 'Platform Settings')
@section('page-title', 'Platform Settings')

@section('content')

<section class="page-hero">
    <div>
        <span class="eyebrow">System Management</span>
        <h1>Platform settings</h1>
        <p>
            Configure marketplace-wide operational settings, registration availability,
            transaction defaults, and system behavior.
        </p>
    </div>
</section>

<form method="POST" action="{{ route('admin.settings.update') }}">
@csrf
@method('PATCH')
<section class="settings-grid">

    <article class="panel settings-editor-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Marketplace Configuration</span>
                <h2>General platform settings</h2>
            </div>
        </div>

        <div class="form-grid two-column-form">
            <label class="form-field">
                <span>Marketplace name</span>
                <input
                    type="text"
                    name="marketplace_name"
                    value="{{ old('marketplace_name', $settings['marketplace_name']) }}"
                    data-setting-marketplace-name
                >
            </label>

            <label class="form-field">
                <span>Platform commission rate</span>
                <div class="input-with-suffix">
                    <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.1"
                        name="commission_rate"
                        value="{{ old('commission_rate', $settings['commission_rate']) }}"
                        data-setting-commission-rate
                    >
                    <span>%</span>
                </div>
            </label>
        </div>

        <label class="form-field">
            <span>Marketplace description</span>
            <textarea
                rows="4"
                name="marketplace_description"
                data-setting-marketplace-description
            >{{ old('marketplace_description', $settings['marketplace_description']) }}</textarea>
        </label>
    </article>

    <aside class="panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">System Status</span>
                <h2>Platform availability</h2>
            </div>
        </div>

        <div class="settings-toggle-list">

            <label class="settings-toggle-row">
                <div>
                    <strong>Marketplace operations</strong>
                    <small>
                        Allow users to access and use the marketplace normally.
                    </small>
                </div>

                <input type="hidden" name="platform_active" value="0">
                <input
                    type="checkbox"
                    name="platform_active"
                    value="1"
                    data-setting-platform-active
                    @checked(old('platform_active', $settings['platform_active']))
                >
            </label>

            <label class="settings-toggle-row">
                <div>
                    <strong>Maintenance mode</strong>
                    <small>
                        Temporarily restrict marketplace access during maintenance.
                    </small>
                </div>

                <input type="hidden" name="maintenance_mode" value="0">
                <input
                    type="checkbox"
                    name="maintenance_mode"
                    value="1"
                    data-setting-maintenance-mode
                    @checked(old('maintenance_mode', $settings['maintenance_mode']))
                >
            </label>

        </div>

        <div
            class="settings-availability-state"
            data-platform-availability-state
        >

            <i
                data-lucide="circle-check"
                data-platform-availability-icon
            ></i>

            <span data-platform-availability-message>
                Marketplace is available to users.
            </span>

        </div>

    </aside>

</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Registration Management</span>
            <h2>Account registration availability</h2>
            <p>
                Control which account types can currently submit registration requests.
            </p>
        </div>
    </div>

    <div class="settings-toggle-grid">

        <label class="settings-toggle-card">
            <div>
                <i data-lucide="shopping-bag"></i>
            </div>

            <span>
                <strong>Buyer registration</strong>
                <small>Allow new Buyer accounts to register.</small>
            </span>

            <input type="hidden" name="registration_buyer" value="0">
            <input
                type="checkbox"
                name="registration_buyer"
                value="1"
                data-setting-registration="buyer"
                @checked(old('registration_buyer', $settings['registration_buyer']))
            >
        </label>

        <label class="settings-toggle-card">
            <div>
                <i data-lucide="store"></i>
            </div>

            <span>
                <strong>Seller registration</strong>
                <small>Allow new Sellers to submit applications.</small>
            </span>

            <input type="hidden" name="registration_seller" value="0">
            <input
                type="checkbox"
                name="registration_seller"
                value="1"
                data-setting-registration="seller"
                @checked(old('registration_seller', $settings['registration_seller']))
            >
        </label>

        <label class="settings-toggle-card">
            <div>
                <i data-lucide="warehouse"></i>
            </div>

            <span>
                <strong>Logistics registration</strong>
                <small>Allow Logistics Centers to submit applications.</small>
            </span>

            <input type="hidden" name="registration_logistics" value="0">
            <input
                type="checkbox"
                name="registration_logistics"
                value="1"
                data-setting-registration="logistics"
                @checked(old('registration_logistics', $settings['registration_logistics']))
            >
        </label>

        <label class="settings-toggle-card">
            <div>
                <i data-lucide="bike"></i>
            </div>

            <span>
                <strong>Rider registration</strong>
                <small>Allow Riders to submit applications through Logistics Centers.</small>
            </span>

            <input type="hidden" name="registration_rider" value="0">
            <input
                type="checkbox"
                name="registration_rider"
                value="1"
                data-setting-registration="rider"
                @checked(old('registration_rider', $settings['registration_rider']))
            >
        </label>

    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Transactions</span>
            <h2>Order and payment settings</h2>
        </div>
    </div>

    <div class="form-grid two-column-form">

        <label class="form-field">
            <span>Default order cancellation window</span>
            <div class="input-with-suffix">
                <input
                    type="number"
                    min="1"
                    max="720"
                    name="cancellation_hours"
                    value="{{ old('cancellation_hours', $settings['cancellation_hours']) }}"
                    data-setting-cancellation-hours
                >
                <span>hours</span>
            </div>
        </label>

        <label class="form-field">
            <span>Seller settlement period</span>
            <select name="settlement_days" data-setting-settlement-period>
                <option value="7" @selected(old('settlement_days', $settings['settlement_days']) == 7)>Every 7 days</option>
                <option value="14" @selected(old('settlement_days', $settings['settlement_days']) == 14)>Every 14 days</option>
                <option value="30" @selected(old('settlement_days', $settings['settlement_days']) == 30)>Every 30 days</option>
            </select>
        </label>

    </div>
</section>

<section class="panel settings-save-panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">
                Administrative Controls
            </span>

            <h2>
                Save platform configuration
            </h2>

            <p>
                Review and apply marketplace-wide configuration changes.
            </p>
        </div>


        <div
            class="settings-save-state"
            data-settings-state
            data-state="saved"
        >

            <span class="settings-save-state-icon">
                <i data-lucide="circle-check"></i>
            </span>

            <div>
                <strong data-settings-state-title>
                    Configuration saved
                </strong>

                <small data-settings-state-message>
                    Current settings match the saved platform configuration.
                </small>
            </div>

        </div>

    </div>


    <div class="panel-footer-actions">

        <button
            class="button button-secondary"
            type="button"
            data-open-modal="reset-platform-settings"
        >
            <i data-lucide="rotate-ccw"></i>
            Reset settings
        </button>


        <button
            class="button button-primary"
            type="submit"
        >
            <i data-lucide="save"></i>
            Save settings
        </button>

    </div>

</section>
</form>

<div
    class="modal-shell"
    data-modal="reset-platform-settings"
    hidden
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
        aria-label="Close reset confirmation"
    ></button>


    <section
        class="modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reset-platform-settings-title"
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">
                    Platform Settings
                </span>

                <h2 id="reset-platform-settings-title">
                    Reset platform settings?
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

            <div class="detail-note">

                <span>
                    Restore default configuration
                </span>

                <p>
                    This will restore marketplace settings,
                    registration availability, transaction defaults,
                    and platform availability controls to their default values.
                </p>

            </div>

        </div>


        <div class="modal-footer decision-footer">

            <button
                type="button"
                class="button button-secondary"
                data-close-modal
            >
                Cancel
            </button>


            <form method="POST" action="{{ route('admin.settings.reset') }}">
                @csrf
                <button type="submit" class="button button-danger-soft">
                    <i data-lucide="rotate-ccw"></i>
                    Reset settings
                </button>
            </form>

        </div>

    </section>

</div>

@endsection
