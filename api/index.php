<?php
/* Point d'entrée du moteur des bons cadeaux (hébergement IONOS). Toute la logique est dans lib/app.php. */
declare(strict_types=1);
require __DIR__ . '/lib/app.php';
if (!is_file(__DIR__ . '/config.php')) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); exit('Moteur non configuré (config.php manquant).'); }
$cfg = require __DIR__ . '/config.php';
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!$headers) foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $headers[str_replace('_', '-', substr($k, 5))] = $v;
[$status, $hdrs, $body] = App::handle($cfg, $_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', $headers, (string) file_get_contents('php://input'), $_SERVER['REMOTE_ADDR'] ?? 'x', $_GET);
http_response_code($status);
foreach ($hdrs as $k => $v) header("$k: $v");
header('X-Content-Type-Options: nosniff');
echo $body;
