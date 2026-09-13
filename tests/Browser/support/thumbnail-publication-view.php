<?php
declare(strict_types=1);
require dirname(__DIR__,3).'/vendor/autoload.php';
use App\Core\View;
use App\Media\Thumbnails\ThumbnailOptions;
use App\Media\Thumbnails\PublicationMetadata;
$_SESSION=['user_id'=>7];
$clip=['id'=>1,'title'=>'Fixture local de capa e publicação','project_id'=>1,'render_start_time'=>0,'render_end_time'=>10];$user=['name'=>'Conta de teste','email'=>'test@example.test','credits'=>10,'plan_name'=>'Teste','monthly_minutes'=>10];
$thumbs=[['id'=>1,'kind'=>'candidate','status'=>'ready','offset_seconds'=>3.25]];
if(($argv[1]??'')==='publication')echo (new View())->render('clips.publication',['title'=>'Preparar publicação','clip'=>$clip,'user'=>$user,'publications'=>[['id'=>1,'version'=>2,'status'=>'ready','thumbnail_id'=>1,'metadata'=>PublicationMetadata::normalize(['title'=>'Meu título','caption'=>'Texto de exemplo']),'history'=>[['version'=>2,'status'=>'ready','created_at'=>'2026-09-06 12:00:00']]]],'thumbnails'=>$thumbs,'error'=>null])->body();
else echo (new View())->render('clips.thumbnail-studio',['title'=>'Estúdio de capas','clip'=>$clip,'user'=>$user,'set'=>['status'=>'ready','candidate_count'=>1],'thumbnails'=>$thumbs,'values'=>ThumbnailOptions::fromArray([])->toArray()+['offset_seconds'=>'1','base_thumbnail_id'=>'0'],'requestKey'=>str_repeat('a',64),'error'=>null,'feedback'=>null,'logos'=>[]])->body();
