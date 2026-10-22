<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_and_replace_their_profile_photo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profiles', ['institution' => 'SNSU'])
            ->assertCreated();

        $firstResponse = $this->postJson('/api/v1/profiles/photo', [
            'photo' => $this->fakePng('first.png'),
        ])->assertOk()
            ->assertJsonPath('success', true);
        $firstPhotoUrl = $firstResponse->json('data.profile_photo_url');
        $firstPhotoData = $this->fakePng('first.png')->getContent();
        $this->assertStringContainsString('/api/v1/profiles/'.$user->id.'/photo', $firstPhotoUrl);
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'profile_photo_mime_type' => 'image/png',
        ]);
        $this->assertSame(base64_encode($firstPhotoData), $user->profile()->value('profile_photo_data'));
        $this->get('/api/v1/profiles/'.$user->id.'/photo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertContent($firstPhotoData);

        $secondResponse = $this->postJson('/api/v1/profiles/photo', [
            'photo' => $this->fakePng('second.png'),
        ])->assertOk();
        $secondPhotoData = $this->fakePng('second.png')->getContent();
        $this->assertSame(base64_encode($secondPhotoData), $user->profile()->value('profile_photo_data'));
        $this->get('/api/v1/profiles/'.$user->id.'/photo')
            ->assertOk()
            ->assertContent($secondPhotoData);
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
