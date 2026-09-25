<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AccountRegistrationService
{
    /**
     * @param  array<string, mixed>  $userAttributes
     * @param  list<array{type: string, file: UploadedFile, directory: string, legacy_attribute: string}>  $uploads
     * @param  array<string, mixed>  $applicationAttributes
     */
    public function register(
        array $userAttributes,
        string $requestedRole,
        array $uploads,
        array $applicationAttributes = [],
    ): User {
        $storedDocuments = [];

        try {
            foreach ($uploads as $upload) {
                $file = $upload['file'];
                $path = $file->store($upload['directory'], 'local');

                $storedDocuments[] = [
                    'document_type' => $upload['type'],
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream',
                    'size_bytes' => (int) $file->getSize(),
                    'verification_status' => 'pending',
                    'legacy_attribute' => $upload['legacy_attribute'],
                ];
            }

            return DB::transaction(function () use ($applicationAttributes, $requestedRole, $storedDocuments, $userAttributes): User {
                $role = Role::query()->where('name', $requestedRole)->firstOrFail();

                foreach ($storedDocuments as $document) {
                    $userAttributes[$document['legacy_attribute']] = $document['file_path'];
                }

                $user = User::query()->create($userAttributes);

                $application = $user->applications()->create(array_merge($applicationAttributes, [
                    'application_no' => $this->newApplicationNumber(),
                    'requested_role_id' => $role->id,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]));

                $application->documents()->createMany(array_map(
                    fn (array $document): array => collect($document)->except('legacy_attribute')->all(),
                    $storedDocuments,
                ));

                return $user->load('applications.requestedRole', 'applications.documents');
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_column($storedDocuments, 'file_path'));

            throw $exception;
        }
    }

    private function newApplicationNumber(): string
    {
        return 'APP-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
    }
}
