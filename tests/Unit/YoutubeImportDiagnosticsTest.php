<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Media\{DirectUrlValidator,ValidatedYoutubeUrl,YtDlpYoutubeResolver};
use App\Exceptions\MediaValidationException;
use App\Process\{ProcessRunner,ProcessResult};
use PHPUnit\Framework\TestCase;

final class YoutubeImportDiagnosticsTest extends TestCase
{
    private function url():ValidatedYoutubeUrl{return new ValidatedYoutubeUrl('https://www.youtube.com/watch?v=O1FZD5Zove0','www.youtube.com','O1FZD5Zove0');}
    private function metadata():array{return ['_type'=>'video','extractor_key'=>'Youtube','id'=>'O1FZD5Zove0','availability'=>'public','is_live'=>false,'live_status'=>'not_live','age_limit'=>0,'duration'=>60,'ext'=>'mp4','protocol'=>'https','vcodec'=>'avc1.42001E','acodec'=>'mp4a.40.2','url'=>'https://v.googlevideo.com/large','filesize'=>900];}
    private function resolver(YoutubeDiagnosticRunner $runner,?callable $report=null):YtDlpYoutubeResolver{return new YtDlpYoutubeResolver($runner,new DirectUrlValidator(static fn()=>['8.8.8.8']),'yt-dlp',60,1048576,null,true,$report);}
    public function testProjectsMetadataInsteadOfDumpingDescriptionsAndSubtitleManifests():void
    {
        $runner=new YoutubeDiagnosticRunner(new ProcessResult(0,json_encode($this->metadata()),''));$this->resolver($runner)->resolve($this->url());
        self::assertContains('--print',$runner->command,'Bounded output must use a metadata projection');
        self::assertNotContains('--dump-single-json',$runner->command);
        $template=$runner->command[array_search('--print',$runner->command,true)+1];
        self::assertStringContainsString('requested_formats',$template);self::assertStringNotContainsString('description',$template);self::assertStringNotContainsString('subtitles',$template);
        self::assertSame(1048576,$runner->limit);
    }
    /** @dataProvider failures */
    public function testClassifiesFailuresWithoutLeakingStderr(string $stderr,string $expected):void
    {
        $events=[];$runner=new YoutubeDiagnosticRunner(new ProcessResult(1,'',$stderr.' https://secret.example/token?key=very-secret'));
        try{$this->resolver($runner,static function(array $event)use(&$events){$events[]=$event;})->resolve($this->url());self::fail('Expected rejection');}
        catch(MediaValidationException $e){self::assertSame($expected,$e->publicCode());self::assertStringNotContainsString('secret',$e->getMessage());}
        self::assertNotEmpty($events);self::assertStringNotContainsString('secret',json_encode($events));self::assertSame($expected,end($events)['result_code']);
    }
    public static function failures():iterable
    {
        yield ['ERROR: Sign in to confirm you’re not a bot','youtube_bot_challenge'];
        yield ['ERROR: Sign in to confirm your age','youtube_age_restricted'];
        yield ['ERROR: This video is not available in your country','youtube_region_restricted'];
        yield ['ERROR: Private video. Sign in if granted access','youtube_private_video'];
        yield ['ERROR: HTTP Error 429: Too Many Requests','youtube_rate_limited'];
        yield ['ERROR: Unable to download webpage: Connection timed out','youtube_network_failed'];
        yield ['ERROR: Requested format is not available','youtube_format_unavailable'];
    }
    public function testChoosesSmallerCompatibleVideoBeforeDownloadingAboveBudget():void
    {
        $metadata=$this->metadata();unset($metadata['url']);
        $video=['ext'=>'mp4','protocol'=>'https','vcodec'=>'avc1.4d400c','acodec'=>'none','url'=>'https://v.googlevideo.com/large','filesize'=>900,'height'=>1080];
        $small=array_replace($video,['url'=>'https://v.googlevideo.com/small','filesize'=>400,'height'=>720]);
        $audio=['ext'=>'m4a','protocol'=>'https','vcodec'=>'none','acodec'=>'mp4a.40.2','url'=>'https://a.googlevideo.com/original','filesize'=>100,'language'=>'pt'];
        $metadata['requested_formats']=[$video,$audio];$metadata['formats']=[$audio,$small,$video];
        $runner=new YoutubeDiagnosticRunner(new ProcessResult(0,json_encode($metadata),''));
        $media=$this->resolver($runner)->resolveWithinLimit($this->url(),1000);
        self::assertTrue($media->isAdaptive());self::assertSame('https://v.googlevideo.com/small',$media->tracks()[0]->url()->url());self::assertSame('https://a.googlevideo.com/original',$media->tracks()[1]->url()->url());
    }
    public function testReportsSizeLimitBeforeAnyDownloadIfNoFormatFits():void
    {
        $runner=new YoutubeDiagnosticRunner(new ProcessResult(0,json_encode($this->metadata()),''));
        try{$this->resolver($runner)->resolveWithinLimit($this->url(),100);self::fail('Expected size rejection');}catch(MediaValidationException $e){self::assertSame('media_too_large',$e->publicCode());}
    }
    public function testBrokenDiagnosticSinkDoesNotBreakSuccessfulResolution():void
    {
        $runner=new YoutubeDiagnosticRunner(new ProcessResult(0,json_encode($this->metadata()),''));
        $media=$this->resolver($runner,static function(){throw new \RuntimeException('log offline');})->resolve($this->url());self::assertSame('v.googlevideo.com',$media->url()->host());
    }
    public function testHugeIntegerSizeCannotOverflowIntoAnAllowedSize():void
    {
        $metadata=array_replace($this->metadata(),['filesize'=>PHP_INT_MAX-1]);
        $runner=new YoutubeDiagnosticRunner(new ProcessResult(0,json_encode($metadata),''));
        try{$this->resolver($runner)->resolveWithinLimit($this->url(),500);self::fail('Expected size rejection');}catch(MediaValidationException $e){self::assertSame('media_too_large',$e->publicCode());}
    }
    public function testUnknownDrmCandidateIsNeverSelected():void
    {
        $metadata=$this->metadata();$metadata['formats']=[array_replace($metadata,['filesize'=>100,'has_drm'=>null])];
        $runner=new YoutubeDiagnosticRunner(new ProcessResult(0,json_encode($metadata),''));
        try{$this->resolver($runner)->resolveWithinLimit($this->url(),500);self::fail('Expected rejection');}catch(MediaValidationException $e){self::assertSame('media_too_large',$e->publicCode());}
    }
    public function testRateLimitMustNotMatchDigitsInProviderUrls():void
    {
        self::assertSame('youtube_video_unavailable',\App\Media\YoutubeFailureClassifier::fromStderr('HTTP Error 403 https://example.test/?expire=1729429'));
    }
    public function testRejectedCdnLogsFailureAndNeverSuccess():void
    {
        $metadata=array_replace($this->metadata(),['url'=>'https://evil.example/file']);$events=[];
        $runner=new YoutubeDiagnosticRunner(new ProcessResult(0,json_encode($metadata),''));
        try{$this->resolver($runner,static function(array $event)use(&$events){$events[]=$event;})->resolve($this->url());self::fail('Expected rejection');}catch(MediaValidationException $e){self::assertSame('youtube_response_invalid',$e->publicCode());}
        self::assertSame(['youtube_response_invalid'],array_column($events,'result_code'));
    }
}
final class YoutubeDiagnosticRunner extends ProcessRunner
{
    public array $command=[];public int $limit=0;
    public function __construct(private ProcessResult $result){parent::__construct(['yt-dlp'],sys_get_temp_dir());}
    public function run(array $command,int $timeoutSeconds,int $outputLimitBytes):ProcessResult{$this->command=$command;$this->limit=$outputLimitBytes;return $this->result;}
}
