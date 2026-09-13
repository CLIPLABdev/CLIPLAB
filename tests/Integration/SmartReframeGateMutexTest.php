<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SafePhase5TestDatabase;
use Tests\Support\SmartReframeGateMutex;

final class SmartReframeGateMutexTest extends TestCase
{
    public function testContentionFailsAtomicallyAndTheSameContenderCanAcquireAfterRelease(): void
    {
        $owner = $this->connection();
        $contender = $this->connection();
        $held = SmartReframeGateMutex::acquire($owner, 0);
        try {
            try {
                SmartReframeGateMutex::acquire($contender, 0);
                self::fail('A second smart-reframe gate acquired the shared lock concurrently.');
            } catch (RuntimeException $exception) {
                self::assertSame('The smart reframe test gate is busy.', $exception->getMessage());
            }
        } finally {
            $held->release();
        }

        $afterRelease = SmartReframeGateMutex::acquire($contender, 0);
        $afterRelease->release();
        self::assertTrue(true);
    }

    private function connection(): PDO
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $username = getenv('TEST_DB_USERNAME');
        $password = getenv('TEST_DB_PASSWORD');
        self::assertIsString($username);
        self::assertIsString($password);

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
