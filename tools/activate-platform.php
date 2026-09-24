<?php
declare(strict_types=1);

// Additive platform upgrade only. Does not run seeders or touch media/configuration files.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap/app.php';
use App\Core\Database;
use App\Core\Migrator;

$root=dirname(__DIR__);
$names=[
 '202609070010_create_billing_tables.sql',
 '202609070020_create_communications.sql',
 '202609070021_create_email_verification_challenges.sql',
 '202609070022_create_campaigns.sql',
 '202609070023_create_media_notification_projection.sql',
 '202609070030_create_account_email_changes.sql',
 '202609070040_create_admin_platform_settings_and_promotions.sql',
];
$apply=in_array('--apply',$argv,true);
$phase='preflight';$table='';$pdo=null;
try {
    $pdo=Database::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $applied=$pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $pending=array_values(array_diff($names,$applied));
    $tableNames=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $knownTables=array_fill_keys($tableNames,true);$newColumns=[];$sqlFiles=[];
    foreach($pending as $name) {
        $sql=file_get_contents($root.'/database/migrations/'.$name);
        if(!is_string($sql)) throw new RuntimeException('Missing SQL');
        foreach(preg_split('/;\s*(?:\r?\n|$)/',trim($sql))?:[] as $statement) {
            $statement=trim($statement);if($statement==='')continue;
            if(preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?([a-z_]+)\s*\(/D',$statement,$match)) {
                $table=$match[1];if(isset($knownTables[$table]))throw new RuntimeException('Partial migration detected');
                $knownTables[$table]=true;
            } elseif(preg_match('/^ALTER TABLE ([a-z_]+) ADD (COLUMN|KEY) ([a-z_]+)/D',$statement,$match)) {
                $table=$match[1];if(!isset($knownTables[$table]))throw new RuntimeException('Missing source table');
                if(in_array($table,$tableNames,true)) {
                    $metadata=$pdo->prepare($match[2]==='COLUMN'
                        ? 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
                        : 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
                    $metadata->execute([$table,$match[3]]);
                    if((int)$metadata->fetchColumn()>0)throw new RuntimeException('Partial migration detected');
                }
                $newColumns[]=$table.'.'.$match[3];
            } else throw new RuntimeException('Non-additive SQL rejected');
        }
        $sqlFiles[$name]=$sql;
    }
    echo json_encode(['mode'=>$apply?'apply':'check','pending'=>$pending,'existing_tables'=>count($tableNames),'additions'=>$newColumns],JSON_UNESCAPED_SLASHES).PHP_EOL;
    if(!$apply || $pending===[])exit(0);
    if((int)$pdo->query("SELECT GET_LOCK('cliplab_platform_upgrade',0)")->fetchColumn()!==1)throw new RuntimeException('Upgrade already running');
    $phase='private-backup';
    $directory=$root.'/.local-history/platform-improvement-20260907/db-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
    if(!mkdir($directory,0700,true))throw new RuntimeException('Backup directory failed');
    $backup=fopen($directory.'/database.sql','xb');if($backup===false)throw new RuntimeException('Backup failed');
    $write=static function(string $data)use($backup):void {if(fwrite($backup,$data)!==strlen($data))throw new RuntimeException('Incomplete backup');};
    $write("-- PRIVATE recovery snapshot. Contains user data. Restore only into a separately prepared database.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $counts=[];$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    foreach($tableNames as $table) {
        if(preg_match('/^[a-z0-9_]+$/iD',$table)!==1)throw new RuntimeException('Unexpected table name');
        $schema=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);
        $write($schema[1].";\n");$rows=$pdo->query('SELECT * FROM `'.$table.'`');$count=0;
        while($row=$rows->fetch(PDO::FETCH_ASSOC)) {
            $columns=implode(',',array_map(static fn($key)=>'`'.str_replace('`','``',$key).'`',array_keys($row)));
            $values=implode(',',array_map(static fn($value)=>$value===null?'NULL':"X'".bin2hex((string)$value)."'",array_values($row)));
            $write('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.$values.");\n");$count++;
        }
        $counts[$table]=$count;
    }
    $pdo->commit();$write("SET FOREIGN_KEY_CHECKS=1;\n");fflush($backup);fclose($backup);
    $manifest=['created_utc'=>gmdate(DATE_ATOM),'tables'=>$counts,'sha256'=>hash_file('sha256',$directory.'/database.sql'),'bytes'=>filesize($directory.'/database.sql')];
    if(file_put_contents($directory.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Manifest failed');
    $phase='additive-migrations';$staging=$directory.'/migrations';if(!mkdir($staging,0700))throw new RuntimeException('Staging failed');
    foreach($sqlFiles as $name=>$sql)if(file_put_contents($staging.'/'.$name,$sql)!==strlen($sql))throw new RuntimeException('SQL snapshot failed');
    $executed=(new Migrator($pdo,$staging))->run();
    echo json_encode(['executed'=>$executed,'private_backup'=>$directory,'backup_bytes'=>$manifest['bytes'],'backup_sha256'=>$manifest['sha256']],JSON_UNESCAPED_SLASHES).PHP_EOL;
    $pdo->query("SELECT RELEASE_LOCK('cliplab_platform_upgrade')");
} catch(Throwable $error) {
    if($pdo instanceof PDO && $pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,json_encode(['failed_phase'=>$phase,'table'=>$table,'error_class'=>get_class($error),'error_code'=>$error->getCode(),'action'=>'Stop and inspect; never retry a partial migration blindly.']).PHP_EOL);exit(1);
}
