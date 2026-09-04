@extends('layouts.seller')

@section('title', 'My Store')
@section('page-title', 'My Store')

@section('content')
<section class="page-heading">
    <div><h2>My Store / Store Profile</h2><p>Manage your store information and how it appears to buyers.</p></div>
</section>

<form class="store-profile-form" method="POST" action="{{ route('seller.store.save') }}" enctype="multipart/form-data" data-store-form>
    @csrf
    <div class="store-profile-layout">
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
                    <label class="seller-field"><span>Store Location</span><input value="{{ $store['location'] }}" readonly><small>Your exact registered address is not shown publicly.</small></label>
                    <label class="seller-field"><span>Store Description</span><textarea name="description" maxlength="500" placeholder="Tell buyers about your store and what you sell" data-description>{{ old('description', $store['description']) }}</textarea><small class="character-count"><span data-description-count>{{ strlen(old('description', $store['description'])) }}</span> / 500</small></label>

                    <div class="store-photo-grid">
                        <label class="photo-upload" data-photo-upload>
                            <span class="field-label">Store Profile Photo</span>
                            <span class="upload-box">
                                @if ($store['profile_photo'])<img src="{{ asset('storage/'.$store['profile_photo']) }}" alt="Store profile preview" data-photo-preview>@else<i data-lucide="camera"></i>@endif
                                <strong>Upload profile photo</strong><small>JPG, PNG up to 5MB</small>
                            </span>
                            <input type="file" name="profile_photo" accept="image/png,image/jpeg,image/webp" data-photo-input hidden>
                        </label>
                        <label class="photo-upload" data-photo-upload>
                            <span class="field-label">Store Cover Photo</span>
                            <span class="upload-box">
                                @if ($store['cover_photo'])<img src="{{ asset('storage/'.$store['cover_photo']) }}" alt="Store cover preview" data-photo-preview>@else<i data-lucide="image"></i>@endif
                                <strong>Upload cover photo</strong><small>JPG, PNG up to 10MB</small>
                            </span>
                            <input type="file" name="cover_photo" accept="image/png,image/jpeg,image/webp" data-photo-input hidden>
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <aside class="store-side-column">
            <section class="seller-panel completion-card">
                <h3>Store Completion</h3>
                <div class="completion-ring" style="--completion: {{ $completion * 3.6 }}deg"><strong>{{ $completion }}%</strong></div>
                <ul class="completion-list">
                    <li class="is-done"><i data-lucide="circle-check-big"></i>Business information</li>
                    <li class="is-done"><i data-lucide="circle-check-big"></i>Contact details</li>
                    <li class="{{ $store['profile_photo'] ? 'is-done' : '' }}"><i data-lucide="{{ $store['profile_photo'] ? 'circle-check-big' : 'circle' }}"></i>Add profile photo</li>
                    <li class="{{ $store['cover_photo'] ? 'is-done' : '' }}"><i data-lucide="{{ $store['cover_photo'] ? 'circle-check-big' : 'circle' }}"></i>Add cover photo</li>
                    <li class="{{ $store['description'] ? 'is-done' : '' }}"><i data-lucide="{{ $store['description'] ? 'circle-check-big' : 'circle' }}"></i>Write store description</li>
                </ul>
            </section>

            <section class="seller-panel publication-card">
                <h3>Publication Status</h3>
                <span class="publication-badge {{ $store['published'] ? 'is-published' : '' }}">• {{ $store['published'] ? 'Published' : 'Not Published' }}</span>
                <p>{{ $store['published'] ? 'Your store is visible to buyers.' : 'Your store is not visible to buyers until you publish.' }}</p>
                <button class="publish-button" type="submit" name="intent" value="publish" {{ $completion < 100 ? 'disabled' : '' }}><span>Save & Publish</span>@if ($completion < 100)<i data-lucide="lock-keyhole"></i>@endif</button>
                <button class="draft-button" type="submit" name="intent" value="draft">Save as Draft</button>
            </section>
        </aside>
    </div>

    <div class="locked-information-note"><i data-lucide="info"></i><p>Some information is locked because it was submitted during registration.<br>To request changes, please contact the administrator.</p></div>
</form>
@endsection
