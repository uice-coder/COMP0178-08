<?php
// AI-assisted implementation. Business rules are documented in docs/design-report.md.
declare(strict_types=1);
final class AuctionService {
    public function __construct(private PDO $db) {}
    private function all(string $sql, array $args = []): array { $s=$this->db->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql, array $args = []): ?array { $r=$this->all($sql,$args); return $r[0]??null; }
    private function run(string $sql, array $args = []): int { $s=$this->db->prepare($sql); $s->execute($args); return $s->rowCount(); }
    private function now(): string { return gmdate('Y-m-d H:i:s'); }
    private function mysql(): bool { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; }
    private function transaction(callable $fn): mixed {
        for($attempt=0;;$attempt++) {
            try {
                if($this->mysql()) $this->db->beginTransaction(); else $this->db->exec('BEGIN IMMEDIATE');
                $result=$fn();
                if($this->mysql()) $this->db->commit(); else $this->db->exec('COMMIT');
                return $result;
            } catch(Throwable $e) {
                if($this->mysql()) { if($this->db->inTransaction()) $this->db->rollBack(); }
                else { try {$this->db->exec('ROLLBACK');} catch(Throwable) {} }
                if($e instanceof PDOException && $attempt<2 && (in_array($e->getCode(),['40001','HY000'],true)) && (str_contains($e->getMessage(),'Deadlock') || str_contains($e->getMessage(),'locked') || str_contains($e->getMessage(),'Lock wait'))) { usleep(30000*($attempt+1)); continue; }
                throw $e;
            }
        }
    }
    public static function cents(string $value): int {
        $value=trim($value);
        if(!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$value)) throw new DomainException('Enter a positive price with at most two decimal places.');
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
        $cents=((int)$whole*100)+(int)str_pad($fraction,2,'0');
        if($cents<1 || $cents>9999999999) throw new DomainException('Price must be between £0.01 and £99,999,999.99.');
        return $cents;
    }
    private function date(string $input): string {
        $input=str_replace('T',' ',trim($input));
        if(strlen($input)===16) $input.=':00';
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$input,new DateTimeZone('UTC'));
        if(!$d || $d->format('Y-m-d H:i:s')!==$input) throw new DomainException('Enter a valid date and time (UTC).');
        return $input;
    }
    /** Convert validated integer pence to a DECIMAL value without floating point. */
    private static function decimal(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
    /** PDO MySQL DECIMAL and SQLite fixed-decimal TEXT both return exact strings. */
    private static function storedCents(mixed $value): int { return self::cents((string)$value); }
    private function personName(string $alias): string { return $this->mysql()?"CONCAT($alias.firstName,' ',$alias.lastName)":"$alias.firstName || ' ' || $alias.lastName"; }
    /** Public bidder labels retain given names and only the family-name initial. */
    private function bidderName(string $alias): string {
        return $this->mysql()?"CONCAT($alias.firstName,' ',UPPER(SUBSTR($alias.lastName,1,1)))":"$alias.firstName || ' ' || UPPER(SUBSTR($alias.lastName,1,1))";
    }
    private function auctionColumns(string $alias='a'): string {
        return "$alias.auctionID AS id,$alias.sellerID AS seller_id,$alias.categoryID AS category_id,$alias.title,$alias.description,$alias.itemCondition AS item_condition,$alias.startingPrice AS starting_cents,$alias.reservePrice AS reserve_cents,$alias.bidIncrement AS increment_cents,$alias.startsAt AS starts_at,$alias.endsAt AS ends_at,$alias.status";
    }
    public function user(int $id): ?array {
        $row=$this->one("SELECT u.userID AS id,u.firstName AS first_name,u.lastName AS last_name,u.email,u.createdAt AS created_at,
            CASE WHEN b.userID IS NOT NULL AND s.userID IS NOT NULL THEN 'both' WHEN b.userID IS NOT NULL THEN 'buyer' WHEN s.userID IS NOT NULL THEN 'seller' ELSE NULL END AS role
            FROM `User` u LEFT JOIN Buyer b ON b.userID=u.userID LEFT JOIN Seller s ON s.userID=u.userID WHERE u.userID=?",[$id]);
        if($row) $row['name']=trim($row['first_name'].' '.$row['last_name']);
        return $row;
    }
    private function requireUser(int $id, ?string $role=null): array {
        $u=$this->user($id);
        if(!$u || $u['role']===null) throw new DomainException('Please sign in with a buyer or seller account.');
        if($role && !in_array($u['role'],[$role,'both'],true)) throw new DomainException('This action requires a '.$role.' account.');
        return $u;
    }
    public function register(string $name,string $email,string $password,string $role,?string $lastName=null): array {
        // Four-argument callers may still pass a full name; the registration form supplies both names explicitly.
        if($lastName===null) { $parts=preg_split('/\s+/u',trim($name),2);$firstName=$parts[0]??'';$lastName=$parts[1]??''; }
        else { $firstName=trim($name);$lastName=trim($lastName); }
        $email=strtolower(trim($email));
        if(mb_strlen($firstName)<1 || mb_strlen($firstName)>100 || mb_strlen($lastName)<1 || mb_strlen($lastName)>100) throw new DomainException('First and last names must each contain 1–100 characters.');
        if(strlen($email)>255 || !filter_var($email,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$email)) throw new DomainException('Enter a valid email address.');
        if(strlen($password)<12 || strlen($password)>72 || str_contains($password,"\0")) throw new DomainException('Use a password of 12–72 bytes.');
        if(!in_array($role,['buyer','seller','both'],true)) throw new DomainException('Choose buyer, seller, or both.');
        try {
            return $this->transaction(function()use($firstName,$lastName,$email,$password,$role){
                $this->run('INSERT INTO `User`(firstName,lastName,email,passwordHash,createdAt) VALUES(?,?,?,?,?)',[$firstName,$lastName,$email,password_hash($password,PASSWORD_DEFAULT),$this->now()]);
                $id=(int)$this->db->lastInsertId();
                if($role==='buyer' || $role==='both') $this->run('INSERT INTO Buyer(userID) VALUES(?)',[$id]);
                if($role==='seller' || $role==='both') $this->run('INSERT INTO Seller(userID) VALUES(?)',[$id]);
                return $this->user($id);
            });
        } catch(PDOException $e) {if(in_array($e->getCode(),['23000','23505'],true)) throw new DomainException('An account with this email already exists.'); throw $e;}
    }
    public function authenticate(string $email,string $password): array {
        $row=$this->one('SELECT userID,passwordHash FROM `User` WHERE email=?',[strtolower(trim($email))]);
        // A fixed valid dummy hash keeps a missing-account failure on the password path.
        $hash=$row['passwordHash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if(!password_verify($password,$hash) || !$row) throw new DomainException('Email or password is incorrect.');
        return $this->requireUser((int)$row['userID']);
    }
    public function categories(): array { return $this->all('SELECT categoryID AS id,name,description FROM Category ORDER BY name'); }
    private function baseSql(): string { return "SELECT ".$this->auctionColumns().", ".$this->personName('u')." AS seller_name,c.name AS category_name,
        COALESCE((SELECT b.amount FROM Bid b WHERE b.auctionID=a.auctionID ORDER BY CAST(b.amount AS DECIMAL(10,2)) DESC,b.bidID ASC LIMIT 1),a.startingPrice) AS current_cents,
        (SELECT COUNT(*) FROM Bid b WHERE b.auctionID=a.auctionID) AS bid_count,
        (SELECT b.buyerID FROM Bid b WHERE b.auctionID=a.auctionID ORDER BY CAST(b.amount AS DECIMAL(10,2)) DESC,b.bidID ASC LIMIT 1) AS leading_buyer_id
        FROM Auction a JOIN Seller s ON s.userID=a.sellerID JOIN `User` u ON u.userID=s.userID JOIN Category c ON c.categoryID=a.categoryID"; }
    private function enrich(array $a): array {
        foreach(['id','seller_id','category_id','bid_count'] as $k) $a[$k]=(int)$a[$k];
        foreach(['starting_cents','increment_cents','current_cents'] as $k) $a[$k]=self::storedCents($a[$k]);
        $a['reserve_cents']=$a['reserve_cents']===null?null:self::storedCents($a['reserve_cents']);
        $a['leading_buyer_id']=$a['leading_buyer_id']===null?null:(int)$a['leading_buyer_id'];
        $a['min_bid_cents']=$a['bid_count']?$a['current_cents']+$a['increment_cents']:$a['starting_cents'];
        return $a;
    }
    public function browse(array $filters=[]): array {
        $where=[];$args=[];
        if(($q=trim((string)($filters['q']??'')))!=='') {
            // Bind complete LIKE patterns and escape wildcard input as a literal search.
            $q=str_replace(['!','%','_'],['!!','!%','!_'],$q);
            $where[]="(a.title LIKE ? ESCAPE '!' OR a.description LIKE ? ESCAPE '!')"; $args[]='%'.$q.'%';$args[]='%'.$q.'%';
        }
        if(!empty($filters['category'])) {$where[]='a.categoryID=?';$args[]=(int)$filters['category'];}
        $status=$filters['status']??'open';
        if(in_array($status,['open','sold','unsold','cancelled'],true)) {$where[]='a.status=?';$args[]=$status;}
        $sort=['ending'=>'a.endsAt ASC,a.auctionID ASC','price_asc'=>'CAST(current_cents AS DECIMAL(10,2)) ASC,a.auctionID ASC','price_desc'=>'CAST(current_cents AS DECIMAL(10,2)) DESC,a.auctionID ASC','newest'=>'a.auctionID DESC'][$filters['sort']??'ending']??'a.endsAt ASC,a.auctionID ASC';
        return array_map($this->enrich(...),$this->all($this->baseSql().($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY '.$sort,$args));
    }
    public function auction(int $id,?int $viewerId=null): array {
        // Read the summary, history and outcome from one consistent snapshot.
        return $this->transaction(function()use($id,$viewerId){
            $a=$this->one($this->baseSql().' WHERE a.auctionID=?',[$id]);
            if(!$a) throw new DomainException('Auction not found.');
            $a=$this->enrich($a);
            $a['bids']=$this->all('SELECT b.bidID AS id,b.buyerID AS buyer_id,'. $this->bidderName('u').' AS buyer_name,b.amount AS amount_cents,b.placedAt AS created_at FROM Bid b JOIN Buyer r ON r.userID=b.buyerID JOIN `User` u ON u.userID=r.userID WHERE b.auctionID=? ORDER BY b.placedAt DESC,b.bidID DESC',[$id]);
            foreach($a['bids'] as &$bid) $bid['amount_cents']=self::storedCents($bid['amount_cents']);unset($bid);
            $a['watching']=$viewerId!==null && $this->one('SELECT buyerID AS buyer_id FROM WatchlistEntry WHERE buyerID=? AND auctionID=?',[$viewerId,$id])!==null;
            $a['order']=$this->one('SELECT o.orderID AS id,o.status,o.createdAt AS created_at,'. $this->bidderName('u').' AS winner_name,b.amount AS winning_cents FROM SaleOrder o JOIN Bid b ON b.auctionID=o.auctionID AND b.bidID=o.winningBidID JOIN `User` u ON u.userID=b.buyerID WHERE o.auctionID=?',[$id]);
            if($a['order']) $a['order']['winning_cents']=self::storedCents($a['order']['winning_cents']);
            return $a;
        });
    }
    public function createAuction(int $userId,array $input): int {
        $this->requireUser($userId,'seller');
        $title=trim((string)($input['title']??'')); $description=trim((string)($input['description']??''));
        if(mb_strlen($title)<3 || mb_strlen($title)>200) throw new DomainException('Title must contain 3–200 characters.');
        if(mb_strlen($description)<10 || mb_strlen($description)>10000) throw new DomainException('Description must contain 10–10,000 characters.');
        $condition=trim((string)($input['item_condition']??''));
        if(mb_strlen($condition)>100) throw new DomainException('Item condition must contain at most 100 characters.');
        $category=(int)($input['category_id']??0);
        if(!$this->one('SELECT categoryID FROM Category WHERE categoryID=?',[$category])) throw new DomainException('Choose an existing category.');
        $starting=self::cents((string)($input['starting_price']??''));
        $reserveInput=trim((string)($input['reserve_price']??''));
        $reserve=$reserveInput===''?null:self::cents($reserveInput);
        $increment=self::cents((string)($input['bid_increment']??'1.00'));
        if($reserve!==null && $reserve<$starting) throw new DomainException('Reserve must be at least the starting price.');
        $start=empty($input['starts_at'])?$this->now():$this->date((string)$input['starts_at']);
        $end=$this->date((string)($input['ends_at']??''));
        if($end<=$this->now() || $end<=$start) throw new DomainException('End time must be in the future and after the start.');
        $this->run('INSERT INTO Auction(sellerID,categoryID,title,description,itemCondition,startingPrice,reservePrice,bidIncrement,startsAt,endsAt,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$userId,$category,$title,$description,$condition===''?null:$condition,self::decimal($starting),$reserve===null?null:self::decimal($reserve),self::decimal($increment),$start,$end,'open']);
        return (int)$this->db->lastInsertId();
    }
    private function lockedAuction(int $id): array {
        $a=$this->one('SELECT '.$this->auctionColumns().' FROM Auction a WHERE a.auctionID=?'.($this->mysql()?' FOR UPDATE':''),[$id]);
        if(!$a) throw new DomainException('Auction not found.');
        foreach(['starting_cents','increment_cents'] as $key) $a[$key]=self::storedCents($a[$key]);
        $a['reserve_cents']=$a['reserve_cents']===null?null:self::storedCents($a['reserve_cents']);
        return $a;
    }
    private function topBid(int $id): ?array {
        $bid=$this->one('SELECT bidID AS id,auctionID AS auction_id,buyerID AS buyer_id,amount AS amount_cents,placedAt AS created_at FROM Bid WHERE auctionID=? ORDER BY CAST(amount AS DECIMAL(10,2)) DESC,bidID ASC LIMIT 1',[$id]);
        if($bid) $bid['amount_cents']=self::storedCents($bid['amount_cents']);
        return $bid;
    }
    private function notify(int $userId,int $auctionId,string $kind,string $message): void {
        $user=$this->requireUser($userId);
        $now=$this->now();
        $this->run('INSERT INTO notifications(user_id,auction_id,kind,message,created_at) VALUES(?,?,?,?,?)',[$userId,$auctionId,$kind,$message,$now]);
        $notification=(int)$this->db->lastInsertId();
        $this->run('INSERT INTO email_outbox(notification_id,recipient,subject,body,status,attempts,created_at) VALUES(?,?,?,?,?,?,?)',[$notification,$user['email'],'Auction-it: '.str_replace('_',' ',$kind),$message.' (Auction #'.$auctionId.')','queued',0,$now]);
    }
    public function placeBid(int $userId,int $auctionId,string $amount): int {
        $this->requireUser($userId,'buyer'); $cents=self::cents($amount);
        return $this->transaction(function()use($userId,$auctionId,$cents){
            $a=$this->lockedAuction($auctionId);$now=$this->now();
            if((int)$a['seller_id']===$userId) throw new DomainException('You cannot bid on your own auction.');
            if($a['status']!=='open' || $a['ends_at']<=$now) throw new DomainException('This auction has ended.');
            if($a['starts_at']>$now) throw new DomainException('This auction has not started yet.');
            $top=$this->topBid($auctionId);
            $min=$top?(int)$top['amount_cents']+(int)$a['increment_cents']:(int)$a['starting_cents'];
            if($cents<$min) throw new DomainException('Bid at least £'.number_format($min/100,2,'.','').'.');
            $this->run('INSERT INTO Bid(auctionID,buyerID,amount,placedAt) VALUES(?,?,?,?)',[$auctionId,$userId,self::decimal($cents),$now]);
            $bidId=(int)$this->db->lastInsertId();
            $notified=[];
            if($top && (int)$top['buyer_id']!==$userId) {
                $this->notify((int)$top['buyer_id'],$auctionId,'outbid','You were outbid on '.$a['title'].'. The highest bid is now £'.number_format($cents/100,2,'.','').'.');
                $notified[]=(int)$top['buyer_id'];
            }
            foreach($this->all('SELECT buyerID AS buyer_id FROM WatchlistEntry WHERE auctionID=? ORDER BY buyerID',[$auctionId]) as $w) {
                $uid=(int)$w['buyer_id'];
                if($uid!==$userId && !in_array($uid,$notified,true)) $this->notify($uid,$auctionId,'watched_bid','A new bid of £'.number_format($cents/100,2,'.','').' was placed on '.$a['title'].'.');
            }
            return $bidId;
        });
    }
    public function watch(int $userId,int $auctionId,bool $watch=true): void {
        $this->requireUser($userId,'buyer');
        $this->transaction(function()use($userId,$auctionId,$watch){
            $a=$this->lockedAuction($auctionId);
            if($watch) {
                if((int)$a['seller_id']===$userId) throw new DomainException('Your own auctions are already in your seller dashboard.');
                if(!$this->one('SELECT buyerID AS buyer_id FROM WatchlistEntry WHERE buyerID=? AND auctionID=?',[$userId,$auctionId])) $this->run('INSERT INTO WatchlistEntry(buyerID,auctionID,watchedAt) VALUES(?,?,?)',[$userId,$auctionId,$this->now()]);
            } else $this->run('DELETE FROM WatchlistEntry WHERE buyerID=? AND auctionID=?',[$userId,$auctionId]);
        });
    }
    public function cancelAuction(int $userId,int $auctionId): void {
        $this->requireUser($userId,'seller');
        $this->transaction(function()use($userId,$auctionId){
            $a=$this->lockedAuction($auctionId);
            if((int)$a['seller_id']!==$userId) throw new DomainException('Only the seller can cancel this auction.');
            if($a['status']!=='open' || $a['ends_at']<=$this->now() || $this->topBid($auctionId)) throw new DomainException('Only an unbid, unexpired open auction can be cancelled.');
            $this->run("UPDATE Auction SET status='cancelled' WHERE auctionID=?",[$auctionId]);
            foreach($this->all('SELECT buyerID AS buyer_id FROM WatchlistEntry WHERE auctionID=? ORDER BY buyerID',[$auctionId]) as $w) $this->notify((int)$w['buyer_id'],$auctionId,'cancelled',$a['title'].' was cancelled by its seller.');
        });
    }
    public function settleDue(): int {
        $ids=$this->all("SELECT auctionID AS id FROM Auction WHERE status='open' AND endsAt<=? ORDER BY auctionID",[$this->now()]); $count=0;
        foreach($ids as $id) {
            $count+=(int)$this->transaction(function()use($id){
                $a=$this->lockedAuction((int)$id['id']);
                if($a['status']!=='open' || $a['ends_at']>$this->now()) return 0;
                $top=$this->topBid((int)$a['id']);
                $sold=$top && ($a['reserve_cents']===null || (int)$top['amount_cents']>=(int)$a['reserve_cents']);
                $this->run('UPDATE Auction SET status=? WHERE auctionID=?',[$sold?'sold':'unsold',$a['id']]);
                $people=[(int)$a['seller_id']=>true];
                if($sold) {
                    $this->run("INSERT INTO SaleOrder(auctionID,winningBidID,status,createdAt) VALUES(?,?,'awaiting_payment',?)",[$a['id'],$top['id'],$this->now()]);
                    $people[(int)$top['buyer_id']]=true;
                }
                foreach($this->all('SELECT DISTINCT buyerID AS buyer_id FROM Bid WHERE auctionID=? UNION SELECT buyerID AS buyer_id FROM WatchlistEntry WHERE auctionID=?',[$a['id'],$a['id']]) as $r) $people[(int)$r['buyer_id']]=true;
                ksort($people);
                foreach(array_keys($people) as $uid) {
                    $outcome=$sold?'sold':'unsold';
                    $message=$a['title'].($sold?' sold for £'.number_format((int)$top['amount_cents']/100,2,'.','').'.':' ended without a qualifying winning bid.');
                    if($sold && $uid===(int)$top['buyer_id']) { $outcome='won';$message='You won! '.$message; }
                    elseif($uid===(int)$a['seller_id']) $message='Your auction '.$message;
                    $this->notify($uid,(int)$a['id'],$outcome,$message);
                }
                return 1;
            });
        }
        return $count;
    }
    public function notifications(int $userId): array { $this->requireUser($userId);return $this->all('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC',[$userId]); }
    public function markRead(int $userId): void { $this->requireUser($userId);$this->run('UPDATE notifications SET read_at=? WHERE user_id=? AND read_at IS NULL',[$this->now(),$userId]); }
    public function emails(int $userId): array { $this->requireUser($userId);return $this->all('SELECT e.id,e.subject,e.body,e.status,e.attempts,e.created_at,e.sent_at FROM email_outbox e JOIN notifications n ON n.id=e.notification_id WHERE n.user_id=? ORDER BY e.id DESC',[$userId]); }
    public function recommendations(int $userId): array {
        // User-based implicit collaborative filtering. Count common auctions with peers,
        // then score unseen candidates by the sum of peer-overlap weights.
        $this->requireUser($userId);
        $own=array_map('intval',array_column($this->all('SELECT DISTINCT auctionID AS auction_id FROM Bid WHERE buyerID=?',[$userId]),'auction_id'));
        if(!$own) return [];
        $overlap=$this->all('SELECT peer.buyerID AS buyer_id,COUNT(DISTINCT peer.auctionID) AS overlap_count FROM Bid peer JOIN Bid own ON own.auctionID=peer.auctionID WHERE own.buyerID=? AND peer.buyerID<>? GROUP BY peer.buyerID',[$userId,$userId]);
        $scores=[];
        foreach($overlap as $p) {
            foreach($this->all('SELECT DISTINCT auctionID AS auction_id FROM Bid WHERE buyerID=?',[$p['buyer_id']]) as $r) {
                $id=(int)$r['auction_id']; if(!in_array($id,$own,true)) $scores[$id]=($scores[$id]??0)+(int)$p['overlap_count'];
            }
        }
        $rows=[];
        foreach($this->browse(['status'=>'open']) as $a) if(isset($scores[$a['id']]) && $a['seller_id']!==$userId && $a['starts_at']<=$this->now() && $a['ends_at']>$this->now()) { $a['score']=$scores[$a['id']];$rows[]=$a; }
        usort($rows,fn($a,$b)=>($b['score']<=>$a['score'])?:($a['id']<=>$b['id']));
        return array_slice($rows,0,6);
    }
    public function dashboard(int $userId): array {
        $this->requireUser($userId);$rows=$this->browse(['status'=>'all','sort'=>'newest']);
        $mine=array_values(array_filter($rows,fn($a)=>$a['seller_id']===$userId));
        $maxima=[];foreach($this->all('SELECT auctionID AS auction_id,amount AS your_max_cents FROM Bid WHERE buyerID=?',[$userId]) as $b) {
            $aid=(int)$b['auction_id'];$maxima[$aid]=max($maxima[$aid]??0,self::storedCents($b['your_max_cents']));
        }
        $bids=[];foreach($rows as $a) if(isset($maxima[$a['id']])) {$a['your_max_cents']=$maxima[$a['id']];$bids[]=$a;}
        $watchids=array_map('intval',array_column($this->all('SELECT auctionID AS auction_id FROM WatchlistEntry WHERE buyerID=?',[$userId]),'auction_id'));
        $watching=array_values(array_filter($rows,fn($a)=>in_array($a['id'],$watchids,true)));
        $orders=[];
        foreach($this->all('SELECT o.orderID AS order_id,o.auctionID AS auction_id,o.status AS order_status,b.amount AS winning_cents,'. $this->bidderName('u').' AS winner_name FROM SaleOrder o JOIN Bid b ON b.auctionID=o.auctionID AND b.bidID=o.winningBidID JOIN `User` u ON u.userID=b.buyerID JOIN Auction a ON a.auctionID=o.auctionID WHERE b.buyerID=? OR a.sellerID=? ORDER BY o.orderID DESC',[$userId,$userId]) as $o) {
            $o['winning_cents']=self::storedCents($o['winning_cents']);
            foreach($rows as $a) if($a['id']===(int)$o['auction_id']) {$orders[]=array_merge($a,$o);break;}
        }
        return ['mine'=>$mine,'bids'=>$bids,'watching'=>$watching,'orders'=>$orders,'recommendations'=>$this->recommendations($userId)];
    }
}
