<?php
function nexa_smtp_ensure(): void { if(!table_exists('mail_log')) create_table('mail_log',['recipient','subject','status','created_at']); }
nexa_smtp_ensure();
function nexa_mail(string $to, string $subject, string $body): bool { nexa_smtp_ensure(); $from=(string)get_setting('smtp_from','no-reply@localhost'); $ok=@mail($to,$subject,$body,"From: NexaCMS <{$from}>\r\nContent-Type: text/plain; charset=UTF-8"); query("INSERT INTO mail_log (recipient,subject,status,created_at) VALUES (?,?,?,?)",[$to,$subject,$ok?'sent':'failed',date('Y-m-d H:i:s')]); return (bool)$ok; }
