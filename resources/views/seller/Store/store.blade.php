@extends('layouts.seller')

@section('title', 'My Store')
@section('page-title', 'My Store')

@section('content')
<section class="page-heading">
    <div><span class="section-kicker">Store management</span><h2>Store Profile</h2><p>Review your registered business information and manage store contact details.</p></div>
</section>

<form class="store-profile-form" method="POST" action="{{ route('seller.store.save') }}" enctype="multipart/form-data" data-store-form>
    @csrf
    <div class="store-profile-layout store-profile-layout-focused">
        <section class="seller-panel store-information-card">
            <h3>Store Information</h3>

            @if ($errors->any())
                <div class="form-alert"><i data-lucide="circle-alert"></i><span>{{ $errors->first() }}</span></div>
            @endif

            <div class="store-form-columns">
                <div class="store-field-column">
                    <label class="seller-field"><span>Business / Store Name</span><span class="locked-input"><input value="{{ $store['name'] }}" readonly><i data-lucide="lock-keyhole"></i></span><small>Submitted during registration • Changes require administrator review</small></label>
                    <label class="seller-field"><span>Business Category</span><span class="locked-input"><input value="{{ $store['category'] }}" readonly><i data-lucide="lock-keyhole"></i></span><small>Submitted during registration</small></label>
                    <label class="seller-field"><span>Store Contact Email</span><input type="email" name="email" value="{{ old('email', $store['email']) }}" required></label>
                    <label class="seller-field"><span>Store Contact Number</span><input name="phone" value="{{ old('phone', $store['phone']) }}" required></label>
                </div>

                <div class="store-field-column">
                    <label class="seller-field"><span>Store Location</span><span class="locked-input"><input value="{{ $store['location'] }}" readonly><i data-lucide="lock-keyhole"></i></span><small>Your exact registered address is kept private from buyers.</small></label>
                    <div class="store-profile-save"><p><i data-lucide="info"></i>Only store contact details can be edited here during the frontend stage.</p><button class="seller-primary-button" type="submit" name="intent" value="draft"><i data-lucide="save"></i>Save contact details</button></div>
                </div>
            </div>
        </section>

        <aside class="store-side-column">
            <a class="seller-panel store-management-link" href="{{ route('seller.store.appearance') }}"><span><i data-lucide="palette"></i></span><div><strong>Store Appearance</strong><small>Manage your description, profile photo, cover, and buyer preview.</small></div><i data-lucide="chevron-right"></i></a>
            <a class="seller-panel store-management-link" href="{{ route('seller.store.publication') }}"><span><i data-lucide="store"></i></span><div><strong>Publication Settings</strong><small>Review requirements and control buyer visibility.</small></div><i data-lucide="chevron-right"></i></a>
        </aside>
    </div>

    <div class="locked-information-note"><i data-lucide="info"></i><p>Some information is locked because it was submitted during registration.<br>To request changes, please contact the administrator.</p></div>
</form>
@endsection
