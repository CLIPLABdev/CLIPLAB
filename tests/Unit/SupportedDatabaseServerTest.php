<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SupportedDatabaseServer;

final class SupportedDatabaseServerTest extends TestCase
{
    public function testRejectsUnknownNumericEightSeriesServerIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported test database engine.');

        SupportedDatabaseServer::identify('8.9.1-UnknownDB', 'Enterprise SQL Distribution');
    }

    public function testPositivelyIdentifiesSupportedServerMetadata(): void
    {
        self::assertSame('mariadb', SupportedDatabaseServer::identify(
            '10.4.32-MariaDB',
            'mariadb.org binary distribution'
        ));
        self::assertSame('mysql', SupportedDatabaseServer::identify(
            '8.0.16',
            'MySQL Community Server - GPL'
        ));
    }
}
