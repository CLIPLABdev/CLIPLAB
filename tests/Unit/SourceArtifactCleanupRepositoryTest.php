<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\SourceArtifactCleanupRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceCleanupPdo;

require_once dirname(__DIR__).'/Support/SourceCleanupFakes.php';

final class SourceArtifactCleanupRepositoryTest extends TestCase
{
    public function testInvalidKeysCannotReachStorageOrDatabase(): void
    {
        $repository=new SourceArtifactCleanupRepository(new SourceCleanupPdo());
        foreach (['../.env','users/0/uploads/'.str_repeat('a',32).'.mp4','users/7/uploads/foo.mp4','imports/4/'.str_repeat('b',32).'.php',
            'processed/4/'.str_repeat('a',32).'.mp4','imports/4/../../secret','imports/4/'.str_repeat('c',32).'.mp4/extra'] as $key) {
            foreach (['reserve','lockForPublication','release','discard','cleanupExpired'] as $method) {
                try { $repository->$method($key,...(in_array($method,['discard','cleanupExpired'],true) ? [static function (): void { self::fail('Unsafe key reached delete.'); }] : [])); self::fail('Unsafe key accepted.'); }
                catch (\InvalidArgumentException) { self::assertTrue(true); }
            }
        }
    }
    public function testPublicationMethodsRequireAnExistingTransaction(): void
    {
        $repository=new SourceArtifactCleanupRepository(new SourceCleanupPdo());
        $key='users/7/uploads/'.str_repeat('a',32).'.mp4';
        foreach (['lockForPublication','release'] as $method) {
            try { $repository->$method($key); self::fail('Publication must be transactional.'); }
            catch (\LogicException) { self::assertTrue(true); }
        }
    }
    public function testReservationAndCleanupCannotRunInsidePublicationTransaction(): void
    {
        $pdo=new SourceCleanupPdo();
        $pdo->beginTransaction();
        $repository=new SourceArtifactCleanupRepository($pdo);
        $key='users/7/uploads/'.str_repeat('a',32).'.mp4';
        foreach (['reserve','discard','cleanupExpired'] as $method) {
            try { $repository->$method($key,...($method==='reserve' ? [] : [static function (): void { self::fail('Cleanup ran inside outer transaction.'); }])); self::fail('Outer transaction must be rejected.'); }
            catch (\LogicException) { self::assertTrue(true); }
        }
    }
    public function testPendingBatchesCannotExceedBoundedLimit(): void
    {
        $repository=new SourceArtifactCleanupRepository(new SourceCleanupPdo());
        foreach ([0,26,-1,100000] as $limit) {
            try { $repository->pending($limit); self::fail('Unbounded batch accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
