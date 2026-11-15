BEARLY MARKETPLACE — {{ strtoupper($role) }} APPLICATION

Hello {{ $applicantName }},

Your Bearly {{ $role }} application decision is: {{ $decisionLabel }}.

@if ($decision === 'approved')
Your application was approved. You may now sign in to access your account.
@elseif ($decision === 'needs_revision')
Please review the requested changes below and update your application. Contact Bearly if you need help.
@elseif ($decision === 'rejected')
Your application was not approved. You may contact Bearly support if you have questions about this decision.
@else
There is an update about your Bearly {{ $role }} application.
@endif

@if ($reason)
Reason or requested changes:
{{ $reason }}
@endif

Bearly Marketplace
