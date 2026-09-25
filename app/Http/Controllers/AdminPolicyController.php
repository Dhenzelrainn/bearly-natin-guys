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
    private const CATEGORIES = ['Marketplace', 'Seller Compliance', 'Transactions', 'Account'];

    public function index(Request $request): View
    {
        $policies = Policy::query()
            ->with(['latestVersion.creator'])
            ->latest('updated_at')
            ->get()
            ->map(fn (Policy $policy): array => $this->viewRecord($policy));

        return view('admin.system.policies', [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'policies' => $policies,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        DB::transaction(function () use ($request, $validated): void {
            $published = $validated['status'] === 'published';
            $policy = Policy::query()->create([
                'code' => $this->newCode(),
                'title' => $validated['title'],
                'category' => $validated['category'],
                'status' => $published ? 'active' : 'draft',
            ]);
            $version = $policy->versions()->create([
                'version' => $validated['version'],
                'summary' => $validated['summary'] ?? null,
                'body' => $validated['body'],
                'status' => $published ? 'published' : 'draft',
                'created_by' => $request->user()->id,
                'effective_at' => $published ? now() : null,
                'published_at' => $published ? now() : null,
            ]);
            $this->audit($request, $policy, $published ? 'policy.published' : 'policy.created', null, $version->toArray());
        }, 3);

        return back()->with('success', 'Policy was saved.');
    }

    public function update(Request $request, Policy $policy): RedirectResponse
    {
        $validated = $this->validated($request, $policy);

        DB::transaction(function () use ($request, $policy, $validated): void {
            $policy = Policy::query()->with('latestVersion')->lockForUpdate()->findOrFail($policy->id);
            $current = $policy->latestVersion;
            $oldValues = ['policy' => $policy->toArray(), 'version' => $current?->toArray()];

            $policy->update(['title' => $validated['title'], 'category' => $validated['category']]);

            if ($current?->status === 'draft' && $current->version === $validated['version']) {
                $current->update([
                    'summary' => $validated['summary'] ?? null,
                    'body' => $validated['body'],
                ]);
            } else {
                if ($current?->status === 'published' && $current->version === $validated['version']) {
                    throw ValidationException::withMessages(['version' => 'Published content is immutable. Enter a new version number.']);
                }
                $current = $policy->versions()->create([
                    'version' => $validated['version'],
                    'summary' => $validated['summary'] ?? null,
                    'body' => $validated['body'],
                    'status' => 'draft',
                    'created_by' => $request->user()->id,
                ]);
                $policy->update(['status' => 'draft']);
            }

            $this->audit($request, $policy, 'policy.updated', $oldValues, ['policy' => $policy->fresh()->toArray(), 'version' => $current->fresh()->toArray()]);
        }, 3);

        return back()->with('success', 'Policy draft was updated.');
    }

    public function publish(Request $request, Policy $policy): RedirectResponse
    {
        DB::transaction(function () use ($request, $policy): void {
            $policy = Policy::query()->with('latestVersion')->lockForUpdate()->findOrFail($policy->id);
            $version = $policy->latestVersion;
            abort_unless($version && $version->status === 'draft', 422);

            $policy->versions()->where('status', 'published')->update(['status' => 'archived']);
            $version->update(['status' => 'published', 'effective_at' => now(), 'published_at' => now()]);
            $policy->update(['status' => 'active']);
            $this->audit($request, $policy, 'policy.published', null, $version->fresh()->toArray());
        }, 3);

        return back()->with('success', 'Policy version was published.');
    }

    public function archive(Request $request, Policy $policy): RedirectResponse
    {
        DB::transaction(function () use ($request, $policy): void {
            $policy = Policy::query()->lockForUpdate()->findOrFail($policy->id);
            $oldValues = $policy->toArray();
            $policy->update(['status' => 'archived']);
            $policy->versions()->whereIn('status', ['draft', 'published'])->update(['status' => 'archived']);
            $this->audit($request, $policy, 'policy.archived', $oldValues, $policy->fresh()->toArray());
        }, 3);

        return back()->with('success', 'Policy was archived.');
    }

    private function validated(Request $request, ?Policy $policy = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'version' => [
                'required', 'string', 'max:20',
                Rule::unique('policy_versions', 'version')->where('policy_id', $policy?->id)->ignore($policy?->latestVersion?->id),
            ],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:50000'],
            'status' => [$policy ? 'nullable' : 'required', Rule::in(['draft', 'published'])],
        ]);
    }

    private function viewRecord(Policy $policy): array
    {
        $version = $policy->latestVersion;
        return [
            'database_id' => $policy->id,
            'id' => $policy->code,
            'title' => $policy->title,
            'category' => $policy->category,
            'version' => $version?->version ?? '—',
            'version_status' => $version?->status,
            'status' => ucfirst($policy->status),
            'updated' => $version?->updated_at?->format('M j, Y') ?? $policy->updated_at?->format('M j, Y'),
            'updated_by' => $version?->creator?->name ?? 'System',
            'summary' => $version?->summary ?? '—',
            'body' => $version?->body ?? '—',
        ];
    }

    private function audit(Request $request, Policy $policy, string $action, ?array $oldValues, ?array $newValues): void
    {
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
            'severity' => $action === 'policy.archived' ? 'warning' : 'info',
            'created_at' => now(),
        ]);
    }

    private function newCode(): string
    {
        return 'POL-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    private function adminSummary(Request $request): array
    {
        $admin = $request->user();
        return ['name' => $admin->name, 'role' => 'Admin', 'email' => $admin->email, 'initials' => collect(explode(' ', $admin->name))->map(fn (string $part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('')];
    }
}
