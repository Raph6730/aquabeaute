<?php
/*
 * Tests complets du moteur PHP, avec PayPal et les e-mails simulés et une base SQLite temporaire.
 * Lancement : php api/tests/run.php   → crée api/tests/out/*.pdf et *.csv pour vérifier le rendu.
 */
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { if (!(error_reporting() & $no)) return false; throw new ErrorException($str, 0, $no, $file, $line); });
require __DIR__ . '/../lib/app.php';

$tmp = sys_get_temp_dir() . '/ab-test-' . getmypid(); @mkdir($tmp);
$out = __DIR__ . '/out'; @mkdir($out);
$promoFile = "$tmp/promos.json";
file_put_contents($promoFile, '{"promos":[]}');
$cfg = [
    'paypal_env' => 'sandbox', 'paypal_client_id' => 'id', 'paypal_secret' => 'secret', 'paypal_webhook_id' => '',
    'db_dsn' => "sqlite:$tmp/bons.sqlite", 'db_user' => null, 'db_pass' => null,
    'mail_mode' => 'test', 'sender_email' => 'contact@aquabeaute-esthetique.fr', 'shop_email' => 'contact@aquabeaute-esthetique.fr',
    'staff_pin' => '246810', 'cron_key' => 'cle-secrete', 'site_url' => 'https://www.aquabeaute-esthetique.fr/',
    'allowed_origin' => 'https://raph6730.github.io', 'promos_file' => $promoFile,
];

/* ---------- faux PayPal ---------- */
$orders = []; $tamper = null; $mode = 'normal';
Http::$mock = function ($method, $url, $headers, $body) use (&$orders, &$tamper, &$mode) {
    $res = fn($d, $s = 200) => ['status' => $s, 'body' => json_encode($d)];
    if (str_ends_with($url, '/v1/oauth2/token')) return $res(['access_token' => 'tok']);
    if (str_ends_with($url, '/v1/notifications/verify-webhook-signature')) return $res(['verification_status' => $mode === 'badsig' ? 'FAILURE' : 'SUCCESS']);
    if (str_ends_with($url, '/v2/checkout/orders') && $method === 'POST') { $id = 'PP' . (count($orders) + 1); $orders[$id] = json_decode($body, true); return $res(['id' => $id, 'status' => 'CREATED'], 201); }
    if (preg_match('#/v2/checkout/orders/(\w+)/capture$#', $url, $m)) {
        if ($mode === 'declined') return $res(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]], 422);
        $value = $tamper ?? $orders[$m[1]]['purchase_units'][0]['amount']['value'];
        return $res(['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-' . $m[1], 'status' => $mode === 'pending' ? 'PENDING' : 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => $value]]]]]]], 201);
    }
    throw new Exception("appel inattendu $url");
};

$call = function (string $method, string $path, $body = null, array $h = [], string $ip = '1.1.1.1', array $q = []) use (&$cfg) {
    [$s, $hd, $b] = App::handle($cfg, $method, $path, ['Origin' => 'https://raph6730.github.io'] + $h, $body === null ? '' : json_encode($body), $ip, $q);
    return ['status' => $s, 'headers' => $hd, 'data' => json_decode($b, true), 'raw' => $b];
};
$ok = function (bool $c, string $msg) { if (!$c) { fwrite(STDERR, "✗ ÉCHEC : $msg\n"); exit(1); } };
$pin = ['X-Staff-Pin' => '246810'];
$buyer = ['name' => 'Marc Test', 'email' => 'marc@test.fr', 'tel' => '0600000000'];

/* 1. prix recalculé par le moteur */
$r = $call('POST', '/api/orders', ['cgv' => true, 'buyer' => $buyer, 'items' => [
    ['type' => 'montant', 'amount' => 50, 'design' => 'fete', 'occasion' => 'Joyeux anniversaire', 'to' => 'Julie', 'msg' => 'Joyeux anniversaire ma belle ! 🎉', 'format' => 'e', 'price' => 1],
    ['type' => 'soin', 'soin' => 'Escapade au pays des sens', 'design' => 'classique', 'to' => 'Paul', 'format' => 'papier', 'price' => 1]]]);
$ok($r['status'] === 200, 'création de commande ' . $r['raw']);
$ok(($r['headers']['Access-Control-Allow-Origin'] ?? '') === 'https://raph6730.github.io', 'CORS');
$ok($orders[$r['data']['id']]['purchase_units'][0]['amount']['value'] === '115.00', 'total recalculé');
echo "✓ commande créée, total recalculé : 115,00 €\n";

/* 2. refus */
$ok($call('POST', '/api/orders', ['cgv' => true, 'buyer' => $buyer, 'items' => [['type' => 'montant', 'amount' => 5]]])['status'] === 400, 'montant trop bas');
$ok($call('POST', '/api/orders', ['cgv' => true, 'buyer' => $buyer, 'items' => [['type' => 'montant', 'amount' => 601]]])['status'] === 400, 'montant trop haut');
$ok($call('POST', '/api/orders', ['cgv' => true, 'buyer' => $buyer, 'items' => [['type' => 'soin', 'soin' => 'Soin imaginaire']]])['status'] === 400, 'soin inconnu');
$ok($call('POST', '/api/orders', ['cgv' => true, 'buyer' => ['name' => 'x', 'email' => 'pas-un-email'], 'items' => [['type' => 'montant', 'amount' => 50]]])['status'] === 400, 'e-mail invalide');
echo "✓ montants (20 à 600 €), soins et e-mails invalides refusés\n";

/* 3. encaissement → bons, PDF, e-mails */
$id = $r['data']['id'];
$r = $call('POST', "/api/orders/$id/capture");
$ok($r['status'] === 200 && count($r['data']['vouchers']) === 2 && $r['data']['emailed'] === true, 'encaissement ' . $r['raw']);
[$v1, $v2] = $r['data']['vouchers'];
$ok((bool) preg_match('/^AQB-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $v1['code']), 'format du code');
$ok(count(Mail::$outbox) === 2, 'deux e-mails');
$ok(Mail::$outbox[0]['to'] === 'marc@test.fr' && count(Mail::$outbox[0]['attachments']) === 1, 'acheteur : seul le bon par e-mail est joint');
$ok(Mail::$outbox[1]['to'] === 'contact@aquabeaute-esthetique.fr' && count(Mail::$outbox[1]['attachments']) === 2, 'institut : les deux PDF');
foreach (Mail::$outbox[1]['attachments'] as $i => $a) file_put_contents("$out/bon-" . ($i + 1) . '.pdf', $a['data']);
file_put_contents("$out/mail-client.html", Mail::$outbox[0]['html']);
echo "✓ paiement encaissé, 2 bons créés, PDF et e-mails envoyés : {$v1['code']} {$v2['code']}\n";

/* 4. second encaissement : pas de doublon */
$r = $call('POST', "/api/orders/$id/capture");
$ok(array_column($r['data']['vouchers'], 'code') === [$v1['code'], $v2['code']] && count(Mail::$outbox) === 2, 'pas de doublon');
App::$db = null; // nouvelle connexion, comme une seconde requête parallèle
$res = App::finalize($id, ['id' => 'CAP-' . $id, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '115.00']]);
$ok(count($res['vouchers']) === 2 && count(Mail::$outbox) === 2, 'finalisation concurrente sans doublon');
echo "✓ second encaissement ou traitement simultané : pas de bon en double, pas d'e-mail en double\n";

/* 5. montant incohérent */
$r = $call('POST', '/api/orders', ['cgv' => true, 'buyer' => $buyer, 'items' => [['type' => 'montant', 'amount' => 80]]]);
$tamper = '1.00'; $bad = $call('POST', "/api/orders/{$r['data']['id']}/capture"); $tamper = null;
$ok($bad['status'] === 402, 'montant incohérent refusé');
echo "✓ montant PayPal incohérent refusé\n";

/* 6. vérification en magasin */
$ok($call('GET', "/api/vouchers/{$v1['code']}")['status'] === 401, 'PIN obligatoire');
$ok($call('GET', "/api/vouchers/{$v1['code']}", null, ['X-Staff-Pin' => '000000'])['status'] === 401, 'PIN faux');
$r = $call('GET', "/api/vouchers/{$v1['code']}", null, $pin);
$ok($r['data']['status'] === 'valide' && $r['data']['price'] == 50 && $r['data']['to'] === 'Julie' && $r['data']['typeLabel'] === 'Montant (toutes prestations)', 'détail du bon');
$ok($call('POST', "/api/vouchers/{$v1['code']}/use", null, $pin)['data']['status'] === 'utilisé', 'utilisation');
$r = $call('POST', "/api/vouchers/{$v1['code']}/use", null, $pin);
$ok($r['status'] === 409 && str_contains($r['data']['error'], 'déjà été utilisé'), 'double utilisation refusée');
$ok($call('POST', "/api/vouchers/{$v1['code']}/unuse", null, $pin)['data']['status'] === 'valide', 'annulation');
$ok($call('GET', '/api/vouchers/AQB-XXXX-XXXX', null, $pin)['status'] === 404, 'bon inconnu');
$ok(count($call('GET', '/api/vouchers', null, $pin)['data']['vouchers']) === 2, 'liste');
echo "✓ PIN obligatoire, bon validé une seule fois, annulation possible, liste OK\n";

/* 7. blocage des PIN */
for ($i = 0; $i < 8; $i++) $call('GET', '/api/vouchers', null, ['X-Staff-Pin' => '111111'], '9.9.9.9');
$ok($call('GET', '/api/vouchers', null, $pin, '9.9.9.9')['status'] === 429, 'blocage');
echo "✓ blocage après 8 PIN erronés\n";

/* 8. page de vérification */
[$s, $h, $b] = App::handle($cfg, 'GET', '/api/verifier', [], '', '1.1.1.1', ['code' => $v1['code']]);
$ok($s === 200 && str_contains($b, 'Vérifier un bon') && str_contains($b, 'Exporter'), 'page /api/verifier');
echo "✓ page de vérification servie (avec export Excel)\n";

/* 9. accessoires 600 € */
$r = $call('POST', '/api/orders', ['cgv' => true, 'buyer' => $buyer, 'items' => [['type' => 'accessoire', 'amount' => 600, 'format' => 'e']]]);
$ok($orders[$r['data']['id']]['purchase_units'][0]['amount']['value'] === '600.00' && str_contains($orders[$r['data']['id']]['purchase_units'][0]['items'][0]['description'], '[Accessoires]'), 'accessoires');
$acc = $call('POST', "/api/orders/{$r['data']['id']}/capture")['data']['vouchers'][0];
$ok($call('GET', "/api/vouchers/{$acc['code']}", null, $pin)['data']['typeLabel'] === 'Accessoires', 'type accessoires');
echo "✓ bon accessoires de 600 € : type indiqué à PayPal et en caisse\n";

/* 10. carte refusée */
$r = $call('POST', '/api/orders', ['cgv' => true, 'buyer' => $buyer, 'items' => [['type' => 'montant', 'amount' => 40]]]);
$mode = 'declined'; $dec = $call('POST', "/api/orders/{$r['data']['id']}/capture"); $mode = 'normal';
$ok($dec['status'] === 422 && $dec['data']['restart'] === true, 'carte refusée');
echo "✓ carte refusée : le client peut choisir un autre moyen de paiement\n";

/* 11. paiement en attente puis confirmé par notification */
$pendId = $r['data']['id']; $before = count(Mail::$outbox);
$mode = 'pending'; $pend = $call('POST', "/api/orders/$pendId/capture"); $mode = 'normal';
$ok($pend['data']['pending'] === true && $pend['data']['vouchers'] === [], 'en attente');
$evt = ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => "CAP-$pendId", 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '40.00'], 'supplementary_data' => ['related_ids' => ['order_id' => $pendId]]]];
$ok($call('POST', '/api/paypal/webhook', $evt)['status'] === 503, 'webhook non configuré');
$cfg['paypal_webhook_id'] = 'WH-1';
$ok($call('POST', '/api/paypal/webhook', $evt)['status'] === 200, 'webhook');
$o = App::order($pendId);
$ok($o['status'] === 'payée' && count(App::vouchersOf($pendId)) === 1 && count(Mail::$outbox) > $before + 1, 'bons après confirmation');
echo "✓ paiement en attente : bons créés et envoyés à la confirmation PayPal\n";

/* 12. remboursement */
$ok($call('POST', '/api/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => 'REF-1', 'links' => [['rel' => 'up', 'href' => "https://api-m.paypal.com/v2/payments/captures/CAP-$pendId"]]]])['status'] === 200, 'webhook remboursement');
$code = App::vouchersOf($pendId)[0]['code'];
$ok($call('GET', "/api/vouchers/$code", null, $pin)['data']['status'] === 'remboursé', 'statut remboursé');
$ok($call('POST', "/api/vouchers/$code/use", null, $pin)['status'] === 409, 'bon remboursé refusé');
echo "✓ bon remboursé sur PayPal : refusé à l'institut\n";

/* 13. fausse notification */
$mode = 'badsig'; $ok($call('POST', '/api/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => []])['status'] === 401, 'signature'); $mode = 'normal';
echo "✓ fausse notification PayPal rejetée\n";

/* 14. promotions */
$today = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
file_put_contents($promoFile, json_encode(['promos' => [['id' => 't', 'du' => $today, 'au' => $today, 'soins' => ['Escapade au pays des sens' => 50, 'Soin du dos' => 999]]]]));
$r = $call('POST', '/api/orders', ['cgv' => true, 'buyer' => $buyer, 'items' => [['type' => 'soin', 'soin' => 'Escapade au pays des sens'], ['type' => 'soin', 'soin' => 'Soin du dos']]]);
$ok($orders[$r['data']['id']]['purchase_units'][0]['amount']['value'] === '100.00', 'promo appliquée');
file_put_contents($promoFile, json_encode(['promos' => [['id' => 't', 'du' => '2000-01-01', 'au' => '2000-01-02', 'soins' => ['Escapade au pays des sens' => 50]]]]));
$r = $call('POST', '/api/orders', ['cgv' => true, 'buyer' => $buyer, 'items' => [['type' => 'soin', 'soin' => 'Escapade au pays des sens']]]);
$ok($orders[$r['data']['id']]['purchase_units'][0]['amount']['value'] === '65.00', 'promo expirée');
echo "✓ prix promotionnel appliqué uniquement pendant la période, jamais plus cher que le prix normal\n";

/* 15. sauvegardes */
$r = $call('GET', '/api/vouchers/export.csv', null, $pin);
$ok($r['status'] === 200 && str_starts_with($r['raw'], "\xEF\xBB\xBF") && str_contains($r['raw'], $v1['code']) && str_contains($r['raw'], 'remboursé'), 'export CSV');
file_put_contents("$out/export.csv", $r['raw']);
$ok($call('GET', '/api/cron/recap', null, [], '1.1.1.1', ['cle' => 'mauvaise'])['status'] === 403, 'clé du récap');
$n = count(Mail::$outbox);
$r = $call('GET', '/api/cron/recap', null, [], '1.1.1.1', ['cle' => 'cle-secrete']);
$last = end(Mail::$outbox);
$ok($r['status'] === 200 && count(Mail::$outbox) === $n + 1 && $last['to'] === 'contact@aquabeaute-esthetique.fr' && str_ends_with($last['attachments'][0]['name'], '.csv'), 'récap');
file_put_contents("$out/recap.html", $last['html']);
echo "✓ export Excel et récapitulatif hebdomadaire avec sauvegarde jointe\n";

/* 15 bis. durées de conservation */
$now = time(); $y = 365 * 86400;
$od = fn($e) => json_encode(['buyer' => ['name' => 'Ancienne', 'email' => $e, 'tel' => '0600000000'], 'items' => [['to' => 'Zoé', 'msg' => 'Bon anniv']]]);
App::run("INSERT INTO ab_orders (id, ref, total, status, data, created, updated) VALUES ('OLD-ABANDON','AB-X','50.00','créée',?,?,?)", [$od('a@x.fr'), $now - 40 * 86400, $now]);
App::run("INSERT INTO ab_orders (id, ref, total, status, data, created, updated) VALUES ('OLD-4ANS','AB-Y','50.00','payée',?,?,?)", [$od('b@x.fr'), $now - 4 * $y, $now]);
App::run("INSERT INTO ab_orders (id, ref, total, status, data, created, updated) VALUES ('OLD-11ANS','AB-Z','50.00','payée',?,?,?)", [$od('c@x.fr'), $now - 11 * $y, $now]);
App::run("INSERT INTO ab_vouchers (code, order_id, type, price, design, to_name, msg, format, buyer_name, buyer_email, buyer_tel, created, expires, status) VALUES ('OLD4','OLD-4ANS','montant',50,'classique','Zoé','Bon anniv','e','Ancienne','b@x.fr','0600000000',?,?,'utilisé')", [$now - 4 * $y, $now - 3 * $y]);
App::run("INSERT INTO ab_vouchers (code, order_id, type, price, design, format, buyer_name, buyer_email, created, expires, status) VALUES ('OLD11','OLD-11ANS','montant',50,'classique','e','Ancienne','c@x.fr',?,?,'utilisé')", [$now - 11 * $y, $now - 10 * $y]);
$p = App::purge($now);
$ok(App::one("SELECT id FROM ab_orders WHERE id = 'OLD-ABANDON'") === null, 'commande abandonnée supprimée');
$ok(App::one("SELECT id FROM ab_orders WHERE id = 'OLD-11ANS'") === null && App::one("SELECT code FROM ab_vouchers WHERE code = 'OLD11'") === null, 'données de plus de 10 ans supprimées');
$v = App::one("SELECT * FROM ab_vouchers WHERE code = 'OLD4'");
$ok($v['buyer_email'] === '' && $v['buyer_tel'] === '' && $v['to_name'] === '' && $v['msg'] === '' && $v['buyer_name'] === 'Ancienne' && (float) $v['price'] === 50.0, 'bon de 4 ans anonymisé, montant gardé');
$d = json_decode(App::one("SELECT data FROM ab_orders WHERE id = 'OLD-4ANS'")['data'], true);
$ok($d['buyer']['email'] === '' && $d['buyer']['tel'] === '' && $d['items'][0]['msg'] === '' && $d['buyer']['name'] === 'Ancienne', 'commande de 4 ans anonymisée');
$ok(App::one('SELECT COUNT(*) AS n FROM ab_vouchers WHERE created > ? AND buyer_email <> ""', [$now - $y])['n'] > 0, 'bons récents intacts');
$ok(App::purge($now)['anonymisees'] === 0, 'purge idempotente');
echo "✓ durées de conservation : abandon 30 j, coordonnées effacées à 3 ans, suppression à 10 ans\n";

/* 16. CGV obligatoires et formulaire de rappel */
$r = $call('POST', '/api/orders', ['buyer' => $buyer, 'items' => [['type' => 'montant', 'amount' => 50]]]);
$ok($r['status'] === 400 && str_contains($r['data']['error'], 'conditions générales'), 'CGV obligatoires');
$ok(str_contains(Mail::$outbox[0]['html'], '#cgv') && str_contains(Mail::$outbox[0]['html'], '14 jours'), 'e-mail avec CGV et rétractation');
$n = count(Mail::$outbox);
$ok($call('POST', '/api/contact', ['name' => 'Julie', 'tel' => 'abc'])['status'] === 400, 'téléphone invalide');
$ok($call('POST', '/api/contact', ['name' => 'Robot', 'tel' => '0600000000', 'site' => 'spam'])['status'] === 200 && count(Mail::$outbox) === $n, 'robot ignoré');
$ok($call('POST', '/api/contact', ['name' => 'Julie', 'tel' => '06 12 34 56 78', 'msg' => 'Bilan lumière pulsée'], [], '5.5.5.5')['status'] === 200, 'rappel');
$last = end(Mail::$outbox); $ok($last['to'] === 'contact@aquabeaute-esthetique.fr' && str_contains($last['html'], 'Julie'), 'e-mail de rappel');
$call('POST', '/api/contact', ['name' => 'J', 'tel' => '0600000000'], [], '5.5.5.5'); $call('POST', '/api/contact', ['name' => 'J', 'tel' => '0600000000'], [], '5.5.5.5');
$ok($call('POST', '/api/contact', ['name' => 'J', 'tel' => '0600000000'], [], '5.5.5.5')['status'] === 429, 'limite de demandes');
echo "✓ CGV obligatoires, e-mail avec CGV et rétractation, formulaire de rappel (anti-robots, limite)\n";

/* 17. santé */
$ok($call('GET', '/api/sante')['data']['ok'] === true, 'santé');
echo "✓ contrôle de bon fonctionnement\n\nTous les tests du moteur PHP passent.\n";
