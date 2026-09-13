<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Media\Thumbnails\ThumbnailOptions;
use PHPUnit\Framework\TestCase;
final class ThumbnailOptionsTest extends TestCase
{
 public function test_rejects_arbitrary_paths_and_arguments(): void { $this->expectException(\InvalidArgumentException::class); ThumbnailOptions::fromArray(['ffmpeg_args'=>'-i /secret']); }
 public function test_preserves_literal_text_and_rejects_overlong_title(): void { self::assertSame("Olá {\\pos(1,2)}",ThumbnailOptions::fromArray(['title'=>"Olá {\\pos(1,2)}"])->toArray()['title']); $this->expectException(\InvalidArgumentException::class); ThumbnailOptions::fromArray(['title'=>str_repeat('é',121)]); }
 public function test_bounded_candidates_are_distinct_and_avoid_first_frame(): void { $times=ThumbnailOptions::candidateTimes(10); self::assertCount(15,$times); self::assertGreaterThan(0,$times[0]); self::assertLessThan(10,end($times)); self::assertCount(15,array_unique($times)); }
 public function test_time_must_be_inside_clip(): void { $this->expectException(\InvalidArgumentException::class); ThumbnailOptions::offset('10',10); }
}
