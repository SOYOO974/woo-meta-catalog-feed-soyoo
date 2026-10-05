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

- **Méthode unique** : `\SOYOO\MetaCatalog\Feed_Item::get_content_id( \WC_Product $product ) : string`
  - Produit simple / externe : `get_sku()` sinon `get_id()`.
  - Variation : `get_sku( 'edit' )` (SKU propre, sans héritage parent) sinon ID de la variation → aucun `<g:id>` en double.
  - Utilisée par `Feed_Item::build()` pour `<g:id>`. **Interdiction de recalculer l'ID ailleurs.**
- **Filtre exposé (API publique, ne jamais renommer ni changer la signature)** : `soyoo_meta_catalog_content_id( $id, \WC_Product $product )`, enregistré dans `woo_meta_catalog_feed_init()`. Consommé par `\WFBT\Product_Id::get()` via `apply_filters( 'soyoo_meta_catalog_content_id', null, $product )`.
- **Filtre de personnalisation** : `woo_meta_catalog_item_id( $id, $product )`, appliqué DANS `get_content_id()` → le flux ET le tracking suivent.
- **Garde-fou visuel** : encadré « Tracking Meta » dans `Feed_Admin::render_tracking_alignment_box()` (compare `\WFBT\Product_Id::get()` et `get_content_id()` sur 5 produits, même échantillon que l'encadré côté tracking). Le pendant côté tracking : `WooCommerce > wfbt-settings`.
- **Toute modification de la logique d'ID** = changement cassant pour Meta (articles recréés, historique d'apprentissage perdu) : bump de version mineure minimum, mention explicite dans le changelog et la release GitHub, et vérification que les deux encadrés restent verts.
