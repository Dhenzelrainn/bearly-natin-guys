@extends('layouts.admin')

@section('title', 'Announcements')
@section('page-title', 'Announcements')

@section('content')
    <section class="page-hero announcement-page-hero">
        <div>
            <span class="eyebrow">Communication</span>
            <h1>Platform announcements</h1>
            <p>Share updates with Buyers, Sellers, Logistics Centers, Riders, or all active users.</p>
        </div>

        <div class="hero-context-stat">
            <span class="hero-context-icon"><i data-lucide="megaphone"></i></span>
            <div>
                <strong>{{ $announcements->total() }}</strong>
                <span>Announcement posts</span>
            </div>
        </div>
    </section>

    @if (session('success'))
        <div class="announcement-feedback" role="status">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="announcement-feedback is-error" role="alert">
            <strong>We couldn't save this announcement.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $selectedAudienceLabel = $roles->firstWhere('name', $filters['audience'])?->display_name ?? 'All audiences';
        $selectedStatusLabel = $filters['status'] !== '' ? ucfirst($filters['status']) : 'All statuses';
    @endphp

    <section class="panel announcement-feed-panel">
        <div class="panel-heading announcement-feed-heading">
            <div>
                <span class="eyebrow">Announcement feed</span>
                <h2>Posts from Bearly Admin</h2>
            </div>

            <div class="announcement-feed-tools">
                <form method="GET" action="{{ route('admin.announcements') }}" class="announcement-filter-form">
                    <label class="field-with-icon compact-field">
                        <i data-lucide="search" aria-hidden="true"></i>
                        <span class="sr-only">Search announcements</span>
                        <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search announcements">
                    </label>

                    <details class="announcement-filter-menu">
                        <summary class="announcement-filter-trigger">
                            <i data-lucide="users-round" aria-hidden="true"></i>
                            <span>Filter by audience</span>
                            <strong>{{ $selectedAudienceLabel }}</strong>
                            <i class="announcement-filter-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
                        </summary>
                        <div class="announcement-filter-popover">
                            <label>
                                <span>Choose audience</span>
                                <select class="select-field" name="audience">
                                    <option value="">All audiences</option>
                                    @foreach ($roles as $roleOption)
                                        <option value="{{ $roleOption->name }}" @selected($filters['audience'] === $roleOption->name)>
                                            {{ $roleOption->display_name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </details>

                    <details class="announcement-filter-menu">
                        <summary class="announcement-filter-trigger">
                            <i data-lucide="list-filter" aria-hidden="true"></i>
                            <span>Filter by status</span>
                            <strong>{{ $selectedStatusLabel }}</strong>
                            <i class="announcement-filter-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
                        </summary>
                        <div class="announcement-filter-popover">
                            <label>
                                <span>Choose status</span>
                                <select class="select-field" name="status">
                                    <option value="">All statuses</option>
                                    @foreach (['published', 'scheduled', 'draft', 'archived'] as $statusOption)
                                        <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>
                                            {{ ucfirst($statusOption) }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </details>

                    <button class="button button-secondary button-small" type="submit">Apply filters</button>
                </form>

                <button type="button" class="button button-primary" data-open-modal="create-announcement">
                    <i data-lucide="plus" aria-hidden="true"></i>
                    New post
                </button>
            </div>
        </div>

        <div class="announcement-feed">
            @forelse ($announcements as $announcement)
                @php
                    $roleNames = $announcement->roles->pluck('name')->all();
                    $audienceLabel = count($roleNames) === 4
                        ? 'All Users'
                        : $announcement->roles->pluck('display_name')->implode(', ');
                    $postTime = $announcement->published_at ?? $announcement->publish_at ?? $announcement->created_at;
                    $statusTone = match ($announcement->status) {
                        'published' => 'is-published',
                        'scheduled' => 'is-scheduled',
                        'archived' => 'is-archived',
                        default => 'is-draft',
                    };
                    $statusLabel = $announcement->status === 'archived' && $announcement->archive_reason === 'retention'
                        ? 'Archived · retained'
                        : ucfirst($announcement->status);
                @endphp

                <article class="announcement-post">
                    <header class="announcement-post-header">
                        <span class="announcement-post-avatar" aria-hidden="true">
                            <i data-lucide="megaphone"></i>
                        </span>
                        <div class="announcement-post-author">
                            <strong>{{ $announcement->creator?->name ?? 'Bearly Admin' }}</strong>
                            <span>{{ $postTime?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Date unavailable' }}</span>
                        </div>
                        <span class="announcement-status {{ $statusTone }}">{{ $statusLabel }}</span>
                    </header>

                    <div class="announcement-post-content">
                        <div class="announcement-post-audience">
                            <i data-lucide="users-round" aria-hidden="true"></i>
                            <span>{{ $audienceLabel ?: 'No audience selected' }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $announcement->announcement_no }}</span>
                        </div>
                        <h2>{{ $announcement->title }}</h2>
                        <div class="announcement-post-message">{{ $announcement->body }}</div>

                        @if ($announcement->media->isNotEmpty())
                            <div class="announcement-post-media" aria-label="Announcement media">
                                @foreach ($announcement->media as $media)
                                    @php
                                        $mediaUrl = route('admin.announcements.media', [$announcement, $media]);
                                    @endphp
                                    <figure class="announcement-post-media-item">
                                        @if ($media->media_type === 'image')
                                            <img src="{{ $mediaUrl }}" alt="{{ $announcement->title }} image {{ $loop->iteration }}" loading="lazy">
                                        @else
                                            <video controls preload="metadata" aria-label="Video attached to {{ $announcement->title }}">
                                                <source src="{{ $mediaUrl }}" type="{{ $media->mime_type }}">
                                                Your browser cannot play this video.
                                            </video>
                                        @endif
                                        <figcaption>{{ $media->original_name }}</figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <footer class="announcement-post-footer">
                        <span><i data-lucide="bell-ring" aria-hidden="true"></i> In-app delivery to {{ $audienceLabel ?: 'selected audience' }}</span>
                        <button type="button" class="button button-ghost button-small" data-open-modal="announcement-{{ $announcement->id }}">
                            <i data-lucide="pencil" aria-hidden="true"></i>
                            Edit post
                        </button>
                    </footer>
                </article>
            @empty
                <div class="announcement-feed-empty">
                    <span class="announcement-post-avatar" aria-hidden="true"><i data-lucide="megaphone"></i></span>
                    <strong>No announcement posts yet</strong>
                    <span>Create a post to notify its selected active audience.</span>
                </div>
            @endforelse
        </div>

        @if ($announcements->hasPages())
            <div class="panel-footer">{{ $announcements->links() }}</div>
        @endif
    </section>

    @foreach ($announcements as $announcement)
        @php
            $roleNames = $announcement->roles->pluck('name')->all();
            $audienceLabel = count($roleNames) === 4
                ? 'All Users'
                : $announcement->roles->pluck('display_name')->implode(', ');
            $editModalOpen = $errors->any() && (int) old('_announcement_id') === (int) $announcement->id;
        @endphp

        <div class="modal-shell announcement-modal" data-modal="announcement-{{ $announcement->id }}" @if (! $editModalOpen) hidden @endif>
            <button type="button" class="modal-backdrop" data-close-modal aria-label="Close announcement editor"></button>
            <section class="modal-card modal-wide announcement-composer" role="dialog" aria-modal="true" aria-labelledby="announcement-edit-title-{{ $announcement->id }}">
                <div class="modal-heading">
                    <div>
                        <span class="eyebrow">Edit announcement post</span>
                        <h2 id="announcement-edit-title-{{ $announcement->id }}">{{ $announcement->announcement_no }}</h2>
                    </div>
                    <button type="button" class="icon-button" data-close-modal aria-label="Close"><i data-lucide="x"></i></button>
                </div>

                <form method="POST" action="{{ route('admin.announcements.update', $announcement) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_announcement_id" value="{{ $announcement->id }}">

                    <div class="announcement-composer-author">
                        <span class="announcement-post-avatar" aria-hidden="true"><i data-lucide="megaphone"></i></span>
                        <span><strong>{{ $admin['name'] ?? 'Bearly Admin' }}</strong><small>Editing a platform post</small></span>
                    </div>
                    @if ($announcement->archive_reason === 'retention')
                        <p class="announcement-retention-note"><i data-lucide="archive" aria-hidden="true"></i><span>This post was archived automatically to keep the latest 10 published posts. Its original audience can still read it.</span></p>
                    @endif

                    <div class="announcement-composer-fields">
                        <label class="form-field">
                            <span>Post title</span>
                            <input class="text-field" name="title" value="{{ old('title', $announcement->title) }}" maxlength="180" required>
                        </label>

                        <div class="announcement-composer-grid">
                            @php($selectedRoleNames = old('audience_roles', $roleNames))
                            <fieldset class="announcement-audience-field">
                                <legend>Audience <small>Choose at least one role. Selecting all four reaches all users.</small></legend>
                                <div class="announcement-audience-options">
                                    @foreach ($roles as $roleOption)
                                        <label><input type="checkbox" name="audience_roles[]" value="{{ $roleOption->name }}" @checked(in_array($roleOption->name, $selectedRoleNames, true))><span>{{ $roleOption->display_name }}</span></label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <label class="form-field">
                                <span>Status</span>
                                <select class="select-field" name="status" required>
                                    @foreach (['draft', 'scheduled', 'published', 'archived'] as $statusOption)
                                        <option value="{{ $statusOption }}" @selected(old('status', $announcement->status) === $statusOption)>{{ ucfirst($statusOption) }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="form-field">
                                <span>Publish date and time <small>Philippine time</small></span>
                                <input class="text-field" type="datetime-local" name="publish_at" value="{{ old('publish_at', $announcement->publish_at?->timezone('Asia/Manila')->format('Y-m-d\\TH:i')) }}">
                            </label>

                            <p class="announcement-retention-note"><i data-lucide="archive" aria-hidden="true"></i><span>No expiration is required. When more than 10 posts are published, the oldest move to retained history and remain readable to their selected audience.</span></p>
                        </div>

                        <label class="form-field">
                            <span>Announcement message</span>
                            <textarea class="text-field announcement-message-input" name="body" rows="5" maxlength="10000" required>{{ old('body', $announcement->body) }}</textarea>
                        </label>

                        <fieldset class="announcement-existing-media">
                            <legend>Current media <span>Check files to remove them when saving</span></legend>
                            <div class="announcement-media-grid">
                                @forelse ($announcement->media as $media)
                                    <div class="announcement-existing-media-item">
                                        @if ($media->media_type === 'image')
                                            <img src="{{ route('admin.announcements.media', [$announcement, $media]) }}" alt="{{ $media->original_name }}" loading="lazy">
                                        @else
                                            <video controls preload="metadata" aria-label="{{ $media->original_name }}">
                                                <source src="{{ route('admin.announcements.media', [$announcement, $media]) }}" type="{{ $media->mime_type }}">
                                            </video>
                                        @endif
                                        <span class="announcement-media-caption">{{ $media->original_name }}</span>
                                        <label class="announcement-remove-choice"><input type="checkbox" name="remove_media_ids[]" value="{{ $media->id }}" @checked(in_array($media->id, old('remove_media_ids', [])))> Remove file</label>
                                    </div>
                                @empty
                                    <p class="announcement-no-media">No media attached to this post yet.</p>
                                @endforelse
                            </div>
                        </fieldset>

                        @include('admin.communication.partials.announcement-media-picker', [
                            'pickerId' => 'edit-'.$announcement->id,
                            'existingCount' => $announcement->media->count(),
                        ])
                    </div>

                    <div class="modal-footer decision-footer">
                        <button type="submit" class="button button-primary"><i data-lucide="save"></i> Save post</button>
                        <button type="button" class="button button-ghost" data-close-modal>Cancel</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}" onsubmit="return confirm('Delete this announcement and its attached media?')">
                    @csrf
                    @method('DELETE')
                    <div class="announcement-delete-row"><button class="button button-danger-soft" type="submit"><i data-lucide="trash-2"></i> Delete post</button></div>
                </form>
            </section>
        </div>
    @endforeach

    @php($createModalOpen = $errors->any() && old('_announcement_form') === 'create')
    <div class="modal-shell announcement-modal" data-modal="create-announcement" @if (! $createModalOpen) hidden @endif>
        <button type="button" class="modal-backdrop" data-close-modal aria-label="Close announcement composer"></button>
        <section class="modal-card modal-wide announcement-composer" role="dialog" aria-modal="true" aria-labelledby="announcement-create-title">
            <div class="modal-heading">
                <div>
                    <span class="eyebrow">Communication</span>
                    <h2 id="announcement-create-title">Create an announcement post</h2>
                </div>
                <button type="button" class="icon-button" data-close-modal aria-label="Close"><i data-lucide="x"></i></button>
            </div>

            <form method="POST" action="{{ route('admin.announcements.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="_announcement_form" value="create">

                <div class="announcement-composer-author">
                    <span class="announcement-post-avatar" aria-hidden="true"><i data-lucide="megaphone"></i></span>
                    <span><strong>{{ $admin['name'] ?? 'Bearly Admin' }}</strong><small>Posting to the selected audience</small></span>
                </div>

                <div class="announcement-composer-fields">
                    <label class="form-field">
                        <span>Post title</span>
                        <input class="text-field" name="title" value="{{ old('title') }}" maxlength="180" placeholder="What should people know?" required>
                    </label>

                    <div class="announcement-composer-grid">
                        @php($selectedRoleNames = old('audience_roles', ['buyer', 'seller', 'logistics', 'rider']))
                        <fieldset class="announcement-audience-field">
                            <legend>Audience <small>Choose at least one role. All four roles means all users.</small></legend>
                            <div class="announcement-audience-options">
                                @foreach ($roles as $roleOption)
                                    <label><input type="checkbox" name="audience_roles[]" value="{{ $roleOption->name }}" @checked(in_array($roleOption->name, $selectedRoleNames, true))><span>{{ $roleOption->display_name }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>

                        <label class="form-field">
                            <span>Publish status</span>
                            <select class="select-field" name="status" required>
                                <option value="draft" @selected(old('status', 'draft') === 'draft')>Save as draft</option>
                                <option value="scheduled" @selected(old('status') === 'scheduled')>Schedule</option>
                                <option value="published" @selected(old('status') === 'published')>Publish now</option>
                            </select>
                        </label>

                        <label class="form-field">
                            <span>Publish date and time <small>Philippine time</small></span>
                            <input class="text-field" type="datetime-local" name="publish_at" value="{{ old('publish_at') }}">
                        </label>

                        <p class="announcement-retention-note"><i data-lucide="archive" aria-hidden="true"></i><span>No expiration is required. When more than 10 posts are published, the oldest move to retained history and remain readable to their selected audience.</span></p>
                    </div>

                    <label class="form-field">
                        <span>Message</span>
                        <textarea class="text-field announcement-message-input" name="body" rows="5" maxlength="10000" placeholder="Write your announcement..." required>{{ old('body') }}</textarea>
                    </label>

                    @include('admin.communication.partials.announcement-media-picker', [
                        'pickerId' => 'create',
                        'existingCount' => 0,
                    ])
                </div>

                <div class="announcement-delivery-note">
                    <i data-lucide="bell-ring" aria-hidden="true"></i>
                    <span>Publishing sends an in-app notification to active accounts in the selected audience. Email is off by default.</span>
                </div>

                <div class="modal-footer decision-footer">
                    <button type="submit" class="button button-primary"><i data-lucide="send"></i> Save announcement</button>
                    <button type="button" class="button button-ghost" data-close-modal>Cancel</button>
                </div>
            </form>
        </section>
    </div>
@endsection
