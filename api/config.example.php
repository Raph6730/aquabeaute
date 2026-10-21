<?php
/*
 * Modèle de configuration. Le vrai fichier config.php est fabriqué automatiquement lors de l'envoi
 * vers IONOS, à partir des « Secrets » du dépôt GitHub (voir INSTALLATION-IONOS.md). Ne jamais y mettre de vrais mots de passe ici.
 */
return [
    'paypal_env'        => 'sandbox',          // 'sandbox' pour les essais, 'live' pour les vrais paiements
    'paypal_client_id'  => '',
    'paypal_secret'     => '',
    'paypal_webhook_id' => '',
    'db_dsn'            => 'mysql:host=XXX;dbname=XXX;charset=utf8mb4',
    'db_user'           => '',
    'db_pass'           => '',
    'smtp_host'         => 'smtp.ionos.fr',
    'smtp_port'         => 465,               // 465 (SSL) ; 587 si besoin
    'smtp_user'         => 'contact@aquabeaute-esthetique.fr',
    'smtp_pass'         => '',
    'sender_email'      => 'contact@aquabeaute-esthetique.fr',
    'sender_name'       => 'Aqua Beauté',
    'shop_email'        => 'contact@aquabeaute-esthetique.fr',   // copie de chaque vente + récapitulatifs
    'staff_pin'         => '',                 // code PIN de la page de vérification (6 chiffres conseillés)
    'cron_key'          => '',                 // clé secrète de l'adresse du récapitulatif hebdomadaire
    'site_url'          => 'https://www.aquabeaute-esthetique.fr/',
    'allowed_origin'    => '',                 // vide : le site et le moteur sont sur le même domaine
];
