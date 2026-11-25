<?php
// AI-assisted reproducible setup. Refuses to overwrite existing application data.
declare(strict_types=1);
require_once __DIR__.'/../stage2/db.php';
require_once __DIR__.'/../src/AuctionService.php';
date_default_timezone_set('UTC');
try {
    $db=connect_db();
    $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    try { $db->query('SELECT userID FROM `User` LIMIT 1'); $exists=true; } catch(PDOException) {$exists=false;}
    if($exists) {fwrite(STDERR,"Application tables already exist. Setup leaves them unchanged; use a separate empty database for a fresh demo.\n");exit(1);}
    try { $db->query('SELECT id FROM users LIMIT 1'); $legacy=true; } catch(PDOException) {$legacy=false;}
    if($legacy) {fwrite(STDERR,"Legacy application tables found. Setup leaves them unchanged; migrate the SQLite database or select a separate empty database.\n");exit(1);}
    $db->exec(file_get_contents(__DIR__.'/../sql/schema.'.$driver.'.sql'));
    $insert=$db->prepare('INSERT INTO Category(name,description) VALUES(?,?)');
    foreach(['Books','Electronics','Home & Garden','Sports','Collectibles'] as $name) $insert->execute([$name,'Browse '.$name.' auctions.']);
    if(in_array('--demo',$argv,true)) {
        $service=new AuctionService($db);
        $password = bin2hex(random_bytes(16));
        $seller=$service->register('Alex R','seller@qq.com',$password,'seller');
        $buyer=$service->register('PixelFox B','buyer@qq.com',$password,'buyer');
        $rival=$service->register('Tayor S','rival@qq.com',$password,'buyer');
        $both=$service->register('OakAndVinyl C','both@qq.com',$password,'both');
        $cats=[];foreach($service->categories() as $c) $cats[$c['name']]=$c['id'];
        $create=function($sellerId,$title,$description,$category,$start,$reserve,$days) use($service,$cats) {
            return $service->createAuction($sellerId,['title'=>$title,'description'=>$description,'item_condition'=>'Used — good','category_id'=>$cats[$category],'starting_price'=>$start,'reserve_price'=>$reserve,'bid_increment'=>'1.00','starts_at'=>gmdate('Y-m-d H:i:s',time()-86400),'ends_at'=>gmdate('Y-m-d H:i:s',time()+$days*86400)]);
        };
        $bike=$create($seller['id'],'City bicycle','A reliable everyday bicycle, recently serviced. Collection arranged with the seller.','Sports','50.00','65.00',2);
        $camera=$create($seller['id'],'Compact film camera','A classic compact camera with working shutter and a clean lens. Film not included.','Electronics','25.00','30.00',3);
        $lamp=$create($both['id'],'Oak desk lamp','A warm desk lamp in solid oak, perfect for a reading corner.','Home & Garden','15.00','15.00',1);
        $books=$create($seller['id'],'Database book collection','Three introductory database books, with clean pages and light cover wear.','Books','12.00','15.00',4);
        $vinyl=$create($both['id'],'Jazz vinyl collection','Five carefully stored records with original sleeves. See description for condition.','Collectibles','20.00','25.00',5);
        $service->watch($buyer['id'],$bike);
        $service->watch($buyer['id'],$camera);
        $service->placeBid($buyer['id'],$bike,'50.00');
        $service->placeBid($rival['id'],$bike,'51.00');
        $service->placeBid($rival['id'],$camera,'30.00');
        $service->placeBid($buyer['id'],$lamp,'15.00');
        $expired=$create($seller['id'],'Completed demo: reading chair','A completed example with a qualifying winning bid and a recorded order.','Home & Garden','30.00','35.00',1);
        $service->placeBid($buyer['id'],$expired,'35.00');
        $unsold=$create($seller['id'],'Completed demo: antique print','A completed example that did not attract any bids.','Collectibles','40.00','50.00',1);
        $stmt=$db->prepare('UPDATE Auction SET startsAt=?,endsAt=? WHERE auctionID=?');
        foreach([$expired,$unsold] as $id) $stmt->execute([gmdate('Y-m-d H:i:s',time()-172800),gmdate('Y-m-d H:i:s',time()-60),$id]);
        $service->settleDue();
        // The requested short password applies only to these local demo accounts.
        $demoPassword = 'zimugao';
        $updatePassword = $db->prepare('UPDATE `User` SET passwordHash = ? WHERE userID = ?');
        foreach ([$seller, $buyer, $rival, $both] as $account) {
            $updatePassword->execute([password_hash($demoPassword, PASSWORD_DEFAULT), $account['id']]);
        }
        echo "Demo accounts created: seller@qq.com, buyer@qq.com, rival@qq.com, both@qq.com.\n";
    }
    echo "Setup complete ($driver). Start: php -S 127.0.0.1:8080 -t stage2\n";
} catch(Throwable $e) {fwrite(STDERR,'Setup failed: '.$e->getMessage()."\n");exit(1);}
