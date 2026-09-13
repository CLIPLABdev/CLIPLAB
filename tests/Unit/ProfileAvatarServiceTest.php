<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Account\ProfileAvatarService;
use App\Account\ProfileValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProfileAvatarServiceTest extends TestCase
{
    private PDO $pdo;
    private string $directory;
    private string $upload;

    protected function setUp(): void
    {
        $this->directory = dirname(__DIR__,2).'/storage/testing/profile-avatar-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700,true);
        $this->upload = $this->directory.'/upload.tmp';
        $this->pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY, status TEXT, avatar_path TEXT)');
        $this->pdo->exec("INSERT INTO users(id,status) VALUES(1,'active'),(2,'active'),(3,'suspended')");
        $chunk = static fn(string $kind,string $bytes): string => pack('N',strlen($bytes)).$kind.$bytes.pack('N',crc32($kind.$bytes));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,6,0,0,0)).$chunk('IDAT',gzcompress("\x00\xFF\x00\x00\xFF")).$chunk('IEND','');
        file_put_contents($this->upload,$png);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->directory);
    }

    public function testValidPrivateAvatarIsOwnedAndOriginalFilenameIsIgnored(): void
    {
        $service = $this->service();
        $service->store(1,$this->input());
        $path = $service->pathForUser(1);
        self::assertNotNull($path);
        self::assertSame(hash_file('sha256',$this->upload),hash_file('sha256',$path));
        self::assertStringNotContainsString('unsafe',$path);
        self::assertNull($service->pathForUser(2));
        $service->remove(1);
        self::assertNull($service->pathForUser(1));
        self::assertFileExists($path,'Previous avatar remains privately recoverable.');
    }

    public function testNonUploadedFileIsRejected(): void
    {
        $this->expectException(ProfileValidationException::class);
        (new ProfileAvatarService($this->pdo,$this->directory.'/avatars'))->store(1,$this->input());
    }

    public function testPolyglotOrCorruptPngIsRejected(): void
    {
        file_put_contents($this->upload,'<?php echo "unsafe";?>',FILE_APPEND);
        $this->expectException(ProfileValidationException::class);
        $this->service()->store(1,$this->input());
    }

    public function testOversizeAndPartialUploadsAreRejected(): void
    {
        $input = $this->input();
        $input['error'] = UPLOAD_ERR_PARTIAL;
        $this->expectException(ProfileValidationException::class);
        $this->service()->store(1,$input);
    }

    public function testForgedArrayFieldsAreRejectedWithoutWarnings(): void
    {
        $input = $this->input();
        $input['tmp_name'] = [];
        $this->expectException(ProfileValidationException::class);
        $this->service()->store(1,$input);
    }

    public function testSuspendedUserCannotSaveAvatar(): void
    {
        $this->expectException(ProfileValidationException::class);
        $this->service()->store(3,$this->input());
    }

    public function testDatabasePathCannotEscapePrivateOwnerDirectory(): void
    {
        $this->pdo->exec("UPDATE users SET avatar_path='../upload.tmp' WHERE id=1");
        self::assertNull($this->service()->pathForUser(1));
    }

    private function service(): ProfileAvatarService { return new ProfileAvatarService($this->pdo,$this->directory.'/avatars',static fn(string $path): bool=>is_file($path)); }
    private function input(): array { return ['error'=>UPLOAD_ERR_OK,'tmp_name'=>$this->upload,'name'=>'../../unsafe.php','size'=>filesize($this->upload),'type'=>'image/png']; }
}
