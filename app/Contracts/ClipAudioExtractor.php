<?php
declare(strict_types=1);
namespace App\Contracts;
use App\Media\ProjectSource;
interface ClipAudioExtractor { public function extract(ProjectSource $source,float $startTime,float $durationSeconds):string; }
