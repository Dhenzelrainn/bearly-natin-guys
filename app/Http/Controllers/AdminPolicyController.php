<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\PolicyVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminPolicyController extends Controller
{
    private const CATEGORIES = [
        'Marketplace',
        'Seller Compliance',
        'Transactions',
        'Account',
    ];

    public function index(Request $request): View
    {
        $policies = Policy::query()
            ->with([
                'latestVersion.creator',
                'versions' => fn ($query) => $query->with('creator')->orderByDesc('id'),
            ])
            ->orderBy('title')
            ->get()
            ->map(fn (Policy $policy): array => $this->policyPayload($policy))
            ->all();

        return view('admin.system.policies', [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'policies' => $policies,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'version' => ['required', 'string', 'max:20'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:50000'],
            'action' => ['required', Rule::in(['draft', 'publish'])],
        ]);

        $publish = $validated['action'] === 'publish';

        $policy = DB::transaction(function () use ($request, $validated, $publish): Policy {
            $policy = Policy::query()->create([
                'code' => $this->newPolicyCode(),
                'title' => $validated['title'],
                'category' => $validated['category'],
                'status' => $publish ? 'active' : 'draft',
            ]);

            $version = $policy->versions()->create([
                'version' => $validated['version'],
                'body' => $validated['body'],
                'summary' => $validated['summary'] ?? null,
                'status' => $publish ? 'published' : 'draft',
                'created_by' => $request->user()->id,
                'published_at' => $publish ? now() : null,
                'effective_at' => $publish ? now() : null,
            ]);

            $this->audit($request, $policy, 'policy.created', null, [
                'policy' => $policy->only(['code', 'title', 'category', 'status']),
                'version' => $version->only(['version', 'status', 'published_at']),
            ]);

            return $policy;
        }, 3);

        return back()->with('success', $publish
            ? "Policy {$policy->code} was created and version {$validated['version']} was published."
            : "Policy {$policy->code} was saved as a draft.");
    }

    public function update(Request $request, Policy $policy): RedirectResponse
    {
        if ($policy->status === 'archived') {
            return back()->with('warning', 'Archived policies cannot be edited.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'version' => ['required', 'string', 'max:20'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:50000'],
        ]);

        if ($policy->versions()->where('version', $validated['version'])->exists()) {
            throw ValidationException::withMessages([
                'version' => 'This version label already exists for this policy. Choose a new label so previous versions stay intact.',
            ]);
        }

        DB::transaction(function () use ($request, $policy, $validated): void {
            $before = $policy->only(['title', 'category', 'status']);

            $policy->update([
                'title' => $validated['title'],
                'category' => $validated['category'],
            ]);

            $version = $policy->versions()->create([
                'version' => $validated['version'],
                'body' => $validated['body'],
                'summary' => $validated['summary'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->audit($request, $policy, 'policy.updated', $before, [
                'policy' => $policy->fresh()?->only(['code', 'title', 'category', 'status']),
                'version' => $version->only(['version', 'status']),
            ]);
        }, 3);

        return back()->with('success', "Policy {$policy->code} draft {$validated['version']} was saved. Publish it when it is ready.");
    }

    public function publish(Request $request, Policy $policy): RedirectResponse
    {
        if ($policy->status === 'archived') {
            return back()->with('warning', 'Archived policies cannot be published.');
        }

        $latest = $policy->versions()->orderByDesc('id')->first();

        if ($latest === null) {
            return back()->with('warning', 'This policy has no version to publish.');
        }

        if ($latest->status !== 'draft') {
            return back()->with('warning', "The latest version ({$latest->version}) is already published.");
        }

        DB::transaction(function () use ($request, $policy, $latest): void {
            $before = $latest->only(['version', 'status', 'published_at']);

            $previousPublished = $policy->versions()
                ->whereKeyNot($latest->id)
                ->where('status', 'published')
                ->pluck('version')
                ->all();

            $policy->versions()
                ->whereKeyNot($latest->id)
                ->where('status', 'published')
                ->update(['status' => 'archived']);

            $latest->update([
                'status' => 'published',
                'published_at' => now(),
                'effective_at' => now(),
            ]);

            $policy->update(['status' => 'active']);

            $this->audit($request, $policy, 'policy.published', $before, [
                'version' => $latest->fresh()?->only(['version', 'status', 'published_at']),
                'superseded_versions' => $previousPublished,
            ]);
        }, 3);

        return back()->with('success', "Policy {$policy->code} version {$latest->version} is now published.");
    }

    public function archive(Request $request, Policy $policy): RedirectResponse
    {
        if ($policy->status === 'archived') {
            return back()->with('warning', 'This policy is already archived.');
        }

        DB::transaction(function () use ($request, $policy): void {
            $before = $policy->only(['code', 'status']);

            $policy->update(['status' => 'archived']);

            $this->audit($request, $policy, 'policy.archived', $before, [
                'policy' => $policy->fresh()?->only(['code', 'status']),
            ]);
        }, 3);

        return back()->with('success', "Policy {$policy->code} was archived. Existing versions are preserved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function policyPayload(Policy $policy): array
    {
        $latest = $policy->latestVersion;

        return [
            'id' => (int) $policy->id,
            'code' => $policy->code,
            'title' => $policy->title,
            'category' => $policy->category,
            'status_value' => $policy->status,
            'status' => $this->statusLabel($policy->status),
            'latest' => $latest === null ? null : [
                'version' => $latest->version,
                'status_value' => $latest->status,
                'status' => $this->statusLabel($latest->status),
                'summary' => $latest->summary,
                'body' => $latest->body,
                'author' => $latest->creator?->name ?? 'System',
                'updated_at' => $latest->created_at?->format('M j, Y g:i A'),
                'published_at' => $latest->published_at?->format('M j, Y g:i A'),
            ],
            'next_version' => $this->nextVersionLabel($latest?->version),
            'versions' => $policy->versions
                ->map(fn (PolicyVersion $version): array => [
                    'version' => $version->version,
                    'status_value' => $version->status,
                    'status' => $this->statusLabel($version->status),
                    'summary' => $version->summary,
                    'author' => $version->creator?->name ?? 'System',
                    'created_at' => $version->created_at?->format('M j, Y g:i A'),
                    'published_at' => $version->published_at?->format('M j, Y g:i A'),
                ])
                ->values()
                ->all(),
        ];
    }

    private function nextVersionLabel(?string $current): string
    {
        if ($current === null) {
            return 'v1.0';
        }

        if (preg_match('/^v?(\d+)(?:\.(\d+))?$/', $current, $matches) === 1) {
            $minor = isset($matches[2]) ? ((int) $matches[2]) + 1 : 1;

            return "v{$matches[1]}.{$minor}";
        }

        return '';
    }

    private function newPolicyCode(): string
    {
        return 'POL-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    private function statusLabel(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }

    private function audit(
        Request $request,
        Policy $policy,
        string $action,
        ?array $oldValues,
        ?array $newValues,
    ): void {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'module' => 'policies',
            'auditable_type' => Policy::class,
            'auditable_id' => $policy->id,
            'description' => "Policy {$policy->code}: {$action}.",
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'severity' => 'info',
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function adminSummary(Request $request): array
    {
        $admin = $request->user();

        return [
            'name' => $admin->name,
            'role' => 'Admin',
            'email' => $admin->email,
            'initials' => collect(explode(' ', $admin->name))->map(fn (string $part) => strtoupper(substr($part, 0, 1)))->take(2)->implode(''),
        ];
    }
}
