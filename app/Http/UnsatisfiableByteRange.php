<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class UnsatisfiableByteRange extends RuntimeException
{
}
