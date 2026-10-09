# Woo Meta Catalog Feed Soyoo

[![WooCommerce HPOS](https://img.shields.io/badge/WooCommerce-HPOS%20Compatible-brightgreen.svg)](#)
[![PHP](https://img.shields.io/badge/PHP-7.4%20to%208.3%2B-blue.svg)](#)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](#)
[![Meta CAPI Aligned](https://img.shields.io/badge/Meta%20Ads-CAPI%20Aligned-success.svg)](#)

Générateur de flux catalogue XML haute performance, ultra-léger et autonome pour **Meta Ads** (Commerce Manager, Advantage+ Catalog Ads, retargeting DPA) et **Google Shopping**, développé sur-mesure par l'agence **SOYOO**.

Déployé en priorité sur des catalogues volumineux (ex: **KidShow.fr** : 2 749 produits publiés, 210 produits variables, 0 SKU - uniquement des Post IDs sur Rocket.net Cloudflare Enterprise) avec vocation à être mutualisé sur tous les sites e-commerce sous gestion SOYOO.

---

## 🚀 1. Caractéristiques Principales

- **Alignement CAPI Critique (100% Match Rate) — le flux est la source de vérité unique des ID Meta** : l'identifiant XML (`<g:id>`) est calculé par une seule méthode, `\SOYOO\MetaCatalog\Feed_Item::get_content_id( \WC_Product $product )`, exposée à notre extension in-house `woo-fb-tracking-server-side` (v2.1.0+, mode « auto ») via le filtre `soyoo_meta_catalog_content_id`. Le tracking envoie donc exactement les mêmes `content_ids` que le catalogue, sans logique dupliquée.
  - Produits simples / externes : SKU si défini, sinon Post ID.
  - Déclinaisons : SKU **propre** de la variation (`get_sku( 'edit' )`, sans héritage du SKU parent), sinon ID de la variation. L'ID du produit parent est assigné à `<g:item_group_id>`.
  - Un encadré « Tracking Meta » sur l'écran de réglages compare en direct les ID tracking / flux sur 5 produits publiés.
- **Filtrage Intelligent du « Stock Mort » (Algorithme Ventes Anti-Saturation)** :
  - Détection automatique via la table WooCommerce Analytics `wc_order_product_lookup` (ou date de création si 0 vente).
  - Élimine chirurgicalement les références épuisées depuis plus de 60 jours, 90 jours, 180 jours, 1 an ou 2 ans sans vente.
  - Insensible aux fausses modifications (`post_modified`) causées par les synchronisations nocturnes ERP/caisse.
  - Préserve l'apprentissage algorithmique de Meta Advantage+ Catalog Ads pour les ruptures récentes temporaires.
  - Traitement granulaire des déclinaisons : exclut uniquement les tailles/déclinaisons mortes sans altérer les variantes en stock.
- **Exclusion des Produits Masqués (`catalog_visibility = hidden`)** :
  - Écarte automatiquement les produits configurés sur « Caché » dans WooCommerce qui ne doivent plus apparaître sur la boutique.
- **Moteur Résilient Anti-Timeout & Anti-Memory Exhaustion** :
  - Découpage du catalogue en tranches de 200 à 250 produits.
  - Ordonnancement asynchrone via **WooCommerce Action Scheduler** (aucun blocage PHP ou Cloudflare 504 Gateway Timeout).
  - Écriture en streaming direct dans un fichier temporaire `meta-catalog.xml.tmp`.
  - Renommage atomique vers `meta-catalog.xml` une fois le lot terminé.
  - Consommation mémoire constante $O(1)$ grâce à la libération périodique des caches WP (`wp_cache_delete`, `gc_collect_cycles`).
- **Compatibilité Stricte WooCommerce HPOS** : Déclaration officielle de compatibilité avec le stockage haute performance des commandes (`FeaturesUtil::declare_compatibility( 'custom_order_tables' )`).
- **Distribution Intelligente & Caching HTTP** :
  - Support natif des en-têtes `ETag` et `If-Modified-Since` avec réponse `HTTP 304 Not Modified` pour économiser la bande passante et les ressources serveur lors des passages répétés du robot Meta.
- **Sécurité Anti-Scraping** : Jeton secret optionnel (`?feed_key=xxx`) pour empêcher le téléchargement concurrentiel automatisé du catalogue tout en autorisant le crawler Meta.
- **Mises à Jour Automatiques via GitHub** : Intégration de **Plugin Update Checker (PUC v5.6)** pointant sur le dépôt privé/public de SOYOO.
- **Support WP-CLI** : Commandes terminal intégrées pour les administrateurs et DevOps.

---

## 📐 2. Structure du Flux XML

Le flux produit respecte scrupuleusement la spécification **RSS 2.0 avec l'espace de noms Google Base** (`xmlns:g="http://base.google.com/ns/1.0"`) :

| Balise XML | Description & Logique |
| :--- | :--- |
| `<g:id>` | `Feed_Item::get_content_id()` : ID produit WooCommerce (par défaut, recommandé) ou SKU avec repli sur ID selon réglage `id_format`. Partagé avec le tracking CAPI. |
| `<g:title>` | Titre nettoyé du produit (ou Titre Parent + Attributs pour les variations). |
| `<g:description>` | Extrait court (ou description longue tronquée à 5 000 car.), balises HTML nettoyées. |
| `<g:link>` | URL canonique HTTPS avec balisage UTM paramétrable (`utm_source=facebook&utm_medium=catalog`). |
| `<g:image_link>` | URL absolue HTTPS de l'image principale (avec repli sur l'image parent si variation sans visuel). |
| `<g:additional_image_link>` | Jusqu'à 5 images additionnelles issues de la galerie produit. |
| `<g:price>` | Prix régulier d'origine formaté avec devise ISO (ex: `100.00 EUR`), calculé via `wc_get_price_to_display()` (conformité TTC/HT alignée avec `woo-fb-tracking-server-side`). |
| `<g:sale_price>` | Prix remisé si promotion active (supporte soldes WooCommerce et réductions dynamiques). Déclenche automatiquement le **prix barré** sur Meta Ads et Instagram Shop. |
| `<g:sale_price_effective_date>` | Intervalle de validité ISO 8601 (`YYYY-MM-DDTHH:MM+TZ/YYYY-MM-DDTHH:MM+TZ`) si des dates de promotion sont programmées dans WooCommerce. |
| `<g:condition>` | `new`. |
| `<g:brand>` | Marque issue de l'attribut produit configuré (`pa_marque`, `pa_brand`), ou nom du site en repli. |
| `<g:item_group_id>` | Présent uniquement sur les variations, contenant l'identifiant du produit parent. |
| `<g:product_type>` | Fil d'Ariane hiérarchique des catégories (ex: `Vêtements > Fille > Robes`). |
| `<g:internal_label>` | Balises répétables pour chaque étiquette interne Meta (catégories, tags, promos, nouveautés, bestsellers, tendances), sans crochets ni guillemets parasites, permettant de filtrer les ensembles de produits dans Commerce Manager. |
| `<g:inventory>` | Quantité numérique en stock si la gestion des stocks est active. |

---

## 🛠️ 3. Configuration dans Meta Commerce Manager

### Étape 1 : Première génération & Récupération de l'URL du flux
1. Rendez-vous dans votre administration WordPress sous **WooCommerce > Flux Meta Catalog**.
2. **Lors du premier lancement**, cliquez sur **« Régénérer le flux maintenant »** pour compiler le catalogue (le moteur AJAX traite les lots en ~10-15 secondes).
3. Copiez l'URL mise en valeur dans l'encadré vert :
   - URL canonique optimisée : `https://votredomaine.fr/feed/meta-catalog.xml` (avec gestion de cache conditionnel ETag / 304)
   - Format sécurisé : `https://votredomaine.fr/feed/meta-catalog.xml?feed_key=VOTRE_JETON`
   - URL statique directe : `https://votredomaine.fr/wp-content/uploads/feeds/meta-catalog.xml`

### Étape 2 : Ajouter la source de données dans Meta
1. Accédez à [Meta Commerce Manager](https://business.facebook.com/commerce).
2. Sélectionnez votre catalogue de produits.
3. Dans le menu latéral, cliquez sur **Catalogue > Sources de données**.
4. Cliquez sur **Ajouter des articles** > **Flux de données (Data feed)**.
5. Choisissez l'option **Flux programmé (Scheduled feed)**.
6. Collez l'URL de votre flux SOYOO (`https://votredomaine.fr/feed/meta-catalog.xml`).
7. Réglez la fréquence de mise à jour sur **Quotidienne** à **04h30 ou 05h00 du matin** (1 heure après la génération nocturne automatique WordPress de 03h30).
8. Définissez la devise par défaut (ex: `EUR - Euro`).
9. Lancez l'importation initiale : Meta traitera immédiatement tous vos articles simples et déclinaisons.

---

## 💻 4. Commandes WP-CLI

Pour lancer la génération en ligne de commande ou l'intégrer à un cron système :

```bash
# Déclencher la génération en arrière-plan via Action Scheduler
wp meta-catalog generate

# Forcer la génération synchrone immédiate (idéal en maintenance SSH)
wp meta-catalog generate --sync

# Consulter le statut, la date, la taille et le nombre d'articles
wp meta-catalog status

# Réinitialiser un verrou orphelin suite à un arrêt inopiné du serveur
wp meta-catalog reset-lock
```

---

## 🔌 5. Hooks pour Développeurs

### Action : `woo_meta_catalog_feed_generated`
Déclenchée immédiatement après la finalisation et le renommage atomique du fichier XML :

```php
add_action( 'woo_meta_catalog_feed_generated', function( $file_path, $total_items, $duration ) {
    // Purger les caches externes (ex: WP Agent Bridge, Cloudflare, WP Rocket)
    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
    }
}, 10, 3 );
```

### Filtre : `woo_meta_catalog_item_id`
Personnalise le `<g:id>` (format sur-mesure par site). Appliqué dans `Feed_Item::get_content_id()`, il est donc **automatiquement répercuté sur le tracking** via le contrat inter-extensions :

```php
add_filter( 'woo_meta_catalog_item_id', function( $id, $product ) {
    return 'KS-' . $id;
}, 10, 2 );
```

### Filtre exposé : `soyoo_meta_catalog_content_id` (contrat inter-extensions)
Consommé par `woo-fb-tracking-server-side` v2.1.0+ (mode « auto ») : `apply_filters( 'soyoo_meta_catalog_content_id', null, $product )` renvoie le `<g:id>` exact écrit dans le XML. Ne pas le surcharger : utiliser `woo_meta_catalog_item_id`.

---

## 📝 Changelog

### 1.9.3
- **Optimisation ergonomique & pleine largeur de l'interface d'administration** :
  - Élargissement du conteneur principal `.woo-meta-catalog-wrap` à 1 440 px (au lieu de 1 100 px) pour exploiter les écrans larges modernes et supprimer l'espace gris inutilisé.
  - Déplacement du panneau de configuration Tendance et du tableau de prévisualisation dans une rangée dédiée en pleine largeur (`colspan="2"`), éliminant la gouttière gauche vide de 200 px qui comprimait les colonnes.
  - Suppression de la contrainte `fixed` sur le tableau de prévisualisation pour permettre une distribution responsive naturelle de la largeur des colonnes sans tronquer les titres produits.
- **Filtre de prix effectif minimum pour les Meilleures Ventes (`label_bestseller_min_price`)** :
  - Nouveau réglage paramétrable (défaut : 8,0 €) pour exclure du marqueur Best-Seller les articles à faible valeur unitaire (vis, consommables, échantillons).
  - Filtrage optimisé dans la requête `wc_product_meta_lookup` (`max_price >= %f`) avec vérification individuelle du prix des déclinaisons en stock.
- **Filtre de prix effectif minimum pour les Produits Tendances en Mode Classique (`label_trending_min_price`)** :
  - Alignement du mode classique sur le seuil de prix minimum via `check_product_eligibility`, empêchant les articles à bas prix d'entrer dans le Top N des ventes récentes.
  - Validation du prix effectif au niveau des déclinaisons dans `is_trending()`.

### 1.9.2
- **Correctif bloquant sur le retrait du préfixe des statuts (`query_net_sales`)** : remplacement du découpage `ltrim( $st, 'wc-' )` (qui tronquait les statuts débutant par 'c' comme `cancelled` en `ancelled` ou `checkout-draft` en `heckout-draft`) par un test strict de préfixe `strpos( $clean, 'wc-' ) === 0`. Les commandes annulées et brouillons sont à nouveau rigoureusement exclues de l'agrégation des ventes.
- **Préservation des réglages du mode inactif** : lors de l'enregistrement des réglages de l'extension, les champs du mode non sélectionné (qui sont désactivés dans le DOM pour éviter toute collision) ne sont plus écrasés par leurs valeurs par défaut codées en dur, mais fidèlement préservés depuis les réglages existants (`$existing`).

### 1.9.1
- **Prise en compte des statuts de commande personnalisés** : remplacement de la liste blanche fixe (`completed`, `processing`) par une liste d'exclusion des commandes non payées/annulées (`pending`, `failed`, `cancelled`, `refunded`, `on-hold`, `checkout-draft`, `trash`, `auto-draft`). Intègre un nouveau filtre `woo_meta_catalog_trending_excluded_statuses`. Les commandes en cours de préparation, expédition ou retrait en boutique (ex: `preparing`, `shipping`, `pickup` sur KidShow.fr) sont désormais comptabilisées à 100 %.
- **Champ N dédié pour le mode saisonnier** : séparation de `label_trending_seasonal_count` (défaut 60) et `label_trending_count` (défaut 35, mode classique), éliminant tout écrasement accidentel. Les champs du mode inactif sont automatiquement désactivés (`disabled`) dans l'interface d'administration.
- **Éligibilité individuelle des variations** : en mode saisonnier, les variations dont le parent est sélectionné ne reçoivent le marqueur que si elles respectent individuellement le stock minimum et le prix effectif minimum configurés.
- **Fiabilisation de l'alerte fiches renouvelées (`b1_warning`)** : calcul de la couverture de B1 effectué sur la sélection initiale (avant l'absorption des débordements de A) pour conserver la visibilité des fiches recréées.
- **Alignement temporel HPOS / GMT** : conversion explicite des bornes locales en GMT lors des requêtes sur les colonnes `date_created_gmt` et `post_date_gmt`.

### 1.9.0
- **Mode Tendance Saisonnière Multi-Sources** : ajout de l'algorithme saisonnier combinant ventes récentes (Source A), même période l'année précédente (Source B1) et repli automatique par catégorie la plus précise (Source B2).
- **Repli Catégorie Intelligent & Distribution Round-Robin** : si les articles de l'an dernier sont épuisés ou recréés sous de nouvelles fiches (ex: Halloween/Noël sur KidShow.fr), le moteur identifie la catégorie feuille de chaque article et sélectionne à tour de rôle les nouveautés publiées et en stock dans ces rayons.
- **Pondération des Ajouts au Panier (ATC)** : compteur interne résilient via le hook serveur `woocommerce_add_to_cart` dans une table dédiée avec purge automatique > 60 jours, sans dépendance externe et insensible au cache de page.
- **Filtres d'Éligibilité Riches** : respect des seuils de stock minimum (pour simples et variations) et de prix effectif minimum (sur la variation la moins chère).
- **Aperçu & Diagnostic Interactif** : tableau de prévisualisation dans l'administration avec bouton « Recalculer maintenant » AJAX, badges sources, indicateurs de ventes/score et alerte proactive si les fiches de l'an passé ont été renouvelées.
- **Planification WP-Cron Dédiée** : recalcul automatique quotidien à 03h00 (fuseau horaire du site) avec mise en cache persistante 26h.

### 1.8.0
- Ajout du réglage `id_format` dans l'administration WooCommerce ('id' => 'ID produit WooCommerce (recommandé)', 'sku' => 'SKU avec repli sur ID') avec persistance dans `woo_meta_catalog_settings`.
- `Feed_Item::get_content_id()` utilise désormais l'option `id_format` (valeur par défaut : 'id' = Post ID WooCommerce).
- Résout les désalignements constatés sur les boutiques (ex. KidShow.fr) indexées sur les Post IDs où quelques rares produits possèdent un SKU sans que le catalogue ne doive basculer en SKU.

### 1.3.0
- Le flux devient la source de vérité unique des ID Meta : nouvelle méthode `Feed_Item::get_content_id()` + filtre exposé `soyoo_meta_catalog_content_id` (consommé par `woo-fb-tracking-server-side` v2.1.0+).
- Nouveau filtre `woo_meta_catalog_item_id` pour les formats d'ID sur-mesure.
- Correctif doublons : une variation sans SKU propre n'hérite plus du SKU parent, elle sort avec son ID de variation.
- Encadré « Tracking Meta » sur l'écran de réglages : comparaison en direct tracking / flux sur 5 produits.

> [!WARNING]
> Les variations sans SKU propre dont le parent a un SKU changent de `<g:id>` (SKU parent → ID variation). Meta recrée ces articles au prochain import ; le tracking suit automatiquement en mode « auto ».

---

## 📦 6. Packaging & Déploiement

Le déploiement est entièrement automatisé via les **Releases GitHub** et le système **Plugin Update Checker (PUC)**. Aucun déploiement FTP manuel n'est requis par défaut.

1. **Génération de l'archive ZIP** :
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\bin\build-zip.ps1
   ```
   Le script effectue une analyse syntaxique stricte (`php -l`) sur chaque fichier source avant de générer l'archive `woo-meta-catalog-feed-soyoo.zip` avec arborescence compatible Linux.

2. **Publication de la Release GitHub** :
   ```powershell
   gh release create vX.Y.Z woo-meta-catalog-feed-soyoo.zip --title "vX.Y.Z - <Titre>" --notes "<Changelog>"
   ```
   Les sites sous gestion SOYOO équipés de l'extension reçoivent automatiquement la notification de mise à jour dans le tableau de bord WordPress et s'actualisent en un clic.

---

## 🔒 7. Sanctuarisation In-House SOYOO

Ce plugin fait partie du catalogue officiel des extensions in-house SOYOO. Toute modification doit être effectuée et versionnée exclusivement au sein de son dépôt de référence :
`c:\Antigravity\woo-plugins\woo-meta-catalog-feed-soyoo\`
et synchronisée sur GitHub :
`https://github.com/SOYOO974/woo-meta-catalog-feed-soyoo`
