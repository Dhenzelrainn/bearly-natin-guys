<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:20px 0 0;border-collapse:collapse;">
    <tr>
        <td style="padding:15px 16px;background-color:{{ $tone['surface'] }};border:1px solid #e7dfd6;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:0 12px 0 0;color:#65584f;font-size:12px;line-height:19px;">Previous status</td>
                    <td align="right" style="padding:0;color:#35251e;font-size:13px;font-weight:bold;line-height:19px;">{{ $emailData['from_status'] }}</td>
                </tr>
                <tr>
                    <td style="padding:9px 12px 0 0;color:#65584f;font-size:12px;line-height:19px;border-top:1px solid #e7dfd6;">Updated status</td>
                    <td align="right" style="padding:9px 0 0;color:{{ $tone['text'] }};font-size:13px;font-weight:bold;line-height:19px;border-top:1px solid #e7dfd6;">{{ $emailData['to_status'] }}</td>
                </tr>
            </table>
            @if (filled($emailData['reason'] ?? null))
                <p style="margin:12px 0 0;padding:11px 0 0;border-top:1px solid #e7dfd6;color:#51453d;font-size:13px;line-height:21px;">
                    <strong>Reason:</strong> {{ $emailData['reason'] }}
                </p>
            @endif
        </td>
    </tr>
</table>
