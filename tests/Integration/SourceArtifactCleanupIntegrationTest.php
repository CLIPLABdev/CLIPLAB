<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Contracts\JobDispatcher;
use App\Contracts\PrivateStorage;
use App\Media\DirectUrlValidator;
use App\Media\StoredObject;
use App\Media\UploadValidator;
use App\Queue\ClaimedJob;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Repositories\SourceArtifactCleanupRepository;
use App\Services\ProjectIntakeService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class SourceArtifactCleanupIntegrationTest extends TestCase
{
    private PDO $pdo;
    private PDO $other;
    private SourceArtifactCleanupRepository $repository;
    private int $userId;
    private int $projectId;
    private array $keys=[];
    private array $temporaryFiles=[];

    protected function setUp(): void
    {
        $dsn=getenv('TEST_DB_DSN');
        if (!is_string($dsn) || $dsn==='') self::markTestSkipped('TEST_DB_DSN is not configured.');
        $dsn=SafePhase5TestDatabase::validatedDsn($dsn);
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
        $this->pdo=new PDO($dsn,getenv('TEST_DB_USERNAME') ?: null,getenv('TEST_DB_PASSWORD') ?: null,$options);
        $this->other=new PDO($dsn,getenv('TEST_DB_USERNAME') ?: null,getenv('TEST_DB_PASSWORD') ?: null,$options);
        $this->repository=new SourceArtifactCleanupRepository($this->pdo);
        (new Migrator($this->pdo,dirname(__DIR__,2).'/database/migrations'))->run();
        $plan=(int)$this->pdo->query("SELECT id FROM plans WHERE slug='free'")->fetchColumn();
        $statement=$this->pdo->prepare('INSERT INTO users (name,email,password_hash,plan_id) VALUES (?,?,?,?)');
        $statement->execute(['Source cleanup fixture','cleanup-'.bin2hex(random_bytes(10)).'@example.test','not-a-login',$plan]);
        $this->userId=(int)$this->pdo->lastInsertId();
        $statement=$this->pdo->prepare("INSERT INTO projects (user_id,name,status) VALUES (?,?,'queued')");
        $statement->execute([$this->userId,'Source cleanup fixture']);
        $this->projectId=(int)$this->pdo->lastInsertId();
    }
    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) @unlink($path);
        foreach (['pdo','other'] as $property) if (isset($this->$property) && $this->$property->inTransaction()) $this->$property->rollBack();
        if (isset($this->pdo)) {
            foreach ($this->keys as $key) { $statement=$this->pdo->prepare('DELETE FROM source_artifact_cleanups WHERE object_key=?'); $statement->execute([$key]); }
            if (isset($this->userId)) { $statement=$this->pdo->prepare('DELETE FROM users WHERE id=?'); $statement->execute([$this->userId]); }
        }
    }

    public function testHttpReservationIsCommittedBeforePublicationAndExpiresAfterOneHour(): void
    {
        $key=$this->key();
        self::assertTrue($this->repository->reserve($key));
        $row=$this->row($key,$this->other);
        self::assertNotFalse($row);
        self::assertNull($row['job_id']);
        $seconds=(int)$row['seconds_left'];
        self::assertGreaterThanOrEqual(3595,$seconds);
        self::assertLessThanOrEqual(3600,$seconds);
        $this->pdo->beginTransaction();
        $this->repository->lockForPublication($key);
        $this->publish($key);
        $this->repository->release($key);
        $this->pdo->rollBack();
        self::assertNotFalse($this->row($key,$this->other));
        self::assertSame(0,$this->sourceCount($key));
    }
    public function testPublicationReleasesReservationInTheSameCommit(): void
    {
        $key=$this->key();
        $this->repository->reserve($key);
        $this->pdo->beginTransaction();
        $this->repository->lockForPublication($key);
        $this->publish($key);
        $this->repository->release($key);
        $this->pdo->commit();
        self::assertFalse($this->row($key,$this->other));
        self::assertSame(1,$this->sourceCount($key));
        $this->repository->discard($key,static function (): void { self::fail('A committed source must never be deleted.'); });
        self::assertFalse($this->row($key));
        self::assertSame(1,$this->sourceCount($key));
    }
    public function testExpiredOrCollectedReservationCannotPublishLateBytes(): void
    {
        $key=$this->key();
        $this->repository->reserve($key);
        $this->expire($key);
        $this->assertPublicationBlocked($key);
        $deleted=[];
        $this->repository->cleanupExpired($key,static function () use (&$deleted,$key): void { $deleted[]=$key; });
        self::assertSame([$key],$deleted);
        self::assertFalse($this->row($key));
        $this->assertPublicationBlocked($key);
    }
    public function testLateWriterRecreatesDurableCleanupAfterCollectorAlreadyRemovedTheRow(): void
    {
        $key=$this->key();
        $this->repository->reserve($key);
        $this->expire($key);
        $this->repository->cleanupExpired($key,static function (): void {});
        self::assertFalse($this->row($key));
        try { $this->repository->discard($key,static function (): void { throw new \RuntimeException('late delete failed'); }); self::fail('Delete failure expected.'); }
        catch (\RuntimeException $error) { self::assertSame('late delete failed',$error->getMessage()); }
        self::assertNotFalse($this->row($key,$this->other));
        self::assertLessThanOrEqual(0,(int)$this->row($key)['seconds_left']);
        $deleted=false;
        (new SourceArtifactCleanupRepository($this->other))->cleanupExpired($key,static function () use (&$deleted): void { $deleted=true; });
        self::assertTrue($deleted);
        self::assertFalse($this->row($key));
    }
    public function testDeleteFailureSurvivesARepositoryRestartAndRetriesSuccessfully(): void
    {
        $key=$this->key();
        $this->repository->reserve($key);
        $this->expire($key);
        try { $this->repository->cleanupExpired($key,static function (): void { throw new \RuntimeException('disk unavailable'); }); self::fail('Delete failure expected.'); }
        catch (\RuntimeException $error) { self::assertSame('disk unavailable',$error->getMessage()); }
        self::assertNotFalse($this->row($key,$this->other));
        $calls=0;
        (new SourceArtifactCleanupRepository($this->other))->cleanupExpired($key,static function () use (&$calls): void { $calls++; });
        self::assertSame(1,$calls);
        self::assertFalse($this->row($key));
    }
    public function testCollectorRechecksReferencesAndExpirationUnderLock(): void
    {
        $key=$this->key();
        $this->repository->reserve($key);
        $this->repository->cleanupExpired($key,static function (): void { self::fail('Active reservation must not be collected.'); });
        self::assertNotFalse($this->row($key));
        $this->pdo->beginTransaction();
        $this->repository->lockForPublication($key);
        $this->publish($key);
        $this->pdo->commit(); // Deliberately leave a stale reservation as a crash-recovery fixture.
        $this->expire($key);
        self::assertContains($key,$this->repository->pending());
        $this->repository->cleanupExpired($key,static function (): void { self::fail('Referenced source must not be collected.'); });
        self::assertFalse($this->row($key));
        self::assertSame(1,$this->sourceCount($key));
    }
    public function testCleanupWaitsForPublicationLockAndCannotDeleteTheWinner(): void
    {
        $key=$this->key();
        $this->repository->reserve($key);
        $this->pdo->beginTransaction();
        $this->repository->lockForPublication($key);
        $this->other->exec('SET SESSION innodb_lock_wait_timeout=1');
        try { (new SourceArtifactCleanupRepository($this->other))->cleanupExpired($key,static function (): void { self::fail('Cleanup bypassed the publication lock.'); }); self::fail('Lock timeout expected.'); }
        catch (\PDOException $error) { self::assertSame(1205,(int)($error->errorInfo[1] ?? 0)); }
        $this->publish($key);
        $this->repository->release($key);
        $this->pdo->commit();
        (new SourceArtifactCleanupRepository($this->other))->cleanupExpired($key,static function (): void { self::fail('Published winner must survive.'); });
        self::assertSame(1,$this->sourceCount($key));
    }
    public function testImportReservationUsesRealLeaseAndRejectsAStaleClaim(): void
    {
        $token=str_repeat('a',64);
        $statement=$this->pdo->prepare("INSERT INTO processing_jobs (type,project_id,payload_json,idempotency_key,status,available_at,worker_id,lease_token_hash,leased_until,attempts) VALUES ('fetch_and_probe',?,'{}',?,'running',UTC_TIMESTAMP(),'source-fixture',?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 MINUTE),1)");
        $statement->execute([$this->projectId,hash('sha256',random_bytes(20)),hash('sha256',$token)]);
        $jobId=(int)$this->pdo->lastInsertId();
        $job=new ClaimedJob($jobId,'media','fetch_and_probe',$this->projectId,[],'source-fixture',$token,1,3);
        $key=$this->key(true);
        self::assertTrue($this->repository->reserve($key,$job));
        self::assertSame($jobId,(int)$this->row($key)['job_id']);
        $lease=$this->pdo->query('SELECT leased_until FROM processing_jobs WHERE id='.$jobId)->fetchColumn();
        self::assertSame($lease,$this->row($key)['cleanup_after']);
        $stale=new ClaimedJob($jobId,'media','fetch_and_probe',$this->projectId,[],'source-fixture',str_repeat('b',64),1,3);
        $otherKey=$this->key(true);
        self::assertFalse($this->repository->reserve($otherKey,$stale));
        self::assertFalse($this->row($otherKey));
        $statement=$this->pdo->prepare('UPDATE processing_jobs SET leased_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?');
        $statement->execute([$jobId]);
        $expiredKey=$this->key(true);
        self::assertFalse($this->repository->reserve($expiredKey,$job));
        self::assertFalse($this->row($expiredKey));
        $statement=$this->pdo->prepare('DELETE FROM processing_jobs WHERE id=?');
        $statement->execute([$jobId]);
        self::assertNotFalse($this->row($key)); // No cascade may erase cleanup obligations.
    }

    public function testRealIntakeReleasesPublishedSourceButKeepsFailedReplayCleanupDurably(): void
    {
        [$service,$storage,$file]=$this->intake();
        $input=['name'=>'Durable upload','idempotency_key'=>'durable-upload'];
        $first=$service->fromUpload($this->userId,$input,$file);
        self::assertTrue($first->created());
        self::assertTrue($storage->reservedBeforeWrite);
        $original=array_key_first($storage->files);
        self::assertSame(1,$this->sourceCount($original));
        self::assertFalse($this->row($original));
        $storage->deleteFails=true;
        $replay=$service->fromUpload($this->userId,$input,$file);
        self::assertFalse($replay->created());
        self::assertSame($first->projectId(),$replay->projectId());
        $duplicate=array_key_last($storage->files);
        self::assertNotSame($original,$duplicate);
        self::assertNotFalse($this->row($duplicate,$this->other));
        self::assertSame(0,$this->sourceCount($duplicate));
        self::assertSame(1,$this->sourceCount($original));
        $storage->deleteFails=false;
        $this->repository->cleanupExpired($duplicate,static fn ()=>$storage->delete($duplicate));
        self::assertSame([$original],array_keys($storage->files));
        self::assertFalse($this->row($duplicate));
    }
    public function testRealIntakeRollbackAndFailedDeleteKeepTheOriginalErrorAndCommittedOutbox(): void
    {
        $failure=new \RuntimeException('original dispatch failure');
        [$service,$storage,$file]=$this->intake($failure);
        $storage->deleteFails=true;
        try { $service->fromUpload($this->userId,['name'=>'Rollback upload','idempotency_key'=>'rollback-upload'],$file); self::fail('Dispatch failure expected.'); }
        catch (\RuntimeException $caught) { self::assertSame($failure,$caught); }
        $key=array_key_first($storage->files);
        self::assertTrue($storage->reservedBeforeWrite);
        self::assertNotFalse($this->row($key,$this->other));
        self::assertSame(0,$this->sourceCount($key));
        self::assertFalse($this->pdo->inTransaction());
        $storage->deleteFails=false;
        $this->repository->cleanupExpired($key,static fn ()=>$storage->delete($key));
        self::assertSame([],$storage->files);
        self::assertFalse($this->row($key));
    }
    public function testReleaseCannotDropCleanupWithoutPublishingASource(): void
    {
        $key=$this->key();
        $this->repository->reserve($key);
        $this->pdo->beginTransaction();
        try { $this->repository->release($key); self::fail('Release without publication must fail.'); }
        catch (\RuntimeException $error) { self::assertSame('Source publication must precede reservation release.',$error->getMessage()); }
        finally { $this->pdo->rollBack(); }
        self::assertNotFalse($this->row($key,$this->other));
    }

    private function intake(?\Throwable $failure=null): array
    {
        $track=function (string $key): bool { $this->keys[]=$key; return $this->row($key,$this->other)!==false; };
        $storage=new class($track) implements PrivateStorage {
            public array $files=[];
            public bool $reservedBeforeWrite=false;
            public bool $deleteFails=false;
            public function __construct(private $track) {}
            public function putUploaded(string $path,string $key): StoredObject { $this->reservedBeforeWrite=($this->track)($key); $this->files[$key]=true; return new StoredObject($key,32,hash('sha256','fixture')); }
            public function putStream(mixed $stream,string $key,int $maxBytes): StoredObject { throw new \LogicException('Not used by upload.'); }
            public function absolutePath(string $key): string { throw new \LogicException('Not used by upload.'); }
            public function delete(string $key): void { if ($this->deleteFails) throw new \RuntimeException('delete failed'); unset($this->files[$key]); }
        };
        $jobs=new class($failure) implements JobDispatcher {
            public function __construct(private ?\Throwable $failure) {}
            public function dispatch(string $type,int $projectId,array $payload,string $key): int { if ($this->failure) throw $this->failure; return 1; }
        };
        $service=new ProjectIntakeService($this->pdo,new ProjectRepository($this->pdo),new ProjectSourceRepository($this->pdo),$storage,$jobs,
            new UploadValidator(1024,static fn (): string=>'video/mp4'),new DirectUrlValidator(static fn (): array=>['1.1.1.1']),null,$this->repository);
        $path=tempnam(sys_get_temp_dir(),'source-db-fixture-');
        $this->temporaryFiles[]=$path;
        file_put_contents($path,"\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41");
        $file=['name'=>'test.mp4','tmp_name'=>$path,'size'=>filesize($path),'error'=>UPLOAD_ERR_OK];
        return [$service,$storage,$file];
    }

    private function key(bool $import=false): string
    {
        $key=($import ? 'imports/'.$this->projectId : 'users/'.$this->userId.'/uploads').'/'.bin2hex(random_bytes(16)).'.mp4';
        $this->keys[]=$key;
        return $key;
    }
    private function row(string $key,?PDO $pdo=null): array|false
    {
        $statement=($pdo ?? $this->pdo)->prepare('SELECT *,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),cleanup_after) AS seconds_left FROM source_artifact_cleanups WHERE object_key=?');
        $statement->execute([$key]);
        return $statement->fetch();
    }
    private function expire(string $key): void
    {
        $statement=$this->pdo->prepare('UPDATE source_artifact_cleanups SET cleanup_after=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE object_key=?');
        $statement->execute([$key]);
    }
    private function publish(string $key): void
    {
        $statement=$this->pdo->prepare("INSERT INTO project_sources (project_id,source_type,object_key,status) VALUES (?,'upload',?,'stored')");
        $statement->execute([$this->projectId,$key]);
    }
    private function sourceCount(string $key): int
    {
        $statement=$this->pdo->prepare('SELECT COUNT(*) FROM project_sources WHERE object_key=?');
        $statement->execute([$key]);
        return (int)$statement->fetchColumn();
    }
    private function assertPublicationBlocked(string $key): void
    {
        $this->pdo->beginTransaction();
        try { $this->repository->lockForPublication($key); self::fail('Expired or missing reservation must block publication.'); }
        catch (\RuntimeException $error) { self::assertSame('Source reservation is missing or expired.',$error->getMessage()); }
        finally { $this->pdo->rollBack(); }
    }
}
