<?php
declare(strict_types=1);
namespace App\Queue;
final class RenderThumbnailDesignHandler extends ThumbnailJobHandler { protected function kind(): string { return 'design'; } }
