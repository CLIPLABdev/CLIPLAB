<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ClipEditorController;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Exceptions\ClipRenderValidationException;
use App\Media\ClipRenderReceipt;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Media\Editor\EditorOptions;
use PHPUnit\Framework\TestCase;
use Tests\Support\ClipEditorFixture as Fixture;

final class ClipEditorControllerTest extends TestCase
{
    public function testPageProvidesExactTimedWordsSnapshotOrNullToPreview(): void
    {
        $track=new \App\Media\Subtitles\Transcript('pt',[new \App\Media\Subtitles\SubtitleCue(0,1000,'Olá mundo',[
            ['start_ms'=>0,'end_ms'=>400,'text'=>'Olá'],['start_ms'=>500,'end_ms'=>1000,'text'=>'mundo']])],10000);
        foreach ([$track,null] as $transcript) {
            $data=null;
            $view=new View(null,static function ($name,$values) use (&$data): array { $data=$values; return []; });
            $controller=new ClipEditorController($view,static fn (): array=>Fixture::clip(),
                static fn (): array=>array_replace(Fixture::snapshot(),['transcript'=>$transcript]),static fn (): ClipRenderReceipt=>new ClipRenderReceipt(42,12,1,true));
            self::assertSame(200,$controller->show(Request::fake('GET','/'),['id'=>'41'])->status());
            self::assertArrayHasKey('previewTranscript',$data);
            self::assertSame($transcript ? $transcript->toArray() : null,$data['previewTranscript']);
        }
    }
    protected function setUp(): void { $_SESSION=[]; Session::put('user_id',7); }
    protected function tearDown(): void { $_SESSION=[]; }

    private function controller(array $adapters=[]): ClipEditorController
    {
        return new ClipEditorController(new View(),
            $adapters['owned'] ?? static fn (int $id,int $user): ?array => $id===41 && $user===7 ? Fixture::clip() : null,
            $adapters['snapshot'] ?? static fn (): array => Fixture::snapshot(),
            $adapters['request'] ?? static fn (): ClipRenderReceipt => new ClipRenderReceipt(42,12,1,true),
            null, $adapters['consent'] ?? static fn (): bool => false,
            $adapters['limit'] ?? static fn (): bool => true,
            $adapters['profile'] ?? null);
    }

    public function testGuestsAreRedirectedWithoutReadingAnyClip(): void
    {
        Session::forget('user_id');
        $controller=$this->controller(['owned'=>static function (): void { throw new \LogicException('No guest lookup'); }]);
        foreach (['show','store','subtitles'] as $method) {
            $response=$controller->$method(Request::fake('GET','/'),['id'=>'41']);
            self::assertSame(302,$response->status());
            self::assertSame('/login',$response->header('Location'));
        }
    }

    public function testForeignStaleAndInvalidIdsReturnPrivate404BeforeReadingInputsOrTracks(): void
    {
        $controller=$this->controller(['snapshot'=>static function (): void { throw new \LogicException('No foreign track read'); }]);
        foreach (['42','0','-1','01','9223372036854775808','x'] as $id) {
            foreach (['show','store','subtitles'] as $method) {
                $response=$controller->$method(Request::fake('POST','/', ['style'=>['viral']]),['id'=>$id]);
                self::assertSame(404,$response->status());
                self::assertSame('private, no-store',$response->header('Cache-Control'));
            }
        }
    }

    public function testOwnerCanDownloadExactReadyTranscriptWithoutHtmlOrPrivatePaths(): void
    {
        $response=$this->controller()->subtitles(Request::fake('GET','/'),['id'=>'41']);
        self::assertSame(200,$response->status());
        self::assertSame(Fixture::srt(),$response->body());
        self::assertSame('application/x-subrip; charset=UTF-8',$response->header('Content-Type'));
        self::assertSame('attachment; filename="clipe-41.srt"',$response->header('Content-Disposition'));
        self::assertSame('nosniff',$response->header('X-Content-Type-Options'));
        self::assertSame('private, no-store',$response->header('Cache-Control'));
    }

    public function testPendingAndAbsentTracksCannotBeDownloaded(): void
    {
        foreach ([null,array_replace(Fixture::snapshot(),['track_status'=>'pending','transcript'=>null])] as $snapshot) {
            $response=$this->controller(['snapshot'=>static fn () => $snapshot])->subtitles(Request::fake('GET','/'),['id'=>'41']);
            self::assertSame(404,$response->status());
        }
    }

    public function testSubmissionUsesSessionIdentityAndCreatesNewVersionWithOriginalId(): void
    {
        $submitted=null;
        $controller=$this->controller(['request'=>static function (...$args) use (&$submitted): ClipRenderReceipt {
            $submitted=$args;
            return new ClipRenderReceipt(42,12,1,true);
        }]);
        $response=$controller->store(Request::fake('POST','/',Fixture::input()+['user_id'=>'999','clip_id'=>'777']),['id'=>'41']);
        self::assertSame('/clips/42/editar',$response->header('Location'));
        self::assertSame([41,7,str_repeat('a',64),'10','20'],array_slice($submitted,0,5));
        self::assertInstanceOf(EditorOptions::class,$submitted[5]);
        self::assertSame('@meucanal',$submitted[5]->watermark());
        self::assertInstanceOf(ReframeSubmission::class,$submitted[6]);
        self::assertSame('original',(new ReframePlanValidator())->validate($submitted[6],10000)->mode());
        self::assertSame('manual',$submitted[7]);
        self::assertSame(Fixture::srt(),$submitted[8]);
    }

    public function testNoJavascriptCenterAndManualFormsProduceValidReframePlans(): void
    {
        foreach (['center','manual'] as $mode) {
            $controller=$this->controller(['request'=>static function ($id,$user,$key,$start,$end,$options,ReframeSubmission $submission) use ($mode): ClipRenderReceipt {
                $plan=(new ReframePlanValidator())->validate($submission,10000);
                self::assertSame($mode,$plan->mode());
                self::assertSame('9:16',$plan->aspectRatio()->value());
                return new ClipRenderReceipt(42,12,1,true);
            }]);
            $input=array_replace(Fixture::input(),['aspect_ratio'=>'9:16','reframe_mode'=>$mode,'focus_x'=>'0.25','focus_y'=>'0.5']);
            self::assertSame(302,$controller->store(Request::fake('POST','/',$input),['id'=>'41'])->status());
        }
    }

    public function testMalformedOptionsNeverDispatchAndRetainEscapedSrtForCorrection(): void
    {
        foreach (['0','97','44.2','44oops',['44']] as $font) {
            $input=array_replace(Fixture::input(),['font_size'=>$font,'srt'=>"</textarea><script>alert(1)</script>"]);
            $controller=$this->controller(['request'=>static function (): void { throw new \LogicException('Invalid options dispatched'); }]);
            $response=$controller->store(Request::fake('POST','/',$input),['id'=>'41']);
            self::assertSame(422,$response->status());
            self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;',$response->body());
            self::assertStringNotContainsString('<script>alert(1)</script>',$response->body());
            self::assertStringContainsString('aria-invalid="true"',$response->body());
        }
    }

    public function testValidationErrorsKeepManualReviewAndRequestKeyOnSameForm(): void
    {
        $controller=$this->controller(['request'=>static function (): void {
            throw new ClipRenderValidationException(['subtitles'=>'Revise os tempos.'],12);
        }]);
        $response=$controller->store(Request::fake('POST','/',Fixture::input()),['id'=>'41']);
        self::assertSame(422,$response->status());
        self::assertStringContainsString('Revise os tempos.',$response->body());
        self::assertStringContainsString('Olá, mundo!',$response->body());
        self::assertStringContainsString('value="'.str_repeat('a',64).'"',$response->body());
        self::assertSame('private, no-store',$response->header('Cache-Control'));
    }

    public function testRateLimitPreventsTwentyFirstExportAndPreservesDraft(): void
    {
        $controller=$this->controller(['limit'=>static fn (int $user): bool => false,
            'request'=>static function (): void { throw new \LogicException('Rate limited export dispatched'); }]);
        $response=$controller->store(Request::fake('POST','/',Fixture::input()),['id'=>'41']);
        self::assertSame(429,$response->status());
        self::assertSame('3600',$response->header('Retry-After'));
        self::assertStringContainsString('Olá, mundo!',$response->body());
    }

    public function testAutoCropNeedsPersistedConsentEvenWithForgedClientFlag(): void
    {
        $input=array_replace(Fixture::input(),['aspect_ratio'=>'9:16','reframe_mode'=>'auto',
            'consent_active'=>'1','reframe_keyframes'=>'[{"at_ms":0,"center_x":0.5,"center_y":0.5},{"at_ms":10000,"center_x":0.5,"center_y":0.5}]']);
        $controller=$this->controller(['request'=>static function (): void { throw new \LogicException('Unconsented export dispatched'); }]);
        self::assertSame(422,$controller->store(Request::fake('POST','/',$input),['id'=>'41'])->status());
    }

    public function testServerOwnedReframeFieldsCannotReachRenderer(): void
    {
        $controller=$this->controller(['request'=>static function (): void { throw new \LogicException('Filter injection dispatched'); }]);
        self::assertSame(422,$controller->store(Request::fake('POST','/',Fixture::input()+['filtergraph'=>'movie=/private']),['id'=>'41'])->status());
    }

    public function testExtendedAppearanceIsTypedAndRetainsLegacyDefaults(): void
    {
        $received=null;
        $controller=$this->controller(['request'=>static function ($id,$user,$key,$start,$end,$options) use (&$received): ClipRenderReceipt { $received=$options; return new ClipRenderReceipt(42,12,1,true); }]);
        $extra=['font_family'=>'Georgia','background_color'=>'#123456','outline_width'=>'4','shadow_depth'=>'3','font_weight'=>'bold','animation'=>'fade','cta_text'=>'Inscreva-se','logo_asset_id'=>'12','logo_position'=>'bottom_left','logo_scale'=>'15'];
        self::assertSame(302,$controller->store(Request::fake('POST','/',Fixture::input()+$extra),['id'=>'41'])->status());
        self::assertSame('Georgia',$received->fontFamily()); self::assertSame(12,$received->logoAssetId()); self::assertSame(4,$received->outlineWidth());
        self::assertSame(302,$controller->store(Request::fake('POST','/',Fixture::input()),['id'=>'41'])->status());
        self::assertSame(0,$received->logoAssetId()); self::assertSame('Arial',$received->fontFamily());
        foreach (['-1','1.1','9999999999999999999999',['2']] as $invalid) {
            self::assertSame(422,$controller->store(Request::fake('POST','/',Fixture::input()+['logo_asset_id'=>$invalid]),['id'=>'41'])->status());
        }
    }

    public function testAllEffectsPassThroughPostWithSignedIntegers(): void
    {
        $extra=['font_italic'=>'1','letter_spacing'=>'3','caption_margin_x'=>'27','caption_offset_y'=>'-43',
            'outline_color'=>'#123456','background_mode'=>'box','animation_duration_ms'=>'456','animation_out'=>'fade',
            'animation_out_duration_ms'=>'321','video_fade_in_ms'=>'654','video_fade_out_ms'=>'789',
            'brightness'=>'-25','contrast'=>'125','saturation'=>'135','blur'=>'4','noise'=>'9','vignette'=>'35','zoom_percent'=>'123','motion'=>'pan_left'];
        $received=null;
        $controller=$this->controller(['request'=>static function ($id,$user,$key,$start,$end,$options) use (&$received): ClipRenderReceipt { $received=$options->toArray(); return new ClipRenderReceipt(42,12,1,true); }]);
        self::assertSame(302,$controller->store(Request::fake('POST','/',Fixture::input()+$extra),['id'=>'41'])->status());
        foreach ($extra as $key=>$value) self::assertSame(is_numeric($value) ? (int)$value : $value,$received[$key],$key);
        foreach (['-101','2e1','2.1','bogus'] as $invalid) {
            self::assertSame(422,$controller->store(Request::fake('POST','/',array_replace(Fixture::input()+$extra,['brightness'=>$invalid])),['id'=>'41'])->status());
        }
    }

    public function testChangingIntervalRequiresReviewOfInheritedTranscript(): void
    {
        $controller=$this->controller();
        $input=array_replace(Fixture::input(),['start_time'=>'11','end_time'=>'21']);
        $response=$controller->store(Request::fake('POST','/',$input),['id'=>'41']);
        self::assertSame(422,$response->status());
        self::assertStringContainsString('intervalo',$response->body());
        self::assertStringContainsString('Olá, mundo!',$response->body());
        self::assertSame(302,$controller->store(Request::fake('POST','/',$input+['srt_interval_confirmed'=>'1']),['id'=>'41'])->status());
    }
}
