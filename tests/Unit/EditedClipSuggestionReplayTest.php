<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\AiAnalysisResult;
use App\Ai\AiClipSuggestion;
use App\Repositories\ClipRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class EditedClipSuggestionReplayTest extends TestCase
{
    public function testEditedVersionsAreNotMistakenForAdditionalAiSuggestions(): void
    {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE clips (id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,ai_analysis_id INTEGER,suggestion_index INTEGER NULL,title TEXT,start_time TEXT,end_time TEXT,duration_seconds TEXT,viral_score INTEGER,hook TEXT,reason TEXT,category TEXT,status TEXT,UNIQUE(ai_analysis_id,suggestion_index))');
        $repo=new ClipRepository($pdo);
        $result=new AiAnalysisResult('Summary',[new AiClipSuggestion(0,'Original',0,2,2,90,'Reason','Hook','insight')]);
        $ids=$repo->materialize(1,1,$result);
        $pdo->exec("INSERT INTO clips (project_id,ai_analysis_id,suggestion_index,title,status) VALUES (1,1,NULL,'User version','queued')");
        self::assertSame($ids,$repo->materialize(1,1,$result));
        self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM clips')->fetchColumn());
    }
}
