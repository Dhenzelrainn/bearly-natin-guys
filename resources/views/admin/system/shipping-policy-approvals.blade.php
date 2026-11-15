@extends('layouts.admin')

@section('title', 'Shipping Policy Approvals')
@section('page-title', 'Shipping Policy Approvals')

@section('content')
    @if (session('success'))
        <div class="panel" role="status">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="panel admin-shipping-policy-errors" role="alert">
            <strong>Please review the policy decision details.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="page-hero">
        <div>
            <span class="eyebrow">System Management · Bearly Logistics</span>
            <h1>Shipping and Rider policy reviews</h1>
            <p>Review the submitted policy and its cited source. Approval publishes this version to Sellers and Logistics accounts.</p>
        </div>
        <div class="hero-context-card policy-hero-stat">
            <span class="hero-context-icon"><i data-lucide="clipboard-check"></i></span>
            <div class="hero-context-copy" aria-live="polite" aria-atomic="true">
                <small>Awaiting review</small>
                <strong data-shipping-policy-pending-count>{{ count($pendingPolicies) }}</strong>
                <span>Bearly Logistics submissions</span>
            </div>
        </div>
    </section>

    <div
        data-shipping-policy-approval-queue
        data-updates-url="{{ route('admin.shipping-policy-approvals.updates') }}"
        data-poll-interval="15000"
    >
        @forelse ($pendingPolicies as $submission)
            @include('admin.system.partials.shipping-policy-submission', ['submission' => $submission, 'preserveOldInput' => true])
        @empty
            @include('admin.system.partials.shipping-policy-empty')
        @endforelse
    </div>

    <section class="panel admin-shipping-policy-history" aria-labelledby="shipping-policy-approval-history-title">
        <div class="panel-heading panel-heading-wrap">
            <div>
                <span class="eyebrow">Recorded decisions</span>
                <h2 id="shipping-policy-approval-history-title">Approval history</h2>
                <p>Approved versions stay listed here even after a newer version is published.</p>
            </div>
            <span class="status-badge badge-success">{{ number_format($approvalHistory->total()) }} recorded</span>
        </div>

        @if ($approvalHistory->total() > 0)
            <div class="table-wrap">
                <table class="admin-table" aria-label="Approved Bearly Logistics policies">
                    <thead>
                        <tr>
                            <th>Policy and version</th>
                            <th>Approved by</th>
                            <th>Source reviewed</th>
                            <th>Admin reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($approvalHistory as $approval)
                            <tr>
                                <td>
                                    <strong>{{ $approval['title'] }}</strong>
                                    <span class="table-subline">{{ $approval['code'] }} · {{ $approval['version'] }} · {{ $approval['category'] }}</span>
                                    @if ($approval['body'])
                                        <details class="admin-shipping-policy-history-details">
                                            <summary>View approved policy text</summary>
                                            <div>{{ $approval['body'] }}</div>
                                        </details>
                                    @endif
                                </td>
                                <td>
                                    {{ $approval['approved_by'] }}
                                    <span class="table-subline">{{ $approval['approved_at'] }}</span>
                                </td>
                                <td>
                                    {{ $approval['source_reference'] }}
                                    <span class="table-subline">{{ $approval['source_reviewed'] ? 'Reviewed before publication' : 'Review confirmation not recorded' }}</span>
                                </td>
                                <td>{{ $approval['reason'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">There are no approvals on this history page.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($approvalHistory->hasPages())
                <div class="panel-footer">{{ $approvalHistory->links() }}</div>
            @endif
        @else
            <div class="table-empty">
                <i data-lucide="scroll-text" aria-hidden="true"></i>
                <strong>No approved policies recorded yet</strong>
                <span>Policies will remain in this history after an Admin approves and publishes them.</span>
            </div>
        @endif
    </section>
@endsection
