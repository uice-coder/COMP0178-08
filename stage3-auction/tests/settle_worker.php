<?php
/** AI-assisted concurrent-settlement worker; invoked by integration.php. */
declare(strict_types=1);
require_once __DIR__.'/../src/AuctionService.php';
$pdo=new PDO(getenv('TEST_DSN'),getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$service=new AuctionService($pdo);
echo $service->settleDue()."\n";
