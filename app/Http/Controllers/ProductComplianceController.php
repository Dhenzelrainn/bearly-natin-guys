<?php

namespace App\Http\Controllers;

use App\Models\ProductViolation;
use App\Services\ProductViolationDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductComplianceController extends Controller
{
    public function decide(
        Request $request,
        ProductViolation $violation,
        ProductViolationDecisionService $decisionService,
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => [
                'required',
                Rule::in(['dismiss', 'confirm', 'request_correction', 'remove_product', 'warn_seller']),
            ],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $decisionService->decide(
            $violation,
            $request->user(),
            $data['decision'],
            $data['note'],
        );

        return back()->with('success', 'Compliance decision recorded.');
    }
}
