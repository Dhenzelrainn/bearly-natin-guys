<?php

namespace App\Http\Controllers\auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function send(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        return response()->json($verification->send($request, strtolower(trim($data['email']))));
    }

    public function check(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
        ]);
        return response()->json($verification->check($request, strtolower(trim($data['email'])), $data['code']));
    }
}
