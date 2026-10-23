# Mise en conformité légale — Aqua Beauté (23/10/2026)

Cas vérifié dans le code : SARL AQUA BEAUTE (France), site vitrine + vente en ligne de bons cadeaux à des particuliers, paiement PayPal, hébergement prévu chez IONOS (UE). Pas de compte client, pas de newsletter (peut-être plus tard). Taille : supposée sous 10 salariés et 2 M€ (à confirmer).

Ce document n'est pas un avis juridique. Relecture conseillée par un avocat ou la CCI avant la mise en ligne, car le site vend à des particuliers.

## Fichiers créés ou modifiés

| Fichier | Changement |
|---|---|
| `index.html` | Confidentialité et cookies mis à jour, formulaires corrigés, pied de page (cookies + médiateur), source des avis Google, contrastes, focus clavier, lien « Aller au contenu », titres, carte des soins lisible sans JavaScript, métadonnées, données structurées |
| `outils/pages-legales.html` | Source des pages légales : mêmes changements de confidentialité et cookies (réinsérée dans index.html) |
| `outils/generer-fichiers-ia.mjs` | Nouveau : génère llms.txt, sitemap.xml, robots.txt, la carte des soins statique et le JSON-LD depuis les données de la page |
| `llms.txt`, `sitemap.xml`, `robots.txt` | Nouveaux : fichiers pour moteurs de recherche et assistants IA |
| `api/lib/app.php` | Nouvelle purge automatique des données (durées de conservation), lancée par la tâche hebdomadaire |
| `api/tests/run.php` | Test de la purge |
| `api/lib/mail.php`, `worker/src/index.js` | Identité légale du vendeur (SARL, capital, SIRET, TVA) en bas des e-mails |
| `.github/workflows/envoi-ionos.yml` | Envoie llms.txt, sitemap.xml, robots.txt ; robots bloqués tant que MISE_EN_LIGNE ≠ oui |

## Point par point

1. **Mentions légales — s'applique.** Déjà complètes (SARL, capital, siège, RCS, SIRET, TVA, directrice de publication, hébergeur IONOS). Restent : ville du greffe, nom du créateur du site.
2. **Confidentialité — s'applique.** Ajouts : transferts hors UE (PayPal, encadrés par ses règles d'entreprise contraignantes ; OpenStreetMap au Royaume-Uni, pays reconnu adéquat), durées réelles, téléphone facultatif, base légale du formulaire de rappel. Traitements identifiés : achat de bon, utilisation du bon, demande de rappel (e-mail seulement, rien en base), tentatives de connexion (IP, 15 min), statistiques IONOS sans cookie, journaux de l'hébergeur.
   - **Nouveau dans le code :** commandes jamais payées supprimées après 30 jours ; e-mail, téléphone, bénéficiaire et message effacés 3 ans après l'achat ; tout supprimé après 10 ans. Avant, tout restait 10 ans.
3. **Cookies — pas de bandeau.** Inventaire : panier et thème (stockage du navigateur, nécessaires), cookies PayPal à l'étape paiement (nécessaires). Aucun analytics, pixel ou police externe. Ajout d'un tableau des traceurs dans la politique et d'un lien « Cookies » dans le pied de page.
4. **CGV — s'applique.** Déjà présentes : rétractation 14 jours, formulaire type, case non cochée, contrôle côté serveur. Inchangées en attendant les CGV de Christelle.
5. **CGU — ne s'applique pas** (voir plus bas).
6. **Médiateur — s'applique.** Emplacement `[MÉDIATEUR À CHOISIR]` ajouté dans le pied de page, déjà présent à l'article 13 des CGV.
7. **Formulaires.** Rappel : champs obligatoires signalés, message marqué facultatif, mention précise sous le formulaire. Achat : téléphone rendu facultatif (le moteur ne l'exigeait pas), champs obligatoires signalés, mention et lien vers la confidentialité. Aucun champ superflu trouvé. Pas de consentement à recueillir (pas de prospection).
8. **Services tiers :**

| Service | Quand | Traceur | Hors UE | Verdict |
|---|---|---|---|---|
| PayPal (SDK + paiement) | Étape paiement seulement | Oui, nécessaires | Possible (BCR) | Nécessaire, déclaré |
| OpenStreetMap (plan) | Au clic seulement | Non | Royaume-Uni (adéquat) | Conforme, déclaré |
| Google Maps (itinéraire) | Simple lien | Non sur le site | — | Conforme |
| Facebook | Simple lien | Non sur le site | — | Conforme |
| Polices | Hébergées sur le site | — | — | Conforme |

9. **Accessibilité (recommandée).** Corrigé : bleu des titres et boutons foncé (#1F7299, contraste 5,3:1 au lieu de 3,7:1), gris des petits textes (#646C76), focus visible au clavier, lien « Aller au contenu », titres du pied de page (plus de saut de niveau), tableaux défilables au clavier, notes en étoiles lisibles par les lecteurs d'écran, onglets de soins (aria-selected), textes alternatifs en double retirés. Contrôle automatique axe : plus aucune erreur sauf le logo PayPal (logotype, exempté). Reste à faire à la main : relire les textes alternatifs des photos, tester un parcours d'achat complet au clavier et au lecteur d'écran.
10. **Agents IA.** llms.txt (coordonnées, horaires, pages, 120 soins avec prix), robots.txt ouvert (sauf le moteur `api/`), sitemap.xml, balise canonique, Open Graph, données structurées « BeautySalon » (adresse, horaires, carte des soins). La carte des soins est désormais écrite dans la page : lisible sans JavaScript. Après chaque changement de soins, prix ou horaires : `node outils/generer-fichiers-ia.mjs`. Limite : les rubriques en `#` ne sont pas des adresses séparées, les moteurs ne voient qu'une page.
11. **Contenu — risques :**
    - **Photos de banque d'images** probablement achetées par Marketify (licence à leur nom) : `images/2023/05/african-american-lady-having-skin-scrubbing-proced-2022-12-16-07-52-57-utc-2.jpg`, `images/2025/01/relaxing-back-massage-2025-01-29-08-15-31-utc-scaled.webp`. À remplacer ou à faire confirmer par écrit.
    - **Image venant manifestement d'un article** : `images/2023/05/j-ai-teste-les-soins-du-visage-1b.webp`. À remplacer.
    - **Origine à confirmer** : `images/2022/12/image-2-1.jpg`, `Frame-*.png`, `image-4-1.png`, `images/2025/01/soin-chocolat-saint-valentin.jpg`, vignettes `images/elementor/thumbs/`.
    - **Logos de marques** (Esthederm, Bernard Cassière, Acorelle, Senteurs…) : usage habituel pour un revendeur, mais à confirmer avec chaque marque.
    - **Avis Google** : source et absence de vérification désormais indiquées, lien vers tous les avis ajouté. À vérifier : note 4,6 et « plus de 50 avis » encore exacts au moment de la mise en ligne ; les 3 avis cités existent bien sur Google.
    - **« Gratuit » / « offert »** : « Retrait à l'institut : gratuit » et la livraison « offerte dès 60 € » ne s'affichent que pour des produits, désactivés aujourd'hui. Rien à corriger.
12. **Coordonnées.** Nom, adresse, SIRET identiques entre mentions légales, CGV, pied de page et e-mails. Pied de page unique (le site est une seule page), donc présent partout. E-mail contact@ affiché : il dépend du compte Marketify (voir dossier du domaine). Formulaire de rappel : envoie vraiment une fois le moteur branché.
13. **Autres risques :**
    - **Confirmation sur support durable** : l'e-mail renvoie aux CGV par un lien. La loi demande de remettre les conditions sur un support durable : joindre les CGV en PDF à l'e-mail de confirmation (à faire, proposé).
    - **Note de prestation** : obligatoire dès 25 € pour un particulier qui la demande ; l'e-mail porte désormais l'identité légale, à compléter par le montant de TVA si Christelle est assujettie.
    - **TVA des bons** : bon « soin précis » = TVA à la vente ; bon « montant libre » = TVA à l'utilisation. À faire valider par le comptable.
    - **Version Cloudflare de secours** (`worker/`) : la purge automatique n'y est pas installée. À ajouter si cette version est un jour utilisée.
    - **Page de démo `verifier.html`** (code 1234) : exclue de l'envoi chez IONOS, à laisser ainsi.
    - **Domaine au nom de Marketify** : risque pour l'adresse canonique, les e-mails et la confidentialité (Marketify a accès à la boîte contact@).

## À faire par un humain

- [ ] Christelle : ville du greffe RCS ; nom du créateur du site ; les 4 règles surlignées dans les CGV (retrait, solde d'un bon, RDV annulé, médiateur) ; ou ses propres CGV.
- [ ] **Médiateur de la consommation** : en choisir un dans la liste officielle (economie.gouv.fr, rubrique médiation de la consommation), y adhérer (payant), puis remplacer `[MÉDIATEUR À CHOISIR]` (pied de page + article 13 des CGV).
- [ ] Hébergeur : confirmer IONOS et l'activation de SiteAnalytics, sinon retirer la ligne « Statistiques » de la politique.
- [ ] Photos et logos : décider de remplacer ou de faire confirmer les droits (liste au point 11).
- [ ] Vérifier la note Google et le nombre d'avis le jour de la mise en ligne.
- [ ] Confirmer la taille (salariés, CA) : au-delà de 10 salariés ou 2 M€, une déclaration d'accessibilité devient obligatoire.
- [ ] Comptable : TVA des 3 types de bons, contenu de la note de prestation.
- [ ] Relecture de l'ensemble par un avocat ou la CCI avant la mise en ligne.

## Jugé non applicable (contredis-moi si besoin)

- **Bandeau cookies** : aucun traceur non nécessaire.
- **CGU** : pas d'inscription, pas d'espace client, pas de contenu publié par les visiteurs ; l'espace de vérification est réservé à l'institut.
- **CGV entre professionnels** : vente aux particuliers uniquement.
- **Déclaration d'accessibilité** : structure supposée sous 10 salariés et 2 M€.
- **Consentement au formulaire** : pas de prospection ni de newsletter. Le jour où une newsletter arrive : case non cochée, mention, lien de désinscription dans chaque envoi.
