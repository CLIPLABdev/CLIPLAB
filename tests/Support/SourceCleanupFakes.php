<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\PrivateStorage;
use App\Contracts\SourceArtifactCleanupStore;
use App\Media\StoredObject;
use App\Queue\ClaimedJob;
use PDO;
use PDOStatement;

final class SourceCleanupPdo extends PDO
{
    public bool $active=false;
    public bool $replay=false;
    public bool $rollbackFails=false;
    public array $keys=[];
    public array $events=[];
    private array $before=[];
    public function __construct() {}
    public function beginTransaction(): bool { $this->events[]='begin'; $this->active=true; $this->before=$this->keys; return true; }
    public function commit(): bool { $this->events[]='commit'; $this->active=false; return true; }
    public function rollBack(): bool { $this->events[]='rollback'; if ($this->rollbackFails) throw new \RuntimeException('rollback failed'); $this->keys=$this->before; $this->active=false; return true; }
    public function inTransaction(): bool { return $this->active; }
    public function lastInsertId(?string $name=null): string|false { return '4'; }
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new SourceCleanupStatement($this,$query); }
}

final class SourceCleanupStatement extends PDOStatement
{
    private array $row=[];
    private int $affected=0;
    public function __construct(private SourceCleanupPdo $pdo,private string $query) {}
    public function execute(?array $params=null): bool
    {
        if (str_starts_with($this->query,'INSERT INTO projects')) $this->affected=$this->pdo->replay ? 2 : 1;
        elseif (str_starts_with($this->query,'SELECT id, status FROM projects')) $this->row=['id'=>4,'status'=>'queued'];
        elseif (str_contains($this->query,'INSERT INTO project_sources')) {
            $this->pdo->events[]='publish';
            $this->pdo->keys[]=$params['object_key'];
            $this->affected=1;
        } else throw new \LogicException('Unexpected fixture query.');
        return true;
    }
    public function rowCount(): int { return $this->affected; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->row ?: false; }
}

final class SourceCleanupStoreFake implements SourceArtifactCleanupStore
{
    public array $keys=[];
    public array $events=[];
    public bool $reserveAllowed=true;
    public ?\Throwable $reserveError=null;
    public bool $expired=false;
    public array $referenced=[];
    public ?ClaimedJob $job=null;
    public function __construct(public ?SourceCleanupPdo $pdo=null) {}
    public function reserve(string $objectKey,?ClaimedJob $job=null): bool
    {
        $this->events[]='reserve';
        $this->job=$job;
        if ($this->pdo?->inTransaction()) throw new \LogicException('Reservation must be outside publication transaction.');
        if ($this->reserveError) throw $this->reserveError;
        if ($this->reserveAllowed) $this->keys[$objectKey]=true;
        return $this->reserveAllowed;
    }
    public function lockForPublication(string $objectKey): void
    {
        $this->events[]='lock';
        if ($this->pdo && !$this->pdo->inTransaction()) throw new \LogicException('Publication lock needs transaction.');
        if ($this->expired || !isset($this->keys[$objectKey])) throw new \RuntimeException('Source reservation expired.');
    }
    public function release(string $objectKey): void
    {
        $this->events[]='release';
        if ($this->pdo && !$this->pdo->inTransaction()) throw new \LogicException('Publication release needs transaction.');
        unset($this->keys[$objectKey]);
    }
    public function pending(int $limit=25): array { return array_slice(array_keys($this->keys),0,$limit); }
    public function discard(string $objectKey,callable $delete): void
    {
        if (!isset($this->keys[$objectKey])) return;
        if (!in_array($objectKey,$this->referenced,true)) $delete();
        unset($this->keys[$objectKey]);
    }
    public function cleanupExpired(string $objectKey,callable $delete): void { $this->discard($objectKey,$delete); }
}

final class SourceCleanupStorage implements PrivateStorage
{
    public array $files=[];
    public array $deleted=[];
    public int $writes=0;
    public bool $reservedBeforeWrite=false;
    public bool $deleteFails=false;
    public ?\Throwable $writeError=null;
    public function __construct(private SourceCleanupStoreFake $store) {}
    public function putUploaded(string $temporaryPath,string $objectKey): StoredObject
    {
        $this->writes++;
        $this->reservedBeforeWrite=isset($this->store->keys[$objectKey]);
        $this->files[$objectKey]=true;
        if ($this->writeError) throw $this->writeError;
        return new StoredObject($objectKey,32,hash('sha256','test source'));
    }
    public function putStream(mixed $stream,string $objectKey,int $maxBytes): StoredObject { return $this->putUploaded('',$objectKey); }
    public function absolutePath(string $objectKey): string { throw new \LogicException('No physical storage used.'); }
    public function delete(string $objectKey): void
    {
        $this->deleted[]=$objectKey;
        if ($this->deleteFails) throw new \RuntimeException('storage cleanup failed');
        unset($this->files[$objectKey]);
    }
}
