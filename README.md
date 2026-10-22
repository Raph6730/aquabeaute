# Aqua Beauté Mutzig — nouveau site (proposition)

Proposition de refonte du site de l'institut Aqua Beauté, 8 rue du Maréchal Foch, 67190 Mutzig.

**Voir le site :** https://raph6730.github.io/aquabeaute/

## Ce que contient le site
- **Accueil** : présentation, statut ouvert/fermé en direct, avis Google, marques.
- **Soins & tarifs** : toute la carte (visage, corps, épilation, lumière pulsée, mains & pieds, regard & maquillage, minceur), avec un bouton « Offrir » sur chaque soin.
- **Bons cadeaux en ligne** : montant libre ou soin précis, envoi par e-mail ou retrait à l'institut, paiement PayPal (compte PayPal ou carte bancaire).
- **Trois types de bons** : montant (toutes prestations), soin précis, accessoires (traitements de TVA différents), jusqu'à 600 €.
- **Promotions de fêtes** : fichier `promos.json` (Noël, Saint-Valentin, fête des mères, fête des pères…) : bandeau, section sur l'accueil, prix barrés et bons cadeaux au prix promo, automatiquement entre les dates. Aperçu : ajouter `?promo=noel` à l'adresse.
- **Maquette de Noël** (non mise en ligne) : https://raph6730.github.io/aquabeaute/maquette-noel.html
- **Photos** : menu déroulant, carrousel sur l'accueil et page avec toutes les photos de l'institut.
- **L'institut** et **Contact** : horaires, plan d'accès, demande de rappel.

## Pages légales
- **Mentions légales**, **conditions générales de vente des bons cadeaux** et **politique de confidentialité** : pages `#mentions-legales`, `#cgv`, `#confidentialite` (texte source : `outils/pages-legales.html`, à réinsérer avec `python3 outils/inserer-pages-legales.py`).
- Les passages **surlignés en jaune** sont à confirmer par Christelle avant la mise en ligne (ville du greffe, médiateur de la consommation, règles d'utilisation des bons, créateur du site).
- La case « J'accepte les CGV » est obligatoire avant le paiement, et vérifiée aussi par le moteur.
- Aucun cookie publicitaire ni outil d'audience ; polices hébergées sur le site ; plan OpenStreetMap chargé seulement au clic.

## Bons cadeaux automatiques
Le dossier `api/` contient le moteur (PHP + MySQL, pour l'hébergement IONOS) qui encaisse les paiements PayPal, crée les bons (code unique + QR code), les envoie par e-mail depuis la boîte de l'institut, permet de les vérifier à l'institut, et sauvegarde le registre (export Excel + récapitulatif hebdomadaire). Mise en service : **[INSTALLATION-IONOS.md](INSTALLATION-IONOS.md)**. Envoi automatique vers IONOS : `.github/workflows/envoi-ionos.yml`.

Solution de secours (Cloudflare + Brevo) : dossier `worker/`, guide [INSTALLATION-CLOUDFLARE.md](INSTALLATION-CLOUDFLARE.md).

Tests : `php api/tests/run.php` (moteur PHP) et `cd worker && npm test` (version Cloudflare).

## Ce qui reste à valider ensemble
- [ ] Compte PayPal Business et identifiant « Client ID »
- [ ] Photos de l'institut et de Christelle
- [ ] Conditions générales de vente et validité des bons cadeaux
- [ ] Nom de domaine et mise en ligne définitive

> Version de démonstration : aucune commande ni aucun paiement n'est réellement envoyé.

## Suivre les mises à jour
Cliquez sur **Watch** en haut de cette page (puis « All Activity ») pour recevoir un e-mail à chaque nouvelle version du site.
