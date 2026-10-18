<?php

namespace App\Http\Controllers;

use App\Services\NotificationCategoryService;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
        private readonly NotificationCategoryService $categories,
    ) {}

    /**
     * Authenticated notification history for the current user only.
     * Unread counts derive from read_at on that user's own rows. Optional
     * `category` and `search` filters and pagination metadata power the
     * full-page centers while keeping the popover contract unchanged.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = (string) $user->role;

        $perPage = max(1, min((int) $request->integer('per_page', 15), 50));
        $category = $request->query('category');
        $category = is_string($category) ? $category : null;
        $search = trim((string) $request->query('search', ''));

        $query = $user->notifications()->latest();

        if ($this->categories->isUnreadOnly($role, $category)) {
            $query->whereNull('read_at');
        } elseif (($events = $this->categories->eventsFor($role, $category)) !== null) {
            $query->whereIn('data->event', $events);
        }

        if ($search !== '') {
            $term = '%'.addcslashes(mb_substr($search, 0, 100), '%_\\').'%';

            $query->where(function ($nested) use ($term): void {
                $nested->where('data->title', 'like', $term)
                    ->orWhere('data->body', 'like', $term)
                    ->orWhere('data->message', 'like', $term);
            });
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => [
                'items' => collect($page->items())
                    ->map(fn (DatabaseNotification $notification) => $this->payload($notification, $role))
                    ->all(),
                'unread_count' => $user->unreadNotifications()->count(),
                'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'message' => 'Notifications loaded.',
        ]);
    }

    /**
     * Full-page Notification Center. The role comes from the authenticated
     * user only; every role renders its own layout and category set.
     */
    public function center(Request $request): View
    {
        $role = (string) $request->user()->role;

        abort_unless($this->categories->supports($role), 403);

        return view("notifications.center.{$role}", [
            'tabs' => $this->categories->tabsFor($role),
            'perPage' => 20,
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $user = $request->user();

        $record = $user->notifications()->whereKey($notification)->firstOrFail();

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        return response()->json([
            'data' => [
                'unread_count' => $user->unreadNotifications()->count(),
            ],
            'message' => 'Notification marked as read.',
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->unreadNotifications->markAsRead();

        return response()->json([
            'data' => ['unread_count' => 0],
            'message' => 'All notifications marked as read.',
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['events' => array_values($this->preferences->forUser($request->user()))],
            'message' => 'Notification preferences loaded.',
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['required', 'array'],
            'preferences.*.in_app' => ['required', 'boolean'],
            'preferences.*.email' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        $this->preferences->updateForUser($user, $validated['preferences']);

        return response()->json([
            'data' => ['events' => array_values($this->preferences->forUser($user))],
            'message' => 'Notification preferences saved.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DatabaseNotification $notification, string $role): array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        $event = isset($data['event']) ? (string) $data['event'] : null;

        return [
            'id' => (string) $notification->id,
            'event' => $event,
            'category' => $this->categories->categoryForEvent($role, $event),
            'title' => (string) ($data['title'] ?? $data['message'] ?? 'Bearly notification'),
            'body' => (string) ($data['body'] ?? $data['message'] ?? ''),
            'url' => isset($data['url']) && $data['url'] !== '' ? (string) $data['url'] : null,
            'type' => (string) ($data['type'] ?? $this->displayTypeFromStatus($data['status'] ?? null) ?? 'info'),
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
            'created_at_human' => $notification->created_at?->diffForHumans(),
        ];
    }

    /**
     * Older notification payloads stored a machine status instead of a display
     * type. Mirror the historical Seller popover mapping so those rows keep
     * their colour instead of falling back to a neutral dot.
     */
    private function displayTypeFromStatus(?string $status): ?string
    {
        $status = strtolower(trim((string) $status));

        if ($status === '') {
            return null;
        }

        return match (true) {
            in_array($status, ['completed', 'approved', 'paid', 'success', 'verified'], true) => 'success',
            in_array($status, ['rejected', 'failed', 'danger', 'suspended'], true) => 'danger',
            default => 'warning',
        };
    }
}
