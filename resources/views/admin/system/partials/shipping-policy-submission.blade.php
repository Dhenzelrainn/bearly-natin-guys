<article class="panel admin-shipping-policy-review" data-policy-submission="{{ $submission['id'] }}">
    <header class="admin-shipping-policy-review-heading">
        <div>
            <span class="eyebrow">{{ $submission['category'] }} · {{ $submission['code'] }}</span>
            <h2>{{ $submission['title'] }}</h2>
            <p>Version {{ $submission['version'] }} · Submitted by {{ $submission['author'] }} · {{ $submission['submitted_at'] }}</p>
        </div>
        <span class="status-badge badge-warning">Pending approval</span>
    </header>

    @if ($submission['summary'])
        <p class="admin-shipping-policy-summary">{{ $submission['summary'] }}</p>
    @endif

    <section class="admin-shipping-policy-content" aria-label="Submitted policy content">
        <h3>Policy content</h3>
        <div>{{ $submission['body'] }}</div>
    </section>

    <dl class="admin-shipping-policy-source">
        <dt>Source or approval reference</dt>
        <dd>{{ $submission['source_reference'] }}</dd>
        <dt>Manager’s submission reason</dt>
        <dd>{{ $submission['submission_reason'] }}</dd>
    </dl>

    <div class="admin-shipping-policy-decisions">
        <form method="POST" action="{{ route('admin.shipping-policy-approvals.approve', [$submission['policy_id'], $submission['id']]) }}" class="admin-shipping-policy-decision-form">
            @csrf
            <input type="hidden" name="decision" value="approve">
            <h3>Approve and publish</h3>
            <p>Confirm you reviewed the policy and its cited source before publication.</p>
            <label class="admin-shipping-policy-confirm">
                <input type="checkbox" name="source_reviewed" value="1" required>
                <span>I reviewed the cited source and approve this policy version.</span>
            </label>
            <label class="admin-shipping-policy-field">
                <span>Approval reason</span>
                <textarea name="reason" rows="3" minlength="5" maxlength="1000" required>{{ ($preserveOldInput ?? false) && old('decision') === 'approve' ? old('reason') : '' }}</textarea>
            </label>
            <button class="button button-primary" type="submit">Approve &amp; Publish</button>
        </form>

        <form method="POST" action="{{ route('admin.shipping-policy-approvals.request-changes', [$submission['policy_id'], $submission['id']]) }}" class="admin-shipping-policy-decision-form is-return">
            @csrf
            <input type="hidden" name="decision" value="return">
            <h3>Return for changes</h3>
            <p>The appointed Logistics manager will see your reason and can submit a new version.</p>
            <label class="admin-shipping-policy-field">
                <span>Changes required</span>
                <textarea name="reason" rows="3" minlength="5" maxlength="1000" required>{{ ($preserveOldInput ?? false) && old('decision') === 'return' ? old('reason') : '' }}</textarea>
            </label>
            <button class="button button-secondary" type="submit">Return for changes</button>
        </form>
    </div>
</article>
