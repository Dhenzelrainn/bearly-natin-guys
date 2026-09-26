<?php

namespace App\Http\Controllers;

use App\Models\AccountApplication;
use App\Models\ApplicationDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationDocumentController extends Controller
{
    public function show(AccountApplication $application, ApplicationDocument $document): StreamedResponse
    {
        $this->ensureDocumentBelongsToApplication($application, $document);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->response(
            $document->file_path,
            basename($document->original_name),
            [
                'Content-Type' => $document->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            ],
            'inline',
        );
    }

    public function update(
        Request $request,
        AccountApplication $application,
        ApplicationDocument $document,
    ): RedirectResponse {
        $this->ensureDocumentBelongsToApplication($application, $document);

        $validated = $request->validate([
            'verification_status' => ['required', Rule::in(['verified', 'rejected'])],
            'rejection_reason' => ['nullable', 'required_if:verification_status,rejected', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($application, $document, $request, $validated): void {
            $lockedApplication = AccountApplication::query()->lockForUpdate()->findOrFail($application->id);
            $lockedDocument = ApplicationDocument::query()->lockForUpdate()->findOrFail($document->id);

            $this->ensureDocumentBelongsToApplication($lockedApplication, $lockedDocument);
            abort_unless(in_array($lockedApplication->status, ['submitted', 'under_review', 'needs_revision'], true), 422);

            $lockedDocument->forceFill([
                'verification_status' => $validated['verification_status'],
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'rejection_reason' => $validated['verification_status'] === 'rejected'
                    ? $validated['rejection_reason']
                    : null,
            ])->save();

            if ($lockedApplication->status === 'submitted') {
                $lockedApplication->forceFill([
                    'status' => 'under_review',
                    'review_started_at' => now(),
                    'reviewed_by' => $request->user()->id,
                ])->save();
            }
        });

        return back()
            ->with('success', 'Document verification was updated.')
            ->with('review_application_id', $application->id);
    }

    private function ensureDocumentBelongsToApplication(
        AccountApplication $application,
        ApplicationDocument $document,
    ): void {
        abort_unless($document->application_id === $application->id, 404);
    }
}
