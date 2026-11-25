<?php
// AI-assisted minimal SMTP transport. Default mode never sends external email.
declare(strict_types=1);
final class SmtpMailer {
    private $stream;
    private function read(int ...$expected): string {
        $reply='';
        do {
            $line=fgets($this->stream,4096);
            if($line===false) throw new RuntimeException('SMTP connection ended unexpectedly.');
            $reply.=$line;
            if(strlen($reply)>32768) throw new RuntimeException('SMTP response too long.');
        } while(strlen($line)>=4 && $line[3]==='-');
        if(!in_array((int)substr($reply,0,3),$expected,true)) throw new RuntimeException('SMTP command was rejected (code '.substr($reply,0,3).').');
        return $reply;
    }
    private function command(string $text,int ...$expected): string {
        if(fwrite($this->stream,$text."\r\n")===false) throw new RuntimeException('SMTP write failed.');
        return $this->read(...$expected);
    }
    public function send(string $recipient,string $subject,string $body,string $messageId): void {
        $host=getenv('SMTP_HOST')?:'';$port=(int)(getenv('SMTP_PORT')?:587);
        $security=getenv('SMTP_SECURITY')?:'tls';$from=getenv('MAIL_FROM')?:'';
        foreach([$recipient,$from] as $address) if(!filter_var($address,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$address)) throw new RuntimeException('SMTP needs valid sender and recipient email addresses.');
        if(preg_match('/[\r\n]/',$subject) || !preg_match('/^[A-Za-z0-9_.@-]+$/D',$messageId)) throw new RuntimeException('Invalid email header.');
        if($host==='' || !preg_match('/^[A-Za-z0-9.-]+$/D',$host) || !in_array($security,['tls','ssl'],true) || $port<1 || $port>65535) throw new RuntimeException('Configure an SMTP host, TLS/SSL security, port and sender.');
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);
        $prefix=$security==='ssl'?'ssl://':'tcp://';
        $this->stream=@stream_socket_client($prefix.$host.':'.$port,$errno,$error,15,STREAM_CLIENT_CONNECT,$context);
        if(!$this->stream) throw new RuntimeException('Could not connect to the configured SMTP server.');
        stream_set_timeout($this->stream,15);
        try {
            $this->read(220);$this->command('EHLO auction-it.local',250);
            if($security==='tls') {
                $this->command('STARTTLS',220);
                if(!stream_socket_enable_crypto($this->stream,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS negotiation failed.');
                $this->command('EHLO auction-it.local',250);
            }
            $user=getenv('SMTP_USER')?:'';
            if($user!=='') {
                $this->command('AUTH LOGIN',334);
                $this->command(base64_encode($user),334);
                $this->command(base64_encode(getenv('SMTP_PASSWORD')?:''),235);
            }
            $this->command('MAIL FROM:<'.$from.'>',250);
            $this->command('RCPT TO:<'.$recipient.'>',250,251);
            $this->command('DATA',354);
            $headers=['From: '.$from,'To: '.$recipient,'Subject: =?UTF-8?B?'.base64_encode($subject).'?=','Date: '.gmdate('D, d M Y H:i:s').' +0000','Message-ID: <'.$messageId.'>','MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'];
            $payload=implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
            $payload=preg_replace('/(?m)^\./','..',$payload);
            $this->command(rtrim($payload,"\r\n")."\r\n.",250);
            // A disconnect after DATA acceptance must not cause an immediate resend.
            try {$this->command('QUIT',221);} catch(Throwable) {}
        } finally {fclose($this->stream);}
    }
}
final class OutboxWorker {
    public function __construct(private PDO $db) {}
    public function deliver(callable $send,int $limit=20): array {
        $mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $result=['sent'=>0,'failed'=>0];
        for($i=0;$i<max(0,min($limit,100));$i++) {
            if($mysql) $this->db->beginTransaction(); else $this->db->exec('BEGIN IMMEDIATE');
            try {
                $stmt=$this->db->query("SELECT * FROM email_outbox WHERE status='queued' AND attempts<5 ORDER BY id LIMIT 1".($mysql?' FOR UPDATE':''));
                $row=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$row) {if($mysql)$this->db->commit();else $this->db->exec('COMMIT');break;}
                $failed=false;
                try {$send($row['recipient'],$row['subject'],$row['body'],'outbox-'.$row['id'].'@auction-it.local');}
                catch(Throwable $e) {$failed=true;error_log('Outbox message '.$row['id'].' failed: '.$e->getMessage());}
                $q=$this->db->prepare('UPDATE email_outbox SET attempts=attempts+1,status=?,sent_at=? WHERE id=?');
                $q->execute([$failed?'queued':'sent',$failed?null:gmdate('Y-m-d H:i:s'),$row['id']]);
                if($mysql) $this->db->commit();else $this->db->exec('COMMIT');
                $result[$failed?'failed':'sent']++;
                if($failed) break; // Avoid retrying a failing server in a tight loop.
            } catch(Throwable $e) {
                if($mysql && $this->db->inTransaction()) $this->db->rollBack();
                elseif(!$mysql) {try{$this->db->exec('ROLLBACK');}catch(Throwable){}}
                throw $e;
            }
        }
        return $result;
    }
}
