<?php
declare(strict_types=1);
use App\Core\{Database,Env,View};
use App\Communications\{DeferredCommunicationEmitter,CommunicationEmitterService,CommunicationEventCatalog};
use App\Security\SecretCipher;
use App\Services\PlatformPresentationService;

// Register factories without opening a database or initializing credentials.
$platformPdo=static fn()=>Database::connection();
$platformCipher=static fn()=>new SecretCipher((string)Env::get('APP_ENCRYPTION_KEY',''));
$platformFeatures=['admin_tools'=>true,'billing'=>true,'communications'=>true,'campaigns'=>true];
$presentation=null;
$platformView=new View(null,static function(string $name,array $data)use(&$presentation,$platformPdo,$platformFeatures):array {
    try {
        $presentation??=new PlatformPresentationService($platformPdo(),dirname(__DIR__).'/public',$platformFeatures);
        return $presentation->context($name,$data);
    } catch(\PDOException $exception) {
        // Optional presentation storage cannot disable a public or login page.
        // Account mutations and outbox operations never use this fallback.
        return ['platformFeatures'=>[]];
    }
});
return ['pdo'=>$platformPdo,'cipher'=>$platformCipher,'view'=>$platformView,'features'=>$platformFeatures,
    'base_url'=>(string)Env::get('APP_URL','http://localhost:8093'),
    'communications'=>new DeferredCommunicationEmitter(static fn()=>new CommunicationEmitterService($platformPdo(),$platformCipher(),new CommunicationEventCatalog()))];
