<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\ClipEditorRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ClipEditorRepositoryTest extends TestCase
{
    public function testAllEffectsSurviveRenderSnapshotReload(): void
    {
        $pdo=new \Tests\Support\EofTestDatabase();
        $repository=new ClipEditorRepository($pdo);
        $options=\App\Media\Editor\EditorOptions::fromArray(['style'=>'minimal','font_italic'=>1,'letter_spacing'=>4,
            'caption_margin_x'=>47,'caption_offset_y'=>-53,'outline_color'=>'#123456','background_mode'=>'box',
            'animation_duration_ms'=>287,'animation_out'=>'fade','animation_out_duration_ms'=>391,'video_fade_in_ms'=>523,
            'video_fade_out_ms'=>647,'brightness'=>-31,'contrast'=>113,'saturation'=>139,'blur'=>5,'noise'=>13,
            'vignette'=>43,'zoom_percent'=>137,'motion'=>'pan_left']);
        $pdo->beginTransaction();
        try {
            $repository->createProfile(41,1,null,7,hash('sha256','effects-snapshot'),$options,'manual',2000);
            $fresh=(new ClipEditorRepository($pdo))->snapshot(41,1)['options']->toArray();
            self::assertSame($options->toArray(),$fresh);
            self::assertSame(-31,$fresh['brightness']);
            self::assertSame('pan_left',$fresh['motion']);
        } finally { $pdo->rollBack(); }
    }
    public function testEmptyTranscriptCannotBecomeAReadyCaptionTrack(): void
    {
        $pdo = new \Tests\Support\EofTestDatabase();
        $repository = new ClipEditorRepository($pdo);
        $pdo->beginTransaction();
        $repository->createProfile(41, 1, null, 7, hash('sha256', 'empty-caption'),
            \App\Media\Editor\EditorOptions::fromArray(['style' => 'minimal']), 'auto', 2000);
        $rejected = false;
        try {
            $repository->saveTranscript(41, 1, new \App\Media\Subtitles\Transcript('pt', [], 2000));
        } catch (\RuntimeException $error) {
            $rejected = true;
            self::assertStringContainsString('vazia', $error->getMessage());
        }
        self::assertTrue($rejected, 'An empty transcript was saved as ready.');
        self::assertSame('pending', $repository->snapshot(41, 1)['track_status']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM clip_subtitle_cues')->fetchColumn());
        $pdo->rollBack();
    }

    public function testNonemptyTranscriptIsSavedAndReplayedWithoutChangingTextOrTiming(): void
    {
        $pdo = new \Tests\Support\EofTestDatabase();
        $repository = new ClipEditorRepository($pdo);
        $pdo->beginTransaction();
        $repository->createProfile(41, 1, null, 7, hash('sha256', 'valid-caption'),
            \App\Media\Editor\EditorOptions::fromArray(['style' => 'minimal']), 'manual', 2000);
        $transcript = new \App\Media\Subtitles\Transcript('pt', [
            new \App\Media\Subtitles\SubtitleCue(100, 1700, 'Texto corrigido')
        ], 2000);
        $repository->saveTranscript(41, 1, $transcript);
        $repository->saveTranscript(41, 1, $transcript);
        self::assertSame('ready', $repository->snapshot(41, 1)['track_status']);
        self::assertSame($transcript->toArray(), $repository->snapshot(41, 1)['transcript']->toArray());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM clip_subtitle_cues')->fetchColumn());
        $pdo->rollBack();
    }

    public function testAdmissionRequiresBothActiveUserAndActivePlan(): void
    {
        $pdo=new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT NOT NULL, plan_id INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE clips (id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO plans (id,is_active) VALUES (1,0)");
        $pdo->exec("INSERT INTO users (id,status,plan_id) VALUES (7,'active',1)");
        $pdo->exec('INSERT INTO projects (id,user_id) VALUES (4,7)');
        $pdo->exec('INSERT INTO clips (id,project_id) VALUES (9,4)');
        $repository=new ClipEditorRepository($pdo);

        $pdo->beginTransaction();
        try {
            self::assertFalse($repository->lockOwnerAndProject(9,7));
            $pdo->exec('UPDATE plans SET is_active=1 WHERE id=1');
            self::assertTrue($repository->lockOwnerAndProject(9,7));
        } finally {
            $pdo->rollBack();
        }
    }
}
