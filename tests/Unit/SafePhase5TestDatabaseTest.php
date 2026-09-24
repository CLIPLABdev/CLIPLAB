<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class SafePhase5TestDatabaseTest extends TestCase
{
    /**
     * @dataProvider unsafeDsnProvider
     * @param mixed $dsn
     */
    public function testUnsafeDsnIsRejectedBeforeConnectionOrWorkerLaunch($dsn): void
    {
        $connectionAttempts = 0;
        $workerLaunches = 0;
        $consumers = [
            static function (string $validatedDsn) use (&$connectionAttempts): void {
                ++$connectionAttempts;
            },
            static function (string $validatedDsn) use (&$workerLaunches): void {
                ++$workerLaunches;
            },
        ];

        foreach ($consumers as $consumer) {
            try {
                SafePhase5TestDatabase::using($dsn, $consumer);
                self::fail('Unsafe TEST_DB_DSN was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'TEST_DB_DSN must target only cliplab_phase5_test.',
                    $exception->getMessage()
                );
            }
        }

        self::assertSame(0, $connectionAttempts);
        self::assertSame(0, $workerLaunches);
    }

    /** @return array<string,array{mixed}> */
    public function unsafeDsnProvider(): array
    {
        return [
            'missing' => [false],
            'empty' => [''],
            'database absent' => ['mysql:host=127.0.0.1;port=3306;charset=utf8mb4'],
            'database empty' => ['mysql:host=127.0.0.1;dbname=;charset=utf8mb4'],
            'duplicate matching databases' => ['mysql:dbname=cliplab_phase5_test;dbname=cliplab_phase5_test'],
            'conflicting database after allowed database' => ['mysql:host=127.0.0.1;dbname=cliplab_phase5_test;dbname=production'],
            'conflicting database before allowed database' => ['mysql:host=127.0.0.1;dbname=production;dbname=cliplab_phase5_test'],
            'case aliased duplicate' => ['mysql:dbname=cliplab_phase5_test;DBNAME=production'],
            'percent encoded database key' => ['mysql:host=127.0.0.1;db%6Eame=cliplab_phase5_test'],
            'percent encoded database value' => ['mysql:host=127.0.0.1;dbname=cliplab_phase5%5Ftest'],
            'percent encoded conflicting duplicate' => ['mysql:dbname=cliplab_phase5_test;db%6Eame=production'],
            'leading whitespace alias' => ['mysql:host=127.0.0.1; dbname=cliplab_phase5_test'],
            'whitespace before equals alias' => ['mysql:host=127.0.0.1;dbname =cliplab_phase5_test'],
            'whitespace before database value' => ['mysql:host=127.0.0.1;dbname= cliplab_phase5_test'],
            'whitespace after database value' => ['mysql:host=127.0.0.1;dbname=cliplab_phase5_test '],
            'whitespace conflicting duplicate' => ['mysql:dbname=cliplab_phase5_test; dbname = production'],
            'non mysql driver' => ['sqlite:dbname=cliplab_phase5_test'],
        ];
    }

    public function testCanonicalDsnIsPassedUnchangedToConsumer(): void
    {
        $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4';

        $result = SafePhase5TestDatabase::using(
            $dsn,
            static fn (string $validatedDsn): string => $validatedDsn
        );

        self::assertSame($dsn, $result);
    }
}
