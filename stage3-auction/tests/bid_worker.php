<?php
/** AI-assisted contention-test worker; invoked by integration.php. */
declare(strict_types=1);
require_once __DIR__.'/../src/AuctionService.php';
try {
    $pdo=new PDO(getenv('TEST_DSN'),getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $service=new AuctionService($pdo);
    $service->placeBid((int)$argv[2],(int)$argv[1],$argv[3]);
    echo "accepted\n";exit(0);
} catch(DomainException|InvalidArgumentException|RuntimeException $e) {
    echo "rejected: ".$e->getMessage()."\n";exit(3);
}
