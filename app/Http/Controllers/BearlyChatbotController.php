<?php

namespace App\Http\Controllers;

use App\Services\BearlyChatbotService;
use App\Services\BearlyChatbotUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BearlyChatbotController extends Controller
{
    public function send(Request $request, BearlyChatbotService $chatbot): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $reply = $chatbot->reply($validated['message'], $request->user());
        } catch (BearlyChatbotUnavailableException) {
            return response()->json([
                'data' => [
                    'answer' => 'The Bearly assistant is temporarily unavailable. Please try again or contact Bearly Support.',
                    'products' => [],
                ],
                'message' => 'The Bearly assistant is temporarily unavailable.',
            ], 503);
        }

        return response()->json([
            'data' => $reply,
            'message' => 'Assistant reply ready.',
        ]);
    }
}
