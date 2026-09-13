<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOStatement;

/** In-memory schema only; never connects to external databases. */
final class EofTestDatabase extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>'2026-09-07 12:00:00');
        $this->exec(<<<'SQL'
CREATE TABLE plans(id INTEGER PRIMARY KEY,is_active INTEGER);
CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,plan_id INTEGER,credits INTEGER);
CREATE TABLE projects(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,status TEXT,progress INTEGER,auto_render_requested INTEGER);
CREATE TABLE ai_analyses(id INTEGER PRIMARY KEY,project_id INTEGER);
CREATE TABLE project_sources(id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,duration_seconds INTEGER,
 storage_disk TEXT,object_key TEXT,mime_type TEXT,sha256 TEXT,size_bytes INTEGER,width INTEGER,height INTEGER,has_audio INTEGER);
CREATE TABLE clips(id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,ai_analysis_id INTEGER,suggestion_index INTEGER,title TEXT,status TEXT,
 start_time REAL,end_time REAL,duration_seconds REAL,viral_score INTEGER,hook TEXT,reason TEXT,category TEXT,
 render_start_time REAL,render_end_time REAL,render_revision INTEGER DEFAULT 0,render_error_code TEXT,approved_at TEXT,render_requested_at TEXT);
CREATE TABLE clip_render_profiles(id INTEGER PRIMARY KEY AUTOINCREMENT,clip_id INTEGER,render_revision INTEGER,aspect_ratio TEXT,reframe_mode TEXT,
 output_width INTEGER,output_height INTEGER,detector_version TEXT,UNIQUE(clip_id,render_revision));
CREATE TABLE clip_reframe_keyframes(id INTEGER PRIMARY KEY AUTOINCREMENT,render_profile_id INTEGER,sequence_index INTEGER,at_ms INTEGER,
 center_x REAL,center_y REAL,source TEXT);
CREATE TABLE clip_editor_profiles(id INTEGER PRIMARY KEY AUTOINCREMENT,clip_id INTEGER,render_revision INTEGER,parent_clip_id INTEGER,user_id INTEGER,
 request_key TEXT,options_json TEXT,transcript_mode TEXT,duration_ms INTEGER,UNIQUE(user_id,request_key));
CREATE TABLE clip_subtitle_tracks(id INTEGER PRIMARY KEY AUTOINCREMENT,editor_profile_id INTEGER,status TEXT DEFAULT 'pending',language TEXT,error_code TEXT,completed_at TEXT);
CREATE TABLE clip_subtitle_cues(id INTEGER PRIMARY KEY AUTOINCREMENT,track_id INTEGER,cue_index INTEGER,start_ms INTEGER,end_ms INTEGER,text TEXT,words_json TEXT);
INSERT INTO plans VALUES(1,1);
INSERT INTO users VALUES(7,'active',1,64),(8,'active',1,10);
INSERT INTO projects VALUES(11,7,'EOF','completed',100,1);
INSERT INTO ai_analyses VALUES(31,11);
INSERT INTO clips(id,project_id,ai_analysis_id,suggestion_index,title,status,start_time,end_time,duration_seconds,viral_score,hook,reason,category,render_start_time,render_end_time,render_revision)
 VALUES(41,11,31,0,'Original','completed',0,46,46,90,'Hook','Reason','insight',0,46,1);
SQL);
        $this->prepare("INSERT INTO project_sources VALUES(21,11,'ready',46,'local','imports/11/source.mp4','video/mp4',?,1024,1920,1080,1)")
            ->execute([str_repeat('a',64)]);
    }

    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        return parent::prepare(str_replace('UPDATE projects p','UPDATE projects AS p',$query),$options);
    }
}
