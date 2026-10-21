# Mise en service sur Cloudflare (solution de secours)

> La solution retenue est l'hébergement IONOS : voir **[INSTALLATION-IONOS.md](INSTALLATION-IONOS.md)**. Ce guide décrit l'alternative Cloudflare + Brevo (dossier `worker/`).

Ce guide explique comment brancher le vrai paiement. À faire **une seule fois**, idéalement pendant un rendez-vous avec Christelle (compter 45 minutes).

## Comment ça marche

1. Le client choisit son bon sur le site et paie avec PayPal (compte PayPal ou carte bancaire).
2. Le **moteur** (un petit programme hébergé gratuitement chez Cloudflare) vérifie le paiement auprès de PayPal, crée un **code unique** (ex. `AQB-7KQ2-M9XD`) et fabrique le bon en PDF avec un QR code.
3. Le client reçoit le bon par e-mail. L'institut reçoit une copie de chaque vente.
4. À l'institut, Christelle scanne le QR code avec son téléphone : la page de vérification indique si le bon est valable, et elle le marque comme utilisé en un clic. Un bon ne peut servir qu'une fois.

Coût : 0 € par mois. Seule la commission PayPal s'applique sur chaque vente (2,90 % + 0,35 € pour un acheteur en France).

## Ce qu'il faut créer

| Service | À quoi il sert | Au nom de |
|---|---|---|
| PayPal Business (existe déjà) | Encaisser les paiements | Christelle |
| Brevo (gratuit) | Envoyer les bons par e-mail au nom de l'institut | à décider |
| Cloudflare (gratuit) | Héberger le moteur et le registre des bons | à décider |

> **Règle de sécurité :** les clés secrètes (PayPal *Secret*, clé API Brevo) ne doivent jamais être envoyées par e-mail, SMS ou dans une conversation. On les colle directement dans Cloudflare (étape 4).

---

## Étape 1 : PayPal (Christelle, 10 min)

1. Aller sur **developer.paypal.com** et se connecter avec le compte PayPal Business de l'institut.
2. Menu **Apps & Credentials**.
3. Commencer en mode **Sandbox** (bouton en haut) pour les essais : cliquer sur **Create App**, nom : `Site Aqua Beauté`.
4. Noter le **Client ID** (il n'est pas secret) et laisser la page ouverte pour le **Secret** (étape 4).
5. Plus tard, une fois les essais réussis : refaire la même chose en mode **Live**.

## Étape 2 : Brevo (15 min)

1. Créer un compte gratuit sur **brevo.com** avec l'adresse `contact@aquabeaute-esthetique.fr`.
2. Menu **Expéditeurs, domaines et IP dédiées** → **Expéditeurs** → ajouter `contact@aquabeaute-esthetique.fr` et valider le code reçu par e-mail.
3. *(Recommandé pour éviter les spams)* Onglet **Domaines** → authentifier `aquabeaute-esthetique.fr` : Brevo donne des lignes à ajouter chez l'hébergeur du nom de domaine.
4. Menu **SMTP & API** → **Clés API** → **Générer une nouvelle clé**. La garder pour l'étape 4.

## Étape 3 : Cloudflare (15 min)

1. Créer un compte gratuit sur **dash.cloudflare.com**.
2. **Stockage et bases de données** → **KV** → **Créer** : nom `aquabeaute-bons`. Copier l'**ID** affiché.
3. **Workers et Pages** → **Créer** → **Importer un dépôt** → connecter GitHub → choisir `Raph6730/aquabeaute`.
   - **Répertoire racine (Root directory)** : `worker`
   - Laisser le reste par défaut et déployer.
   - Si ce premier déploiement échoue, c'est normal : il manque encore l'ID du KV. Dès que Claude l'a ajouté, Cloudflare redéploie tout seul.
4. Une fois déployé, Cloudflare affiche l'adresse du moteur, du type `https://aquabeaute-bons-cadeaux.xxxx.workers.dev`.

**À envoyer à Claude** (rien de secret) : l'ID du KV, l'adresse du moteur et le Client ID PayPal. Claude met à jour le site et le moteur.

## Étape 4 : les secrets (5 min, directement dans Cloudflare)

Dans Cloudflare : le Worker `aquabeaute-bons-cadeaux` → **Paramètres** → **Variables et secrets** → **Ajouter**, type **Secret** :

| Nom | Valeur |
|---|---|
| `PAYPAL_CLIENT_ID` | le Client ID PayPal |
| `PAYPAL_SECRET` | le Secret PayPal |
| `BREVO_API_KEY` | la clé API Brevo |
| `STAFF_PIN` | un code PIN choisi par Christelle (6 chiffres conseillés) pour la page de vérification |
| `PAYPAL_WEBHOOK_ID` | voir étape 4 bis |

## Étape 4 bis : les notifications PayPal (5 min, fortement conseillé)

Elles servent de filet de sécurité : si le client ferme la page juste après avoir payé, si PayPal confirme un paiement plus tard, ou si Christelle rembourse un bon depuis PayPal (le bon est alors automatiquement annulé).

1. Sur **developer.paypal.com** → **Apps & Credentials** → l'app `Site Aqua Beauté` → section **Webhooks** → **Add Webhook**.
2. URL : `https://…workers.dev/api/paypal/webhook` (l'adresse du moteur suivie de `/api/paypal/webhook`).
3. Cocher les événements : `Checkout order approved`, `Payment capture completed`, `Payment capture refunded`, `Payment capture reversed`.
4. Enregistrer, puis copier le **Webhook ID** affiché et l'ajouter dans Cloudflare comme secret `PAYPAL_WEBHOOK_ID`.

## Étape 5 : essai complet

1. Sur le site, acheter un bon avec un **compte acheteur de test** PayPal (developer.paypal.com → Sandbox → Accounts).
2. Vérifier la réception de l'e-mail avec le PDF, et la copie reçue par l'institut.
3. Sur le téléphone de Christelle, ouvrir `https://…workers.dev/verifier`, entrer le PIN, cocher « Se souvenir de ce téléphone ». Ajouter la page à l'écran d'accueil.
4. Scanner le QR code du bon de test : il doit apparaître **✅ Valable**, puis **❌ Déjà utilisé** après validation.

## Étape 6 : passage aux vrais paiements

1. Créer l'app PayPal en mode **Live** (étape 1), refaire le webhook (étape 4 bis) et remplacer `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET` et `PAYPAL_WEBHOOK_ID` dans Cloudflare.
2. Envoyer le nouveau Client ID à Claude, qui passe `PAYPAL_ENV` en `live` et met le site à jour.

---

## Au quotidien (pour Christelle)

- **Une vente** : elle reçoit un e-mail « Bon cadeau vendu » avec les PDF. Si le client a choisi « À l'institut », elle imprime le bon et le prépare en enveloppe.
- **Un client présente un bon** : scanner le QR code avec l'appareil photo du téléphone (ou taper le code sur la page `/verifier`), vérifier ✅, puis **Marquer comme utilisé**.
- **Erreur de clic** : le bouton « Annuler l'utilisation » remet le bon comme valable.
- **Trois types de bons** (affichés à la vérification) : *Montant* (toutes prestations), *Soin précis*, *Accessoires*. Chaque vente indique le type dans PayPal, pour le comptable.
- **Remboursement** : rembourser depuis PayPal suffit, le bon passe automatiquement en « remboursé » et sera refusé à la vérification.
- **Suivi** : l'onglet « Tous les bons » montre les bons vendus, ceux encore à utiliser et le montant restant dû.
