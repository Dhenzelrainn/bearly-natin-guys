<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAnnouncementMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'buyer', 'seller', 'logistics', 'rider'] as $role) {
            Role::query()->firstOrCreate(
                ['name' => $role],
                ['display_name' => ucfirst($role)],
            );
        }
    }

    public function test_admin_can_publish_private_image_and_video_media_for_the_selected_audience(): void
    {
        Storage::fake('local');

        $admin = $this->user('admin', 'Bearly Admin');
        $buyer = $this->user('buyer', 'Media Buyer');
        $seller = $this->user('seller', 'Outside Audience Seller');

        $this->actingAs($admin)->post(
            route('admin.announcements.store'),
            [
                'title' => 'Media announcement',
                'body' => 'Here are the updated service instructions.',
                'status' => 'published',
                'audience_roles' => ['buyer'],
                'media' => [
                    UploadedFile::fake()->create('service-map.png', 100, 'image/png'),
                    UploadedFile::fake()->create('service-guide.mp4', 120, 'video/mp4'),
                ],
            ],
            ['Accept' => 'text/html'],
        )->assertRedirect();

        $announcement = Announcement::query()->with('media')->firstOrFail();
        $this->assertCount(2, $announcement->media);

        $image = $announcement->media->firstWhere('media_type', 'image');
        $video = $announcement->media->firstWhere('media_type', 'video');

        $this->assertNotNull($image);
        $this->assertNotNull($video);
        Storage::disk('local')->assertExists($image->file_path);
        Storage::disk('local')->assertExists($video->file_path);

        $this->actingAs($admin)
            ->get(route('admin.announcements.media', [$announcement, $image]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.announcements'))
            ->assertOk()
            ->assertSee('announcement-post-media', false)
            ->assertSee('service-map.png')
            ->assertSee('service-guide.mp4');

        $this->actingAs($buyer)
            ->get(route('announcements.show', $announcement))
            ->assertOk()
            ->assertSee('announcement-media-grid', false)
            ->assertSee(route('announcements.media', [$announcement, $image]), false)
            ->assertSee(route('announcements.media', [$announcement, $video]), false);

        $this->actingAs($buyer)
            ->get(route('announcements.media', [$announcement, $image]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($buyer)
            ->get(route('announcements.media', [$announcement, $video]))
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4');

        $this->actingAs($seller)
            ->get(route('announcements.show', $announcement))
            ->assertNotFound();

        $this->actingAs($seller)
            ->get(route('announcements.media', [$announcement, $image]))
            ->assertNotFound();
    }

    public function test_admin_can_remove_existing_media_and_add_replacement(): void
    {
        Storage::fake('local');

        $admin = $this->user('admin', 'Bearly Admin');
        $buyer = $this->user('buyer', 'Media Buyer');
        $announcement = $this->announcement($admin, $buyer);

        $oldMedia = $announcement->media()->create([
            'media_type' => 'image',
            'disk' => 'local',
            'file_path' => 'announcement-media/'.$announcement->id.'/old-map.png',
            'original_name' => 'old-map.png',
            'mime_type' => 'image/png',
            'size_bytes' => 100,
            'position' => 0,
        ]);
        Storage::disk('local')->put($oldMedia->file_path, 'old');

        $this->actingAs($admin)->patch(
            route('admin.announcements.update', $announcement),
            [
                'title' => $announcement->title,
                'body' => $announcement->body,
                'status' => 'published',
                'audience_roles' => ['buyer'],
                'remove_media_ids' => [$oldMedia->id],
                'media' => [UploadedFile::fake()->create('new-map.webp', 100, 'image/webp')],
            ],
            ['Accept' => 'text/html'],
        )->assertRedirect();

        $this->assertDatabaseMissing('announcement_media', ['id' => $oldMedia->id]);
        Storage::disk('local')->assertMissing($oldMedia->file_path);
        $this->assertDatabaseHas('announcement_media', [
            'announcement_id' => $announcement->id,
            'original_name' => 'new-map.webp',
            'media_type' => 'image',
        ]);
        $this->assertCount(1, $buyer->notifications()->get());

        $replacement = $announcement->media()->firstOrFail();
        Storage::disk('local')->assertExists($replacement->file_path);

        $this->actingAs($admin)
            ->delete(route('admin.announcements.destroy', $announcement))
            ->assertRedirect();

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
        $this->assertDatabaseMissing('announcement_media', ['id' => $replacement->id]);
        Storage::disk('local')->assertMissing($replacement->file_path);
    }

    public function test_upload_validation_rejects_unsupported_media_and_more_than_five_files(): void
    {
        Storage::fake('local');

        $admin = $this->user('admin', 'Bearly Admin');
        $validPayload = [
            'title' => 'Media validation',
            'body' => 'Testing uploaded media validation.',
            'status' => 'draft',
            'audience_roles' => ['buyer'],
        ];

        $this->actingAs($admin)->post(
            route('admin.announcements.store'),
            $validPayload + [
                'media' => [UploadedFile::fake()->create('payload.php', 10, 'text/x-php')],
            ],
            ['Accept' => 'text/html'],
        )->assertSessionHasErrors('media.0');

        $this->actingAs($admin)->post(
            route('admin.announcements.store'),
            $validPayload + [
                'media' => [
                    UploadedFile::fake()->create('one.png', 100, 'image/png'),
                    UploadedFile::fake()->create('two.png', 100, 'image/png'),
                    UploadedFile::fake()->create('three.png', 100, 'image/png'),
                    UploadedFile::fake()->create('four.png', 100, 'image/png'),
                    UploadedFile::fake()->create('five.png', 100, 'image/png'),
                    UploadedFile::fake()->create('six.png', 100, 'image/png'),
                ],
            ],
            ['Accept' => 'text/html'],
        )->assertSessionHasErrors('media');

        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('announcement_media', 0);
    }

    private function announcement(User $admin, User $buyer): Announcement
    {
        $announcement = Announcement::query()->create([
            'announcement_no' => 'ANN-MEDIA-'.fake()->unique()->numerify('####'),
            'title' => 'Media removal test',
            'body' => 'An existing notice with a previous image.',
            'status' => 'published',
            'created_by' => $admin->id,
            'publish_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);
        $announcement->roles()->sync([
            Role::query()->where('name', 'buyer')->value('id'),
        ]);

        return $announcement;
    }

    private function user(string $role, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
