<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthSessionStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user('web');

        if ($user === null) {
            return response()->json([
                'data' => null,
                'message' => 'Your session has ended. Please sign in again.',
                'code' => 'UNAUTHENTICATED',
            ], 401)->header('Cache-Control', 'no-store, private');
        }

        return response()->json([
            'data' => [
                'authenticated' => true,
                'user_id' => (int) $user->getAuthIdentifier(),
            ],
            'message' => 'Your session is active.',
        ])->header('Cache-Control', 'no-store, private');
    }
}
