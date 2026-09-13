<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Contracts\RenderArtifactCleanupStore;
use App\Queue\ClaimedJob;
use PDO;
/** Variable sized thumbnail batches share the durable render cleanup outbox. */
final class ThumbnailArtifactCleanupRepository implements RenderArtifactCleanupStore
{
 public function __construct(private PDO $pdo) {}
 public function reserve(ClaimedJob $job,array $objectKeys): bool { $this->validate($objectKeys);$this->pdo->beginTransaction();try{$q=$this->pdo->prepare("SELECT leased_until FROM processing_jobs WHERE id=? AND status='running' AND worker_id=? AND lease_token_hash=? AND leased_until>=UTC_TIMESTAMP() FOR UPDATE");$q->execute([$job->id(),$job->workerId(),hash('sha256',$job->leaseToken())]);$until=$q->fetchColumn();if(!$until){$this->pdo->rollBack();return false;}$q=$this->pdo->prepare('INSERT INTO render_artifact_cleanups(object_key,job_id,lease_token_hash,cleanup_after) VALUES(?,?,?,?)');foreach($objectKeys as $key)$q->execute([$key,$job->id(),hash('sha256',$job->leaseToken()),$until]);$this->pdo->commit();return true;}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;} }
 public function release(ClaimedJob $job,array $objectKeys): void { $this->validate($objectKeys);$q=$this->pdo->prepare('DELETE FROM render_artifact_cleanups WHERE object_key=? AND job_id=? AND lease_token_hash=?');foreach($objectKeys as $key){$q->execute([$key,$job->id(),hash('sha256',$job->leaseToken())]);if($q->rowCount()!==1)throw new \RuntimeException('Thumbnail reservation missing.');} }
 public function markForCleanup(ClaimedJob $job,array $objectKeys): void { $this->validate($objectKeys);$q=$this->pdo->prepare('UPDATE render_artifact_cleanups SET cleanup_after=UTC_TIMESTAMP() WHERE object_key=? AND job_id=? AND lease_token_hash=?');foreach($objectKeys as $key)$q->execute([$key,$job->id(),hash('sha256',$job->leaseToken())]); }
 public function pending(int $limit=25): array { $limit=max(1,min(100,$limit));return $this->pdo->query("SELECT object_key FROM render_artifact_cleanups WHERE object_key LIKE 'thumbnails/%' AND (cleanup_after IS NULL OR cleanup_after<UTC_TIMESTAMP()) ORDER BY created_at LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN); }
 public function forget(string $key): void { $this->validate([$key]);$q=$this->pdo->prepare('DELETE FROM render_artifact_cleanups WHERE object_key=?');$q->execute([$key]); }
 private function validate(array $keys): void { if(!$keys||count($keys)>5||count(array_unique($keys))!==count($keys))throw new \InvalidArgumentException('Thumbnail cleanup batch invalid.');foreach($keys as $key)if(!is_string($key)||preg_match('~^thumbnails/[1-9][0-9]*/[1-9][0-9]*-[a-f0-9]{32}\.jpg$~D',$key)!==1)throw new \InvalidArgumentException('Thumbnail cleanup key invalid.'); }
}
