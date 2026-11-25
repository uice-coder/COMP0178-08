<?php
/** AI-assisted integration tests. Run only against a disposable database. */
declare(strict_types=1);
require_once __DIR__ . '/../src/AuctionService.php';

$dsn = getenv('TEST_DSN') ?: 'sqlite::memory:';
if (str_starts_with($dsn, 'mysql:') && !preg_match('/(?:^|;)dbname=auction_eight_test(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Refusing to reset anything except the disposable auction_eight_test database.\n");
    exit(2);
}
if (!str_starts_with($dsn, 'mysql:') && $dsn !== 'sqlite::memory:') {
    fwrite(STDERR, "SQLite tests require an isolated in-memory database.\n"); exit(2);
}
$user = getenv('TEST_DB_USER') ?: 'root';
$password = getenv('TEST_DB_PASS') ?: '';
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
if ($mysql) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
} else { $pdo->exec('PRAGMA foreign_keys=ON'); }
$pdo->exec(file_get_contents(__DIR__ . '/../sql/schema.' . ($mysql ? 'mysql' : 'sqlite') . '.sql'));
$service = new AuctionService($pdo);
$passed = 0;
$failed = 0;
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects(callable $operation, string $message): void {
    try { $result = $operation(); }
    catch (PDOException $e) {
        if (str_starts_with((string)$e->getCode(), '23')) { return; }
        throw $e;
    }
    catch (DomainException|InvalidArgumentException|RuntimeException $e) { return; }
    if (isset($result) && $result === false) { return; }
    throw new RuntimeException($message);
}
function integrity_rejects(callable $operation, string $message): void {
    try { $operation(); }
    catch (PDOException $e) {
        if (str_starts_with((string)$e->getCode(), '23')) return;
        throw $e;
    }
    throw new RuntimeException($message);
}
function run_test(string $name, callable $operation): void {
    global $passed, $failed;
    try { $operation(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}
function uid(array $user): int { return (int)($user['id'] ?? $user['user_id'] ?? 0); }
function auction_data(int $category, array $overrides=[]): array {
    return array_replace([
        'title'=>'Integration bicycle', 'description'=>'A durable sample bicycle for integration checks.',
        'category_id'=>$category, 'starting_price'=>'10.00', 'reserve_price'=>'15.00', 'bid_increment'=>'1.00',
        'starts_at'=>gmdate('Y-m-d H:i:s', time()-60), 'ends_at'=>gmdate('Y-m-d H:i:s',time()+3600),
    ], $overrides);
}
function expire(PDO $pdo, int $id): void {
    $stmt=$pdo->prepare('UPDATE Auction SET endsAt=? WHERE auctionID=?');
    $stmt->execute([gmdate('Y-m-d H:i:s',time()-1), $id]);
}

$pdo->exec("INSERT INTO Category (name,description) VALUES ('Integration category','Category used for database integration checks')");
$categories=$service->categories();
$category=(int)$categories[0]['id'];
$seller=$service->register('Seller One','seller@example.test','Test-Password-123!','seller');
$buyer=$service->register('Buyer One','buyer@example.test','Test-Password-123!','buyer');
$buyer2=$service->register('Buyer Two','buyer2@example.test','Test-Password-123!','buyer');
$both=$service->register('Both Roles','both@example.test','Test-Password-123!','both');
$sellerId=uid($seller); $buyerId=uid($buyer); $buyer2Id=uid($buyer2); $bothId=uid($both);

run_test('registration persists a password hash and roles', function()use($pdo,$buyerId,$sellerId,$bothId){
    check($buyerId>0 && $sellerId>0 && $bothId>0,'Registration did not return user IDs');
    $users=$pdo->query('SELECT * FROM User')->fetchAll();
    foreach($users as $u) {
        $hash=$u['passwordHash'] ?? '';
        check($hash !== 'Test-Password-123!' && password_verify('Test-Password-123!',$hash),'Password must be hashed with password_hash');
    }
});
run_test('core tables and attributes match the final group ER model',function()use($pdo){
    $expected=[
        'User'=>['userID','email','firstName','lastName','passwordHash','createdAt'],
        'Buyer'=>['userID'], 'Seller'=>['userID'],
        'Category'=>['categoryID','name','description'],
        'Auction'=>['auctionID','sellerID','categoryID','title','description','itemCondition','startingPrice','reservePrice','bidIncrement','startsAt','endsAt','status'],
        'Bid'=>['bidID','auctionID','buyerID','amount','placedAt'],
        'WatchlistEntry'=>['buyerID','auctionID','watchedAt'],
        'SaleOrder'=>['orderID','auctionID','winningBidID','status','createdAt'],
    ];
    foreach($expected as $table=>$columns) {
        $statement=$pdo->query('SELECT * FROM `'.$table.'` WHERE 1=0');
        $actual=[];
        for($i=0;$i<$statement->columnCount();$i++) $actual[]=$statement->getColumnMeta($i)['name'];
        sort($actual);sort($columns);
        check($actual===$columns,'Attributes differ from final_ER_diagram.dbml for '.$table);
    }
});
run_test('overlapping Buyer and Seller subtypes persist and enforce role foreign keys',function()use($pdo,$sellerId,$buyerId,$bothId){
    $membership=function(string $table,int $id)use($pdo):int {
        $statement=$pdo->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE userID=?');$statement->execute([$id]);return (int)$statement->fetchColumn();
    };
    check($membership('Seller',$sellerId)===1 && $membership('Buyer',$sellerId)===0,'Seller has wrong subtype membership');
    check($membership('Buyer',$buyerId)===1 && $membership('Seller',$buyerId)===0,'Buyer has wrong subtype membership');
    check($membership('Buyer',$bothId)===1 && $membership('Seller',$bothId)===1,'Both role did not create both subtype records');
    foreach(['Buyer','Seller'] as $table) {
        integrity_rejects(fn()=>$pdo->exec('INSERT INTO `'.$table.'`(userID) VALUES(999999)'),'Orphan '.$table.' subtype accepted');
        integrity_rejects(fn()=>$pdo->exec('INSERT INTO `'.$table.'`(userID) VALUES('.$bothId.')'),'Duplicate '.$table.' subtype accepted');
    }
});
run_test('registration preserves separate given and family names',function()use($pdo,$service){
    $u=$service->register('Anne Marie','names@example.test','Test-Password-123!','buyer','van Dijk');
    $statement=$pdo->prepare('SELECT firstName,lastName FROM User WHERE userID=?');$statement->execute([$u['id']]);$names=$statement->fetch();
    check($names===['firstName'=>'Anne Marie','lastName'=>'van Dijk'],'Name fields were combined or split incorrectly');
    check($u['name']==='Anne Marie van Dijk','Public display name is inconsistent');
});
run_test('authentication accepts valid credentials and rejects wrong passwords',function()use($service,$buyerId){
    check(uid($service->authenticate('buyer@example.test','Test-Password-123!'))===$buyerId,'Wrong authenticated user');
    rejects(fn()=>$service->authenticate('buyer@example.test','wrong'),'Wrong password accepted');
    rejects(fn()=>$service->authenticate('missing@example.test','wrong'),'Missing user accepted');
});
run_test('duplicate email and invalid account data are rejected',function()use($service){
    rejects(fn()=>$service->register('Duplicate','BUYER@example.test','Test-Password-123!','buyer'),'Case-insensitive duplicate email accepted');
    rejects(fn()=>$service->register('Bad','not-an-email','Test-Password-123!','buyer'),'Invalid email accepted');
    rejects(fn()=>$service->register('Bad','bad@example.test','x','buyer'),'Weak password accepted');
    rejects(fn()=>$service->register('Bad','admin@example.test','Test-Password-123!','admin'),'Unknown role accepted');
});
run_test('buyer cannot create an auction',function()use($service,$buyerId,$category){
    rejects(fn()=>$service->createAuction($buyerId,auction_data($category)),'Buyer created auction');
});
run_test('invalid auction money, timing, and categories are rejected',function()use($service,$sellerId,$category){
    foreach(['-1.00','10.001','1e3','NaN'] as $money) {
        rejects(fn()=>$service->createAuction($sellerId,auction_data($category,['starting_price'=>$money])),'Invalid money accepted: '.$money);
    }
    rejects(fn()=>$service->createAuction($sellerId,auction_data($category,['bid_increment'=>'0.00'])),'Zero increment accepted');
    rejects(fn()=>$service->createAuction($sellerId,auction_data($category,['ends_at'=>gmdate('Y-m-d H:i:s',time()-3600)])),'Past end accepted');
    rejects(fn()=>$service->createAuction($sellerId,auction_data(999999)),'Nonexistent category accepted');
});
$auctionId=$service->createAuction($sellerId,auction_data($category));
run_test('seller auction creation and search retrieve persisted records',function()use($service,$auctionId,$category){
    $a=$service->auction($auctionId);
    check($a['title']==='Integration bicycle','Auction did not persist');
    $rows=$service->browse(['q'=>'bicycle','category'=>$category]);
    check(in_array($auctionId,array_map(fn($r)=>(int)$r['id'],$rows),true),'Search/category omitted matching auction');
    check(count($service->browse(['q'=>"' OR 1=1 --"]))===0,'Search input changed query semantics');
});
run_test('price sorting uses numeric decimal order and newest uses auction identity',function()use($service,$sellerId,$buyerId,$category){
    $cheap=$service->createAuction($sellerId,auction_data($category,['title'=>'Numeric order scenario cheap','starting_price'=>'2.00','reserve_price'=>'','bid_increment'=>'0.01']));
    $dear=$service->createAuction($sellerId,auction_data($category,['title'=>'Numeric order scenario dear','starting_price'=>'10.00','reserve_price'=>'']));
    $service->placeBid($buyerId,$cheap,'2.50');
    $ids=fn(array $rows)=>array_column($rows,'id');
    check($ids($service->browse(['q'=>'Numeric order scenario','sort'=>'price_asc']))===[$cheap,$dear],'Ascending order compares decimal strings lexically');
    check($ids($service->browse(['q'=>'Numeric order scenario','sort'=>'price_desc']))===[$dear,$cheap],'Descending price order incorrect');
    check($ids($service->browse(['q'=>'Numeric order scenario','sort'=>'newest']))===[$dear,$cheap],'Newest order refers to a non-model creation timestamp');
});
run_test('role foreign keys reject a buyer as seller and a seller as bidder or watcher',function()use($pdo,$auctionId,$sellerId,$buyerId){
    integrity_rejects(function()use($pdo,$auctionId,$buyerId){
        $s=$pdo->prepare('UPDATE Auction SET sellerID=? WHERE auctionID=?');$s->execute([$buyerId,$auctionId]);
    },'Buyer-only account accepted as Auction seller');
    integrity_rejects(function()use($pdo,$auctionId,$sellerId){
        $s=$pdo->prepare('INSERT INTO Bid(auctionID,buyerID,amount,placedAt) VALUES(?,?,?,?)');$s->execute([$auctionId,$sellerId,'10.00',gmdate('Y-m-d H:i:s')]);
    },'Seller-only account accepted as Bid buyer');
    integrity_rejects(function()use($pdo,$auctionId,$sellerId){
        $s=$pdo->prepare('INSERT INTO WatchlistEntry(buyerID,auctionID,watchedAt) VALUES(?,?,?)');$s->execute([$sellerId,$auctionId,gmdate('Y-m-d H:i:s')]);
    },'Seller-only account accepted as watcher');
});
run_test('item condition and category description persist, with the final title length',function()use($service,$pdo,$sellerId,$category){
    $id=$service->createAuction($sellerId,auction_data($category,['title'=>str_repeat('T',200),'item_condition'=>'Used, excellent condition']));
    check($service->auction($id)['item_condition']==='Used, excellent condition','Item condition missing');
    check($pdo->query('SELECT description FROM Category WHERE categoryID='.(int)$category)->fetchColumn()==='Category used for database integration checks','Category description missing');
    rejects(fn()=>$service->createAuction($sellerId,auction_data($category,['title'=>str_repeat('T',201)])),'Title above final model limit accepted');
});
run_test('optional reserve and exact decimal prices survive persistence and settlement',function()use($service,$pdo,$sellerId,$buyerId,$category){
    $id=$service->createAuction($sellerId,auction_data($category,['starting_price'=>'0.29','reserve_price'=>'','bid_increment'=>'0.01']));
    $a=$service->auction($id);
    check($a['reserve_cents']===null && $a['starting_cents']===29,'Optional reserve or exact starting price lost');
    $service->placeBid($buyerId,$id,'0.29');$service->placeBid($buyerId,$id,'0.30');
    $statement=$pdo->prepare('SELECT startingPrice,reservePrice,bidIncrement FROM Auction WHERE auctionID=?');$statement->execute([$id]);$stored=$statement->fetch();
    check((string)$stored['startingPrice']==='0.29' && $stored['reservePrice']===null && (string)$stored['bidIncrement']==='0.01','Prices not stored as canonical decimals');
    $statement=$pdo->prepare('SELECT amount FROM Bid WHERE auctionID=? ORDER BY amount DESC');$statement->execute([$id]);
    check((string)$statement->fetchColumn()==='0.30','Bid amount failed exact decimal roundtrip');
    expire($pdo,$id);$service->settleDue();$a=$service->auction($id);
    check($a['status']==='sold' && (int)$a['order']['winning_cents']===30,'No-reserve auction did not award the qualifying winner');
});
run_test('seller-only and self bidding are rejected',function()use($service,$sellerId,$auctionId,$bothId,$category){
    rejects(fn()=>$service->placeBid($sellerId,$auctionId,'20.00'),'Seller role placed bid');
    $own=$service->createAuction($bothId,auction_data($category));
    rejects(fn()=>$service->placeBid($bothId,$own,'20.00'),'Seller bid on own auction');
});
run_test('starting-price and increment rules use exact cents',function()use($service,$buyerId,$buyer2Id,$auctionId){
    rejects(fn()=>$service->placeBid($buyerId,$auctionId,'9.99'),'Below starting price accepted');
    $service->placeBid($buyerId,$auctionId,'10.00');
    rejects(fn()=>$service->placeBid($buyer2Id,$auctionId,'10.99'),'Below minimum increment accepted');
    $service->placeBid($buyer2Id,$auctionId,'11.00');
    $a=$service->auction($auctionId);
    check((int)$a['current_cents']===1100 && (int)$a['bid_count']===2,'Wrong latest price/count');
});
run_test('watching is unique and removable',function()use($service,$buyerId,$auctionId){
    $service->watch($buyerId,$auctionId,true);$service->watch($buyerId,$auctionId,true);
    $watching=$service->dashboard($buyerId)['watching'];
    check(count(array_filter($watching,fn($r)=>(int)$r['id']===$auctionId))===1,'Duplicate watch entry');
    $service->watch($buyerId,$auctionId,false);
    check(count($service->dashboard($buyerId)['watching'])===0,'Unwatch did not remove entry');
});
run_test('watchlist composite key and references reject duplicates and orphan records',function()use($service,$pdo,$buyerId,$auctionId){
    $service->watch($buyerId,$auctionId);
    $insert=function(int $buyer,int $auction)use($pdo):void {
        $s=$pdo->prepare('INSERT INTO WatchlistEntry(buyerID,auctionID,watchedAt) VALUES(?,?,?)');$s->execute([$buyer,$auction,gmdate('Y-m-d H:i:s')]);
    };
    integrity_rejects(fn()=>$insert($buyerId,$auctionId),'Duplicate watchlist composite key accepted');
    integrity_rejects(fn()=>$insert($buyerId,999999),'Orphan watch auction accepted');
    integrity_rejects(fn()=>$insert(999999,$auctionId),'Orphan watch buyer accepted');
    $service->watch($buyerId,$auctionId,false);
});
run_test('bids generate outbid notifications and queued email',function()use($service,$buyerId){
    check(count($service->notifications($buyerId))>0,'No notification to outbid buyer');
    check(count($service->emails($buyerId))>0,'No queued outbid email');
});
run_test('future auctions cannot receive bids',function()use($service,$sellerId,$buyerId,$category){
    $id=$service->createAuction($sellerId,auction_data($category,['starts_at'=>gmdate('Y-m-d H:i:s',time()+1800)]));
    rejects(fn()=>$service->placeBid($buyerId,$id,'10.00'),'Future auction accepted a bid');
});
run_test('reserve not met produces no order',function()use($service,$pdo,$auctionId){
    expire($pdo,$auctionId);$service->settleDue();$a=$service->auction($auctionId);
    check(empty($a['order']),'Reserve failure created order');
    check($a['status']!=='open','Expired auction stayed open');
});
run_test('no-bid closure produces no order',function()use($service,$pdo,$sellerId,$category){
    $id=$service->createAuction($sellerId,auction_data($category));expire($pdo,$id);$service->settleDue();
    check(empty($service->auction($id)['order']),'No-bid auction produced an order');
});
run_test('successful closure awards highest bid exactly once',function()use($service,$pdo,$sellerId,$buyerId,$buyer2Id,$category){
    $id=$service->createAuction($sellerId,auction_data($category));
    $service->placeBid($buyerId,$id,'20.00');$service->placeBid($buyer2Id,$id,'25.00');
    expire($pdo,$id);$service->settleDue();$a=$service->auction($id,$buyer2Id);
    check(!empty($a['order']),'Winning order missing');
    check((int)$a['order']['winning_cents']===2500,'Wrong winning bid');
    check($a['order']['winner_name']==='Buyer T','Wrong winner');
    $before=$pdo->query('SELECT COUNT(*) FROM SaleOrder')->fetchColumn();
    $service->settleDue();
    check($pdo->query('SELECT COUNT(*) FROM SaleOrder')->fetchColumn()===$before,'Closure created duplicate order');
    rejects(fn()=>$service->placeBid($buyerId,$id,'30.00'),'Expired auction accepted a bid');
});
run_test('public bid and outcome labels keep given names and hide the family name',function()use($service,$pdo,$sellerId,$category){
    $buyer=$service->register('Anne Marie','bidder-privacy@example.test','Test-Password-123!','buyer','van Dijk');
    $id=$service->createAuction($sellerId,auction_data($category));
    $service->placeBid((int)$buyer['id'],$id,'20.00');
    $a=$service->auction($id);
    check($a['bids'][0]['buyer_name']==='Anne Marie V','Public bid label is not first names plus uppercase last initial');
    check($a['seller_name']==='Seller One','Seller name was unexpectedly abbreviated');
    $private=$service->user((int)$buyer['id']);
    check($private['name']==='Anne Marie van Dijk' && $private['first_name']==='Anne Marie' && $private['last_name']==='van Dijk','Private account name was abbreviated');
    $statement=$pdo->prepare('SELECT firstName,lastName FROM User WHERE userID=?');$statement->execute([$buyer['id']]);
    check($statement->fetch()===['firstName'=>'Anne Marie','lastName'=>'van Dijk'],'Canonical name fields were changed');
    expire($pdo,$id);$service->settleDue();
    check($service->auction($id)['order']['winner_name']==='Anne Marie V','Public winner label exposes the family name');
    foreach([$sellerId,(int)$buyer['id']] as $viewer) {
        $orders=array_values(array_filter($service->dashboard($viewer)['orders'],fn($order)=>(int)$order['id']===$id));
        check(count($orders)===1 && $orders[0]['winner_name']==='Anne Marie V','Dashboard winner label differs from auction winner');
    }
});
run_test('only owner may cancel and bids prevent cancellation',function()use($service,$sellerId,$buyerId,$category){
    $id=$service->createAuction($sellerId,auction_data($category));
    rejects(fn()=>$service->cancelAuction($buyerId,$id),'Non-owner cancelled auction');
    $service->cancelAuction($sellerId,$id);
    rejects(fn()=>$service->placeBid($buyerId,$id,'20.00'),'Cancelled auction accepted bid');
    $id2=$service->createAuction($sellerId,auction_data($category));$service->placeBid($buyerId,$id2,'20.00');
    rejects(fn()=>$service->cancelAuction($sellerId,$id2),'Auction with bids cancelled');
});
run_test('foreign keys prevent orphan bids',function()use($pdo,$buyerId){
    rejects(function()use($pdo,$buyerId){
        $s=$pdo->prepare('INSERT INTO Bid (auctionID,buyerID,amount,placedAt) VALUES (?,?,?,?)');
        $s->execute([999999,$buyerId,'10.00',gmdate('Y-m-d H:i:s')]);
    },'Orphan bid accepted');
});

run_test('order winning bid must belong to the same auction',function()use($service,$pdo,$sellerId,$buyerId,$category){
    $one=$service->createAuction($sellerId,auction_data($category));
    $two=$service->createAuction($sellerId,auction_data($category));
    $bid=$service->placeBid($buyerId,$one,'20.00');
    try {
        $s=$pdo->prepare('INSERT INTO SaleOrder(auctionID,winningBidID,status,createdAt) VALUES(?,?,?,?)');
        $s->execute([$two,$bid,'awaiting_payment',gmdate('Y-m-d H:i:s')]);
    } catch(PDOException $e) {
        check(str_starts_with((string)$e->getCode(),'23'),'Not an integrity rejection');return;
    }
    throw new RuntimeException('Order accepted a winning bid from a different auction');
});
run_test('sale order auction and winning bid cardinalities are unique',function()use($service,$pdo,$sellerId,$buyerId,$category){
    $one=$service->createAuction($sellerId,auction_data($category));
    $first=$service->placeBid($buyerId,$one,'20.00');$second=$service->placeBid($buyerId,$one,'21.00');
    expire($pdo,$one);$service->settleDue();
    integrity_rejects(function()use($pdo,$one,$first){
        $s=$pdo->prepare('INSERT INTO SaleOrder(auctionID,winningBidID,status,createdAt) VALUES(?,?,?,?)');$s->execute([$one,$first,'awaiting_payment',gmdate('Y-m-d H:i:s')]);
    },'Multiple SaleOrders for the same auction accepted');
    $statement=$pdo->prepare('SELECT winningBidID FROM SaleOrder WHERE auctionID=?');$statement->execute([$one]);
    check((int)$statement->fetchColumn()===$second,'Order failed to select the highest bid');
});
run_test('collaborative recommendations follow overlapping bidder history',function()use($service,$sellerId,$buyerId,$buyer2Id,$category){
    $shared=$service->createAuction($sellerId,auction_data($category,['title'=>'Shared interest']));
    $service->placeBid($buyerId,$shared,'10.00');$service->placeBid($buyer2Id,$shared,'11.00');
    $candidate=$service->createAuction($sellerId,auction_data($category,['title'=>'Recommended candidate']));
    $service->placeBid($buyer2Id,$candidate,'10.00');
    $recs=$service->dashboard($buyerId)['recommendations'];
    $ids=array_map(fn($r)=>(int)$r['id'],$recs);
    check(in_array($candidate,$ids,true),'No collaborative candidate from overlapping bidder history');
    check(!in_array($shared,$ids,true),'Already-bid auction recommended');
});
run_test('notification and email lists remain scoped to the recipient',function()use($service,$buyerId,$buyer2Id,$bothId,$sellerId,$category){
    $marker='Private watch notification 72851';
    $id=$service->createAuction($sellerId,auction_data($category,['title'=>$marker]));
    $service->watch($bothId,$id,true);$service->placeBid($buyer2Id,$id,'10.00');
    check(str_contains(json_encode($service->emails($bothId)),$marker),'Watcher did not receive queued email');
    check(!str_contains(json_encode($service->emails($buyerId)),$marker),'Another user could read watcher email');
    check(!str_contains(json_encode($service->notifications($buyerId)),$marker),'Another user could read watcher notification');
    $service->markRead($buyerId);
    foreach($service->notifications($buyerId) as $notice) {
        check(!empty($notice['read_at']),'Notice stayed unread');
    }
    $other=$service->notifications($bothId);
    check(count($other)>0 && empty($other[0]['read_at']),'Mark-read changed another recipient');
});

// MySQL contention test uses independent PHP processes, avoiding inherited PDO sockets.
if ($mysql) {
    run_test('concurrent equal bids serialize: exactly one succeeds',function()use($service,$pdo,$sellerId,$buyerId,$buyer2Id,$category,$dsn,$user,$password){
        $id=$service->createAuction($sellerId,auction_data($category));
        // Hold the auction lock so independently launched bidders contend on release.
        $pdo->beginTransaction();
        $pdo->query('SELECT auctionID FROM Auction WHERE auctionID='.(int)$id.' FOR UPDATE')->fetch();
        $workers=[];
        foreach([$buyerId,$buyer2Id] as $bidder) {
            $command=[PHP_BINARY,__DIR__.'/bid_worker.php',(string)$id,(string)$bidder,'10.00'];
            $env=array_merge(getenv(),['TEST_DSN'=>$dsn,'TEST_DB_USER'=>$user,'TEST_DB_PASS'=>$password]);
            $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
            check(is_resource($p),'Could not spawn worker');fclose($pipes[0]);$workers[]=[$p,$pipes];
        }
        usleep(150000);
        $pdo->commit();
        $success=0;
        foreach($workers as [$p,$pipes]) {
            $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            $exit=proc_close($p);check(in_array($exit,[0,3],true),'Worker failed: '.$err.$out);
            if($exit===0)$success++;
        }
        check($success===1,'Expected one accepted bid, got '.$success);
        check((int)$service->auction($id)['bid_count']===1,'Race persisted multiple equal bids');
    });
    run_test('bid rechecks expiry after waiting for the auction lock',function()use($service,$pdo,$sellerId,$buyerId,$category,$dsn,$user,$password){
        $id=$service->createAuction($sellerId,auction_data($category));
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('UPDATE Auction SET endsAt=? WHERE auctionID=?');
        $stmt->execute([gmdate('Y-m-d H:i:s',time()-1),$id]);
        $env=array_merge(getenv(),['TEST_DSN'=>$dsn,'TEST_DB_USER'=>$user,'TEST_DB_PASS'=>$password]);
        $p=proc_open([PHP_BINARY,__DIR__.'/bid_worker.php',(string)$id,(string)$buyerId,'10.00'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        check(is_resource($p),'Could not spawn worker');fclose($pipes[0]);
        usleep(150000);$pdo->commit();
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        check(proc_close($p)===3,'Waiting bid accepted after committed expiry: '.$out.$err);
        check((int)$service->auction($id)['bid_count']===0,'Expired bid persisted');
    });
    run_test('concurrent settlements create one outcome and one notice per recipient',function()use($service,$pdo,$sellerId,$buyerId,$category,$dsn,$user,$password){
        $service->settleDue(); // Clear any other expired fixtures first.
        $id=$service->createAuction($sellerId,auction_data($category));$service->placeBid($buyerId,$id,'20.00');
        expire($pdo,$id);
        $pdo->beginTransaction();$pdo->query('SELECT auctionID FROM Auction WHERE auctionID='.(int)$id.' FOR UPDATE')->fetch();
        $workers=[];
        for($i=0;$i<2;$i++) {
            $env=array_merge(getenv(),['TEST_DSN'=>$dsn,'TEST_DB_USER'=>$user,'TEST_DB_PASS'=>$password]);
            $p=proc_open([PHP_BINARY,__DIR__.'/settle_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
            check(is_resource($p),'Could not spawn settlement worker');fclose($pipes[0]);$workers[]=[$p,$pipes];
        }
        usleep(150000);$pdo->commit();$settled=0;
        foreach($workers as [$p,$pipes]) {
            $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            check(proc_close($p)===0,'Settlement worker failed: '.$err.$out);$settled+=(int)trim($out);
        }
        check($settled===1,'Workers settled the same auction twice');
        $s=$pdo->prepare('SELECT COUNT(*) FROM SaleOrder WHERE auctionID=?');$s->execute([$id]);
        check((int)$s->fetchColumn()===1,'Duplicate or missing winning order');
        $s=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE auction_id=?');$s->execute([$id]);
        check((int)$s->fetchColumn()===2,'Expected exactly one notification each for winner and seller');
    });
}

echo "\n$passed passed; $failed failed (".($mysql?'MySQL':'SQLite').").\n";
exit($failed===0?0:1);
