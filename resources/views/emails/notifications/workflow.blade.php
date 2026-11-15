@extends('emails.layout')

@section('title', $title)
@section('preheader', \Illuminate\Support\Str::limit(strip_tags($body), 110))

@section('content')
    @include('emails.partials.notice-heading', ['eyebrow' => $presentation['family_label'], 'heading' => $title])

    @if ($presentation['family'] === 'announcement')
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:24px 0 0;border-collapse:separate;border-spacing:0;border:1px solid #eee4d8;border-radius:7px;">
            <tr>
                <td width="5" bgcolor="#e9a72f" style="width:5px;background-color:#e9a72f;font-size:1px;line-height:1px;">&nbsp;</td>
                <td bgcolor="#fffbf5" style="padding:20px 21px;background-color:#fffbf5;border-radius:0 7px 7px 0;">
                    <p style="margin:0 0 8px;color:#80634d;font-size:10px;font-weight:bold;line-height:15px;letter-spacing:1px;text-transform:uppercase;">A note from Bearly</p>
                    <p style="margin:0;color:#42332a;font-size:15px;line-height:25px;">{{ $body }}</p>
                </td>
            </tr>
        </table>
    @elseif ($presentation['family'] === 'message')
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:24px 0 0;border-collapse:separate;border-spacing:0;border:1px solid #e7dfd6;border-radius:7px;">
            <tr>
                <td bgcolor="#fbf8f4" style="padding:18px 20px;background-color:#fbf8f4;border-left:4px solid #80634d;border-radius:6px;color:#42332a;font-size:15px;line-height:25px;">
                    {{ $body }}
                </td>
            </tr>
        </table>
    @else
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:24px 0 0;border-collapse:separate;border-spacing:0;border:1px solid #eee8e2;border-radius:7px;">
            <tr>
                <td bgcolor="{{ $presentation['tone']['surface'] }}" style="padding:18px 20px;background-color:{{ $presentation['tone']['surface'] }};border-left:4px solid {{ $presentation['tone']['accent'] }};border-radius:6px;color:#42332a;font-size:15px;line-height:25px;">
                    {{ $body }}
                </td>
            </tr>
        </table>
    @endif

    @if ($presentation['family'] === 'account' && !empty($emailData['to_status']))
        @include('emails.notifications.partials.account-status', ['emailData' => $emailData, 'tone' => $presentation['tone']])
    @endif

    @if ($presentation['family'] === 'order' && $presentation['order'])
        @include('emails.notifications.partials.order-summary', ['order' => $presentation['order']])
    @endif

    @if ($presentation['family'] === 'shipping' && $presentation['reference'])
        @include('emails.notifications.partials.shipping-reference', ['reference' => $presentation['reference'], 'tone' => $presentation['tone']])
    @endif

    @if ($presentation['family'] === 'return' && $presentation['return_case'])
        @include('emails.notifications.partials.return-summary', ['returnCase' => $presentation['return_case'], 'title' => $title, 'tone' => $presentation['tone']])
    @endif

    @if ($presentation['family'] === 'finance' && !empty($emailData['withdrawal_no']))
        @include('emails.notifications.partials.withdrawal-summary', ['emailData' => $emailData, 'tone' => $presentation['tone']])
    @endif

    @if ($actionUrl)
        @include('emails.partials.button', ['url' => $actionUrl, 'label' => $presentation['action_label']])
        @include('emails.partials.link-fallback', ['url' => $actionUrl])
    @endif
@endsection
