<?php

namespace App\Services;

use App\Models\AccountApplication;
use Illuminate\Support\Facades\DB;

class ApplicationDocumentVerificationService
{
    public function sync(AccountApplication $application): int
    {
        if (! in_array(
            $application->status,
            ['approved', 'rejected'],
            true
        )) {
            return 0;
        }

        $targetStatus =
            $application->status === 'approved'
                ? 'approved'
                : 'rejected';

        $reviewerId =
            $application->reviewed_by
            ?? $this->assignedByFromRole($application);

        $values = [
            'verification_status' => $targetStatus,
            'verified_by' => $reviewerId,
            'verified_at' =>
                $application->decided_at ?? now(),
            'rejection_reason' =>
                $targetStatus === 'rejected'
                    ? $application->decision_reason
                    : null,
            'updated_at' => now(),
        ];

        return DB::table('application_documents')
            ->where(
                'application_id',
                $application->id
            )
            ->update($values);
    }

    private function assignedByFromRole(
        AccountApplication $application
    ): ?int {
        $assignedBy = DB::table('role_user')
            ->where('user_id', $application->user_id)
            ->where(
                'role_id',
                $application->requested_role_id
            )
            ->value('assigned_by');

        return $assignedBy !== null
            ? (int) $assignedBy
            : null;
    }
}
