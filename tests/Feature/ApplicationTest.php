<?php

namespace Tests\Feature;

use App\Enums\ConfigKey;
use App\Enums\GroupConfigKey;
use App\Enums\ImagePermission;
use App\Enums\Watermark\Mode;
use App\Models\Config;
use App\Models\Group;
use App\Models\Image;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    private function image(string $format = 'png', string $color = 'red'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lsky-test-');
        $image = new \Imagick();
        $image->newImage($format === 'ico' ? 128 : 640, $format === 'ico' ? 128 : 480, $color);
        $image->setImageFormat($format);
        $image->writeImage($path);
        $image->clear();
        return new UploadedFile($path, 'test.'.$format, test: true);
    }

    private function configureGroup(array $values): void
    {
        $group = Group::query()->firstOrFail();
        $group->configs = $group->configs->merge($values);
        $group->save();
    }

    private function configureSite(string $key, mixed $value): void
    {
        Config::query()->where('name', $key)->update(['value' => $value]);
        Cache::forget('configs');
    }

    private function upload(?User $user = null, string $format = 'png'): Image
    {
        if ($user) $this->actingAs($user->refresh());
        $this->postJson('/upload', ['file' => $this->image($format)])
            ->assertOk()->assertJsonPath('status', true);
        return Image::query()->latest('id')->firstOrFail();
    }

    public function test_exact_php_and_required_extensions(): void
    {
        $this->assertSame('8.5.12', PHP_VERSION);
        foreach (['bcmath', 'imagick', 'mbstring', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'ftp', 'redis', 'zip'] as $extension) {
            $this->assertTrue(extension_loaded($extension), $extension);
        }
    }

    public static function pageProvider(): array
    {
        return array_map(fn ($path) => [$path], [
            '/dashboard', '/upload', '/images', '/settings', '/gallery', '/api',
            '/admin/console', '/admin/users', '/admin/images', '/admin/groups',
            '/admin/groups/create', '/admin/strategies', '/admin/strategies/create', '/admin/settings',
        ]);
    }

    #[DataProvider('pageProvider')]
    public function test_application_pages_render(string $path): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_adminer' => true])->save();
        $this->actingAs($user)->get($path)->assertOk();
    }

    public function test_regular_users_cannot_access_admin(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/console')->assertForbidden();
    }

    public static function formatProvider(): array
    {
        return array_map(fn ($format) => [$format], ['png', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'tif', 'psd']);
    }

    #[DataProvider('formatProvider')]
    public function test_real_image_uploads_and_thumbnails(string $format): void
    {
        $image = $this->upload(format: $format);
        $this->assertTrue($image->filesystem()->fileExists($image->pathname));
        $this->assertFileExists(public_path($image->getThumbnailPathname()));
        $thumb = new \Imagick(public_path($image->getThumbnailPathname()));
        $this->assertLessThanOrEqual(400, $thumb->getImageWidth());
        $this->assertLessThanOrEqual(400, $thumb->getImageHeight());
        $this->artisan('lsky:thumbnails')->assertExitCode(0);
        $this->assertFileExists(public_path($image->getThumbnailPathname()));
        $image->delete();
    }

    public function test_svg_upload(): void
    {
        $file = UploadedFile::fake()->createWithContent('test.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20" fill="red"/></svg>');
        $this->postJson('/upload', ['file' => $file])->assertJsonPath('status', true);
        $image = Image::query()->firstOrFail();
        $this->assertFileExists(public_path($image->getThumbnailPathname()));
        $this->artisan('lsky:thumbnails')->assertExitCode(0);
        $this->assertFileExists(public_path($image->getThumbnailPathname()));
        $image->delete();
    }

    public function test_upload_deduplication_and_deletion_preserve_shared_file(): void
    {
        $this->configureGroup([GroupConfigKey::ImageSaveQuality => 100]);
        $contents = $this->image()->getContent();
        $this->postJson('/upload', ['file' => UploadedFile::fake()->createWithContent('same.png', $contents)])->assertJsonPath('status', true);
        $first = Image::query()->latest('id')->firstOrFail();
        $this->postJson('/upload', ['file' => UploadedFile::fake()->createWithContent('same.png', $contents)])->assertJsonPath('status', true);
        $second = Image::query()->latest('id')->firstOrFail();
        $this->assertSame($first->pathname, $second->pathname);
        $first->delete();
        $this->assertTrue($second->filesystem()->fileExists($second->pathname));
        $this->assertFileExists(public_path($second->getThumbnailPathname()));
        $second->delete();
        $this->assertFalse($second->filesystem()->fileExists($second->pathname));
        $this->assertFileDoesNotExist(public_path($second->getThumbnailPathname()));
    }

    public function test_deletion_preserves_thumbnail_shared_across_storage_strategies(): void
    {
        $this->configureGroup([GroupConfigKey::ImageSaveQuality => 100]);
        $contents = $this->image()->getContent();
        $this->postJson('/upload', ['file' => UploadedFile::fake()->createWithContent('same.png', $contents)])
            ->assertJsonPath('status', true);
        $first = Image::query()->latest('id')->firstOrFail();

        $strategy = Strategy::query()->firstOrFail()->replicate();
        $strategy->configs = $strategy->configs->merge([
            'root' => storage_path('app/secondary-uploads'),
            'url' => 'http://localhost/secondary-uploads',
        ]);
        @mkdir(storage_path('app/secondary-uploads'), 0777, true);
        $strategy->save();
        Group::query()->firstOrFail()->strategies()->attach($strategy->id);
        $this->postJson('/upload', [
            'file' => UploadedFile::fake()->createWithContent('same.png', $contents),
            'strategy_id' => $strategy->id,
        ])->assertJsonPath('status', true);
        $second = Image::query()->latest('id')->firstOrFail();
        $this->assertNotSame($first->strategy_id, $second->strategy_id);
        $this->assertSame($first->getThumbnailPathname(), $second->getThumbnailPathname());
        $thumbnail = public_path($second->getThumbnailPathname());
        $this->assertFileExists($thumbnail);

        $first->delete();
        $this->assertFalse($first->filesystem()->fileExists($first->pathname));
        $this->assertTrue($second->filesystem()->fileExists($second->pathname));
        $this->assertFileExists($thumbnail);
        $second->delete();
        $this->assertFalse($second->filesystem()->fileExists($second->pathname));
        $this->assertFileDoesNotExist($thumbnail);
    }

    public function test_upload_rejects_missing_file_and_disallowed_extension(): void
    {
        $this->postJson('/upload')->assertUnprocessable();
        $this->postJson('/upload', ['file' => UploadedFile::fake()->create('bad.txt')])
            ->assertJsonPath('status', false);
    }

    public function test_upload_enforces_size_and_rate_limits(): void
    {
        $this->configureGroup([GroupConfigKey::MaximumFileSize => 1]);
        $this->postJson('/upload', ['file' => UploadedFile::fake()->create('big.png', 2)])
            ->assertJsonPath('status', false);
        $this->configureGroup([GroupConfigKey::MaximumFileSize => 5120, GroupConfigKey::LimitPerMinute => 1]);
        $first = $this->upload();
        $this->postJson('/upload', ['file' => $this->image()])->assertJsonPath('status', false);
        $first->delete();
    }

    public function test_guest_upload_and_registration_switches(): void
    {
        $this->configureSite(ConfigKey::IsAllowGuestUpload, 0);
        $this->postJson('/upload', ['file' => $this->image()])->assertJsonPath('status', false);
        $this->configureSite(ConfigKey::IsEnableRegistration, 0);
        $this->get('/register')->assertNotFound();
    }

    public function test_account_status_capacity_and_verification_are_enforced(): void
    {
        $user = User::factory()->create();
        $user->capacity = 0;
        $user->save();
        $this->actingAs($user)->postJson('/upload', ['file' => $this->image()])->assertJsonPath('status', false);
        $user->capacity = 512000;
        $user->status = 0;
        $user->save();
        $this->postJson('/upload', ['file' => $this->image()])->assertJsonPath('status', false);
        $user->status = 1;
        $user->email_verified_at = null;
        $user->save();
        $this->configureSite(ConfigKey::IsUserNeedVerify, 1);
        $this->postJson('/upload', ['file' => $this->image()])->assertJsonPath('status', false);
    }

    public function test_image_and_album_management(): void
    {
        $user = User::factory()->create();
        $image = $this->upload($user);
        $this->getJson('/user/images')->assertJsonPath('status', true);
        $this->getJson('/user/images/'.$image->id)->assertJsonPath('status', true);
        $this->putJson('/user/images/rename', ['id' => $image->id, 'name' => 'renamed.png'])->assertJsonPath('status', true);
        $this->putJson('/user/images/permission', ['ids' => [$image->id], 'permission' => 'public'])->assertJsonPath('status', true);
        $this->assertSame(ImagePermission::Public, $image->fresh()->permission);
        $this->postJson('/user/albums', ['name' => 'My-album', 'intro' => 'Example'])->assertJsonPath('status', true);
        $album = $user->albums()->firstOrFail();
        $this->putJson('/user/images/movement', ['selected' => [$image->id], 'id' => $album->id])->assertJsonPath('status', true);
        $this->assertSame($album->id, $image->fresh()->album_id);
        $this->getJson('/user/albums')->assertJsonPath('status', true);
        $this->putJson('/user/albums/'.$album->id, ['name' => 'Updated'])->assertJsonPath('status', true);
        $this->deleteJson('/user/albums/'.$album->id)->assertJsonPath('status', true);
        $this->assertNull($image->fresh()->album_id);
        $this->deleteJson('/user/images', [$image->id])->assertJsonPath('status', true);
        $this->assertModelMissing($image);
    }

    public function test_token_api_and_revocation(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/v1/tokens', ['email' => $user->email, 'password' => 'password'])
            ->assertJsonPath('status', true)->json('data.token');
        $this->withToken($token)->getJson('/api/v1/profile')->assertJsonPath('data.email', $user->email);
        $upload = $this->postJson('/api/v1/upload', ['file' => $this->image()])->assertJsonPath('status', true);
        $this->assertSame($user->id, Image::query()->firstOrFail()->user_id);
        $this->getJson('/api/v1/images')->assertJsonPath('status', true);
        $this->getJson('/api/v1/albums')->assertJsonPath('status', true);
        $this->getJson('/api/v1/strategies')->assertJsonPath('status', true);
        $this->deleteJson('/api/v1/images/'.$upload->json('data.key'))->assertJsonPath('status', true);
        $this->deleteJson('/api/v1/tokens')->assertJsonPath('status', true);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_format_conversion(): void
    {
        $this->configureGroup([GroupConfigKey::ImageSaveFormat => 'webp']);
        $image = $this->upload();
        $this->assertSame('webp', $image->extension);
        $decoded = new \Imagick();
        $decoded->readImageBlob($image->filesystem()->read($image->pathname));
        $this->assertSame('WEBP', $decoded->getImageFormat());
        $image->delete();
    }

    public function test_text_watermark_and_protected_output(): void
    {
        $group = Group::query()->firstOrFail();
        $watermark = $group->configs->get(GroupConfigKey::WatermarkConfigs);
        $watermark['mode'] = Mode::Dynamic;
        @mkdir(storage_path('app/public'), 0777, true);
        copy('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', storage_path('app/public/test-font.ttf'));
        $watermark['drivers']['font']['font'] = 'test-font.ttf';
        $watermark['drivers']['font']['text'] = 'Container verified';
        $this->configureGroup([
            GroupConfigKey::IsEnableWatermark => true,
            GroupConfigKey::IsEnableOriginalProtection => true,
            GroupConfigKey::WatermarkConfigs => $watermark,
        ]);
        $image = $this->upload();
        $response = $this->get('/'.$image->key.'.'.$image->extension)->assertOk();
        $contents = $response->streamedContent();
        $this->assertNotSame($image->filesystem()->read($image->pathname), $contents);
        $this->assertSame($contents, $this->get('/'.$image->key.'.'.$image->extension)->streamedContent());
        $image->delete();
    }

    public static function watermarkPositionProvider(): array
    {
        return [['bottom-right'], ['tiled']];
    }

    #[DataProvider('watermarkPositionProvider')]
    public function test_image_watermark_overlay_changes_stored_pixels(string $position): void
    {
        $plain = $this->upload();
        @mkdir(storage_path('app/public'), 0777, true);
        $mark = $this->image(color: 'blue');
        copy($mark->getPathname(), storage_path('app/public/test-watermark.png'));
        $watermark = Group::query()->first()->configs->get(GroupConfigKey::WatermarkConfigs);
        $watermark['driver'] = 'image';
        $watermark['mode'] = Mode::Overlay;
        $watermark['drivers']['image']['image'] = 'test-watermark.png';
        $watermark['drivers']['image']['width'] = 80;
        $watermark['drivers']['image']['position'] = $position;
        $watermark['drivers']['image']['rotate'] = 15;
        $watermark['drivers']['image']['opacity'] = 50;
        $this->configureGroup([GroupConfigKey::IsEnableWatermark => true, GroupConfigKey::WatermarkConfigs => $watermark]);
        $marked = $this->upload();
        $this->assertNotSame($plain->md5, $marked->md5);
        $plain->delete();
        $marked->delete();
    }

    public function test_gallery_excludes_private_and_flagged_images(): void
    {
        $user = User::factory()->create();
        $public = $this->upload($user);
        $public->permission = ImagePermission::Public;
        $public->save();
        $private = $this->upload($user);
        $flagged = $this->upload($user);
        $flagged->permission = ImagePermission::Public;
        $flagged->is_unhealthy = true;
        $flagged->save();
        $response = $this->get('/gallery')->assertOk();
        $this->assertSame([$public->id], $response->viewData('images')->pluck('id')->all());
        foreach ([$public, $private, $flagged] as $image) $image->delete();
    }

    public function test_token_failures_and_api_switch(): void
    {
        $this->withToken('invalid-token')->getJson('/api/v1/profile')->assertUnauthorized();
        $this->postJson('/api/v1/upload', ['file' => $this->image()])->assertUnauthorized();
        $this->configureSite(ConfigKey::IsEnableApi, 0);
        $this->getJson('/api/v1/strategies')->assertForbidden();
    }

    public function test_users_cannot_read_or_delete_another_users_images(): void
    {
        $owner = User::factory()->create();
        $image = $this->upload($owner);
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/user/images/'.$image->id)->assertJsonPath('status', false);
        $this->deleteJson('/user/images', [$image->id])->assertJsonPath('status', true);
        $this->assertModelExists($image);
        $image->delete();
    }

    public function test_moderation_mark_and_delete_decisions(): void
    {
        // Only the decision pipeline is stubbed. Provider credentials are not available.
        Http::fake(['moderation.test/*' => Http::response(['porn' => 0.99])]);
        $scan = ['driver' => 'nsfwjs', 'drivers' => ['nsfwjs' => ['api_url' => 'http://moderation.test/scan', 'attr_name' => 'image', 'threshold' => 60]]];
        $this->configureGroup([GroupConfigKey::IsEnableScan => true, GroupConfigKey::ScanConfigs => $scan, GroupConfigKey::ScannedAction => 'mark']);
        $image = $this->upload();
        $this->assertTrue($image->is_unhealthy);
        $image->delete();
        $this->configureGroup([GroupConfigKey::ScannedAction => 'delete']);
        $this->postJson('/upload', ['file' => $this->image()])->assertJsonPath('status', false);
        $this->assertDatabaseCount('images', 0);
    }
}
