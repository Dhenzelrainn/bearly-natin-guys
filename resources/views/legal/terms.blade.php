@extends('layouts.auth')
@section('title', 'Terms of Service | Bearly')
@section('header-action')<a class="header-link" href="{{ route('register') }}">Create account</a>@endsection
@section('content')
<article class="legal-page">
    <p class="legal-meta">Version {{ config('bearly-policies.version') }}</p>
    <h1>Terms of Service</h1>
    @if(config('bearly-policies.draft'))<p class="legal-draft">Project draft for testing. These terms need approval by the Bearly team before public launch.</p>@endif
    <p>These terms explain the use of Bearly, a marketplace project operated by {{ config('bearly-policies.operator') }}. By creating an account or submitting a role-specific application, you agree to these terms.</p>
    <h2>1. Your account</h2>
    <p>Provide accurate details and an email address you control and a valid contact number. Keep your password and verification codes private. Do not share your account, impersonate someone else, or submit another person’s identity documents.</p>
    <h2>2. Registration and review</h2>
    <p>New Buyer accounts activate after Bearly verifies access to the registered email address through an OTP or verified Google email. Buyers do not need to upload a government ID or wait for Administrator approval. Email proof confirms inbox access only; it does not verify a phone number or identity. Buyer applications submitted under the previous review process retain their status and records and are no longer in the active Admin queues; contact Bearly Support about an existing pending account. Seller, Logistics, and Rider registrations retain their role-specific document and approval requirements.</p>
    <h2>3. Using the marketplace</h2>
    <p>Use Bearly only for lawful transactions. Do not create fraudulent orders, upload harmful content, harass other users, sell prohibited goods, bypass access controls, or interfere with the service.</p>
    <h2>4. Orders, delivery, and returns</h2>
    <p>Review the seller, item description, price, available payment methods, and delivery details before placing an order. Applicable charges and conditions must be shown before confirmation. Report order problems through the available support process. These terms do not remove rights available under applicable consumer law.</p>
    <p>Registration currently supports Philippine delivery addresses. Selecting an international phone code does not enable overseas delivery.</p>
    <h2>5. Restrictions and account access</h2>
    <p>Bearly may restrict access while reviewing suspected fraud, inaccurate registration information, security incidents, or violations of these terms. Contact the team if you believe a restriction is incorrect.</p>
    <h2>6. Service changes</h2>
    <p>Features may be unavailable during maintenance or testing. Updated terms will carry a new version so you can review changes. Real transactions should only take place when the team announces that the service is ready.</p>
    <h2>7. Privacy and contact</h2>
    <p>Read the <a href="{{ route('privacy') }}">Privacy Policy</a> for information about registration data. Questions about these terms can be raised through the <a href="{{ route('contact') }}">Bearly contact page</a>.</p>
</article>
@endsection
