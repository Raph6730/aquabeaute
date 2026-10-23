<?php
/* Envoi des e-mails depuis la boîte de l'institut (SMTP IONOS) avec PHPMailer. */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/PHPMailer/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

final class Mail {
    /** En mode test, les e-mails sont gardés ici au lieu d'être envoyés. */
    public static array $outbox = [];

    static function send(string $to, string $toName, string $subject, string $html, array $attachments = [], ?string $replyTo = null): void {
        if ($to === '') return;
        $c = App::$cfg;
        if (($c['mail_mode'] ?? 'smtp') === 'test') { self::$outbox[] = compact('to', 'toName', 'subject', 'html', 'attachments', 'replyTo'); return; }
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = $c['smtp_host'] ?? 'smtp.ionos.fr';
        $m->Port = (int) ($c['smtp_port'] ?? 465);
        $m->SMTPSecure = $m->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $m->SMTPAuth = true;
        $m->Username = $c['smtp_user'] ?? '';
        $m->Password = $c['smtp_pass'] ?? '';
        $m->CharSet = 'UTF-8';
        $m->setFrom($c['sender_email'], $c['sender_name'] ?? 'Aqua Beauté');
        $m->addAddress($to, $toName);
        $m->addReplyTo($replyTo ?: ($c['shop_email'] ?? $c['sender_email']));
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body = $html;
        $m->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</li>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
        foreach ($attachments as $a) $m->addStringAttachment($a['data'], $a['name'], PHPMailer::ENCODING_BASE64, $a['type'] ?? 'application/pdf');
        $m->send();
    }

    static function safeSend(?string $to, string $subject, string $html): void {
        try { self::send((string) $to, 'Aqua Beauté', $subject, $html); } catch (Throwable $e) { error_log('[bons-cadeaux] e-mail : ' . $e->getMessage()); }
    }

    static function layout(string $body): string {
        return '<!doctype html><html><body style="margin:0;background:#F4FAFD;font-family:Arial,Helvetica,sans-serif;color:#2A2A33">'
            . '<div style="max-width:560px;margin:0 auto;padding:24px 16px"><div style="background:#fff;border-radius:16px;padding:28px 24px;border:1px solid #E6ECF0">'
            . '<div style="font-family:Georgia,serif;font-style:italic;font-size:28px;color:#B8006E;margin-bottom:16px">Aqua Beauté</div>'
            . '<div style="font-size:15px;line-height:1.6">' . $body . '</div></div>'
            . '<p style="font-size:12px;color:#8A929C;text-align:center;margin-top:16px">Aqua Beauté · Institut de beauté SPA · 8 rue du Maréchal Foch, 67190 Mutzig · 03 88 23 00 98<br>SARL AQUA BEAUTE au capital de 7 000 € · SIRET 798 632 329 00016 · TVA FR06 798 632 329</p>'
            . '</div></body></html>';
    }
}
