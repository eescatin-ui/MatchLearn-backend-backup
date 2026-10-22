<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_and_replace_their_profile_photo(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profiles', ['institution' => 'SNSU'])
            ->assertCreated();

        $firstResponse = $this->postJson('/api/v1/profiles/photo', [
            'photo' => $this->fakePng('first.png'),
        ])->assertOk()
            ->assertJsonPath('success', true);
        $firstPath = basename(parse_url($firstResponse->json('data.profile_photo_url'), PHP_URL_PATH));
        Storage::disk('public')->assertExists("profile-photos/{$firstPath}");

        $secondResponse = $this->postJson('/api/v1/profiles/photo', [
            'photo' => $this->fakePng('second.png'),
        ])->assertOk();
        $secondPath = basename(parse_url($secondResponse->json('data.profile_photo_url'), PHP_URL_PATH));
        Storage::disk('public')->assertMissing("profile-photos/{$firstPath}");
        Storage::disk('public')->assertExists("profile-photos/{$secondPath}");
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'profile_photo_url' => $secondResponse->json('data.profile_photo_url'),
        ]);
        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.profile_photo_url', $secondResponse->json('data.profile_photo_url'));
    }

    public function test_user_must_have_a_profile_and_upload_an_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profiles/photo', [
                'photo' => $this->fakePng('photo.png'),
            ])
            ->assertNotFound();

        $this->postJson('/api/v1/profiles', ['institution' => 'SNSU'])
            ->assertCreated();
        $this->postJson('/api/v1/profiles/photo', [
            'photo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
    }

    private function fakePng(string $name): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pY8AAAAASUVORK5CYII=',
            true
        );

        if ($png === false) {
            throw new \RuntimeException('Could not build a valid test image.');
        }

        return UploadedFile::fake()->createWithContent($name, $png);
    }
}
