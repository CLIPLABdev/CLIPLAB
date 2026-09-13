<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MediaProcessor;
use App\Media\PreciseSourceDuration;
use App\Media\ProjectSource;
use App\Repositories\ProjectSourceRepository;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class SourceDurationPreflight
{
    public function __construct(private PDO $pdo, private ProjectSourceRepository $sources, private MediaProcessor $processor) {}

    /** Local web composition; construction does not inspect media or start a process. */
    public static function local(PDO $pdo,array $media): self
    {
        $root=(string)($media['private_root'] ?? '');
        $ffprobe=(string)($media['ffprobe_binary'] ?? 'ffprobe');
        $storage=new \App\Storage\LocalPrivateStorage($root,(int)($media['effective_upload_bytes'] ?? $media['max_upload_bytes'] ?? 524288000));
        $processor=new \App\Media\LocalFfprobeProcessor($storage,new \App\Process\ProcessRunner([$ffprobe],$root),$ffprobe,
            (int)($media['process_timeout_seconds'] ?? 60),(int)($media['process_output_limit_bytes'] ?? 1048576));
        return new self($pdo,new ProjectSourceRepository($pdo),$processor);
    }

    public function prepare(int $projectId, int $userId): ?PreciseSourceDuration
    {
        if ($this->pdo->inTransaction()) throw new LogicException('Source measurement requires no transaction.');
        $identity = $this->sources->findReadyIdentityForOwnedProject($projectId,$userId);
        if ($identity === null) return null;
        try {
            $metadata = $this->processor->inspect(new ProjectSource(
                $identity['id'],$identity['project_id'],$identity['storage_disk'],$identity['object_key'],$identity['mime_type']
            ));
            $milliseconds = $metadata->durationMilliseconds();
            if ($milliseconds === null || $metadata->durationSeconds() !== $identity['duration_seconds']) {
                throw new RuntimeException('Precise source measurement is unavailable.');
            }
            return new PreciseSourceDuration($identity,$milliseconds);
        } catch (Throwable) {
            throw new RuntimeException('Precise source measurement is unavailable.');
        }
    }

    public function assertCurrent(PreciseSourceDuration $snapshot, int $projectId, int $userId): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Source identity check requires a transaction.');
        if ($snapshot->projectId() !== $projectId || $snapshot->ownerId() !== $userId) {
            throw new RuntimeException('Source identity changed after measurement.');
        }
        $current = $this->sources->findReadyIdentityForOwnedProject($projectId,$userId,true);
        if ($current === null || !$snapshot->matches($current)) {
            throw new RuntimeException('Source identity changed after measurement.');
        }
    }
}
