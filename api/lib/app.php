<?php
/*
 * Moteur des bons cadeaux Aqua Beauté — version PHP / MySQL pour l'hébergement IONOS.
 *
 *  POST /api/orders                    le site envoie le panier → prix recalculé ici, commande PayPal créée
 *  POST /api/orders/{id}/capture       après validation PayPal → encaissement vérifié, bons, PDF, e-mails
 *  POST /api/paypal/webhook            notifications PayPal (paiement confirmé plus tard, page fermée, remboursement)
 *  GET  /api/verifier                  page privée de l'institut (code PIN)
 *  GET  /api/vouchers                  liste des bons (PIN)
 *  GET  /api/vouchers/export.csv       export Excel des bons (PIN)
 *  GET  /api/vouchers/{code}           détail d'un bon (PIN)
 *  POST /api/vouchers/{code}/use       marquer utilisé (PIN)          POST …/unuse  annuler (PIN)
 *  GET  /api/cron/recap?cle=…          récapitulatif + sauvegarde par e-mail (tâche planifiée)
 *  POST /api/contact                   formulaire « Être rappelée » → e-mail à l'institut
 *  GET  /api/sante                     contrôle de bon fonctionnement
 */
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/mail.php';

final class Fail extends Exception {
    public int $status; public array $extra;
    function __construct(string $msg, int $status = 400, array $extra = []) { parent::__construct($msg); $this->status = $status; $this->extra = $extra; }
}

/* Appels HTTP sortants (PayPal). Remplaçable dans les tests. */
final class Http {
    /** @var callable|null */ public static $mock = null;
    static function request(string $method, string $url, array $headers = [], ?string $body = null): array {
        if (self::$mock) return (self::$mock)($method, $url, $headers, $body);
        $ch = curl_init($url);
        $h = []; foreach ($headers as $k => $v) $h[] = "$k: $v";
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false) { $err = curl_error($ch); curl_close($ch); throw new Fail('Connexion à PayPal impossible pour le moment.', 502, ['detail' => $err]); }
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $out];
    }
}

final class App {
    const ALPH = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const OCCASIONS = ['Joyeux anniversaire', 'Bonne fête'];
    const TYPES = ['montant' => 'Montant (toutes prestations)', 'soin' => 'Soin précis', 'accessoire' => 'Accessoires'];

    static array $cfg = [];
    static ?PDO $db = null;

    /* ---------------- point d'entrée ---------------- */
    static function handle(array $cfg, string $method, string $path, array $headers, string $body, string $ip, array $query): array {
        self::$cfg = $cfg;
        $headers = array_change_key_case($headers, CASE_LOWER);
        $p = '/' . trim(preg_replace('#^.*?/api(?=/|$)#', '/api', $path), '/');
        $cors = self::cors($headers);
        try {
            if ($method === 'OPTIONS') return [204, $cors, ''];
            if ($p === '/api/sante') { self::db(); return self::json(['ok' => true, 'paypal' => self::cfg('paypal_env', 'sandbox')]); }
            if ($p === '/api/verifier' && $method === 'GET')
                return [200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex', 'Cache-Control' => 'no-store'], file_get_contents(__DIR__ . '/verifier.html')];
            if ($p === '/api/orders' && $method === 'POST') return self::withCors(self::createOrder(self::body($body)), $cors);
            if (preg_match('#^/api/orders/([A-Z0-9]+)/capture$#', $p, $m) && $method === 'POST') return self::withCors(self::json(self::captureOrder($m[1])), $cors);
            if ($p === '/api/paypal/webhook' && $method === 'POST') return self::webhook($headers, $body);
            if ($p === '/api/contact' && $method === 'POST') return self::withCors(self::contact(self::body($body), $ip), $cors);
            if ($p === '/api/cron/recap' && $method === 'GET') return self::recap($query);
            if (str_starts_with($p, '/api/vouchers')) {
                if ($r = self::checkPin($headers, $ip)) return $r;
                if ($p === '/api/vouchers' && $method === 'GET') return self::listVouchers();
                if ($p === '/api/vouchers/export.csv' && $method === 'GET') return self::exportCsv();
                if (preg_match('#^/api/vouchers/([A-Z0-9-]+)$#', $p, $m) && $method === 'GET') return self::getVoucher($m[1]);
                if (preg_match('#^/api/vouchers/([A-Z0-9-]+)/(use|unuse)$#', $p, $m) && $method === 'POST') return self::useVoucher($m[1], $m[2] === 'use');
            }
            return self::json(['error' => 'Introuvable'], 404);
        } catch (Fail $e) {
            return self::withCors(self::json(['error' => $e->getMessage()] + $e->extra, $e->status), $cors);
        } catch (Throwable $e) {
            error_log('[bons-cadeaux] ' . $e);
            return self::withCors(self::json(['error' => 'Erreur interne, réessayez ou appelez l\'institut au 03 88 23 00 98.'], 500), $cors);
        }
    }

    /* ---------------- outils ---------------- */
    static function cfg(string $k, $def = null) { return self::$cfg[$k] ?? $def; }
    static function json($data, int $status = 200): array { return [$status, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]; }
    static function body(string $b): array { $j = json_decode($b, true); return is_array($j) ? $j : []; }
    static function clean($s, int $n): string {
        $s = preg_replace('/[\x00-\x1F<>]/u', ' ', (string) ($s ?? '')); $s = trim(preg_replace('/\s+/u', ' ', $s));
        return mb_substr($s, 0, $n);
    }
    static function euros($n): string { return number_format(round((float) $n, 2), 2, '.', ''); }
    static function frDate(int $ts): string {
        $m = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone('Europe/Paris'));
        return $d->format('j') . ' ' . $m[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
    }
    static function esc($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    static function cors(array $h): array {
        $o = $h['origin'] ?? ''; $allowed = array_filter(array_map('trim', explode(',', (string) self::cfg('allowed_origin', ''))));
        return in_array($o, $allowed, true) ? ['Access-Control-Allow-Origin' => $o, 'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS', 'Access-Control-Allow-Headers' => 'content-type', 'Vary' => 'Origin'] : [];
    }
    static function withCors(array $r, array $cors): array { $r[1] = $cors + $r[1]; return $r; }
    static function now(): int { return time(); }
    static function label(array $v): string {
        return $v['type'] === 'soin' ? 'Bon cadeau · ' . $v['soin'] : ($v['type'] === 'accessoire' ? 'Bon cadeau accessoires ' . $v['price'] . ' €' : 'Bon cadeau ' . $v['price'] . ' €');
    }
    static function verifyUrl(string $code): string { return rtrim((string) self::cfg('site_url', ''), '/') . '/api/verifier?code=' . $code; }

    /* ---------------- base de données ---------------- */
    static function db(): PDO {
        if (self::$db) return self::$db;
        $pdo = new PDO(self::cfg('db_dsn'), self::cfg('db_user'), self::cfg('db_pass'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS ab_orders (id VARCHAR(40) PRIMARY KEY, ref VARCHAR(20) NOT NULL, total VARCHAR(12) NOT NULL, status VARCHAR(20) NOT NULL, capture_id VARCHAR(40), data TEXT NOT NULL, emailed INT NOT NULL DEFAULT 0, created INT NOT NULL, updated INT NOT NULL)$engine");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ab_vouchers (code VARCHAR(16) PRIMARY KEY, order_id VARCHAR(40) NOT NULL, type VARCHAR(12) NOT NULL, soin VARCHAR(160) NOT NULL DEFAULT '', price DECIMAL(8,2) NOT NULL, design VARCHAR(12) NOT NULL, occasion VARCHAR(40) NOT NULL DEFAULT '', to_name VARCHAR(40) NOT NULL DEFAULT '', msg VARCHAR(200) NOT NULL DEFAULT '', format VARCHAR(8) NOT NULL, buyer_name VARCHAR(90) NOT NULL, buyer_email VARCHAR(130) NOT NULL, buyer_tel VARCHAR(40) NOT NULL DEFAULT '', created INT NOT NULL, expires INT NOT NULL, status VARCHAR(12) NOT NULL, used_at INT, refunded_at INT)$engine");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ab_attempts (ip VARCHAR(64) NOT NULL, ts INT NOT NULL)$engine");
        return self::$db = $pdo;
    }
    static function one(string $sql, array $a = []): ?array { $s = self::db()->prepare($sql); $s->execute($a); $r = $s->fetch(); return $r ?: null; }
    static function run(string $sql, array $a = []): int { $s = self::db()->prepare($sql); $s->execute($a); return $s->rowCount(); }
    static function insertIgnore(): string { return self::db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE'; }

    /* ---------------- catalogue et promotions ---------------- */
    static function catalog(): array { static $c = null; return $c ??= json_decode(file_get_contents(__DIR__ . '/catalog.json'), true); }
    static function activePromo(): ?array {
        $f = self::cfg('promos_file', dirname(__DIR__, 2) . '/promos.json');
        if (!is_file($f)) return null;
        $j = json_decode((string) file_get_contents($f), true);
        $today = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
        foreach (($j['promos'] ?? []) as $p) if (($p['du'] ?? '9') <= $today && $today <= ($p['au'] ?? '0')) return $p;
        return null;
    }

    /* ---------------- PayPal ---------------- */
    static function ppBase(): string { return self::cfg('paypal_env') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com'; }
    static function ppToken(): string {
        static $tok = null; if ($tok) return $tok;
        $r = Http::request('POST', self::ppBase() . '/v1/oauth2/token', ['Authorization' => 'Basic ' . base64_encode(self::cfg('paypal_client_id') . ':' . self::cfg('paypal_secret')), 'Content-Type' => 'application/x-www-form-urlencoded'], 'grant_type=client_credentials');
        $j = json_decode($r['body'], true);
        if ($r['status'] >= 300 || empty($j['access_token'])) throw new Fail('Connexion à PayPal impossible pour le moment.', 502);
        return $tok = $j['access_token'];
    }
    static function pp(string $method, string $path, ?array $body = null, array $extra = []): array {
        $r = Http::request($method, self::ppBase() . $path, ['Authorization' => 'Bearer ' . self::ppToken(), 'Content-Type' => 'application/json'] + $extra, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['ok' => $r['status'] < 300, 'status' => $r['status'], 'data' => json_decode($r['body'], true) ?: []];
    }

    /* ---------------- commande ---------------- */
    static function normalizeCart(array $body, ?array $promo): array {
        if (($body['cgv'] ?? false) !== true) throw new Fail('Merci d\'accepter les conditions générales de vente.');
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (!$items || count($items) > 20) throw new Fail('Panier vide ou trop grand.');
        $cat = self::catalog(); $out = [];
        foreach ($items as $it) {
            $type = isset(self::TYPES[$it['type'] ?? '']) ? $it['type'] : 'montant';
            $soin = '';
            if ($type === 'soin') {
                $soin = self::clean($it['soin'] ?? '', 120);
                $price = $cat['soins'][$soin] ?? null;
                if (!$price) throw new Fail('Ce soin n\'est pas disponible en bon cadeau : ' . $soin);
                $pp = (float) ($promo['soins'][$soin] ?? 0);
                if ($pp > 0 && $pp < $price) $price = $pp;
            } else {
                $price = (int) round((float) ($it['amount'] ?? 0));
                if ($price < $cat['montant_min'] || $price > $cat['montant_max']) throw new Fail("Le montant doit être entre {$cat['montant_min']} et {$cat['montant_max']} €.");
            }
            $out[] = [
                'type' => $type, 'soin' => $soin, 'price' => $price + 0, 'qty' => max(1, min(10, (int) round((float) ($it['qty'] ?? 1)))),
                'design' => ($it['design'] ?? '') === 'fete' ? 'fete' : 'classique',
                'occasion' => in_array($it['occasion'] ?? '', self::OCCASIONS, true) ? $it['occasion'] : self::OCCASIONS[0],
                'to' => self::clean($it['to'] ?? '', 30), 'msg' => self::clean($it['msg'] ?? '', 160),
                'format' => ($it['format'] ?? '') === 'papier' ? 'papier' : 'e',
            ];
        }
        $b = $body['buyer'] ?? [];
        $buyer = ['name' => self::clean($b['name'] ?? '', 80), 'email' => mb_strtolower(self::clean($b['email'] ?? '', 120)), 'tel' => self::clean($b['tel'] ?? '', 30)];
        if ($buyer['name'] === '' || !filter_var($buyer['email'], FILTER_VALIDATE_EMAIL)) throw new Fail('Nom et e-mail valides obligatoires.');
        return ['items' => $out, 'buyer' => $buyer, 'cgv_acceptees_le' => gmdate('c', self::now())];
    }

    static function createOrder(array $body): array {
        $cart = self::normalizeCart($body, self::activePromo());
        $total = 0; foreach ($cart['items'] as $it) $total += $it['price'] * $it['qty'];
        $ref = 'AB-' . self::random(6);
        $items = array_map(fn($it) => [
            'name' => self::clean(self::label($it) . ($it['to'] ? ' pour ' . $it['to'] : ''), 127),
            'description' => self::clean('[' . self::TYPES[$it['type']] . '] ' . ($it['design'] === 'fete' ? $it['occasion'] : 'Classique') . ' · ' . ($it['format'] === 'e' ? 'par e-mail' : 'retrait institut') . ($it['msg'] ? ' · ' . $it['msg'] : ''), 127),
            'quantity' => (string) $it['qty'], 'unit_amount' => ['currency_code' => 'EUR', 'value' => self::euros($it['price'])], 'category' => 'DIGITAL_GOODS'
        ], $cart['items']);
        $r = self::pp('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $ref, 'custom_id' => $ref, 'description' => self::clean('Aqua Beauté · bons cadeaux · ' . $cart['buyer']['name'], 127),
                'amount' => ['currency_code' => 'EUR', 'value' => self::euros($total), 'breakdown' => ['item_total' => ['currency_code' => 'EUR', 'value' => self::euros($total)]]],
                'items' => $items
            ]],
            'application_context' => ['brand_name' => 'Aqua Beauté', 'locale' => 'fr-FR', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW']
        ]);
        if (!$r['ok'] || empty($r['data']['id'])) throw new Fail('PayPal a refusé la commande. Réessayez dans un instant.', 502);
        $now = self::now();
        self::run('INSERT INTO ab_orders (id, ref, total, status, data, created, updated) VALUES (?,?,?,?,?,?,?)',
            [$r['data']['id'], $ref, self::euros($total), 'créée', json_encode($cart, JSON_UNESCAPED_UNICODE), $now, $now]);
        return self::json(['id' => $r['data']['id'], 'ref' => $ref]);
    }

    static function order(string $id): ?array {
        $o = self::one('SELECT * FROM ab_orders WHERE id = ?', [$id]);
        if ($o) $o['data'] = json_decode($o['data'], true);
        return $o;
    }

    static function captureOrder(string $orderId): array {
        $o = self::order($orderId);
        if (!$o) throw new Fail('Commande inconnue.', 404);
        if ($o['status'] === 'payée') return self::publicResult($orderId);
        $r = self::pp('POST', "/v2/checkout/orders/$orderId/capture", null, ['PayPal-Request-Id' => 'cap-' . $orderId]);
        $issues = array_column($r['data']['details'] ?? [], 'issue');
        if (!$r['ok'] && in_array('INSTRUMENT_DECLINED', $issues, true))
            throw new Fail('Votre moyen de paiement a été refusé. Choisissez-en un autre.', 422, ['restart' => true]);
        $cap = $r['data']['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (!$r['ok'] && in_array('ORDER_ALREADY_CAPTURED', $issues, true)) $cap = self::getCapture($orderId);
        elseif (!$r['ok']) throw new Fail('Le paiement n\'a pas abouti. Aucun montant n\'a été prélevé.', 402);
        return self::finalize($orderId, $cap);
    }
    static function getCapture(string $orderId): ?array {
        $r = self::pp('GET', "/v2/checkout/orders/$orderId");
        return $r['data']['purchase_units'][0]['payments']['captures'][0] ?? null;
    }

    /* Création des bons une fois l'argent confirmé (appelé par le site ou par la notification PayPal). */
    static function finalize(string $orderId, ?array $cap): array {
        $o = self::order($orderId);
        if (!$o) throw new Fail('Commande inconnue.', 404);
        if ($o['status'] === 'payée') return self::publicResult($orderId);
        if (!$cap || ($cap['amount']['currency_code'] ?? '') !== 'EUR' || ($cap['amount']['value'] ?? '') !== $o['total'])
            throw new Fail('Paiement non confirmé par PayPal. Contactez l\'institut au 03 88 23 00 98.', 402);
        if (($cap['status'] ?? '') === 'PENDING') {
            if (self::run("UPDATE ab_orders SET status = 'en attente', capture_id = ?, updated = ? WHERE id = ? AND status = 'créée'", [$cap['id'], self::now(), $orderId]))
                Mail::safeSend(self::cfg('shop_email'), 'Paiement PayPal en attente : ' . $o['ref'], Mail::layout('<p>PayPal vérifie le paiement de ' . self::esc($o['data']['buyer']['name']) . ' (' . str_replace('.', ',', $o['total']) . ' €, commande ' . $o['ref'] . '). Les bons seront créés et envoyés automatiquement dès la confirmation.</p>'));
            return ['ok' => true, 'pending' => true, 'ref' => $o['ref'], 'vouchers' => [], 'emailed' => false];
        }
        if (($cap['status'] ?? '') !== 'COMPLETED') throw new Fail('Paiement non confirmé par PayPal. Contactez l\'institut au 03 88 23 00 98.', 402);

        // Codes déterministes + INSERT IGNORE : deux traitements simultanés ne créent jamais de doublon.
        $created = self::now();
        $exp = (new DateTime('@' . $created))->modify('+1 year')->getTimestamp();
        $n = 0; $codes = [];
        foreach ($o['data']['items'] as $it) for ($i = 0; $i < $it['qty']; $i++) {
            $code = self::codeFor($orderId, $n++); $codes[] = $code;
            $b = $o['data']['buyer'];
            self::run(self::insertIgnore() . ' INTO ab_vouchers (code, order_id, type, soin, price, design, occasion, to_name, msg, format, buyer_name, buyer_email, buyer_tel, created, expires, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$code, $orderId, $it['type'], $it['soin'], $it['price'], $it['design'], $it['occasion'], $it['to'], $it['msg'], $it['format'], $b['name'], $b['email'], $b['tel'], $created, $exp, 'valide']);
        }
        // Un seul traitement « gagne » le passage à « payée » et envoie les e-mails.
        $won = self::run("UPDATE ab_orders SET status = 'payée', capture_id = ?, updated = ? WHERE id = ? AND status <> 'payée'", [$cap['id'], self::now(), $orderId]);
        if ($won) {
            try { self::sendEmails($orderId); self::run('UPDATE ab_orders SET emailed = 1 WHERE id = ?', [$orderId]); }
            catch (Throwable $e) { error_log('[bons-cadeaux] e-mail : ' . $e->getMessage()); }
        }
        return self::publicResult($orderId);
    }
    static function codeFor(string $orderId, int $n): string {
        $h = hash('sha256', self::cfg('paypal_secret', '') . '|' . $orderId . '|' . $n, true); $c = '';
        for ($i = 0; $i < 8; $i++) $c .= self::ALPH[ord($h[$i]) % 32];
        return 'AQB-' . substr($c, 0, 4) . '-' . substr($c, 4);
    }
    static function random(int $n): string { $s = ''; for ($i = 0; $i < $n; $i++) $s .= self::ALPH[random_int(0, 31)]; return $s; }
    static function vouchersOf(string $orderId): array {
        $s = self::db()->prepare('SELECT * FROM ab_vouchers WHERE order_id = ? ORDER BY code'); $s->execute([$orderId]);
        $rows = $s->fetchAll(); $o = self::order($orderId); $codes = [];
        // ordre de création (le même que dans la commande)
        $n = 0; foreach ($o['data']['items'] as $it) for ($i = 0; $i < $it['qty']; $i++) $codes[] = self::codeFor($orderId, $n++);
        usort($rows, fn($a, $b) => array_search($a['code'], $codes) <=> array_search($b['code'], $codes));
        return $rows;
    }
    static function publicResult(string $orderId): array {
        $o = self::order($orderId);
        $v = array_map(fn($r) => ['code' => $r['code'], 'design' => $r['design'], 'occasion' => $r['occasion'], 'to' => $r['to_name'], 'msg' => $r['msg'], 'type' => $r['type'], 'soin' => $r['soin'], 'price' => $r['price'] + 0, 'until' => self::frDate((int) $r['expires']), 'format' => $r['format'], 'created' => (int) $r['created'] * 1000, 'expires' => (int) $r['expires'] * 1000], self::vouchersOf($orderId));
        return ['ok' => true, 'ref' => $o['ref'], 'vouchers' => $v, 'emailed' => (bool) $o['emailed'], 'pending' => $o['status'] === 'en attente'];
    }

    /* ---------------- e-mails ---------------- */
    static function sendEmails(string $orderId): void {
        $o = self::order($orderId); $rows = self::vouchersOf($orderId);
        $pdfs = []; $buyerPdfs = []; $papier = [];
        foreach ($rows as $r) {
            $v = self::rowToVoucher($r);
            $file = ['name' => 'Bon-cadeau-Aqua-Beaute-' . $r['code'] . '.pdf', 'data' => VoucherPdf::make($v, self::verifyUrl($r['code']))];
            $pdfs[] = $file;
            if ($r['format'] === 'e') $buyerPdfs[] = $file; else $papier[] = $r['code'];
        }
        $list = implode('', array_map(fn($r) => '<li><b>' . self::esc(self::label(self::rowToVoucher($r))) . '</b> <span style="color:#8A929C">(' . self::TYPES[$r['type']] . ')</span>' . ($r['to_name'] ? ' pour ' . self::esc($r['to_name']) : '') . ' · code <b style="font-family:monospace">' . $r['code'] . '</b>' . ($r['format'] === 'papier' ? ' · à retirer à l\'institut' : '') . '</li>', $rows));
        $b = $o['data']['buyer']; $tot = str_replace('.', ',', $o['total']);
        $html = Mail::layout('<h1 style="color:#2B8DBB;font-size:22px;margin:0 0 8px">Merci ' . self::esc($b['name']) . ' !</h1>'
            . "<p>Votre paiement de <b>$tot €</b> est confirmé (commande {$o['ref']}).</p><ul style=\"padding-left:18px\">$list</ul>"
            . ($buyerPdfs ? '<p>' . (count($buyerPdfs) > 1 ? 'Vos bons cadeaux sont joints' : 'Votre bon cadeau est joint') . ' à cet e-mail en PDF : il suffit de l\'imprimer ou de l\'offrir tel quel. Le jour du rendez-vous, il se présente imprimé ou sur téléphone.</p>' : '')
            . ($papier ? '<p>' . (count($papier) > 1 ? 'Les bons imprimés vous attendent' : 'Le bon imprimé vous attend') . ' à l\'institut, dans sa jolie enveloppe, aux heures d\'ouverture.</p>' : '')
            . '<p>Chaque bon est valable un an, sur rendez-vous au <b>03 88 23 00 98</b>.</p>'
            . '<p style="font-size:13px;color:#5B6470">Vous disposez d\'un droit de rétractation de 14 jours à compter de votre achat, en répondant simplement à cet e-mail avec votre numéro de commande. Conditions générales de vente : <a href="' . self::esc(rtrim((string) self::cfg('site_url', ''), '/') . '/#cgv') . '">' . self::esc(rtrim((string) self::cfg('site_url', ''), '/') . '/#cgv') . '</a></p>');
        Mail::send($b['email'], $b['name'], $buyerPdfs ? 'Votre bon cadeau Aqua Beauté 🎁' : 'Votre commande de bon cadeau Aqua Beauté', $html, $buyerPdfs);
        if (self::cfg('shop_email')) {
            $shop = Mail::layout('<h1 style="color:#B8006E;font-size:20px;margin:0 0 8px">Nouveau bon cadeau vendu</h1>'
                . "<p><b>$tot €</b> payés par PayPal · commande {$o['ref']}</p><p>Acheteur : " . self::esc($b['name']) . ' · ' . self::esc($b['email']) . ' · ' . self::esc($b['tel'] ?: '—') . "</p><ul style=\"padding-left:18px\">$list</ul>"
                . ($papier ? '<p><b>À imprimer et préparer en enveloppe :</b> ' . implode(', ', $papier) . '</p>' : '')
                . '<p>Les PDF sont joints. Vérifier un bon en caisse : <a href="' . self::esc(self::verifyUrl('')) . '">page de vérification</a></p>');
            Mail::send(self::cfg('shop_email'), 'Aqua Beauté', "🎁 Bon cadeau vendu : $tot € (" . $b['name'] . ')', $shop, $pdfs, $b['email']);
        }
    }
    static function rowToVoucher(array $r): array {
        return ['code' => $r['code'], 'type' => $r['type'], 'soin' => $r['soin'], 'price' => $r['price'] + 0, 'design' => $r['design'], 'occasion' => $r['occasion'], 'to' => $r['to_name'], 'msg' => $r['msg'], 'untilFr' => self::frDate((int) $r['expires'])];
    }

    /* ---------------- formulaire « Être rappelée » ---------------- */
    static function contact(array $b, string $ip): array {
        if (trim((string) ($b['site'] ?? '')) !== '') return self::json(['ok' => true]);   // champ piège rempli : robot
        $name = self::clean($b['name'] ?? '', 80); $tel = self::clean($b['tel'] ?? '', 30); $msg = self::clean($b['msg'] ?? '', 1000);
        if ($name === '' || !preg_match('/^[0-9 +().-]{6,30}$/', $tel)) throw new Fail('Merci d\'indiquer votre nom et un numéro de téléphone valide.');
        $key = 'c:' . $ip;
        self::run('DELETE FROM ab_attempts WHERE ts < ?', [self::now() - 900]);
        if ((int) (self::one('SELECT COUNT(*) AS n FROM ab_attempts WHERE ip = ?', [$key])['n'] ?? 0) >= 3) throw new Fail('Trop de demandes. Appelez directement le 03 88 23 00 98.', 429);
        self::run('INSERT INTO ab_attempts (ip, ts) VALUES (?, ?)', [$key, self::now()]);
        Mail::send((string) self::cfg('shop_email'), 'Aqua Beauté', 'Demande de rappel : ' . $name,
            Mail::layout('<h1 style="color:#2B8DBB;font-size:20px;margin:0 0 8px">Demande de rappel</h1><p><b>' . self::esc($name) . '</b> · <a href="tel:' . self::esc(preg_replace('/[^0-9+]/', '', $tel)) . '">' . self::esc($tel) . '</a></p>' . ($msg !== '' ? '<p>' . nl2br(self::esc($msg)) . '</p>' : '') . '<p style="font-size:12px;color:#8A929C">Envoyé depuis le formulaire du site. À supprimer 3 ans après le dernier contact.</p>'));
        return self::json(['ok' => true]);
    }

    /* ---------------- notifications PayPal ---------------- */
    static function webhook(array $h, string $raw): array {
        if (!self::cfg('paypal_webhook_id')) return self::json(['error' => 'Webhook non configuré'], 503);
        $ev = json_decode($raw, true); if (!is_array($ev)) return self::json(['error' => 'JSON invalide'], 400);
        $v = self::pp('POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $h['paypal-auth-algo'] ?? '', 'cert_url' => $h['paypal-cert-url'] ?? '', 'transmission_id' => $h['paypal-transmission-id'] ?? '',
            'transmission_sig' => $h['paypal-transmission-sig'] ?? '', 'transmission_time' => $h['paypal-transmission-time'] ?? '',
            'webhook_id' => self::cfg('paypal_webhook_id'), 'webhook_event' => $ev]);
        if (!$v['ok'] || ($v['data']['verification_status'] ?? '') !== 'SUCCESS') return self::json(['error' => 'Signature invalide'], 401);
        $r = $ev['resource'] ?? []; $type = $ev['event_type'] ?? '';
        try {
            if ($type === 'CHECKOUT.ORDER.APPROVED') {
                if (self::order((string) ($r['id'] ?? ''))) self::captureOrder($r['id']);
            } elseif ($type === 'PAYMENT.CAPTURE.COMPLETED') {
                $oid = $r['supplementary_data']['related_ids']['order_id'] ?? (self::one('SELECT id FROM ab_orders WHERE capture_id = ?', [$r['id'] ?? ''])['id'] ?? '');
                if ($oid && self::order($oid)) self::finalize($oid, $r);
            } elseif ($type === 'PAYMENT.CAPTURE.REFUNDED' || $type === 'PAYMENT.CAPTURE.REVERSED') {
                $capId = $r['id'] ?? '';
                foreach ($r['links'] ?? [] as $l) if (($l['rel'] ?? '') === 'up') $capId = basename($l['href']);
                $oid = self::one('SELECT id FROM ab_orders WHERE capture_id = ?', [$capId])['id'] ?? null;
                if ($oid) self::run("UPDATE ab_vouchers SET status = 'remboursé', refunded_at = ? WHERE order_id = ? AND status <> 'utilisé'", [self::now(), $oid]);
            }
        } catch (Fail $e) {
            if ($e->status >= 500) return self::json(['error' => 'Traitement impossible'], 500);   // PayPal réessaiera
        }
        return self::json(['ok' => true]);
    }

    /* ---------------- vérification en magasin ---------------- */
    static function checkPin(array $h, string $ip): ?array {
        $since = self::now() - 900;
        self::run('DELETE FROM ab_attempts WHERE ts < ?', [$since]);
        $tries = (int) (self::one('SELECT COUNT(*) AS n FROM ab_attempts WHERE ip = ?', [$ip])['n'] ?? 0);
        if ($tries >= 8) return self::json(['error' => 'Trop d\'essais. Réessayez dans 15 minutes.'], 429);
        $pin = (string) ($h['x-staff-pin'] ?? ''); $real = (string) self::cfg('staff_pin', '');
        if ($real === '' || strlen($pin) < 4 || !hash_equals($real, $pin)) {
            self::run('INSERT INTO ab_attempts (ip, ts) VALUES (?, ?)', [$ip, self::now()]);
            return self::json(['error' => 'Code PIN incorrect.'], 401);
        }
        return null;
    }
    static function view(array $r): array {
        return ['code' => $r['code'], 'label' => self::label(self::rowToVoucher($r)), 'type' => $r['type'], 'typeLabel' => self::TYPES[$r['type']], 'soin' => $r['soin'], 'price' => $r['price'] + 0,
            'to' => $r['to_name'], 'msg' => $r['msg'], 'design' => $r['design'], 'buyer' => $r['buyer_name'], 'buyerTel' => $r['buyer_tel'], 'format' => $r['format'],
            'created' => (int) $r['created'] * 1000, 'expires' => (int) $r['expires'] * 1000, 'status' => $r['status'], 'usedAt' => $r['used_at'] ? (int) $r['used_at'] * 1000 : null,
            'expired' => self::now() > (int) $r['expires']];
    }
    static function getVoucher(string $code): array {
        $r = self::one('SELECT * FROM ab_vouchers WHERE code = ?', [$code]);
        return $r ? self::json(self::view($r)) : self::json(['error' => 'Aucun bon ne correspond à ce code.'], 404);
    }
    static function useVoucher(string $code, bool $use): array {
        $r = self::one('SELECT * FROM ab_vouchers WHERE code = ?', [$code]);
        if (!$r) return self::json(['error' => 'Aucun bon ne correspond à ce code.'], 404);
        if ($use) {
            if ($r['status'] === 'remboursé') return self::json(['error' => 'Ce bon a été remboursé : il n\'est plus valable.', 'voucher' => self::view($r)], 409);
            // condition dans la requête : deux validations simultanées → une seule réussit
            if (!self::run("UPDATE ab_vouchers SET status = 'utilisé', used_at = ? WHERE code = ? AND status = 'valide'", [self::now(), $code])) {
                $r = self::one('SELECT * FROM ab_vouchers WHERE code = ?', [$code]);
                $d = new DateTime('@' . (int) $r['used_at']); $d->setTimezone(new DateTimeZone('Europe/Paris'));
                return self::json(['error' => 'Ce bon a déjà été utilisé le ' . $d->format('d/m/Y') . '.', 'voucher' => self::view($r)], 409);
            }
        } else self::run("UPDATE ab_vouchers SET status = 'valide', used_at = NULL WHERE code = ? AND status = 'utilisé'", [$code]);
        return self::json(self::view(self::one('SELECT * FROM ab_vouchers WHERE code = ?', [$code])));
    }
    static function allRows(): array { return self::db()->query('SELECT * FROM ab_vouchers ORDER BY created DESC, code')->fetchAll(); }
    static function listVouchers(): array {
        return self::json(['vouchers' => array_map(fn($r) => ['code' => $r['code'], 'to' => $r['to_name'], 'price' => $r['price'] + 0, 'type' => $r['type'], 'soin' => $r['soin'], 'status' => $r['status'], 'created' => (int) $r['created'] * 1000, 'expires' => (int) $r['expires'] * 1000, 'buyer' => $r['buyer_name'], 'usedAt' => $r['used_at'] ? (int) $r['used_at'] * 1000 : 0, 'expired' => self::now() > (int) $r['expires']], self::allRows())]);
    }

    /* ---------------- sauvegardes ---------------- */
    static function csv(): string {
        $fmt = function ($ts) { if (!$ts) return ''; $d = new DateTime('@' . (int) $ts); $d->setTimezone(new DateTimeZone('Europe/Paris')); return $d->format('d/m/Y H:i'); };
        $lines = [['Code', 'Type', 'Soin', 'Valeur (€)', 'Pour', 'Acheteur', 'E-mail', 'Téléphone', 'Remise', 'Acheté le', 'Expire le', 'Statut', 'Utilisé le', 'Commande PayPal']];
        foreach (self::allRows() as $r) {
            $st = $r['status'] === 'valide' && self::now() > (int) $r['expires'] ? 'expiré' : $r['status'];
            $lines[] = [$r['code'], self::TYPES[$r['type']], $r['soin'], str_replace('.', ',', self::euros($r['price'])), $r['to_name'], $r['buyer_name'], $r['buyer_email'], $r['buyer_tel'], $r['format'] === 'e' ? 'e-mail' : 'institut', $fmt($r['created']), $fmt($r['expires']), $st, $fmt($r['used_at']), $r['order_id']];
        }
        $out = "\xEF\xBB\xBF"; // BOM : Excel ouvre les accents correctement
        foreach ($lines as $l) $out .= implode(';', array_map(fn($c) => '"' . str_replace('"', '""', (string) $c) . '"', $l)) . "\r\n";
        return $out;
    }
    static function exportCsv(): array {
        return [200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="bons-cadeaux-aqua-beaute-' . date('Y-m-d') . '.csv"', 'Cache-Control' => 'no-store'], self::csv()];
    }
    static function stats(): array {
        $rows = self::allRows(); $now = self::now(); $s = ['vendus' => count($rows), 'valables' => 0, 'restant' => 0.0, 'semaine_n' => 0, 'semaine_eur' => 0.0, 'utilises_semaine' => 0];
        foreach ($rows as $r) {
            if ($r['status'] === 'valide' && $now <= (int) $r['expires']) { $s['valables']++; $s['restant'] += (float) $r['price']; }
            if ((int) $r['created'] >= $now - 7 * 86400) { $s['semaine_n']++; $s['semaine_eur'] += (float) $r['price']; }
            if ($r['used_at'] && (int) $r['used_at'] >= $now - 7 * 86400) $s['utilises_semaine']++;
        }
        return $s;
    }
    static function recap(array $q): array {
        $key = (string) self::cfg('cron_key', '');
        if ($key === '' || !hash_equals($key, (string) ($q['cle'] ?? ''))) return self::json(['error' => 'Clé incorrecte'], 403);
        $s = self::stats(); $f = fn($n) => number_format($n, 2, ',', ' ');
        $html = Mail::layout('<h1 style="color:#2B8DBB;font-size:20px;margin:0 0 8px">Récapitulatif des bons cadeaux</h1>'
            . "<p>Ces 7 derniers jours : <b>{$s['semaine_n']}</b> bon(s) vendu(s) pour <b>{$f($s['semaine_eur'])} €</b>, <b>{$s['utilises_semaine']}</b> utilisé(s) à l'institut.</p>"
            . "<p>En tout : <b>{$s['vendus']}</b> bons vendus, dont <b>{$s['valables']}</b> encore à utiliser (<b>{$f($s['restant'])} €</b> de prestations dues).</p>"
            . '<p>Le fichier joint est la sauvegarde complète du registre des bons (s\'ouvre avec Excel). Conservez ces e-mails.</p>');
        Mail::send((string) self::cfg('shop_email'), 'Aqua Beauté', 'Récapitulatif et sauvegarde des bons cadeaux', $html, [['name' => 'bons-cadeaux-' . date('Y-m-d') . '.csv', 'data' => self::csv(), 'type' => 'text/csv']]);
        return self::json(['ok' => true] + $s);
    }
}
