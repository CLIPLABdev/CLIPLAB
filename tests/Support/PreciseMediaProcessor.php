<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\MediaProcessor;
use App\Media\MediaMetadata;
use App\Media\ProjectSource;
use PDO;

final class PreciseMediaProcessor implements MediaProcessor
{
    public int $calls = 0;
    public bool $fail = false;
    public ?\Closure $afterInspect = null;
    public function __construct(private PDO $pdo, public int $milliseconds) {}
    public function inspect(ProjectSource $source): MediaMetadata
    {
        if ($this->pdo->inTransaction()) throw new \LogicException('Test detected probe inside transaction.');
        ++$this->calls;
        if ($this->fail) throw new \RuntimeException('Private probe failure.');
        $result = new MediaMetadata((int)ceil($this->milliseconds/1000),1920,1080,'h264','aac',true,$this->milliseconds);
        if ($this->afterInspect !== null) ($this->afterInspect)();
        return $result;
    }
}
