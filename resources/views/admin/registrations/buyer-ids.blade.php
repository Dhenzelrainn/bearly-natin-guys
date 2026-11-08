@extends('layouts.admin')

@section('title', 'Buyer ID uploads')
@section('page-title', 'Buyer ID uploads')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Registration Documents</span>
        <h1>Buyer ID uploads</h1>
        <p>View optional IDs submitted by Buyers. This list is separate from the approval queue and does not change Buyer account activation.</p>
    </div>

    <div class="hero-context-stat">
        <span class="hero-context-icon">
            <i data-lucide="id-card"></i>
        </span>
        <div>
            <strong>{{ $buyers->total() }}</strong>
            <span>Submitted Buyer IDs</span>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Optional documents</span>
            <h2>Submitted IDs</h2>
        </div>

        <form class="table-toolbar" method="GET" action="{{ route('admin.registrations.buyer-ids') }}">
            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>
                <input
                    type="search"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Search Buyer..."
                    aria-label="Search Buyer ID uploads"
                >
            </label>
            <button class="button button-primary button-small" type="submit">Search</button>
        </form>
    </div>

    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Buyer</th>
                    <th>Account status</th>
                    <th>Registered</th>
                    <th class="align-right">Document</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($buyers as $buyer)
                    <tr>
                        <td>
                            <div class="identity-cell">
                                <span class="avatar avatar-soft">
                                    {{ collect(explode(' ', $buyer->name ?: 'Buyer'))
                                        ->filter()
                                        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
                                        ->take(2)
                                        ->implode('') }}
                                </span>
                                <div>
                                    <strong>{{ $buyer->name ?: trim(($buyer->first_name ?? '').' '.($buyer->last_name ?? '')) }}</strong>
                                    <small>{{ $buyer->email }}</small>
                                </div>
                            </div>
                        </td>
                        <td>{{ str($buyer->status ?: 'unknown')->replace('_', ' ')->title() }}</td>
                        <td>{{ $buyer->created_at?->format('M j, Y') ?? '—' }}</td>
                        <td class="align-right">
                            <a
                                class="button button-ghost button-small"
                                href="{{ route('admin.applications.document', ['user' => $buyer, 'type' => 'valid-id']) }}"
                                target="_blank"
                                rel="noopener"
                            >
                                <i data-lucide="file-search"></i>
                                View ID
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <div class="table-empty">
                                <i data-lucide="inbox"></i>
                                <strong>{{ $search !== '' ? 'No Buyer IDs found' : 'No Buyer IDs submitted' }}</strong>
                                <span>{{ $search !== '' ? 'Try a different name or email.' : 'Optional Buyer IDs will appear here when submitted.' }}</span>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($buyers->hasPages())
        <div class="table-pagination">
            {{ $buyers->links() }}
        </div>
    @endif
</section>
@endsection
