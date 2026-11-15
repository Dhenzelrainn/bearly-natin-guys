@extends('emails.layout')

@section('title', 'Bearly application update')
@section('preheader', 'There is an update about your Bearly application.')

@section('content')
    @php
        $decisionTone = match ($decision) {
            'approved' => ['surface' => '#eef5ee', 'accent' => '#47734f', 'text' => '#2f5b38'],
            'rejected' => ['surface' => '#fbefed', 'accent' => '#ad5148', 'text' => '#813b34'],
            'needs_revision' => ['surface' => '#fff6e8', 'accent' => '#bd8125', 'text' => '#74501c'],
            default => ['surface' => '#f6f2ed', 'accent' => '#80634d', 'text' => '#4b3428'],
        };
    @endphp

    @include('emails.partials.notice-heading', ['eyebrow' => ucfirst($role).' application', 'heading' => 'Hello '.$applicantName])

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:20px 0 0;border-collapse:collapse;">
        <tr>
            <td bgcolor="{{ $decisionTone['surface'] }}" style="padding:16px 18px;background-color:{{ $decisionTone['surface'] }};border-left:4px solid {{ $decisionTone['accent'] }};">
                <p style="margin:0 0 5px;color:#65584f;font-size:11px;font-weight:bold;line-height:16px;letter-spacing:1px;text-transform:uppercase;">Application decision</p>
                <p style="margin:0;color:{{ $decisionTone['text'] }};font-size:18px;font-weight:bold;line-height:25px;">{{ $decisionLabel }}</p>
            </td>
        </tr>
    </table>

    @if ($decision === 'approved')
        <p style="margin:20px 0 0;color:#51453d;font-size:14px;line-height:22px;">Your Bearly {{ $role }} application was approved. You may now sign in to access your account.</p>
    @elseif ($decision === 'needs_revision')
        <p style="margin:20px 0 0;color:#51453d;font-size:14px;line-height:22px;">Please review the requested changes below and update your application. Contact Bearly if you need help.</p>
    @elseif ($decision === 'rejected')
        <p style="margin:20px 0 0;color:#51453d;font-size:14px;line-height:22px;">Your application was not approved. You may contact Bearly support if you have questions about this decision.</p>
    @else
        <p style="margin:20px 0 0;color:#51453d;font-size:14px;line-height:22px;">There is an update about your Bearly {{ $role }} application.</p>
    @endif

    @if ($reason)
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:18px 0 0;border-collapse:collapse;">
            <tr>
                <td style="padding:15px 16px;background-color:#fbf8f4;border:1px solid #e7dfd6;">
                    <p style="margin:0 0 6px;color:#80634d;font-size:11px;font-weight:bold;line-height:16px;letter-spacing:.8px;text-transform:uppercase;">Reason or requested changes</p>
                    <p style="margin:0;color:#51453d;font-size:14px;line-height:22px;">{{ $reason }}</p>
                </td>
            </tr>
        </table>
    @endif
@endsection
