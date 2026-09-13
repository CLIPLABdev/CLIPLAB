#!/usr/bin/env php
<?php
declare(strict_types=1);
use App\Communications\{CampaignService,CampaignUnsubscribeService}; use App\Core\{Database,Env}; use App\Security\SecretCipher;
require dirname(__DIR__).'/bootstrap/app.php';
$pdo=Database::connection();$cipher=new SecretCipher((string)Env::get('APP_ENCRYPTION_KEY',''));$unsub=new CampaignUnsubscribeService($pdo,$cipher,(string)Env::get('APP_URL',''));$service=new CampaignService($pdo,$cipher,$unsub);$queued=$service->due(10);echo "Enfileirados: {$queued}\n";
