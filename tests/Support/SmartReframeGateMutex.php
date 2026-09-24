<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class SmartReframeGateMutex
{
    private const LOCK_NAME = 'cliplab_phase5_smart_reframe_gate_v1';

    private bool $held = true;

    private function __construct(private PDO $pdo)
    {
    }

    public static function acquire(PDO $pdo, int $timeoutSeconds): self
    {
        if ($timeoutSeconds < 0 || $timeoutSeconds > 120) {
            throw new InvalidArgumentException('The smart reframe test gate timeout must be between 0 and 120 seconds.');
        }

        $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $statement->bindValue(':lock_name', self::LOCK_NAME, PDO::PARAM_STR);
        $statement->bindValue(':timeout_seconds', $timeoutSeconds, PDO::PARAM_INT);
        $statement->execute();

        if ((string) $statement->fetchColumn() !== '1') {
            throw new RuntimeException('The smart reframe test gate is busy.');
        }

        return new self($pdo);
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->bindValue(':lock_name', self::LOCK_NAME, PDO::PARAM_STR);
        $statement->execute();
        if ((string) $statement->fetchColumn() !== '1') {
            throw new RuntimeException('The smart reframe test gate could not be released.');
        }

        $this->held = false;
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (Throwable) {
            // Closing the owning PDO connection releases the advisory lock as a fallback.
        }
    }
}
