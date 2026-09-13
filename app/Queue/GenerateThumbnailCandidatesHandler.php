<?php
declare(strict_types=1);
namespace App\Queue;
final class GenerateThumbnailCandidatesHandler extends ThumbnailJobHandler { protected function kind(): string { return 'set'; } }
