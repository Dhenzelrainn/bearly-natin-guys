<?php

namespace App\Http\Controllers;

use App\Services\RegistrationDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationDraftController extends Controller
{
    public function save(Request $request, string $flow, RegistrationDraftService $drafts): JsonResponse
    {
        $validated = $request->validate([
            'fields' => ['present', 'array', 'max:40'],
            'step' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        $draft = $drafts->save(
            $request,
            $flow,
            $validated['fields'],
            (int) $validated['step'],
        );

        return response()->json([
            'data' => [
                'expires_at' => $draft->expires_at->toIso8601String(),
            ],
            'message' => 'Your registration draft was saved on this browser for 7 days.',
        ]);
    }
}
