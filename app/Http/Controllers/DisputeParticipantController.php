<?php

namespace App\Http\Controllers;

use App\Models\DisputeEvidence;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DisputeParticipantController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function downloadEvidence(Request $request, DisputeEvidence $evidence): BinaryFileResponse
    {
        $dispute = $evidence->dispute;

        abort_unless(
            $dispute
                && $dispute->participants()->where('users.id', $request->user()->id)->exists(),
            404,
        );

        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);

        $this->auditService->record(
            action: 'dispute.evidence_downloaded',
            module: 'Compliance & Disputes',
            subject: $dispute,
            description: "Participant downloaded evidence for {$dispute->dispute_no}.",
            oldValues: [],
            newValues: [
                'evidence_id' => $evidence->id,
                'original_name' => $evidence->original_name,
            ],
            severity: 'info',
            request: $request,
        );

        return response()->download(
            Storage::disk('local')->path($evidence->file_path),
            $this->downloadName($evidence->original_name),
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function downloadName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?: 'dispute-evidence';

        return Str::limit($safe, 200, '');
    }
}
