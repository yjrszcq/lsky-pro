<?php

namespace Tests\Feature;

use App\Enums\StrategyKey;
use App\Models\Strategy;
use App\Models\Group;
use App\Models\Image;
use Illuminate\Http\UploadedFile;
use App\Services\ImageService;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

#[TestGroup('integration')]
class StorageIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('LSKY_INTEGRATION') !== '1') {
            $this->markTestSkipped('Run compose.test.yaml to start the protocol fixtures.');
        }
    }

    public static function storageProvider(): array
    {
        $password = 'container-test-password';
        return [
            'FTP' => [StrategyKey::Ftp, ['host' => 'fixtures', 'port' => 21, 'root' => '/', 'username' => 'integration', 'password' => $password, 'ssl' => false, 'passive' => true]],
            'SFTP' => [StrategyKey::Sftp, ['host' => 'fixtures', 'port' => 22, 'root' => '/data/sftp', 'username' => 'integration', 'password' => $password, 'private_key' => null, 'passphrase' => '', 'use_agent' => false]],
            'WebDAV' => [StrategyKey::Webdav, ['base_uri' => 'http://fixtures:8080/', 'username' => '', 'password' => '', 'auth_type' => 0, 'prefix' => '']],
            // Moto provides a real HTTP S3 protocol fixture, not vendor cloud availability.
            'S3 protocol' => [StrategyKey::S3, ['access_key_id' => 'test', 'secret_access_key' => 'test', 'endpoint' => 'http://fixtures:5000', 'region' => 'us-east-1', 'bucket' => 'lsky-tests']],
            'MinIO adapter protocol' => [StrategyKey::Minio, ['access_key' => 'test', 'secret_key' => 'test', 'endpoint' => 'http://fixtures:5000', 'region' => 'us-east-1', 'bucket' => 'lsky-tests', 'bucket_endpoint' => false]],
        ];
    }

    #[DataProvider('storageProvider')]
    public function test_storage_write_read_list_and_delete(int $driver, array $configs): void
    {
        if (in_array($driver, [StrategyKey::S3, StrategyKey::Minio])) {
            $client = new S3Client(['version' => '2006-03-01', 'region' => 'us-east-1', 'endpoint' => 'http://fixtures:5000', 'credentials' => ['key' => 'test', 'secret' => 'test'], 'use_path_style_endpoint' => true]);
            $client->createBucket(['Bucket' => 'lsky-tests']);
        }
        if ($driver === StrategyKey::S3) {
            $configs['endpoint'] = 'http://'.gethostbyname('fixtures').':5000';
        }
        $strategy = new Strategy(['key' => $driver, 'configs' => $configs]);
        $filesystem = new \League\Flysystem\Filesystem(app(ImageService::class)->getAdapter($strategy));
        $path = 'container-'.bin2hex(random_bytes(4)).'/image.png';
        $contents = 'PHP '.PHP_VERSION.' storage test';
        $filesystem->write($path, $contents);
        $this->assertTrue($filesystem->fileExists($path));
        $this->assertSame($contents, $filesystem->read($path));
        $this->assertNotEmpty(iterator_to_array($filesystem->listContents(dirname($path))));
        $filesystem->delete($path);
        $this->assertFalse($filesystem->fileExists($path));
        $filesystem->deleteDirectory(dirname($path));

        $strategy->name = 'Protocol upload';
        $strategy->configs = $strategy->configs->merge(['url' => 'http://fixtures/files']);
        $strategy->save();
        Group::query()->firstOrFail()->strategies()->attach($strategy->id);
        $image = new \Imagick();
        $image->newImage(80, 60, 'blue');
        $image->setImageFormat('png');
        $file = UploadedFile::fake()->createWithContent('protocol.png', $image->getImagesBlob());
        $this->postJson('/upload', ['file' => $file, 'strategy_id' => $strategy->id])->assertJsonPath('status', true);
        $uploaded = Image::query()->firstOrFail();
        $this->assertSame($strategy->id, $uploaded->strategy_id);
        $this->assertTrue($filesystem->fileExists($uploaded->pathname));
        $this->assertFileExists(public_path($uploaded->getThumbnailPathname()));
        $uploaded->delete();
        $this->assertFalse($filesystem->fileExists($uploaded->pathname));
    }

    public function test_redis_cache_round_trip(): void
    {
        $cache = Cache::store('redis');
        $cache->put('container-verification', ['php' => PHP_VERSION], 60);
        $this->assertSame(['php' => '8.5.12'], $cache->get('container-verification'));
        $cache->forget('container-verification');
        $this->assertNull($cache->get('container-verification'));
    }

    public function test_smtp_transport_delivers_message(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'fixtures', 'port' => 8025, 'encryption' => null, 'username' => null, 'password' => null], 'mail.from.address' => 'sender@example.test']);
        $subject = 'Container test '.bin2hex(random_bytes(4));
        Mail::raw('PHP 8.5.12 SMTP delivery', fn ($mail) => $mail->to('recipient@example.test')->subject($subject));
        $messages = Http::get('http://fixtures:8070/messages')->json('messages');
        $this->assertStringContainsString($subject, implode("\n", $messages));
    }
}
