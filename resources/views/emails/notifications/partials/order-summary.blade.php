<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:22px 0 0;border-collapse:collapse;border:1px solid #e7dfd6;">
    <tr>
        <td colspan="2" bgcolor="#fbf8f4" style="padding:13px 15px;background-color:#fbf8f4;border-bottom:1px solid #e7dfd6;">
            <p style="margin:0;color:#80634d;font-size:11px;font-weight:bold;line-height:16px;letter-spacing:.8px;text-transform:uppercase;">Order summary</p>
            <p style="margin:4px 0 0;color:#35251e;font-size:14px;font-weight:bold;line-height:20px;">{{ $order['number'] }}</p>
        </td>
    </tr>
    @foreach ($order['items'] as $item)
        <tr>
            <td valign="top" style="padding:12px 10px 12px 15px;border-bottom:1px solid #eee8e2;">
                <p style="margin:0;color:#35251e;font-size:13px;font-weight:bold;line-height:19px;">{{ $item['name'] }}</p>
                @if ($item['variant'])
                    <p style="margin:3px 0 0;color:#65584f;font-size:12px;line-height:17px;">{{ $item['variant'] }}</p>
                @endif
                <p style="margin:3px 0 0;color:#65584f;font-size:12px;line-height:17px;">Qty {{ $item['quantity'] }} &middot; {{ $item['unit_price'] }} each</p>
            </td>
            <td align="right" valign="top" style="padding:12px 15px 12px 5px;border-bottom:1px solid #eee8e2;color:#35251e;font-size:13px;font-weight:bold;line-height:19px;white-space:nowrap;">
                {{ $item['subtotal'] }}
            </td>
        </tr>
    @endforeach
    @if ($order['item_count'] > count($order['items']))
        <tr>
            <td colspan="2" style="padding:10px 15px;color:#65584f;font-size:12px;line-height:18px;border-bottom:1px solid #eee8e2;">
                {{ $order['item_count'] - count($order['items']) }} more {{ \Illuminate\Support\Str::plural('item', $order['item_count'] - count($order['items'])) }} in this order.
            </td>
        </tr>
    @endif
    <tr>
        <td style="padding:13px 10px 13px 15px;color:#51453d;font-size:13px;font-weight:bold;line-height:19px;">{{ $order['total_label'] }}</td>
        <td align="right" style="padding:13px 15px 13px 5px;color:#35251e;font-size:15px;font-weight:bold;line-height:19px;white-space:nowrap;">{{ $order['total'] }}</td>
    </tr>
</table>
