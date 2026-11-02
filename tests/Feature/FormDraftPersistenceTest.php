<?php

namespace Tests\Feature;

use App\Models\RegistrationDraft;
use App\Models\SellerProductDraft;
use App\Models\SellerProductDraftImage;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Services\RegistrationDraftService;
use App\Services\SellerProductDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FormDraftPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_drafts_are_browser_scoped_and_exclude_secrets(): void
    {
        $service = app(RegistrationDraftService::class);
        $firstBrowser = $this->requestWithBrowserToken(str_repeat('a', 64));
        $secondBrowser = $this->requestWithBrowserToken(str_repeat('b', 64));

        $service->save($firstBrowser, 'rider', [
            'first_name' => 'Alex',
            'password' => 'must-not-be-stored',
            'terms' => '1',
            'email_code' => '123456',
            'unexpected' => 'discarded',
        ], 2);

        $page = $service->forPage($firstBrowser, 'rider');

        $this->assertSame(['first_name' => 'Alex'], $page['fields']);
        $this->assertSame(2, $page['step']);
        $this->assertTrue($page['has_draft']);
        $this->assertFalse($service->forPage($secondBrowser, 'rider')['has_draft']);
    }

    public function test_seller_draft_color_updates_use_explicit_image_ids_and_cleanup_files(): void
    {
        Storage::fake('local');

        $store = $this->store();
        $service = app(SellerProductDraftService::class);
        $service->save($store, ['name' => 'Temporary shirt'], [['label' => 'Red']], ['Red']);

        $images = $service->replaceImages(
            $store,
            'variant_color',
            [UploadedFile::fake()->create('red.jpg', 10, 'image/jpeg')],
            ['Red'],
        );
        $image = $images[0];
        Storage::disk('local')->assertExists($image->path);

        $draft = $service->save(
            $store,
            ['name' => 'Temporary shirt'],
            [['label' => 'Red']],
            ['Original payload color'],
            [$image->id => 'Blue'],
        );

        $this->assertSame('Blue', $image->fresh()->color);
        $this->assertSame(['Original payload color'], $draft->payload['variant_image_colors']);

        $removed = $service->removeImage($store, $image->id);

        $this->assertCount(0, $removed->images);
        $this->assertDatabaseMissing('seller_product_draft_images', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($image->path);
    }

    public function test_failed_image_deletion_retains_metadata_for_a_later_retry(): void
    {
        Storage::fake('local');

        $store = $this->store();
        $service = app(SellerProductDraftService::class);
        $image = $service->replaceImages(
            $store,
            'primary',
            [UploadedFile::fake()->create('primary.png', 10, 'image/png')],
        )[0];

        $fileExists = true;
        $deleteAttempts = 0;
        $disk = Mockery::mock();
        $disk->shouldReceive('delete')
            ->with($image->path)
            ->andReturnUsing(function () use (&$deleteAttempts, &$fileExists): bool {
                $deleteAttempts++;
                if ($deleteAttempts === 1) {
                    return false;
                }

                $fileExists = false;

                return true;
            });
        $disk->shouldReceive('exists')
            ->with($image->path)
            ->andReturnUsing(function () use (&$fileExists): bool {
                return $fileExists;
            });
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $failure = null;
        try {
            $service->removeImage($store, $image->id);
        } catch (ValidationException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(ValidationException::class, $failure);
        $this->assertSame(1, $service->cleanupFailureCount());
        $this->assertDatabaseHas('seller_product_draft_images', ['id' => $image->id]);
        $this->assertContains(
            $image->id,
            SellerProductDraft::query()->where('store_id', $store->id)->firstOrFail()->payload['pending_image_cleanup'],
        );

        $service->forStore($store);

        $this->assertSame(2, $deleteAttempts);
        $this->assertDatabaseMissing('seller_product_draft_images', ['id' => $image->id]);
    }

    public function test_pruner_retries_active_pending_cleanup_without_extending_draft_expiry(): void
    {
        Storage::fake('local');

        $store = $this->store();
        $service = app(SellerProductDraftService::class);
        $image = $service->replaceImages(
            $store,
            'primary',
            [UploadedFile::fake()->create('retry-primary.png', 10, 'image/png')],
        )[0];
        $draft = SellerProductDraft::query()->where('store_id', $store->id)->firstOrFail();
        $expiresAt = now()->addDays(3);
        $draft->forceFill([
            'payload' => ['pending_image_cleanup' => [$image->id]],
            'expires_at' => $expiresAt,
        ])->save();

        $fileExists = true;
        $deleteAttempts = 0;
        $disk = Mockery::mock();
        $disk->shouldReceive('delete')
            ->with($image->path)
            ->andReturnUsing(function () use (&$deleteAttempts, &$fileExists): bool {
                $deleteAttempts++;
                if ($deleteAttempts === 1) {
                    return false;
                }

                $fileExists = false;

                return true;
            });
        $disk->shouldReceive('exists')
            ->with($image->path)
            ->andReturnUsing(function () use (&$fileExists): bool {
                return $fileExists;
            });
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->assertSame(0, $service->pruneExpired());
        $failedDraft = $draft->fresh();
        $this->assertSame([$image->id], $failedDraft->payload['pending_image_cleanup']);
        $this->assertSame($expiresAt->timestamp, $failedDraft->expires_at->timestamp);
        $this->assertSame(1, $service->cleanupFailureCount());

        $this->assertSame(0, $service->pruneExpired());
        $this->assertSame(2, $deleteAttempts);
        $this->assertDatabaseMissing('seller_product_draft_images', ['id' => $image->id]);
        $this->assertDatabaseHas('seller_product_drafts', ['id' => $draft->id]);
        $this->assertArrayNotHasKey('pending_image_cleanup', $draft->fresh()->payload);
        $this->assertSame(0, $service->cleanupFailureCount());
    }

    public function test_orphaned_upload_metadata_is_reconciled_after_transaction_and_delete_failures(): void
    {
        Storage::fake('local');

        $store = $this->store();
        $service = app(SellerProductDraftService::class);
        $service->forStore($store);

        $realDisk = Storage::disk('local');
        $fileExists = true;
        $deleteAttempts = 0;
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')
            ->withAnyArgs()
            ->andReturnUsing(function ($path, $file, $name, $options) use ($realDisk) {
                return $realDisk->putFileAs($path, $file, $name, $options);
            });
        $disk->shouldReceive('delete')
            ->withAnyArgs()
            ->andReturnUsing(function (string $path) use (&$deleteAttempts, &$fileExists, $realDisk): bool {
                $deleteAttempts++;
                if ($deleteAttempts === 1) {
                    return false;
                }

                $fileExists = false;

                return $realDisk->delete($path);
            });
        $disk->shouldReceive('exists')
            ->withAnyArgs()
            ->andReturnUsing(function () use (&$fileExists): bool {
                return $fileExists;
            });
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $connection = app('db')->connection();
        $transactionCalls = 0;
        DB::shouldReceive('transaction')
            ->twice()
            ->andReturnUsing(function (callable $callback) use (&$transactionCalls, $connection) {
                $transactionCalls++;
                if ($transactionCalls === 1) {
                    throw new RuntimeException('Simulated draft metadata transaction failure.');
                }

                return $connection->transaction($callback);
            });

        try {
            $service->replaceImages(
                $store,
                'primary',
                [UploadedFile::fake()->create('orphan-primary.png', 10, 'image/png')],
            );
            $this->fail('The simulated metadata transaction failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated draft metadata transaction failure.', $exception->getMessage());
        }

        $this->assertSame(2, $transactionCalls);
        $this->assertSame(1, $deleteAttempts);
        $draft = SellerProductDraft::query()->where('store_id', $store->id)->firstOrFail();
        $image = $draft->images()->firstOrFail();
        $this->assertContains($image->id, $draft->payload['pending_image_cleanup']);
        $this->assertTrue(SellerProductDraftImage::query()->whereKey($image->id)->exists());

        $this->assertSame(0, $service->pruneExpired());
        $this->assertSame(2, $deleteAttempts);
        $this->assertFalse(SellerProductDraftImage::query()->whereKey($image->id)->exists());
        $this->assertArrayNotHasKey('pending_image_cleanup', $draft->fresh()->payload);
        $this->assertFalse($realDisk->exists($image->path));
    }

    public function test_orphaned_upload_is_reconciled_after_both_metadata_transactions_fail(): void
    {
        Storage::fake('local');

        $store = $this->store();
        $service = app(SellerProductDraftService::class);
        $service->forStore($store);

        $realDisk = Storage::disk('local');
        $deleteAttempts = 0;
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')
            ->withAnyArgs()
            ->andReturnUsing(function ($path, $file, $name, $options) use ($realDisk) {
                return $realDisk->putFileAs($path, $file, $name, $options);
            });
        $disk->shouldReceive('delete')
            ->withAnyArgs()
            ->andReturnUsing(function (string $path) use (&$deleteAttempts, $realDisk): bool {
                $deleteAttempts++;
                if ($deleteAttempts === 1) {
                    return false;
                }

                return $realDisk->delete($path);
            });
        $disk->shouldReceive('exists')
            ->withAnyArgs()
            ->andReturnUsing(fn (string $path): bool => $realDisk->exists($path));
        $disk->shouldReceive('allFiles')
            ->with(SellerProductDraftService::TEMPORARY_IMAGE_STORAGE_PREFIX)
            ->andReturnUsing(fn (string $prefix): array => $realDisk->allFiles($prefix));
        $disk->shouldReceive('lastModified')
            ->withAnyArgs()
            ->andReturnUsing(fn (string $path): int => $realDisk->lastModified($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        DB::shouldReceive('transaction')
            ->twice()
            ->andThrow(new RuntimeException('Simulated original and fallback transaction failures.'));

        try {
            $service->replaceImages(
                $store,
                'primary',
                [UploadedFile::fake()->create('unreconciled-primary.png', 10, 'image/png')],
            );
            $this->fail('The simulated metadata transaction failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated original and fallback transaction failures.', $exception->getMessage());
        }

        $orphanPath = $realDisk->allFiles(SellerProductDraftService::TEMPORARY_IMAGE_STORAGE_PREFIX)[0];
        $this->ageLocalFile($realDisk, $orphanPath);
        $this->assertTrue($realDisk->exists($orphanPath));

        $this->assertSame(1, $service->reconcileOrphanedUploads());
        $this->assertSame(2, $deleteAttempts);
        $this->assertFalse($realDisk->exists($orphanPath));
        $this->assertSame(0, $service->reconcileOrphanedUploads());
    }

    public function test_orphan_reconciliation_preserves_a_referenced_file(): void
    {
        Storage::fake('local');

        $store = $this->store();
        $service = app(SellerProductDraftService::class);
        $draft = $service->forStore($store);
        $path = SellerProductDraftService::TEMPORARY_IMAGE_STORAGE_PREFIX.'/'.$store->id.'/'.$draft->id.'/referenced.png';
        Storage::disk('local')->put($path, 'referenced image');
        $draft->images()->create([
            'purpose' => 'primary',
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'referenced.png',
            'mime_type' => 'image/png',
            'size_bytes' => 16,
            'position' => 0,
        ]);
        $this->ageLocalFile(Storage::disk('local'), $path);

        $this->assertSame(0, $service->reconcileOrphanedUploads());
        Storage::disk('local')->assertExists($path);
    }

    public function test_orphan_reconciliation_preserves_new_and_outside_prefix_files(): void
    {
        Storage::fake('local');

        $newPath = SellerProductDraftService::TEMPORARY_IMAGE_STORAGE_PREFIX.'/new-store/new-draft/new.png';
        $outsidePath = 'other-private-prefix/old.png';
        Storage::disk('local')->put($newPath, 'new image');
        Storage::disk('local')->put($outsidePath, 'outside image');
        $this->ageLocalFile(Storage::disk('local'), $outsidePath);

        $service = app(SellerProductDraftService::class);

        $this->assertSame(0, $service->reconcileOrphanedUploads());
        Storage::disk('local')->assertExists($newPath);
        Storage::disk('local')->assertExists($outsidePath);
    }

    public function test_orphan_reconciliation_retries_a_failed_deletion_on_the_next_run(): void
    {
        Storage::fake('local');

        $path = SellerProductDraftService::TEMPORARY_IMAGE_STORAGE_PREFIX.'/retry-store/retry-draft/retry.png';
        Storage::disk('local')->put($path, 'retry image');
        $this->ageLocalFile(Storage::disk('local'), $path);
        $realDisk = Storage::disk('local');
        $deleteAttempts = 0;
        $disk = Mockery::mock();
        $disk->shouldReceive('allFiles')
            ->with(SellerProductDraftService::TEMPORARY_IMAGE_STORAGE_PREFIX)
            ->andReturnUsing(fn (string $prefix): array => $realDisk->allFiles($prefix));
        $disk->shouldReceive('lastModified')
            ->with($path)
            ->andReturnUsing(fn (): int => $realDisk->lastModified($path));
        $disk->shouldReceive('delete')
            ->with($path)
            ->andReturnUsing(function () use (&$deleteAttempts, $realDisk, $path): bool {
                $deleteAttempts++;
                if ($deleteAttempts === 1) {
                    return false;
                }

                return $realDisk->delete($path);
            });
        $disk->shouldReceive('exists')
            ->with($path)
            ->andReturnUsing(fn (): bool => $realDisk->exists($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $service = app(SellerProductDraftService::class);

        $this->assertSame(0, $service->reconcileOrphanedUploads());
        $this->assertSame(1, $service->cleanupFailureCount());
        $this->assertTrue($realDisk->exists($path));
        $this->assertSame(1, $service->reconcileOrphanedUploads());
        $this->assertSame(2, $deleteAttempts);
        $this->assertFalse($realDisk->exists($path));
    }

    public function test_orphan_reconciliation_retries_a_listing_failure_on_the_next_run(): void
    {
        Storage::fake('local');

        $path = SellerProductDraftService::TEMPORARY_IMAGE_STORAGE_PREFIX.'/listing-store/listing-draft/listing.png';
        Storage::disk('local')->put($path, 'listing retry image');
        $this->ageLocalFile(Storage::disk('local'), $path);
        $realDisk = Storage::disk('local');
        $listingAttempts = 0;
        $disk = Mockery::mock();
        $disk->shouldReceive('allFiles')
            ->with(SellerProductDraftService::TEMPORARY_IMAGE_STORAGE_PREFIX)
            ->andReturnUsing(function (string $prefix) use (&$listingAttempts, $realDisk): array {
                $listingAttempts++;
                if ($listingAttempts === 1) {
                    throw new RuntimeException('Simulated private draft-file listing failure.');
                }

                return $realDisk->allFiles($prefix);
            });
        $disk->shouldReceive('lastModified')
            ->with($path)
            ->andReturnUsing(fn (): int => $realDisk->lastModified($path));
        $disk->shouldReceive('delete')
            ->with($path)
            ->andReturnUsing(fn (): bool => $realDisk->delete($path));
        $disk->shouldReceive('exists')
            ->with($path)
            ->andReturnUsing(fn (): bool => $realDisk->exists($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $service = app(SellerProductDraftService::class);

        $this->assertSame(0, $service->reconcileOrphanedUploads());
        $this->assertSame(1, $service->cleanupFailureCount());
        $this->assertTrue($realDisk->exists($path));
        $this->assertSame(1, $service->reconcileOrphanedUploads());
        $this->assertSame(2, $listingAttempts);
        $this->assertFalse($realDisk->exists($path));
    }

    public function test_seller_draft_image_endpoints_are_store_scoped_and_validate_uploads(): void
    {
        Storage::fake('local');

        $store = $this->store();
        $otherStore = $this->store();
        $service = app(SellerProductDraftService::class);
        $image = $service->replaceImages(
            $store,
            'primary',
            [UploadedFile::fake()->create('private.png', 10, 'image/png')],
        )[0];
        $otherSeller = $otherStore->sellerProfile->user;

        $this->actingAs($otherSeller)
            ->get(route('seller.products.draft.images.show', $image->id))
            ->assertNotFound();

        $this->actingAs($otherSeller)
            ->deleteJson(route('seller.products.draft.images.delete', $image->id))
            ->assertNotFound();

        $this->actingAs($otherSeller)
            ->withHeader('Accept', 'application/json')
            ->post(route('seller.products.draft.images.upload'), [
                'purpose' => 'primary',
                'images' => [UploadedFile::fake()->create('invalid.txt', 10, 'text/plain')],
            ])
            ->assertStatus(422);

        $this->actingAs($otherSeller)
            ->post(route('seller.products.draft.images.upload'), [
                'purpose' => 'primary',
                'images' => [UploadedFile::fake()->create('other.png', 10, 'image/png')],
            ])
            ->assertOk();

        $this->assertDatabaseHas('seller_product_draft_images', ['id' => $image->id]);
        $this->assertDatabaseHas('seller_product_drafts', ['store_id' => $otherStore->id]);
    }

    public function test_authenticated_seller_can_save_a_store_scoped_product_draft(): void
    {
        $store = $this->store();
        $seller = $store->sellerProfile->user;

        $this->actingAs($seller)
            ->postJson(route('seller.products.draft.save'), [
                'fields' => [
                    'name' => 'Saved from the editor',
                    'product_mode' => 'with_variants',
                ],
                'variants' => [['label' => 'Small / Blue', 'stock' => '3']],
                'variant_image_colors' => ['Blue'],
                'draft_image_colors' => ['999' => 'Blue'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Product details saved privately for 7 days.');

        $this->assertDatabaseHas('seller_product_drafts', ['store_id' => $store->id]);
        $this->assertSame(
            'Saved from the editor',
            SellerProductDraft::query()
                ->where('store_id', $store->id)
                ->firstOrFail()
                ->payload['fields']['name'] ?? null,
        );
    }

    public function test_expired_seller_drafts_remove_private_image_files(): void
    {
        Storage::fake('local');

        $store = $this->store();
        $service = app(SellerProductDraftService::class);
        $draft = $service->forStore($store);
        $image = $service->replaceImages(
            $store,
            'primary',
            [UploadedFile::fake()->create('primary.png', 10, 'image/png')],
        )[0];

        $draft->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertSame(1, $service->pruneExpired());
        $this->assertDatabaseMissing('seller_product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseMissing('seller_product_draft_images', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($image->path);
    }

    public function test_prune_command_removes_expired_registration_and_seller_drafts(): void
    {
        Storage::fake('local');

        $registrationDrafts = app(RegistrationDraftService::class);
        $token = str_repeat('d', 64);
        $request = $this->requestWithBrowserToken($token);
        $registrationDrafts->save($request, 'rider', ['first_name' => 'Expired'], 2);
        RegistrationDraft::query()
            ->where('browser_token_hash', hash('sha256', $token))
            ->update(['expires_at' => now()->subMinute()]);

        $store = $this->store();
        $service = app(SellerProductDraftService::class);
        $draft = $service->forStore($store);
        $image = $service->replaceImages(
            $store,
            'primary',
            [UploadedFile::fake()->create('expired.png', 10, 'image/png')],
        )[0];
        $draft->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('drafts:prune-expired')
            ->expectsOutput('Pruned 1 registration draft(s) and 1 Seller product draft(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('registration_drafts', [
            'browser_token_hash' => hash('sha256', $token),
        ]);
        $this->assertDatabaseMissing('seller_product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseMissing('seller_product_draft_images', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($image->path);
    }

    private function requestWithBrowserToken(string $token): Request
    {
        $request = Request::create('/register', 'GET');
        $request->cookies->set(RegistrationDraftService::COOKIE_NAME, $token);

        return $request;
    }

    private function store(): Store
    {
        $user = User::create([
            'name' => 'Draft Seller',
            'first_name' => 'Draft',
            'last_name' => 'Seller',
            'email' => 'draft-'.uniqid().'@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => 'Password123',
        ]);

        $profile = SellerProfile::create([
            'user_id' => $user->id,
            'legal_business_name' => 'Draft Seller Business',
        ]);

        return Store::create([
            'seller_profile_id' => $profile->id,
            'name' => 'Draft Store',
            'slug' => 'draft-store-'.uniqid(),
        ]);
    }

    private function ageLocalFile($disk, string $path): void
    {
        $this->assertTrue(touch(
            $disk->path($path),
            now()->subMinutes(SellerProductDraftService::ORPHAN_FILE_GRACE_MINUTES + 5)->timestamp,
        ));
    }
}
