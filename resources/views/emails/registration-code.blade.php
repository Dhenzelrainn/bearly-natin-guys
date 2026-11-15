@extends('emails.layout')

@section('title', 'Your Bearly verification code')
@section('preheader', 'Use this one-time code to verify your email address.')

@section('content')
    @include('emails.partials.notice-heading', ['eyebrow' => 'Email verification', 'heading' => 'Verify your email'])

    <p style="margin:18px 0 0;color:#51453d;font-size:15px;line-height:24px;">
        Enter this code in the Bearly registration form to verify this email address.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:22px 0 0;border-collapse:collapse;">
        <tr>
            <td align="center" bgcolor="#35251e" style="padding:24px 16px;background-color:#35251e;border-top:4px solid #e9a72f;border-radius:7px;">
                <p style="margin:0 0 9px;color:#f3c36c;font-size:10px;font-weight:bold;line-height:15px;letter-spacing:1.5px;text-transform:uppercase;">One-time verification code</p>
                <p style="margin:0;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:34px;font-weight:bold;line-height:42px;letter-spacing:8px;">{{ $code }}</p>
            </td>
        </tr>
    </table>

    <p style="margin:18px 0 0;color:#51453d;font-size:14px;line-height:22px;">
        This code expires in {{ $expiresInMinutes }} {{ \Illuminate\Support\Str::plural('minute', $expiresInMinutes) }}. Do not share it with anyone. Bearly will never ask you to send this code to support.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:18px 0 0;border-collapse:collapse;">
        <tr>
            <td style="padding:13px 15px;background-color:#fff6e8;border-left:3px solid #bd8125;color:#74501c;font-size:13px;line-height:20px;">
                If you did not request this code, you can ignore this email.
            </td>
        </tr>
    </table>
@endsection
