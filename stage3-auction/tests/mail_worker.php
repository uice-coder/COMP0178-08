<?php
/** AI-assisted outbox tests. Every transport is a local callable; no email is sent. */
declare(strict_types=1);
require_once __DIR__.'/../src/Mailer.php';
$pdo=new PDO('sqlite::memory:','', '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec(file_get_contents(__DIR__.'/../sql/schema.sqlite.sql'));
$pdo->prepare('INSERT INTO User(email,firstName,lastName,passwordHash,createdAt) VALUES(?,?,?,?,?)')->execute(['recipient@example.test','Mail','Recipient','not-a-login-fixture',gmdate('Y-m-d H:i:s')]);
$pdo->exec('INSERT INTO Buyer(userID) VALUES(1)');
$worker=new OutboxWorker($pdo);$passed=0;$failed=0;
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function test(string $name,callable $fn):void {global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
function enqueue(PDO $db,string $body='Watch update'):int {
    $db->prepare('INSERT INTO notifications(user_id,kind,message,created_at) VALUES(?,?,?,?)')->execute([1,'watched_bid',$body,gmdate('Y-m-d H:i:s')]);
    $notice=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO email_outbox(notification_id,recipient,subject,body,status,attempts,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$notice,'recipient@example.test','Auction-it update',$body,'queued',0,gmdate('Y-m-d H:i:s')]);
    return (int)$db->lastInsertId();
}
function message(PDO $db,int $id):array {$s=$db->prepare('SELECT * FROM email_outbox WHERE id=?');$s->execute([$id]);return $s->fetch();}

test('successful callable transport records sent state and stable Message-ID',function()use($pdo,$worker){
    $id=enqueue($pdo,'Unique message body');$calls=[];
    $result=$worker->deliver(function(...$args)use(&$calls){$calls[]=$args;},1);
    verify($result===['sent'=>1,'failed'=>0],'Wrong delivery totals');
    verify(count($calls)===1 && $calls[0]===['recipient@example.test','Auction-it update','Unique message body','outbox-'.$id.'@auction-it.local'],'Incorrect transport arguments');
    $row=message($pdo,$id);verify($row['status']==='sent' && (int)$row['attempts']===1 && $row['sent_at']!==null,'Success state not persisted');
    $result=$worker->deliver(function()use(&$calls){$calls[]=[];},1);
    verify($result===['sent'=>0,'failed'=>0] && count($calls)===1,'Sent email delivered again');
});
test('transport failure remains queued and a later retry can succeed',function()use($pdo,$worker){
    $id=enqueue($pdo,'Retry fixture');
    $result=$worker->deliver(function(){throw new RuntimeException('Synthetic transport failure');},20);
    $row=message($pdo,$id);
    verify($result===['sent'=>0,'failed'=>1] && $row['status']==='queued' && (int)$row['attempts']===1 && $row['sent_at']===null,'Failure state is incorrect');
    $calls=0;$result=$worker->deliver(function()use(&$calls){$calls++;},1);$row=message($pdo,$id);
    verify($calls===1 && $result['sent']===1 && (int)$row['attempts']===2 && $row['status']==='sent','Retry failed to record successful delivery');
});
test('failed batch stops after one attempt instead of exhausting retries in a loop',function()use($pdo,$worker){
    $id=enqueue($pdo,'Failure backoff fixture');$calls=0;
    $worker->deliver(function()use(&$calls){$calls++;throw new RuntimeException('Synthetic second failure');},100);
    verify($calls===1 && (int)message($pdo,$id)['attempts']===1,'One invocation retried the same failure in a tight loop');
    // Exhaust this fixture deliberately; a later eligible row should still progress.
    $pdo->prepare('UPDATE email_outbox SET attempts=5 WHERE id=?')->execute([$id]);
    $next=enqueue($pdo,'Eligible after exhausted fixture');$calls=0;
    $worker->deliver(function()use(&$calls){$calls++;},20);
    verify($calls===1 && message($pdo,$next)['status']==='sent' && message($pdo,$id)['status']==='queued','Retry cap blocked another eligible message or resent exhausted message');
});
test('batch limit and zero limit leave other queued messages untouched',function()use($pdo,$worker){
    $a=enqueue($pdo,'Batch first');$b=enqueue($pdo,'Batch second');$calls=0;
    $worker->deliver(function()use(&$calls){$calls++;},0);verify($calls===0,'Zero limit sent a message');
    $worker->deliver(function()use(&$calls){$calls++;},1);
    verify($calls===1 && message($pdo,$a)['status']==='sent' && message($pdo,$b)['status']==='queued','Batch limit ignored');
    $worker->deliver(function(){},1);
});
test('one outbox row per notification is enforced by the database',function()use($pdo){
    $id=enqueue($pdo,'Unique notification fixture');$row=message($pdo,$id);
    try{$pdo->prepare('INSERT INTO email_outbox(notification_id,recipient,subject,body,status,attempts,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$row['notification_id'],'other@example.test','duplicate','duplicate','queued',0,gmdate('Y-m-d H:i:s')]);}
    catch(PDOException $e){verify(str_starts_with((string)$e->getCode(),'23'),'Failure was not an integrity violation');return;}
    throw new RuntimeException('Duplicate outbox notification accepted');
});
// Each header test must fail at validation, before any attempt to open a socket.
$old=[];foreach(['SMTP_HOST','SMTP_PORT','SMTP_SECURITY','MAIL_FROM'] as $key)$old[$key]=getenv($key);
putenv('SMTP_HOST=127.0.0.1');putenv('SMTP_PORT=9');putenv('SMTP_SECURITY=tls');putenv('MAIL_FROM=sender@example.test');
$mailer=new SmtpMailer();
foreach([
    ['recipient@example.test',"Subject\r\nBcc: injected@example.test",'valid-id@example.test','Invalid email header.'],
    ["recipient@example.test\r\nBcc: injected@example.test",'Subject','valid-id@example.test','SMTP needs valid sender and recipient email addresses.'],
    ['recipient@example.test','Subject',"id\r\nBcc: injected@example.test",'Invalid email header.'],
] as $case){
    test('SMTP rejects injected header before network: '.count(explode("\n",$case[0].$case[1].$case[2])).' lines',function()use($mailer,$case){
        try{$mailer->send($case[0],$case[1],'Body',$case[2]);}
        catch(RuntimeException $e){verify($e->getMessage()===$case[3],'Unexpected failure indicates header reached network: '.$e->getMessage());return;}
        throw new RuntimeException('Injected SMTP header accepted');
    });
}
test('SMTP validates sender before opening a network connection',function()use($mailer){
    putenv("MAIL_FROM=sender@example.test\r\nBcc: attacker@example.test");
    try{$mailer->send('recipient@example.test','Subject','Body','valid-id@example.test');}
    catch(RuntimeException $e){verify($e->getMessage()==='SMTP needs valid sender and recipient email addresses.','Sender was not validated before network');return;}
    throw new RuntimeException('Injected sender accepted');
});
foreach($old as $key=>$value){if($value===false)putenv($key);else putenv($key.'='.$value);}
echo "\n$passed passed; $failed failed (outbox; no external email).\n";exit($failed?1:0);
