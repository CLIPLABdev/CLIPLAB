<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\JobDispatcher;
use App\Contracts\MediaProcessor;
use App\Contracts\PrivateStorage;
use App\Contracts\ProjectCreator;
use App\Media\MediaMetadata;
use App\Media\ProjectReceipt;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class MediaContractsTest extends TestCase
{
    public function testValueObjectsExposeValidatedImmutableState(): void
    {
        $stored = new StoredObject('users/7/source.mp4', 123, str_repeat('a', 64));
        $receipt = new ProjectReceipt(9, 'queued', true);
        $source = new ProjectSource(11, 9, 'local', 'users/7/source.mp4', 'video/mp4');
        $metadata = new MediaMetadata(90, 1920, 1080, 'h264', 'aac', true);

        self::assertSame('users/7/source.mp4', $stored->objectKey());
        self::assertSame(123, $stored->sizeBytes());
        self::assertSame(str_repeat('a', 64), $stored->sha256());
        self::assertSame(9, $receipt->projectId());
        self::assertSame('queued', $receipt->status());
        self::assertTrue($receipt->created());
        self::assertSame(11, $source->id());
        self::assertSame(9, $source->projectId());
        self::assertSame('local', $source->storageDisk());
        self::assertSame('users/7/source.mp4', $source->objectKey());
        self::assertSame('video/mp4', $source->mimeType());
        self::assertSame(90, $metadata->durationSeconds());
        self::assertSame(1920, $metadata->width());
        self::assertSame(1080, $metadata->height());
        self::assertSame('h264', $metadata->videoCodec());
        self::assertSame('aac', $metadata->audioCodec());
        self::assertTrue($metadata->hasAudio());

        foreach ([StoredObject::class, ProjectReceipt::class, ProjectSource::class, MediaMetadata::class] as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertTrue($property->isPrivate(), $class . ' state must be private.');
            }
        }
    }

    /** @dataProvider invalidValueObjects */
    public function testValueObjectsRejectInvalidDomainState(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): object}> */
    public function invalidValueObjects(): iterable
    {
        yield 'stored object empty key' => [static fn (): StoredObject => new StoredObject('', 1, str_repeat('a', 64))];
        yield 'stored object negative size' => [static fn (): StoredObject => new StoredObject('a.mp4', -1, str_repeat('a', 64))];
        yield 'stored object invalid hash' => [static fn (): StoredObject => new StoredObject('a.mp4', 1, 'bad')];
        yield 'receipt invalid project' => [static fn (): ProjectReceipt => new ProjectReceipt(0, 'queued', true)];
        yield 'receipt empty status' => [static fn (): ProjectReceipt => new ProjectReceipt(1, '', true)];
        yield 'source invalid id' => [static fn (): ProjectSource => new ProjectSource(0, 1, 'local', 'a.mp4', 'video/mp4')];
        yield 'source invalid project' => [static fn (): ProjectSource => new ProjectSource(1, 0, 'local', 'a.mp4', 'video/mp4')];
        yield 'source empty disk' => [static fn (): ProjectSource => new ProjectSource(1, 1, '', 'a.mp4', 'video/mp4')];
        yield 'source empty object key' => [static fn (): ProjectSource => new ProjectSource(1, 1, 'local', '', 'video/mp4')];
        yield 'source empty mime' => [static fn (): ProjectSource => new ProjectSource(1, 1, 'local', 'a.mp4', '')];
        yield 'metadata negative duration' => [static fn (): MediaMetadata => new MediaMetadata(-1, 1, 1, 'h264', null, false)];
        yield 'metadata zero width' => [static fn (): MediaMetadata => new MediaMetadata(1, 0, 1, 'h264', null, false)];
        yield 'metadata zero height' => [static fn (): MediaMetadata => new MediaMetadata(1, 1, 0, 'h264', null, false)];
        yield 'metadata empty codec' => [static fn (): MediaMetadata => new MediaMetadata(1, 1, 1, '', null, false)];
        yield 'metadata missing audio codec' => [static fn (): MediaMetadata => new MediaMetadata(1, 1, 1, 'h264', null, true)];
        yield 'metadata unexpected audio codec' => [static fn (): MediaMetadata => new MediaMetadata(1, 1, 1, 'h264', 'aac', false)];
    }

    public function testStableInterfacesDeclareTheExpectedOperations(): void
    {
        self::assertEqualsCanonicalizing(['absolutePath', 'delete', 'putStream', 'putUploaded'], get_class_methods(PrivateStorage::class));
        self::assertEqualsCanonicalizing(['fromDirectUrl', 'fromUpload'], get_class_methods(ProjectCreator::class));
        self::assertSame(['dispatch'], get_class_methods(JobDispatcher::class));
        self::assertSame(['inspect'], get_class_methods(MediaProcessor::class));

        self::assertSame(
            [
                ['temporaryPath:string', 'objectKey:string', 'return:App\\Media\\StoredObject'],
                ['stream:mixed', 'objectKey:string', 'maxBytes:int', 'return:App\\Media\\StoredObject'],
                ['objectKey:string', 'return:string'],
                ['objectKey:string', 'return:void'],
            ],
            array_map(fn (string $method): array => $this->signature(PrivateStorage::class, $method), get_class_methods(PrivateStorage::class))
        );
        self::assertSame(
            [
                ['userId:int', 'input:array', 'file:array', 'return:App\\Media\\ProjectReceipt'],
                ['userId:int', 'input:array', 'return:App\\Media\\ProjectReceipt'],
            ],
            array_map(fn (string $method): array => $this->signature(ProjectCreator::class, $method), get_class_methods(ProjectCreator::class))
        );
        self::assertSame(['type:string', 'projectId:int', 'payload:array', 'idempotencyKey:string', 'return:int'], $this->signature(JobDispatcher::class, 'dispatch'));
        self::assertSame(['source:App\\Media\\ProjectSource', 'return:App\\Media\\MediaMetadata'], $this->signature(MediaProcessor::class, 'inspect'));
    }

    /** @return list<string> */
    private function signature(string $class, string $method): array
    {
        $reflection = new ReflectionMethod($class, $method);
        $signature = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            $signature[] = $parameter->getName() . ':' . $type->getName();
        }
        $returnType = $reflection->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        $signature[] = 'return:' . $returnType->getName();

        return $signature;
    }
}
