<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $announcement->title }} | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @include('partials.session-safety')
    @include('partials.realtime-identity')
    @vite(['resources/css/announcement.css', 'resources/js/realtime.js', 'resources/js/announcement.js'])
</head>
<body
    class="announcement-page"
    data-announcement-feed-url="{{ route('announcements.feed') }}"
    data-current-announcement-id="{{ $announcement->id }}"
    data-latest-published-at="{{ $publishedAt->toIso8601String() }}"
>
    <main class="announcement-shell">
        <header class="announcement-topbar">
            <a class="announcement-brand" href="{{ $dashboardUrl }}" aria-label="Bearly {{ $roleLabel }} dashboard">
                <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly Marketplace">
            </a>

            <a class="announcement-back" href="{{ $dashboardUrl }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.5 5-7 7 7 7M8 12h12" /></svg>
                Back to {{ $roleLabel }} dashboard
            </a>
        </header>

        <div class="announcement-live" data-announcement-live hidden role="status" aria-live="polite"></div>

        <article class="announcement-card" aria-labelledby="announcement-title">
            <div class="announcement-kicker">
                <span class="announcement-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 7v6m0 4h.01M10.3 3.9 2.7 17a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3l-7.6-13.1a2 2 0 0 0-3.4 0Z" /></svg>
                </span>
                <span class="announcement-kicker-label">Platform announcement</span>
            </div>

            <h1 id="announcement-title">{{ $announcement->title }}</h1>

            <div class="announcement-meta">
                <span class="announcement-meta-chip">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
                    <span>For {{ $audienceLabel }}</span>
                </span>
                <time class="announcement-meta-chip" datetime="{{ $publishedAt->toIso8601String() }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4m8-4v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Zm2 10h3m4 0h3m-10 4h3" /></svg>
                    <span>Published {{ $publishedAt->timezone('Asia/Manila')->format('F j, Y · g:i A') }}</span>
                </time>
                <span class="announcement-meta-chip announcement-number-chip">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Zm0 0v6h6M8 13h8m-8 4h8" /></svg>
                    <span>{{ $announcement->announcement_no }}</span>
                </time>
            </div>

            @if ($announcement->archive_reason === 'retention')
                <p class="announcement-retained-note">This post is archived from new announcements and kept here for your reference.</p>
            @endif

            <div class="announcement-message">{{ $announcement->body }}</div>

            @if ($announcement->media->isNotEmpty())
                <div class="announcement-media-grid" aria-label="Announcement attachments">
                    @foreach ($announcement->media as $media)
                        @php($mediaUrl = route('announcements.media', [$announcement, $media]))
                        <figure class="announcement-media-item">
                            @if ($media->media_type === 'image')
                                <img src="{{ $mediaUrl }}" alt="{{ $announcement->title }} image {{ $loop->iteration }}" loading="lazy">
                            @else
                                <video controls playsinline preload="metadata" aria-label="Video attached to {{ $announcement->title }}">
                                    <source src="{{ $mediaUrl }}" type="{{ $media->mime_type }}">
                                    Your browser cannot play this video.
                                </video>
                            @endif
                            <figcaption>{{ $media->original_name }}</figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif

            <footer class="announcement-footer">
                <div class="announcement-footer-signoff">
                    <span class="announcement-footer-mark"><img src="{{ asset('images/bearly-logo-2.png') }}" alt=""></span>
                    <span>Bearly {{ $roleLabel }} Center</span>
                </div>
                <a class="announcement-return-button" href="{{ $dashboardUrl }}">
                    Return to dashboard
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
                </a>
            </footer>
        </article>

        <section class="announcement-history" aria-labelledby="announcement-history-title">
            <header class="announcement-history-heading">
                <span>Keep up with Bearly</span>
                <h2 id="announcement-history-title">Previous announcements</h2>
                <p>Scroll through earlier full posts, including their attached photos and videos.</p>
            </header>

            <div class="announcement-history-list" data-announcement-history-list>
                @forelse ($pastAnnouncements as $pastAnnouncement)
                    <article
                        class="announcement-history-post"
                        data-announcement-id="{{ $pastAnnouncement->id }}"
                    >
                        <header class="announcement-history-post-header">
                            <span class="announcement-history-avatar" aria-hidden="true">
                                <img src="{{ asset('images/bearly-logo-2.png') }}" alt="">
                            </span>
                            <div class="announcement-history-author">
                                <strong>{{ $pastAnnouncement->creator?->name ?? 'Bearly Admin' }}</strong>
                                <time datetime="{{ ($pastAnnouncement->published_at ?? $pastAnnouncement->publish_at)->toIso8601String() }}">
                                    Published {{ ($pastAnnouncement->published_at ?? $pastAnnouncement->publish_at)->timezone('Asia/Manila')->format('F j, Y · g:i A') }}
                                </time>
                            </div>
                            <span class="announcement-history-role">
                                {{ $pastAnnouncement->roles->count() === 4 ? 'All Users' : $pastAnnouncement->roles->pluck('display_name')->implode(', ') }}
                            </span>
                        </header>

                        <div class="announcement-history-post-content">
                            <div class="announcement-history-number">{{ $pastAnnouncement->announcement_no }}</div>
                            <h3>{{ $pastAnnouncement->title }}</h3>
                            <div class="announcement-history-message">{{ $pastAnnouncement->body }}</div>

                            @if ($pastAnnouncement->media->isNotEmpty())
                                <div class="announcement-history-media-grid" aria-label="Media attached to {{ $pastAnnouncement->title }}">
                                    @foreach ($pastAnnouncement->media as $pastMedia)
                                        <figure class="announcement-history-media-item">
                                            @if ($pastMedia->media_type === 'image')
                                                <img src="{{ route('announcements.media', [$pastAnnouncement, $pastMedia]) }}" alt="{{ $pastAnnouncement->title }} image {{ $loop->iteration }}" loading="lazy">
                                            @else
                                                <video controls preload="none" playsinline aria-label="Video attached to {{ $pastAnnouncement->title }}">
                                                    <source src="{{ route('announcements.media', [$pastAnnouncement, $pastMedia]) }}" type="{{ $pastMedia->mime_type }}">
                                                    Your browser cannot play this video.
                                                </video>
                                            @endif
                                            <figcaption>{{ $pastMedia->original_name }}</figcaption>
                                        </figure>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <footer class="announcement-history-post-footer">
                            <span>{{ $pastAnnouncement->archive_reason === 'retention' ? 'Archived · retained for reference' : 'Platform announcement' }}</span>
                            <a href="{{ route('announcements.show', $pastAnnouncement) }}" aria-label="Open full page for {{ $pastAnnouncement->title }}">
                                Open post
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
                            </a>
                        </footer>
                    </article>
                @empty
                    <div class="announcement-history-empty" data-announcement-history-empty>
                        <span class="announcement-history-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </span>
                        <strong>No earlier announcements</strong>
                        <span>Older updates for your account will appear here.</span>
                    </div>
                @endforelse
            </div>

            @if ($pastAnnouncements->hasPages())
                <nav class="announcement-history-pagination" aria-label="Previous announcement pages">
                    {{ $pastAnnouncements->links() }}
                </nav>
            @endif
        </section>
    </main>
</body>
</html>
