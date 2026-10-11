Hello {{ $applicantName }},

Your Bearly {{ $role }} application decision is: {{ str($decision)->replace('_', ' ')->title() }}.

@if ($decision === 'approved')
You may now sign in to access your approved Bearly account.
@elseif ($decision === 'needs_revision')
Please review the requested changes below and contact Bearly if you need help updating your application.
@elseif ($decision === 'rejected')
Your application was not approved. You may contact Bearly support if you have questions about this decision.
@endif

@if ($reason)
Reason or requested changes: {{ $reason }}
@endif

Bearly
