@extends('logistics.layouts.app')

@section('title', 'Shipping Policies')
@section('page-title', 'Shipping Policies')

@section('content')
<div data-shipping-policies-page>
<div class="page-header shipping-policy-page-header">
    <div>
        <p class="page-kicker">Bearly Logistics · Provider-wide standards</p>
        <h2>Shipping and Rider policies</h2>
        <p>Keep published handling guidance and its cited source easy to review across Logistics teams.</p>
    </div>
</div>

@if ($errors->any())
    <section class="shipping-policy-alert is-error" role="alert">
        <strong>Review the policy information before continuing.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </section>
@endif

<section class="shipping-policy-alert {{ $isPolicyManager ? 'is-manager' : 'is-readonly' }}" aria-label="Policy access">
    <i data-lucide="{{ $isPolicyManager ? 'shield-check' : 'file-check-2' }}" aria-hidden="true"></i>
    <div>
        @if ($isPolicyManager)
            <strong>You are the appointed Bearly Logistics policy manager.</strong>
            <p>You can save and submit shared policy drafts for Admin review. Only Admin can approve and publish a submitted version.</p>
        @else
            <strong>Published rules are shared across Logistics accounts.</strong>
            <p>
                @if ($policyManager?->user
                    && $policyManager->status === 'active'
                    && $policyManager->user->status === 'active'
                    && $policyManager->user->role === 'logistics')
                    {{ $policyManager->user->name }} is the appointed policy manager. Contact them for updates.
                @elseif ($policyManager?->user)
                    {{ $policyManager->user->name }} is still listed as the policy manager, but their Logistics profile or account is inactive. An Admin must appoint an active manager.
                @else
                    No active policy manager is assigned. An Admin must appoint one before policies can be changed.
                @endif
            </p>
        @endif
        <p class="shipping-policy-boundary">These documents are informational. Automated restricted-item and product-combination checks are not enabled.</p>
    </div>
</section>

@if ($isPolicyManager)
    <section class="panel shipping-policy-editor" aria-labelledby="shipping-policy-create-title">
        <div class="panel-heading">
            <div>
                <span class="page-kicker">Policy management</span>
                <h3 id="shipping-policy-create-title">Create a policy</h3>
            </div>
        </div>
        <p class="shipping-policy-help">Save a draft first. When it is ready, submit it for Admin review with its source or approval reference. Only Admin approval publishes it; publication does not switch on automatic checks.</p>

        <form method="POST" action="{{ route('logistics.shipping-policies.store') }}" class="shipping-policy-form">
            @csrf
            <div class="shipping-policy-form-grid">
                <label class="shipping-policy-field">
                    <span>Policy title</span>
                    <input name="title" value="{{ old('title') }}" maxlength="180" required>
                </label>
                <label class="shipping-policy-field">
                    <span>Category</span>
                    <select name="category" required>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(old('category') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="shipping-policy-field">
                    <span>Version label</span>
                    <input name="version" value="{{ old('version', 'v1.0') }}" maxlength="20" placeholder="e.g. v1.0" required>
                </label>
                <label class="shipping-policy-field shipping-policy-field-wide">
                    <span>Summary <small>Optional</small></span>
                    <textarea name="summary" rows="2" maxlength="2000">{{ old('summary') }}</textarea>
                </label>
                <label class="shipping-policy-field shipping-policy-field-wide">
                    <span>Policy content</span>
                    <textarea name="body" rows="7" maxlength="50000" required>{{ old('body') }}</textarea>
                </label>
                <label class="shipping-policy-field shipping-policy-field-wide">
                    <span>Source or approval reference <small>Optional for drafts; required for Admin review</small></span>
                    <input name="source_reference" value="{{ old('source_reference') }}" maxlength="2000" placeholder="Document name, revision, or approved internal reference">
                </label>
                <label class="shipping-policy-field shipping-policy-field-wide">
                    <span>Creation note <small>Optional; recorded in the audit history if provided</small></span>
                    <input name="reason" value="{{ old('reason') }}" maxlength="1000">
                </label>
            </div>
            <div class="shipping-policy-form-actions">
                <button class="button button-secondary" type="submit">Save as Draft</button>
            </div>
        </form>
    </section>
@endif

<section class="panel shipping-policy-library" aria-labelledby="shipping-policy-library-title">
    <div class="panel-heading">
        <div>
            <span class="page-kicker">{{ $isPolicyManager ? 'Published documents and drafts' : 'Current provider guidance' }}</span>
            <h3 id="shipping-policy-library-title">Bearly Logistics policy library</h3>
        </div>
    </div>

    @forelse ($policies as $policy)
        @php
            $latest = $policy['latest'];
            $published = $policy['published'];
            $draftAvailable = $isPolicyManager && ($latest['status_value'] ?? null) === 'draft';
            $draftEditFailed = old('shipping_policy_form') === 'edit_draft'
                && (int) old('policy_id') === $policy['id']
                && (int) old('version_id') === (int) ($latest['id'] ?? 0);
            $newDraftFailed = old('shipping_policy_form') === 'update' && (int) old('policy_id') === $policy['id'];
            $archiveFailed = old('shipping_policy_form') === 'archive' && (int) old('policy_id') === $policy['id'];
        @endphp
        <article class="shipping-policy-entry">
            <header class="shipping-policy-entry-heading">
                <div>
                    <span class="shipping-policy-category">{{ $policy['category'] }} · {{ $policy['code'] }}</span>
                    <h4>{{ $policy['title'] }}</h4>
                </div>
                <span class="shipping-policy-status is-{{ $policy['status_value'] }}">{{ $policy['status'] }}</span>
            </header>

            @if ($published)
                <div class="shipping-policy-published">
                    <div class="shipping-policy-version-line">
                        <strong>Published {{ $published['version'] }}</strong>
                        <span>{{ $published['published_at'] ?: 'Publication date not recorded' }}</span>
                    </div>
                    @if ($published['summary'])
                        <p class="shipping-policy-summary">{{ $published['summary'] }}</p>
                    @endif
                    <div class="shipping-policy-body">{!! nl2br(e($published['body'])) !!}</div>
                    <p class="shipping-policy-source"><strong>Source:</strong> {{ $published['source_reference'] }}</p>
                </div>
            @elseif ($isPolicyManager)
                <p class="shipping-policy-draft-note">No version of this policy is currently published. Sellers cannot see this draft.</p>
            @endif

            @if ($isPolicyManager && $latest && $latest['status_value'] === 'pending_approval')
                <p class="shipping-policy-review-note is-pending"><strong>{{ $latest['version'] }} is awaiting Admin review.</strong> The currently published version, if any, remains visible until Admin approves a replacement.</p>
            @elseif ($isPolicyManager && $latest && $latest['status_value'] === 'changes_requested')
                <div class="shipping-policy-review-note is-returned">
                    <strong>Admin returned {{ $latest['version'] }} for changes.</strong>
                    @if ($latest['review_reason'])
                        <p>Admin’s note: {{ $latest['review_reason'] }}</p>
                    @endif
                    <p>Create a new draft version with the requested updates before resubmitting.</p>
                </div>
            @endif

            @if ($isPolicyManager && $latest)
                <details class="shipping-policy-details">
                    <summary>Version history ({{ count($policy['versions']) }})</summary>
                    <div class="table-wrap">
                        <table class="data-table shipping-policy-history-table">
                            <thead><tr><th>Version</th><th>Status</th><th>Author</th><th>Created</th><th>Published</th></tr></thead>
                            <tbody>
                                @foreach ($policy['versions'] as $version)
                                    <tr>
                                        <td>{{ $version['version'] }}</td>
                                        <td>{{ $version['status'] }}</td>
                                        <td>{{ $version['author'] }}</td>
                                        <td>{{ $version['created_at'] ?: '—' }}</td>
                                        <td>{{ $version['published_at'] ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif

            @if ($isPolicyManager && $policy['status_value'] !== 'archived' && ($latest['status_value'] ?? null) !== 'pending_approval')
                @if ($draftAvailable)
                    <details class="shipping-policy-draft-edit" @if ($draftEditFailed) open @endif>
                        <summary class="button button-secondary button-small">Edit draft {{ $latest['version'] }}</summary>
                        <form method="POST" action="{{ route('logistics.shipping-policies.versions.update', [$policy['id'], $latest['id']]) }}" class="shipping-policy-form shipping-policy-draft-edit-form">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="shipping_policy_form" value="edit_draft">
                            <input type="hidden" name="policy_id" value="{{ $policy['id'] }}">
                            <input type="hidden" name="version_id" value="{{ $latest['id'] }}">
                            <div class="shipping-policy-form-grid">
                                <label class="shipping-policy-field">
                                    <span>Policy title</span>
                                    <input name="title" value="{{ $draftEditFailed ? old('title') : $latest['title'] }}" maxlength="180" required>
                                </label>
                                <label class="shipping-policy-field">
                                    <span>Category</span>
                                    <select name="category" required>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category }}" @selected(($draftEditFailed ? old('category') : $latest['category']) === $category)>{{ $category }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="shipping-policy-field shipping-policy-field-wide">
                                    <span>Summary <small>Optional</small></span>
                                    <textarea name="summary" rows="2" maxlength="2000">{{ $draftEditFailed ? old('summary') : ($latest['summary'] ?? '') }}</textarea>
                                </label>
                                <label class="shipping-policy-field shipping-policy-field-wide">
                                    <span>Policy content</span>
                                    <textarea name="body" rows="7" maxlength="50000" required>{{ $draftEditFailed ? old('body') : $latest['body'] }}</textarea>
                                </label>
                                <label class="shipping-policy-field shipping-policy-field-wide">
                                    <span>Source or approval reference <small>Optional for drafts; required before Admin review</small></span>
                                    <input name="source_reference" value="{{ $draftEditFailed ? old('source_reference') : ($latest['source_reference'] ?? '') }}" maxlength="2000">
                                </label>
                                <label class="shipping-policy-field shipping-policy-field-wide">
                                    <span>Reason for editing this draft</span>
                                    <input name="reason" value="{{ $draftEditFailed ? old('reason') : '' }}" minlength="5" maxlength="1000" required>
                                </label>
                            </div>
                            <div class="shipping-policy-form-actions">
                                <button class="button button-secondary" type="submit">Save draft changes</button>
                            </div>
                        </form>
                    </details>

                    <div class="shipping-policy-draft-actions">
                        <p><strong>Draft {{ $latest['version'] }} is not visible to Sellers.</strong> Request Admin approval when its content and source are ready; the current published version stays in effect until approval.</p>
                        <form method="POST" action="{{ route('logistics.shipping-policies.submit-for-approval', $policy['id']) }}" class="shipping-policy-inline-form">
                            @csrf
                            <input type="hidden" name="shipping_policy_form" value="submit">
                            <input type="hidden" name="policy_id" value="{{ $policy['id'] }}">
                            <label class="shipping-policy-field">
                                <span>Source or approval reference</span>
                                <input name="source_reference" value="{{ old('shipping_policy_form') === 'submit' && (int) old('policy_id') === $policy['id'] ? old('source_reference') : $latest['source_reference'] }}" maxlength="2000" required>
                            </label>
                            <label class="shipping-policy-field">
                                <span>Reason for Admin review</span>
                                <input name="reason" value="{{ old('shipping_policy_form') === 'submit' && (int) old('policy_id') === $policy['id'] ? old('reason') : '' }}" minlength="5" maxlength="1000" required>
                            </label>
                            <button class="button button-primary button-small" type="submit">Submit {{ $latest['version'] }} for Admin review</button>
                        </form>
                    </div>
                @else
                    <details class="shipping-policy-details">
                        <summary>Create a new draft version</summary>
                        <form method="POST" action="{{ route('logistics.shipping-policies.update', $policy['id']) }}" class="shipping-policy-form">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="shipping_policy_form" value="update">
                            <input type="hidden" name="policy_id" value="{{ $policy['id'] }}">
                            <div class="shipping-policy-form-grid">
                                <label class="shipping-policy-field">
                                    <span>Policy title</span>
                                    <input name="title" value="{{ $newDraftFailed ? old('title') : ($latest['title'] ?? $policy['title']) }}" maxlength="180" required>
                                </label>
                                <label class="shipping-policy-field">
                                    <span>Category</span>
                                    <select name="category" required>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category }}" @selected(($newDraftFailed ? old('category') : ($latest['category'] ?? $policy['category'])) === $category)>{{ $category }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="shipping-policy-field">
                                    <span>New version label</span>
                                    <input name="version" value="{{ $newDraftFailed ? old('version') : $policy['next_version'] }}" maxlength="20" required>
                                </label>
                                <label class="shipping-policy-field shipping-policy-field-wide">
                                    <span>Summary <small>Optional</small></span>
                                    <textarea name="summary" rows="2" maxlength="2000">{{ $newDraftFailed ? old('summary') : ($latest['summary'] ?? '') }}</textarea>
                                </label>
                                <label class="shipping-policy-field shipping-policy-field-wide">
                                    <span>Policy content</span>
                                    <textarea name="body" rows="7" maxlength="50000" required>{{ $newDraftFailed ? old('body') : ($latest['body'] ?? '') }}</textarea>
                                </label>
                                <label class="shipping-policy-field shipping-policy-field-wide">
                                    <span>Source or approval reference <small>Optional for drafts; required before Admin review</small></span>
                                    <input name="source_reference" value="{{ $newDraftFailed ? old('source_reference') : ($latest['source_reference'] ?? '') }}" maxlength="2000">
                                </label>
                                <label class="shipping-policy-field shipping-policy-field-wide">
                                    <span>Change note <small>Optional; recorded in the audit history if provided</small></span>
                                    <input name="reason" value="{{ $newDraftFailed ? old('reason') : '' }}" maxlength="1000">
                                </label>
                            </div>
                            <div class="shipping-policy-form-actions">
                                <button class="button button-secondary" type="submit">Save new draft</button>
                            </div>
                        </form>
                    </details>
                @endif

                <form method="POST" action="{{ route('logistics.shipping-policies.archive', $policy['id']) }}" class="shipping-policy-archive-form" onsubmit="return confirm('Archive this policy? Its version history will be kept.')">
                    @csrf
                    <input type="hidden" name="shipping_policy_form" value="archive">
                    <input type="hidden" name="policy_id" value="{{ $policy['id'] }}">
                    <label class="shipping-policy-field">
                        <span>Reason for archiving</span>
                        <input name="reason" value="{{ $archiveFailed ? old('reason') : '' }}" minlength="5" maxlength="1000" required>
                    </label>
                    <button class="button button-danger-soft button-small" type="submit">Archive policy</button>
                </form>
            @endif
        </article>
    @empty
        <div class="shipping-policy-empty">
            <strong>No Bearly Logistics policies are published yet.</strong>
            <p>Do not treat this as confirmation that special-handling goods are allowed. Confirm restricted items, packaging, and product-combination rules with Bearly Logistics before shipping them.</p>
            @if ($isPolicyManager)
                <span>You can save a draft above while the official rules are being collected.</span>
            @endif
        </div>
    @endforelse
</section>
</div>
@endsection
