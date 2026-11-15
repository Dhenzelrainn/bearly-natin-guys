@extends('emails.layout')

@section('title', 'Reset your Bearly password')
@section('preheader', 'Use the secure link to choose a new Bearly password.')

@section('content')
    @include('emails.partials.notice-heading', ['eyebrow' => 'Account security', 'heading' => 'Reset your password'])

    <p style="margin:18px 0 0;color:#51453d;font-size:14px;line-height:22px;">
        We received a request to reset the password for your Bearly account. Use the secure link below to choose a new password.
    </p>

    @include('emails.partials.button', ['url' => $resetUrl, 'label' => 'Reset password'])
    @include('emails.partials.link-fallback', ['url' => $resetUrl])

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:22px 0 0;border-collapse:collapse;">
        <tr>
            <td style="padding:14px 16px;background-color:#fff6e8;border-left:3px solid #bd8125;color:#74501c;font-size:13px;line-height:20px;">
                This link expires in {{ $expiresInMinutes }} {{ \Illuminate\Support\Str::plural('minute', $expiresInMinutes) }}. If you did not request a password reset, you can ignore this email; your current password will remain unchanged.
            </td>
        </tr>
    </table>

    <p style="margin:18px 0 0;color:#65584f;font-size:12px;line-height:19px;">
        For your security, do not forward this link or share it with anyone.
    </p>
@endsection
