@extends('layouts.courier')

@section('title', 'Courier Registration')

@section('content')
<section class="registration-shell">
    <aside class="registration-aside">
        <span class="eyebrow">Module 01 · Courier partner application</span>
        <h1>Deliver with Bearly.</h1>
        <p>Complete your personal, address, and vehicle information. This is a static front-end registration flow that mirrors the ERP approval process.</p>
        <div class="registration-flow">
            <div class="registration-flow-item"><span>1</span><div><strong>Courier information</strong><small>Identity and contact details</small></div></div>
            <div class="registration-flow-item"><span>2</span><div><strong>Address details</strong><small>Province, city, barangay and street</small></div></div>
            <div class="registration-flow-item"><span>3</span><div><strong>Vehicle & documents</strong><small>Vehicle, plate, OR/CR and license</small></div></div>
        </div>
    </aside>

    <article class="registration-card">
        <div class="step-heading">
            <div><span class="eyebrow">Registration step <span data-registration-current>1</span> of 3</span><h2>Courier application</h2><p>Fields marked with * are required in the eventual backend implementation.</p></div>
            <span class="step-count"><i data-lucide="bike"></i></span>
        </div>
        <div class="registration-progress"><span class="progress-step is-active" data-progress-step></span><span class="progress-step" data-progress-step></span><span class="progress-step" data-progress-step></span></div>

        <section data-registration-step>
            <div class="form-grid two-column-form">
                <label class="form-field"><span>First name *</span><input type="text" value="Adrian"></label>
                <label class="form-field"><span>Last name *</span><input type="text" value="Cruz"></label>
                <label class="form-field"><span>Middle initial</span><input type="text" value="M" maxlength="1"></label>
                <label class="form-field"><span>Sex *</span><select><option>Male</option><option>Female</option><option>Prefer not to say</option></select></label>
                <label class="form-field"><span>Email *</span><input type="email" value="adrian.courier@example.test"></label>
                <label class="form-field"><span>Contact number *</span><input type="text" value="09175554821"></label>
                <label class="form-field"><span>Birthday *</span><input type="date" value="1998-04-18"></label>
                <label class="form-field"><span>Age (auto-generated)</span><input type="text" value="28" readonly></label>
            </div>
            <div class="registration-actions"><span></span><button class="button button-primary" type="button" data-step-next>Continue <i data-lucide="arrow-right"></i></button></div>
        </section>

        <section data-registration-step hidden>
            <div class="form-grid two-column-form">
                <label class="form-field"><span>Province *</span><select><option>Metro Manila</option><option>Laguna</option><option>Cavite</option><option>Rizal</option></select></label>
                <label class="form-field"><span>City / Municipality *</span><select><option>Quezon City</option><option>Mandaluyong City</option><option>Pasig City</option><option>San Juan City</option></select></label>
                <label class="form-field"><span>Barangay *</span><select><option>Socorro</option><option>Bagumbayan</option><option>Kaunlaran</option></select></label>
                <label class="form-field"><span>House / Unit number</span><input type="text" value="Unit 4B"></label>
            </div>
            <label class="form-field"><span>Street / Building *</span><input type="text" value="General Roxas Avenue, Cubao"></label>
            <label class="form-field"><span>Address notes</span><textarea placeholder="Landmark, subdivision, floor, etc.">Near Gateway Mall.</textarea></label>
            <div class="registration-actions"><button class="button button-secondary" type="button" data-step-back><i data-lucide="arrow-left"></i> Back</button><button class="button button-primary" type="button" data-step-next>Continue <i data-lucide="arrow-right"></i></button></div>
        </section>

        <section data-registration-step hidden>
            <div class="form-grid two-column-form">
                <label class="form-field"><span>Vehicle type *</span><select>@foreach($vehicleTypes as $vehicle)<option>{{ $vehicle }}</option>@endforeach</select></label>
                <label class="form-field"><span>Plate number *</span><input type="text" value="NCR 4821"></label>
                <label class="form-field"><span>Vehicle model</span><input type="text" value="Honda Click 160"></label>
                <label class="form-field"><span>Preferred delivery area</span><input type="text" value="Quezon City / San Juan"></label>
            </div>
            <div class="section-subtitle">Verification documents</div>
            <div class="upload-grid">
                <label class="file-drop"><input type="file"><i data-lucide="file-up"></i><strong>Upload OR / CR *</strong><small>PDF, JPG or PNG • mock upload</small></label>
                <label class="file-drop"><input type="file"><i data-lucide="badge-check"></i><strong>Upload Driver's License / ID *</strong><small>PDF, JPG or PNG • mock upload</small></label>
            </div>
            <div class="policy-note" style="margin-top:14px"><i data-lucide="shield-check"></i><span>Submitting this preview simulates sending the application to an administrator for approval. No file or personal data is stored.</span></div>
            <div class="registration-actions"><button class="button button-secondary" type="button" data-step-back><i data-lucide="arrow-left"></i> Back</button><button class="button button-primary" type="button" data-registration-submit data-pending-url="{{ route('courier.pending') }}"><i data-lucide="send"></i> Submit application</button></div>
        </section>
    </article>
</section>
@endsection
