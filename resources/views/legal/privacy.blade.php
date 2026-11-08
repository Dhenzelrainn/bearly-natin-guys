@extends('layouts.auth')
@section('title', 'Privacy Policy | Bearly')
@section('header-action')<a class="header-link" href="{{ route('register') }}">Create account</a>@endsection
@section('content')
<article class="legal-page">
    <p class="legal-meta">Version {{ config('bearly-policies.version') }}</p>
    <h1>Privacy Policy</h1>
    @if(config('bearly-policies.draft'))<p class="legal-draft">Project draft for testing. The team must confirm its operator details, retention periods, and privacy contact before collecting real registration documents at public launch.</p>@endif
    <p>{{ config('bearly-policies.operator') }} handles information submitted through Bearly. This notice explains the registration process and how to raise a privacy concern.</p>
    <h2>Information collected</h2>
    <p>Registration collects your name, optional middle initial, sex selection, birthday, email, mobile number, address, account type, and password. New Buyers do not upload a government ID. Government IDs and other documents are collected only for roles whose registration requires them; Seller registration also asks for business details and a permit. The system records email verification, policy acceptance, and applicable account review status. Session cookies and temporary request counters support sign-in, verification, and abuse prevention.</p>
    <h2>Why it is used</h2>
    <p>These details support account registration, email verification, role-specific application review, account security, and marketplace operation. Buyer accounts activate after email proof; Seller, Logistics, and Rider applications retain their applicable review workflows. Verification emails confirm access to your inbox; requesting a code does not subscribe you to marketing.</p>
    <h2>Email verification and service providers</h2>
    <p>Bearly sends your email address and verification message through its configured email provider to deliver the code. Your email provider also processes the message. Processing may occur outside the Philippines. The deployment operator should identify the chosen provider and its privacy notice before public launch.</p>
    <h2>Documents and security</h2>
    <p>Documents required for Seller, Logistics, or Rider registration are saved outside the public web directory for role-specific review. New Buyers do not upload a government ID. Existing Buyer documents already submitted remain subject to the published retention and deletion process. Passwords are hashed. Temporary verification records expire automatically. These measures do not remove all security risks. Do not send passwords or verification codes to support staff or other users.</p>
    <h2>Retention</h2>
    <p>Verification codes expire after 10 minutes in Bearly’s registration flow. A successful verification can be used for registration for up to 30 minutes. In-progress Buyer/Seller, Logistics, and Rider registration fields are encrypted and retained until seven days of inactivity on the same browser capability. Passwords, verification codes, consent choices, and registration files (including profile photos) are not saved in these drafts. Seller Add Product fields, variants, and temporary product photos are encrypted or stored privately until seven days of inactivity and are scoped to the Seller’s Store; they do not create a product or change inventory until the Seller saves or publishes. Expired drafts and temporary Seller image files become eligible for cleanup after that inactivity period. The daily cleanup job normally runs at 03:00, so physical deletion may occur during the next scheduled cleanup run; if storage deletion is temporarily unavailable, retained image metadata allows Bearly to retry. Account records and documents remain stored until removed through the team’s retention and deletion process.</p>
    @if(config('bearly-policies.draft'))<p>The project has not yet adopted final retention periods or automatic document deletion. Use test information during development. The team must publish those periods and implement the corresponding deletion process before public launch.</p>@endif
    <h2>Your choices and rights</h2>
    <p>You may request access to your information, correction of inaccurate details, or deletion or restriction where applicable. You may raise concerns about processing or withdraw consent where consent is the basis for processing. Some information may need to be retained for a lawful purpose. You may also raise a complaint with the Philippine National Privacy Commission.</p>
    <h2>Privacy contact</h2>
    @if(config('bearly-policies.contact_email'))
        <p>Contact {{ config('bearly-policies.operator') }} at <a href="mailto:{{ config('bearly-policies.contact_email') }}">{{ config('bearly-policies.contact_email') }}</a>.</p>
    @else
        <p>Contact the Bearly project team through the <a href="{{ route('contact') }}">contact page</a>. A dedicated privacy contact must be confirmed before public launch.</p>
    @endif
    <p>See also: <a href="{{ route('terms') }}">Terms of Service</a>.</p>
</article>
@endsection
