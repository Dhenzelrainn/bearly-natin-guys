<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:20px 0 0;border-collapse:collapse;">
    <tr>
        <td bgcolor="{{ $tone['surface'] }}" style="padding:16px 18px;background-color:{{ $tone['surface'] }};border-left:4px solid {{ $tone['accent'] }};">
            <p style="margin:0;color:#65584f;font-size:11px;font-weight:bold;line-height:16px;letter-spacing:.8px;text-transform:uppercase;">Withdrawal {{ $emailData['withdrawal_no'] }}</p>
            <p style="margin:5px 0 0;color:#35251e;font-size:23px;font-weight:bold;line-height:30px;">{{ $emailData['amount'] }}</p>
            <p style="margin:5px 0 0;color:{{ $tone['text'] }};font-size:13px;font-weight:bold;line-height:19px;">{{ str($emailData['status'])->replace('_', ' ')->title() }}</p>
            @if (filled($emailData['reason'] ?? null))
                <p style="margin:10px 0 0;padding:10px 0 0;border-top:1px solid #e7dfd6;color:#51453d;font-size:13px;line-height:20px;"><strong>Note:</strong> {{ $emailData['reason'] }}</p>
            @endif
        </td>
    </tr>
</table>
