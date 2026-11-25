<?php
// Configure SMTP and explicitly run --send to deliver queued mail. Never invoked by HTTP.
declare(strict_types=1);
require_once __DIR__.'/../stage2/db.php';
require_once __DIR__.'/../src/Mailer.php';
try {
    $db=connect_db();
    $queued=$db->query("SELECT COUNT(*) FROM email_outbox WHERE status='queued'")->fetchColumn();
    if(!in_array('--send',$argv,true) || getenv('MAIL_TRANSPORT')!=='smtp') {
        echo "$queued emails queued. No external mail sent. Configure MAIL_TRANSPORT=smtp and run with --send to deliver.\n";exit(0);
    }
    $mailer=new SmtpMailer();
    $result=(new OutboxWorker($db))->deliver($mailer->send(...));
    echo $result['sent']." accepted by SMTP; ".$result['failed']." failed.\n";
    if($result['failed']) exit(1);
} catch(Throwable $e) {fwrite(STDERR,'Email worker failed: '.$e->getMessage()."\n");exit(1);}
