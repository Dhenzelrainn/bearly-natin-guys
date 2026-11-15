BEARLY MARKETPLACE — {{ strtoupper($presentation['family_label']) }}

@php echo html_entity_decode(e($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'); @endphp

@if ($presentation['family'] === 'account' && !empty($emailData['to_status']))
Account status: {{ $emailData['from_status'] }} → {{ $emailData['to_status'] }}
@if (filled($emailData['reason'] ?? null))
Reason: {{ $emailData['reason'] }}
@endif

@endif
@if ($presentation['order'])
Order: {{ $presentation['order']['number'] }}
@foreach ($presentation['order']['items'] as $item)
- {{ $item['name'] }}@if ($item['variant']) ({{ $item['variant'] }})@endif — Qty {{ $item['quantity'] }} × {{ $item['unit_price'] }}; line total {{ $item['subtotal'] }}
@endforeach
{{ $presentation['order']['total_label'] }}: {{ $presentation['order']['total'] }}

@endif
@if ($presentation['reference'])
{{ $presentation['reference']['label'] }}: {{ $presentation['reference']['value'] }}

@endif
@if ($presentation['return_case'])
Return case: {{ $presentation['return_case']['number'] }}
Case update: @php echo html_entity_decode(e($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'); @endphp
Request type: {{ $presentation['return_case']['request_type'] }}
Amount requested: {{ $presentation['return_case']['requested_amount'] }}
@if ($presentation['return_case']['approved_amount'])
Approved refund amount: {{ $presentation['return_case']['approved_amount'] }}
@endif
@if ($presentation['return_case']['payout_amount'])
Payout recorded: {{ $presentation['return_case']['payout_amount'] }}
@endif
@foreach ($presentation['return_case']['items'] as $item)
- {{ $item['name'] }}@if ($item['variant']) ({{ $item['variant'] }})@endif — Qty {{ $item['quantity'] }}
@endforeach

@endif
@if (!empty($emailData['withdrawal_no']))
Withdrawal: {{ $emailData['withdrawal_no'] }}
Amount: {{ $emailData['amount'] }}
Status: {{ str($emailData['status'])->replace('_', ' ')->title() }}
@if (filled($emailData['reason'] ?? null))
Note: {{ $emailData['reason'] }}
@endif

@endif
@php echo html_entity_decode(e($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'); @endphp

@if ($actionUrl)
{{ $presentation['action_label'] }}: {{ $actionUrl }}
@endif

Bearly Marketplace
