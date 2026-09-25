<?php

namespace App\Http\Controllers;

use App\Models\ProductViolation;
use App\Models\SellerWarning;
use App\Models\ViolationAction;
use App\Notifications\ComplianceDecisionNotification;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductComplianceController extends Controller
{
    public function decide(Request $request, ProductViolation $violation, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:dismiss,confirm,request_correction,remove_product,warn_seller'], 'note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($violation, $data, $request, $audit) {
            $violation = ProductViolation::query()->with(['product', 'sellerProfile.user'])->lockForUpdate()->findOrFail($violation->id);
            $from = $violation->status;
            $status = $data['decision'] === 'dismiss' ? 'dismissed' : ($data['decision'] === 'request_correction' ? 'correction_requested' : 'confirmed');
            $violation->update(['status' => $status, 'resolution' => $data['decision'], 'admin_note' => $data['note'], 'reviewed_at' => now(), 'resolved_at' => in_array($status, ['dismissed', 'confirmed']) ? now() : null]);
            if ($data['decision'] === 'dismiss') {
                $violation->product->update(['compliance_status' => 'clear']);
            }if ($data['decision'] === 'remove_product') {
                $violation->product->update(['product_status' => 'removed', 'compliance_status' => 'blocked', 'removed_at' => now(), 'removed_reason' => $data['note']]);
            }if ($data['decision'] === 'warn_seller') {
                SellerWarning::create(['warning_no' => 'WRN-'.Str::upper(Str::random(10)), 'seller_profile_id' => $violation->seller_profile_id, 'violation_id' => $violation->id, 'level' => $violation->severity, 'points' => match ($violation->severity) {
                    'critical' => 10,'high' => 5,'medium' => 3,default => 1
                }, 'reason' => $data['note'], 'issued_by' => $request->user()->id, 'issued_at' => now()]);
            }ViolationAction::create(['violation_id' => $violation->id, 'action_type' => $data['decision'], 'from_status' => $from, 'to_status' => $status, 'actor_user_id' => $request->user()->id, 'reason' => $data['note']]);
            $audit->record('compliance.'.$data['decision'], 'compliance', $violation,
                'A product compliance decision was recorded.', ['status'=>$from], ['status'=>$status,'note'=>$data['note']],
                in_array($data['decision'], ['remove_product','warn_seller'], true) ? 'warning' : 'info', $request);
            $violation->sellerProfile?->user?->notify(new ComplianceDecisionNotification($violation, $data['decision']));
        }, 3);

        return back()->with('success', 'Compliance decision recorded.');
    }
}
