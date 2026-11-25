<?php
// Schedule once per minute for unattended closing. Requests also settle due auctions.
declare(strict_types=1);
require_once __DIR__.'/../stage2/db.php';
require_once __DIR__.'/../src/AuctionService.php';
try { $service=new AuctionService(connect_db());echo 'Closed '.$service->settleDue()." due auctions.\n"; }
catch(Throwable $e) {fwrite(STDERR,'Closing failed: '.$e->getMessage()."\n");exit(1);}
