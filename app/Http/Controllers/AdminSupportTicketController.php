<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminSupportTicketController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));
        $topic = trim((string) $request->query('topic'));

        $tickets = SupportTicket::query()
            ->with('user')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('ticket_no', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            }))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($topic !== '', fn (Builder $query) => $query->where('topic', $topic))
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.system.support-tickets', [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'tickets' => $tickets,
            'statuses' => SupportTicket::query()
                ->distinct()
                ->orderBy('status')
                ->pluck('status')
                ->all(),
            'topics' => SupportTicket::query()
                ->distinct()
                ->orderBy('topic')
                ->pluck('topic')
                ->all(),
            'openCount' => SupportTicket::query()->where('status', 'open')->count(),
            'totalCount' => SupportTicket::query()->count(),
            'filters' => compact('search', 'status', 'topic'),
        ]);
    }

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
