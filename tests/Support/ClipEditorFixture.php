<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Media\Editor\EditorOptions;
use App\Media\Subtitles\SrtCodec;

final class ClipEditorFixture
{
    public static function clip(): array
    {
        return ['id'=>41,'project_id'=>12,'ai_analysis_id'=>8,'title'=>'Um bom começo','status'=>'completed',
            'start_time'=>'10.000','end_time'=>'20.000','render_start_time'=>'10.000','render_end_time'=>'20.000',
            'render_revision'=>1,'source_duration_seconds'=>120,'width'=>1920,'height'=>1080,'has_audio'=>1,'project_name'=>'Entrevista'];
    }

    public static function input(): array
    {
        return ['request_key'=>str_repeat('a',64),'start_time'=>'10','end_time'=>'20','aspect_ratio'=>'original',
            'reframe_mode'=>'original','focus_x'=>'','focus_y'=>'','reframe_keyframes'=>'','transcript_mode'=>'manual',
            'style'=>'minimal','position'=>'bottom','color'=>'#FFFFFF','accent_color'=>'#FACC15','font_size'=>'44',
            'title'=>'Um título','watermark'=>'@meucanal','srt'=>self::srt()];
    }

    public static function srt(): string { return "1\n00:00:00,000 --> 00:00:01,000\nOlá, mundo!\n"; }

    public static function snapshot(): array
    {
        return ['options'=>EditorOptions::fromArray(['style'=>'minimal']),'transcript'=>SrtCodec::parse(self::srt(),10000),
            'track_status'=>'ready','mode'=>'manual','duration_ms'=>10000,'parent_clip_id'=>40];
    }
}
