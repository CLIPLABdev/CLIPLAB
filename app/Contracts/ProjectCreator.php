<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Media\ProjectReceipt;

interface ProjectCreator
{
    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt;

    public function fromDirectUrl(int $userId, array $input): ProjectReceipt;
}
