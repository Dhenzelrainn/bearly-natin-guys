<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:20px 0 0;border-collapse:collapse;border:1px solid #e7dfd6;">
    <tr>
        <td colspan="2" bgcolor="{{ $tone['surface'] }}" style="padding:13px 15px;background-color:{{ $tone['surface'] }};border-bottom:1px solid #e7dfd6;">
            <p style="margin:0;color:#80634d;font-size:11px;font-weight:bold;line-height:16px;letter-spacing:.8px;text-transform:uppercase;">Case update</p>
            <p style="margin:4px 0 0;color:#35251e;font-size:15px;font-weight:bold;line-height:21px;">{{ $returnCase['number'] }}</p>
            <p style="margin:3px 0 0;color:#51453d;font-size:12px;line-height:18px;">{{ $returnCase['request_type'] }} &middot; {{ $title }}</p>
        </td>
    </tr>
    @foreach ($returnCase['items'] as $item)
        <tr>
            <td style="padding:10px 10px 10px 15px;border-bottom:1px solid #eee8e2;color:#35251e;font-size:13px;line-height:19px;">
                <strong>{{ $item['name'] }}</strong>
                @if ($item['variant'])<br><span style="color:#65584f;font-size:12px;">{{ $item['variant'] }}</span>@endif
            </td>
            <td align="right" style="padding:10px 15px 10px 5px;border-bottom:1px solid #eee8e2;color:#51453d;font-size:12px;line-height:19px;white-space:nowrap;">Qty {{ $item['quantity'] }}</td>
        </tr>
    @endforeach
    @if ($returnCase['item_count'] > count($returnCase['items']))
        <tr>
            <td colspan="2" style="padding:9px 15px;color:#65584f;font-size:12px;line-height:18px;border-bottom:1px solid #eee8e2;">
                {{ $returnCase['item_count'] - count($returnCase['items']) }} more {{ \Illuminate\Support\Str::plural('item', $returnCase['item_count'] - count($returnCase['items'])) }} in this case.
            </td>
        </tr>
    @endif
    <tr>
        <td style="padding:12px 10px 12px 15px;color:#51453d;font-size:12px;line-height:18px;">Amount requested</td>
        <td align="right" style="padding:12px 15px 12px 5px;color:#35251e;font-size:13px;font-weight:bold;line-height:18px;white-space:nowrap;">{{ $returnCase['requested_amount'] }}</td>
    </tr>
    @if ($returnCase['approved_amount'])
        <tr>
            <td style="padding:9px 10px 12px 15px;border-top:1px solid #eee8e2;color:#51453d;font-size:12px;line-height:18px;">Approved refund amount</td>
            <td align="right" style="padding:9px 15px 12px 5px;border-top:1px solid #eee8e2;color:#35251e;font-size:13px;font-weight:bold;line-height:18px;white-space:nowrap;">{{ $returnCase['approved_amount'] }}</td>
        </tr>
    @endif
    @if ($returnCase['payout_amount'])
        <tr>
            <td style="padding:9px 10px 12px 15px;border-top:1px solid #eee8e2;color:#51453d;font-size:12px;line-height:18px;">Payout recorded</td>
            <td align="right" style="padding:9px 15px 12px 5px;border-top:1px solid #eee8e2;color:#35251e;font-size:13px;font-weight:bold;line-height:18px;white-space:nowrap;">{{ $returnCase['payout_amount'] }}</td>
        </tr>
    @endif
</table>
