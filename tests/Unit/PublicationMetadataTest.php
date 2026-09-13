<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Media\Thumbnails\PublicationMetadata;
use PHPUnit\Framework\TestCase;
final class PublicationMetadataTest extends TestCase
{
 public function test_normalizes_hashtags_without_accepting_other_fields(): void { self::assertSame(['#café','#video'],PublicationMetadata::normalize(['platform'=>'youtube','hashtags'=>'#café video'])['hashtags']); $this->expectException(\InvalidArgumentException::class); PublicationMetadata::normalize(['platform'=>'youtube','access_token'=>'secret']); }
 public function test_rejects_fake_platform(): void { $this->expectException(\InvalidArgumentException::class); PublicationMetadata::normalize(['platform'=>'remote_post']); }
 public function test_rejects_too_many_tags(): void { $this->expectException(\InvalidArgumentException::class); PublicationMetadata::normalize(['platform'=>'youtube','hashtags'=>array_fill(0,31,'a')]); }
}
