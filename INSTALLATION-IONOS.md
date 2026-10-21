# Mise en service sur l'hébergement IONOS

Tout est hébergé chez IONOS, dans l'abonnement que Christelle paie déjà : le site, le moteur des bons cadeaux et la base de données des bons. Les e-mails partent de sa boîte `contact@aquabeaute-esthetique.fr`. **Seul PayPal est à configurer en plus.**

Chaque modification faite par Claude dans GitHub est envoyée automatiquement sur IONOS.

> **Sécurité :** les mots de passe ne passent jamais par e-mail, SMS ou par la conversation avec Claude. Raphaël les saisit lui-même, une seule fois, dans les « Secrets » GitHub (étape 4). Ils ne sont visibles par personne ensuite, pas même dans GitHub.

---

## Étape 0 : récupérer l'accès IONOS (Christelle ↔ Marketify)

1. Demander à Marketify l'accès au compte client IONOS, ou le **transfert du contrat à son nom** s'il est au nom de l'agence.
2. Changer le mot de passe et activer la **double authentification**.
3. Ne rien résilier chez Marketify avant la mise en ligne (étape 8), pour ne pas couper les e-mails.
4. Noter le **nom de l'offre** d'hébergement : elle doit comporter un *espace web*, des *bases de données MySQL* et un accès *SFTP*.

## Étape 1 : préparer l'adresse de test (IONOS, 10 min)

Le nouveau site est d'abord installé à part, sur une adresse de test. L'ancien site WordPress continue de tourner normalement.

1. **Domaines & SSL** → `aquabeaute-esthetique.fr` → **Ajouter un sous-domaine** : `test`.
2. Le faire pointer vers l'**espace web**, dossier **`/nouveau-site`** (le créer s'il n'existe pas).
3. Activer le **certificat SSL** pour `test.aquabeaute-esthetique.fr`.

## Étape 2 : base de données et accès SFTP (IONOS, 10 min)

1. **Hébergement** → **Bases de données** → **Créer une base MySQL**. Choisir un mot de passe. Noter : **nom d'hôte**, **nom de la base**, **utilisateur**, **mot de passe**.
2. **Hébergement** → **SFTP & SSH** → **Créer un utilisateur**. Noter : **serveur** (du type `accessXXXXXXXX.webspace-host.com`), **utilisateur**, **mot de passe**.
3. Dans les réglages e-mail IONOS, vérifier le **serveur d'envoi (SMTP)** indiqué pour `contact@aquabeaute-esthetique.fr` (en général `smtp.ionos.fr`, port 465). Il faudra le **mot de passe de cette boîte**.
   *Si Christelle change un jour ce mot de passe, il faudra aussi le changer dans GitHub (étape 4), sinon les bons ne partiront plus par e-mail.*

## Étape 3 : PayPal (Christelle, 10 min)

1. Sur **developer.paypal.com**, se connecter avec le compte PayPal Business → **Apps & Credentials**.
2. Mode **Sandbox** (essais) → **Create App** `Site Aqua Beauté` → noter **Client ID** et **Secret**.
3. Dans l'app → **Webhooks** → **Add Webhook** :
   - URL : `https://test.aquabeaute-esthetique.fr/api/paypal/webhook`
   - Événements : `Checkout order approved`, `Payment capture completed`, `Payment capture refunded`, `Payment capture reversed`
   - Noter le **Webhook ID**.

## Étape 4 : tout enregistrer dans GitHub (Raphaël, 10 min)

Dépôt `Raph6730/aquabeaute` → **Settings** → **Secrets and variables** → **Actions**.

**Onglet « Secrets »** → **New repository secret**, un par ligne :

| Nom | Valeur |
|---|---|
| `IONOS_SFTP_HOST` | serveur SFTP (étape 2) |
| `IONOS_SFTP_USER` | utilisateur SFTP |
| `IONOS_SFTP_PASSWORD` | mot de passe SFTP |
| `IONOS_DOSSIER` | `/nouveau-site` |
| `DB_HOST` | nom d'hôte de la base |
| `DB_NAME` | nom de la base |
| `DB_USER` | utilisateur de la base |
| `DB_PASSWORD` | mot de passe de la base |
| `SMTP_PASSWORD` | mot de passe de la boîte `contact@` |
| `PAYPAL_CLIENT_ID` | Client ID PayPal |
| `PAYPAL_SECRET` | Secret PayPal |
| `PAYPAL_WEBHOOK_ID` | Webhook ID PayPal |
| `STAFF_PIN` | code PIN de la page de vérification, choisi par Christelle (6 chiffres) |
| `CRON_KEY` | une longue suite de lettres et chiffres au hasard (sert au récapitulatif) |

**Onglet « Variables »** → **New repository variable** :

| Nom | Valeur |
|---|---|
| `SITE_URL` | `https://test.aquabeaute-esthetique.fr/` |
| `PAYPAL_ENV` | `sandbox` |
| `SMTP_HOST` | `smtp.ionos.fr` (ou celui vu à l'étape 2) |

Puis **Actions** → **Envoi vers IONOS** → **Run workflow**. Au bout d'une minute, la ligne doit être verte ✅ et la dernière étape afficher « moteur OK ». Le site de test est en ligne.

## Étape 5 : le récapitulatif hebdomadaire (5 min)

Chaque semaine, Christelle reçoit un e-mail avec les chiffres et **la sauvegarde complète des bons** (fichier Excel). Pour l'activer, programmer l'ouverture de cette adresse tous les lundis à 8 h :

`https://test.aquabeaute-esthetique.fr/api/cron/recap?cle=` suivi de la valeur de `CRON_KEY`

- Avec les **tâches planifiées (Cron)** d'IONOS si l'offre les inclut ;
- sinon avec le service gratuit **cron-job.org**.

Cette adresse contient une clé secrète : ne pas la diffuser.

## Étape 6 : essais avec de l'argent fictif

Avec un **compte acheteur de test** PayPal (developer.paypal.com → Sandbox → Accounts), sur `https://test.aquabeaute-esthetique.fr` :

- [ ] Bon « montant » par e-mail : e-mail reçu avec le PDF, copie reçue par l'institut
- [ ] Bon « soin précis » à retirer à l'institut : e-mail de confirmation sans PDF, PDF reçu par l'institut
- [ ] Bon « accessoires » de 600 €
- [ ] Deux bons dans le même panier
- [ ] Paiement par carte (bouton carte bancaire) avec une carte de test PayPal
- [ ] Paiement annulé sur PayPal : le panier est conservé
- [ ] Page de vérification `https://test.aquabeaute-esthetique.fr/api/verifier` : PIN, scan du QR code, ✅ valable, marquer utilisé, ❌ déjà utilisé au second scan
- [ ] Remboursement depuis PayPal Sandbox : le bon passe en « remboursé »
- [ ] Export Excel depuis l'onglet « Tous les bons »
- [ ] Récapitulatif hebdomadaire (ouvrir l'adresse de l'étape 5 à la main)
- [ ] E-mails pas classés en indésirables (Gmail, Outlook, Orange)

## Étape 7 : passage aux vrais paiements

1. developer.paypal.com → mode **Live** → créer l'app et le webhook (URL avec `www` : voir étape 8).
2. Dans GitHub, remplacer `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET` et `PAYPAL_WEBHOOK_ID`, puis passer la variable `PAYPAL_ENV` à `live`.

## Étape 8 : mise en ligne sur le vrai nom de domaine

1. GitHub, onglet **Variables** : `SITE_URL` = `https://www.aquabeaute-esthetique.fr/`, et ajouter `MISE_EN_LIGNE` = `oui` (retire le bandeau de démonstration et autorise Google).
2. Relancer **Envoi vers IONOS**.
3. IONOS → **Domaines** : faire pointer `aquabeaute-esthetique.fr` et `www.aquabeaute-esthetique.fr` vers le dossier **`/nouveau-site`**. **Ne pas toucher aux réglages e-mail (MX).**
4. Mettre à jour l'adresse du webhook PayPal Live et de la tâche du récapitulatif avec `www`.
5. L'ancien WordPress reste dans son dossier : en cas de souci, il suffit de refaire pointer le domaine vers lui.
6. Passer le dépôt GitHub en **privé** (Settings → General → Danger Zone → Change visibility).
7. Les anciennes adresses (`/visage/`, `/bons-cadeaux/`…) redirigent automatiquement vers les nouvelles pages.

---

## Au quotidien (pour Christelle)

- **Une vente** : e-mail « Bon cadeau vendu » avec les PDF. Si le client a choisi « À l'institut », imprimer le bon et le préparer en enveloppe.
- **Un client présente un bon** : scanner le QR code avec l'appareil photo du téléphone (ou taper le code sur la page de vérification), vérifier ✅, puis **Marquer comme utilisé**.
- **Erreur de clic** : « Annuler l'utilisation ».
- **Remboursement** : rembourser depuis PayPal suffit, le bon est annulé automatiquement.
- **Suivi et sauvegarde** : onglet « Tous les bons » (vendus, à utiliser, restant dû), bouton **Exporter (Excel)**, et récapitulatif chaque lundi par e-mail.
- **Trois types de bons** affichés partout (PayPal, e-mails, vérification) : *Montant*, *Soin précis*, *Accessoires*, pour le comptable.
