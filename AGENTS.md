# Directives pour Agents Antigravity — Woo Meta Catalog Feed Soyoo

## 🚫 PROTOCOLE DE DÉPLOIEMENT : ZÉRO DÉPLOIEMENT FTP PAR DÉFAUT

> [!CRITICAL]
> **INTERDICTION FORMELLE DE DÉPLOYER PAR FTP / SFTP OU DE PROPOSER DES TABLEAUX RÉCAPITULATIFS FTP SANS DEMANDE EXPLICITE DE JULIEN.**
> 
> Cette extension in-house mutualisée est hébergée sur GitHub (`SOYOO974/woo-meta-catalog-feed-soyoo`) et intègre **Plugin Update Checker (PUC v5)** configuré pour surveiller les assets de release GitHub.
> Les sites WordPress/WooCommerce clients (KidShow.fr, Conforama.re, Le Comptoir de Cambaie, etc.) se mettent à jour **automatiquement en 1 clic** via le tableau de bord WordPress dès qu'une nouvelle release GitHub est publiée.

---

## 🔄 WORKFLOW DE PUBLICATION & MISE À JOUR PAR DÉFAUT

Dès qu'une modification, correction de bug ou amélioration est apportée à cette extension :

1. **Incrémentation de Version** :
   - Mettre à jour la version sémantique dans l'en-tête de [`woo-meta-catalog-feed-soyoo.php`](file:///c:/Antigravity/woo-plugins/woo-meta-catalog-feed-soyoo/woo-meta-catalog-feed-soyoo.php) (`Version: X.Y.Z`).
   - Mettre à jour la constante `WOO_META_CATALOG_FEED_VERSION` dans [`woo-meta-catalog-feed-soyoo.php`](file:///c:/Antigravity/woo-plugins/woo-meta-catalog-feed-soyoo/woo-meta-catalog-feed-soyoo.php).

2. **Validation Syntaxique & Linting** :
   - Exécuter impérativement `php -l` sur tous les fichiers PHP modifiés :
     ```bash
     php -l woo-meta-catalog-feed-soyoo.php
     php -l includes/class-feed-admin.php
     php -l includes/class-feed-generator.php
     php -l includes/class-feed-item.php
     php -l includes/class-feed-server.php
     php -l includes/class-feed-cli.php
     ```

3. **Commit & Push Proactif sur GitHub** :
   - Effectuer automatiquement le cycle Git sans attendre de consigne :
     ```bash
     git add .
     git commit -m "feat/fix: <description explicite> (vX.Y.Z)"
     git push origin main
     ```

4. **Génération de l'Archive Release (.zip)** :
   - Exécuter le script de build standard :
     ```powershell
     powershell -ExecutionPolicy Bypass -File .\bin\build-zip.ps1
     ```
   - Le script utilise `tar -a -cf` avec des séparateurs forward slashes `/` stricts pour garantir une compatibilité totale avec l'unzip WordPress sur serveurs Linux.

5. **Publication Proactive de la Release GitHub** :
   - Créer immédiatement la release GitHub avec l'archive attachée via GitHub CLI :
     ```bash
     gh release create vX.Y.Z woo-meta-catalog-feed-soyoo.zip --title "vX.Y.Z - <Titre court>" --notes "<Changelog formaté>"
     ```
   - Les sites WordPress clients détectent automatiquement la nouvelle version et l'appliquent via le gestionnaire d'extensions WordPress.

---

## 🧱 ARCHITECTURE DU PLUGIN

- [`woo-meta-catalog-feed-soyoo.php`](file:///c:/Antigravity/woo-plugins/woo-meta-catalog-feed-soyoo/woo-meta-catalog-feed-soyoo.php) : Point d'entrée, déclaration de compatibilité HPOS (`custom_order_tables`), chargement et configuration de `plugin-update-checker` (v5) avec release assets activés.
- [`includes/class-feed-admin.php`](file:///c:/Antigravity/woo-plugins/woo-meta-catalog-feed-soyoo/includes/class-feed-admin.php) : Page d'administration sous WooCommerce, déclencheur AJAX de régénération, statut en temps réel, barrière hermétique anti-alertes admin tierces (suppression PHP hooks `admin_notices`, ancre `<hr class="wp-header-end">`, CSS scoped et nettoyage DOM JS).
- [`includes/class-feed-generator.php`](file:///c:/Antigravity/woo-plugins/woo-meta-catalog-feed-soyoo/includes/class-feed-generator.php) : Moteur de compilation XML par tranches asynchrones piloté par Action Scheduler, streaming direct dans fichier temporaire `.xml.tmp` puis renommage atomique, gestion de verrous et libération mémoire.
- [`includes/class-feed-item.php`](file:///c:/Antigravity/woo-plugins/woo-meta-catalog-feed-soyoo/includes/class-feed-item.php) : Modélisation des balises RSS 2.0 / Google Merchant. `Feed_Item::get_content_id()` = source de vérité unique du `<g:id>` (voir contrat ci-dessous) ; `<g:item_group_id>` = ID parent pour les déclinaisons.
- [`includes/class-feed-server.php`](file:///c:/Antigravity/woo-plugins/woo-meta-catalog-feed-soyoo/includes/class-feed-server.php) : Route publique `/feed/meta-catalog.xml`, gestion des en-têtes HTTP de cache (`ETag`, `If-Modified-Since`, 304 Not Modified), jeton secret optionnel `?feed_key=...`.
- [`includes/class-feed-cli.php`](file:///c:/Antigravity/woo-plugins/woo-meta-catalog-feed-soyoo/includes/class-feed-cli.php) : Commandes WP-CLI (`wp meta-catalog generate`, `status`, `reset-lock`).

---

## 🤝 CONTRAT INTER-EXTENSIONS : ID META (depuis v1.3.0)

> [!CRITICAL]
> **Le flux catalogue est la SOURCE DE VÉRITÉ UNIQUE des ID Meta.** `woo-fb-tracking-server-side` (v2.1.0+, mode « auto ») ne calcule plus ses `content_ids` : il les demande au flux. Toute divergence fait chuter le taux de correspondance catalogue Meta à 0 % sans alerte côté Meta.

- **Méthode unique** : `\SOYOO\MetaCatalog\Feed_Item::get_content_id( \WC_Product $product, $format = null ) : string`
  - Mode 'id' (défaut, recommandé depuis v1.8.0) : renvoie directement le Post ID WooCommerce (`(string) $product->get_id()`), garantissant 100% d'alignement universel même en présence de SKU isolés.
  - Mode 'sku' : SKU si défini, sinon Post ID (`get_sku( 'edit' )` propre sans héritage pour les variations).
  - Utilisée par `Feed_Item::build()` pour `<g:id>`. **Interdiction de recalculer l'ID ailleurs.**
- **Filtre exposé (API publique, ne jamais renommer ni changer la signature)** : `soyoo_meta_catalog_content_id( $id, \WC_Product $product )`, enregistré dans `woo_meta_catalog_feed_init()`. Consommé par `\WFBT\Product_Id::get()` via `apply_filters( 'soyoo_meta_catalog_content_id', null, $product )`.
- **Filtre de personnalisation** : `woo_meta_catalog_item_id( $id, $product )`, appliqué DANS `get_content_id()` → le flux ET le tracking suivent.
- **Garde-fou visuel** : encadré « Tracking Meta » dans `Feed_Admin::render_tracking_alignment_box()` (compare `\WFBT\Product_Id::get()` et `get_content_id()` sur 5 produits, même échantillon que l'encadré côté tracking). Le pendant côté tracking : `WooCommerce > wfbt-settings`.
- **Toute modification de la logique d'ID** = changement cassant pour Meta (articles recréés, historique d'apprentissage perdu) : bump de version mineure minimum, mention explicite dans le changelog et la release GitHub, et vérification que les deux encadrés restent verts.

### ⚠️ Points de Vigilance Opérationnels & Recette

1. **Impact sur les Variations sans SKU Propre** :
   - Depuis v1.3.0, une variation sans SKU propre n'hérite plus du SKU de son parent et prend désormais son propre ID de variation (`get_sku( 'edit' ) ? get_sku( 'edit' ) : get_id()`).
   - *Conséquence Meta Ads* : Lors de l'import suivant du flux dans Meta Commerce Manager, ces variations sont considérées comme de nouveaux articles (`<g:id>` modifié). L'ancien article passe en rupture / non référencé et Meta réapprend la performance de la variation. Aucun impact sur les boutiques sans aucun SKU (ex: KidShow.fr où tout est géré par Post ID).
2. **Ordre de Déploiement Strict** :
   - Déployer `woo-fb-tracking-server-side` (v2.1.0+ en mode « auto ») **avant ou simultanément** à `woo-meta-catalog-feed-soyoo` (v1.3.0+). Si le flux est mis à jour en v1.3.0 alors qu'une version ancienne du tracking est encore active, le tracking continuera de transmettre le SKU parent sur ces variations, provoquant un désalignement temporaire (détecté en rouge dans l'encadré admin).
3. **Cas Limite : Collision SKU Numérique & Post ID** :
   - Si un produit possède un SKU purement numérique identique au Post ID d'un autre produit du catalogue, une collision de `<g:id>` reste possible.
4. **Commande PowerShell d'Audit Rapide Anti-Doublons** :
   - Pour vérifier l'absence totale de doublons `<g:id>` sur un fichier XML généré :
     ```powershell
     ([xml](Get-Content meta-catalog.xml -Raw -Encoding UTF8)).rss.channel.item | Group-Object { $_.id } | Where-Object Count -gt 1 | Select-Object Name, Count
     ```

---

## 🖼️ DIAGNOSTIC DES IMAGES CATALOGUE META & RATE-LIMITING (Audit Octobre 2026)

### 1. Faux-amis & Mythes déconstruits par l'audit empirique
- **Faux-ami WebP** : Meta Commerce Manager gère et affiche parfaitement les images au format `.webp` dans les catalogues produits récents (validé sur Comptoir de Cambaie : des références en `.webp` comme `100201`, `110021`, `110019`, `110060`, `219996` sont affichées à 100 % dans le catalogue Meta).
- **Vulnérabilité multi-formats** : Le blocage touche indistinctement le PNG, le JPEG et le WebP (`100012` en PNG, `100200` en JPEG 600x600, `100205` en JPEG 500x500 sont tous bloqués avec le carré gris placeholder).
- **Validité des fichiers sur le serveur** : 100 % des fichiers images existent sur le disque, ont des balises `<g:image_link>` valides et renvoient `HTTP 200 OK` avec le bon Content-Type et un binaire intact lorsqu'ils sont testés avec le User-Agent de Meta (`facebookexternalhit/1.1`).
- **Log d'import Meta vide (0 erreur)** : L'ingestion XML du flux est syntaxiquement validée par Meta. C'est le worker de téléchargement asynchrone des vignettes de Meta qui échoue sans bloquer l'import global du catalogue.

### 2. Cause racine : Saturation par requêtes concurrentes (Mass Crawl)
- Lors de l'ingestion d'un catalogue de 1 000 à 5 000 références, le robot de Meta lance des dizaines de requêtes simultanées en quelques secondes sur le chemin `/wp-content/uploads/`.
- Les couches de sécurité Cloudflare Enterprise / Rocket.net WAF déclenchent une limitation de débit (Rate-Limiting) ou un challenge `__cf_bm` après les premières requêtes, produisant une défaillance en blocs consécutifs de produits bloqués.

### 3. Solution stratégique : Déportation des images sur un CDN tiers (Origin Pull)
- Pour immuniser le catalogue contre le rate-limiting du serveur d'origine, le flux doit pouvoir réécrire les URLs d'images vers un CDN / Origin Proxy dédié (ex: ImageKit.io plan gratuit 20 Go/mois) capable d'encaisser des milliers de requêtes concurrentes sans blocage WAF et de normaliser les visuels (carré 1:1, fallback JPEG).


