# WP Grafana Exporter

![Version 1.1.0](https://img.shields.io/badge/version-1.1.0-2563eb)
![PHP 7.4 minimum](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777BB4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-plugin-21759B?logo=wordpress&logoColor=white)
![Prometheus](https://img.shields.io/badge/Prometheus-compatible-E6522C?logo=prometheus&logoColor=white)

**Gardez un œil sur votre site WordPress depuis Grafana.**

WP Grafana Exporter est un plugin WordPress qui expose les métriques du site au format texte Prometheus : contenus, utilisateurs, mises à jour, tâches cron, base de données et indicateurs de configuration. Avec les tables de **WP Statistics**, il ajoute des statistiques de fréquentation.

Un seul fichier PHP à installer, un endpoint protégé par token et une page d’administration pour configurer la collecte.

[Installation](#installation) · [Prometheus](#configuration-prometheus) · [Grafana](#tableau-de-bord-grafana) · [Métriques](#référence-des-métriques) · [Dépannage](#dépannage)

## Fonctionnement

```mermaid
flowchart LR
    G[Grafana] -->|Requêtes PromQL| P[Prometheus]
    P -->|Collecte HTTP avec token Bearer| W[WordPress · WP Grafana Exporter]
    W -->|Lecture des données| D[(Base de données)]
```

Prometheus interroge le plugin à intervalle régulier et conserve les mesures. Grafana utilise ensuite Prometheus comme source de données pour les afficher.

| Domaine | Ce que vous pouvez suivre |
| --- | --- |
| Contenu | Articles, pages et autres types publics, commentaires, médias, dernière modification |
| Utilisateurs | Nombre d’utilisateurs par rôle |
| Maintenance | Mises à jour du cœur, des plugins et des thèmes ; versions des plugins à mettre à jour |
| Exploitation | Tâches cron en retard, taille de la base, options chargées automatiquement, transients |
| Configuration | Versions WordPress et PHP, limite mémoire, mode debug, HTTPS |
| Audience, en option | Visiteurs, pages vues, pays, villes, navigateurs, systèmes, sources et pages populaires |

## Installation

### Prérequis

- Une installation WordPress avec **PHP 7.4 minimum**, selon l’en-tête du plugin.
- Un compte disposant de la capacité `manage_options` pour accéder aux réglages.
- Une instance Prometheus pouvant joindre l’API REST du site.
- Grafana pour créer les tableaux de bord.
- Les tables de WP Statistics pour les métriques d’audience, si vous souhaitez les utiliser.

### Installer le plugin

1. Créez le dossier `wp-grafana-exporter` dans `wp-content/plugins/`.
2. Copiez le fichier [`src/wp-grafana-exporter.php`](src/wp-grafana-exporter.php) dans ce dossier :

   ```text
   wp-content/
   └── plugins/
       └── wp-grafana-exporter/
           └── wp-grafana-exporter.php
   ```

3. Activez **WP Grafana Exporter** depuis **Extensions** dans WordPress.
4. Ouvrez **Outils → Grafana Exporter** pour récupérer l’URL de l’endpoint, le token et les exemples de configuration.

Le token est généré automatiquement à l’activation. Aucune étape Composer ou npm n’est nécessaire.

### Tester l’endpoint

Remplacez le domaine et le token ci-dessous par les valeurs de votre installation :

```bash
curl --fail --show-error \
  --header 'Authorization: Bearer VOTRE_TOKEN' \
  'https://votre-site.fr/wp-json/prometheus/v1/metrics'
```

La réponse est du texte au format Prometheus `0.0.4`. Exemple illustratif :

```text
# HELP wp_posts_total Nombre de contenus par type et statut
# TYPE wp_posts_total gauge
wp_posts_total{type="post",status="publish"} 42
wp_posts_total{type="page",status="publish"} 8
# HELP wp_updates_available Mises à jour disponibles par type
# TYPE wp_updates_available gauge
wp_updates_available{type="core"} 0
wp_updates_available{type="plugin"} 2
wp_updates_available{type="theme"} 0
```

## Configuration Prometheus

Ajoutez ce job à la liste `scrape_configs` de votre fichier `prometheus.yml`. Si cette clé existe déjà, ajoutez uniquement le job à la liste existante.

```yaml
scrape_configs:
  - job_name: wordpress
    scheme: https
    metrics_path: /wp-json/prometheus/v1/metrics
    scrape_interval: 5m
    scrape_timeout: 60s
    authorization:
      type: Bearer
      credentials: 'VOTRE_TOKEN'
    static_configs:
      - targets: ['votre-site.fr']
```

Remplacez `VOTRE_TOKEN` et `votre-site.fr`, puis rechargez la configuration de Prometheus selon votre mode de déploiement. La cible doit apparaître **UP** dans la page des cibles de Prometheus.

Quelques points à adapter à votre installation :

- `targets` contient le nom d’hôte, avec le port si nécessaire, sans schéma ni chemin.
- Pour un WordPress installé dans un sous-dossier, adaptez `metrics_path`, par exemple `/blog/wp-json/prometheus/v1/metrics`.
- Si l’URL REST utilise `?rest_route=`, utilisez l’URL affichée par le plugin pour le test et la configuration alternative ci-dessous pour Prometheus.
- Pour plusieurs sites ayant chacun leur token, créez un job par site avec les identifiants correspondants.

<details>
<summary>Configuration avec une URL REST utilisant un paramètre</summary>

Remplacez `metrics_path` et ajoutez `params` dans le job :

```yaml
metrics_path: /
params:
  rest_route: ['/prometheus/v1/metrics']
```

Pour une installation dans `/blog`, utilisez `metrics_path: /blog/`. Conservez les autres réglages du job, notamment l’authentification.

</details>

> **Fréquence de collecte :** `5m` avec un délai maximal de `60s` reprend les valeurs proposées par le plugin. Chaque collecte calcule les métriques et appelle les fonctions de vérification des mises à jour WordPress, plugins et thèmes. Elle peut donc effectuer des requêtes SQL et des appels réseau ; adaptez la fréquence à la charge du site.

## Tableau de bord Grafana

Ajoutez votre instance Prometheus comme source de données dans Grafana, puis créez des panneaux avec les requêtes suivantes. Elles supposent que le job s’appelle `wordpress`.

| Panneau | Requête PromQL | Affichage suggéré |
| --- | --- | --- |
| Collecte disponible | `up{job="wordpress"}` | Stat, correspondance `1` = disponible, `0` = échec |
| Articles publiés | `wp_posts_total{job="wordpress",type="post",status="publish"}` | Stat |
| Mises à jour disponibles | `sum by (instance) (wp_updates_available{job="wordpress"})` | Stat |
| Tâches cron en retard | `wp_cron_events{job="wordpress",state="overdue"}` | Stat ou série temporelle |
| Taille de la base | `wp_db_size_bytes{job="wordpress"}` | Série temporelle, unité octets |
| Temps depuis la dernière modification | `time() - wp_last_content_update_timestamp{job="wordpress"}` | Stat, unité durée en secondes |
| Visiteurs du jour¹ | `wp_stats_visitors{job="wordpress",period="today"}` | Stat |
| Pages vues sur 30 jours¹ | `wp_stats_visits{job="wordpress",period="30d"}` | Série temporelle |
| Pages les plus vues¹ | `wp_stats_top_pages{job="wordpress"}` | Tableau, requête instantanée |

¹ Nécessite les tables correspondantes de WP Statistics. `up` est une métrique produite par Prometheus lors de la collecte.

Le dépôt ne contient pas de tableau de bord Grafana à importer ; ces requêtes constituent un point de départ pour créer le vôtre.

## Référence des métriques

**Toutes les métriques du plugin sont de type `gauge`**, y compris celles dont le nom se termine par `_total`. Elles représentent un état ou un total sur une période, et ne doivent pas être traitées comme des compteurs monotones avec `rate()` ou `increase()`.

### WordPress

| Métrique | Labels | Description |
| --- | --- | --- |
| `wp_info` | `wp_version`, `php_version`, `multisite` | Informations de version ; valeur constante à `1`, label `multisite` à `0` ou `1` |
| `wp_posts_total` | `type`, `status` | Contenus par type public et statut |
| `wp_comments_total` | `status` | Commentaires approuvés, en attente, indésirables, supprimés ou liés à un contenu dans la corbeille |
| `wp_users_total` | `role` | Utilisateurs par rôle |
| `wp_media_total` | — | Somme des compteurs retournés par `wp_count_attachments()` |
| `wp_updates_available` | `type` | Mises à jour disponibles : `core`, `plugin`, `theme` |
| `wp_plugin_update_pending` | `plugin`, `current`, `new` | Une série de valeur `1` par plugin à mettre à jour |
| `wp_plugins_total` | `state` | Plugins installés (`installed`) et actifs (`active`) |
| `wp_cron_events` | `state` | Événements planifiés (`scheduled`) et sous-ensemble en retard de plus de cinq minutes (`overdue`) |
| `wp_db_size_bytes` | — | Taille des données et index de toutes les tables de la base `DB_NAME`, en octets |
| `wp_autoload_bytes` | — | Taille cumulée des valeurs des options avec `autoload='yes'`, en octets |
| `wp_transients_total` | — | Nombre de lignes d’options dont le nom commence par `_transient_` |
| `wp_memory_limit_bytes` | — | Valeur de `WP_MEMORY_LIMIT` convertie en octets |
| `wp_last_content_update_timestamp` | — | Horodatage Unix de la dernière modification d’un contenu publié ; `0` en l’absence de valeur |
| `wp_debug_enabled` | — | `1` si `WP_DEBUG` est actif, sinon `0` |
| `wp_ssl_enabled` | — | `1` si la requête est reconnue en HTTPS ou si l’URL d’accueil commence par `https://`, sinon `0` |

### WP Statistics — optionnel

Le plugin détecte la présence de la table `statistics_visitor` avec le préfixe de l’installation. Sans cette table, il expose `wp_statistics_available 0` et ignore les métriques d’audience. Les autres séries dépendent des tables et colonnes disponibles.

| Métrique | Labels | Description |
| --- | --- | --- |
| `wp_statistics_available` | — | `1` si la table des visiteurs est détectée, sinon `0` |
| `wp_stats_visitors` | `period` | Visiteurs comptés par jour, cumulés sur `today`, `7d` ou `30d` |
| `wp_stats_visits` | `period` | Pages vues cumulées sur `today`, `7d` ou `30d` |
| `wp_stats_visitors_by_country` | `country` | Jusqu’à 60 pays sur 30 jours |
| `wp_stats_visitors_by_city` | `city` | Jusqu’à 20 villes sur 30 jours |
| `wp_stats_visitors_by_browser` | `browser` | Jusqu’à 10 navigateurs sur 30 jours |
| `wp_stats_visitors_by_os` | `os` | Jusqu’à 10 systèmes d’exploitation sur 30 jours |
| `wp_stats_visitors_by_referrer` | `referrer` | Jusqu’à 10 sources de trafic sur 30 jours, regroupées par hôte lorsque l’URL est interprétable |
| `wp_stats_top_pages` | `uri` | Jusqu’à 10 pages les plus vues sur 30 jours |
| `wp_stats_online` | — | Nombre de lignes de la table des visiteurs en ligne |

Les périodes suivent le fuseau horaire WordPress et incluent le jour courant : `7d` commence six jours avant aujourd’hui, `30d` vingt-neuf jours avant. Un même visiteur présent plusieurs jours peut être compté plusieurs fois dans ces totaux.

Les sources de trafic sont calculées à partir des 300 référents les plus fréquents avant regroupement par hôte. Les classements peuvent changer d’une collecte à l’autre ; ils ne constituent pas une liste exhaustive.

## Gestion du token

L’accès aux métriques nécessite un token, généré sur 40 caractères et stocké dans l’option WordPress `wpge_token`. La page **Outils → Grafana Exporter** permet de le consulter et de le régénérer.

Pour imposer une valeur depuis la configuration, ajoutez cette constante dans `wp-config.php`, avant le chargement de WordPress :

```php
define('WP_GRAFANA_EXPORTER_TOKEN', 'REMPLACEZ_PAR_UN_SECRET_LONG_ET_ALEATOIRE');
```

Une constante non vide prend priorité sur la valeur en base et désactive la régénération depuis l’interface. Pour changer ce token, modifiez la constante puis la configuration de Prometheus.

Utilisez HTTPS et l’en-tête `Authorization: Bearer …`. Le plugin accepte aussi le paramètre `token` dans la requête, mais un secret placé dans l’URL peut être conservé dans les journaux d’accès. Après une régénération depuis l’interface, l’ancien token cesse de fonctionner : mettez à jour Prometheus.

## Points à connaître

- **Périmètre de la base :** `wp_db_size_bytes` porte sur toute la base configurée, même si elle contient des tables d’autres applications ou sites.
- **Options autoload :** seules les lignes avec la valeur littérale `yes` sont comptées. La mesure ne couvre pas les autres valeurs possibles du champ `autoload`.
- **Transients :** le compteur mesure des lignes en base, y compris celles d’expiration portant le préfixe `_transient_`. Il ne représente pas nécessairement un nombre de transients distincts et n’inclut pas un éventuel cache externe.
- **Multisite :** les données sont principalement celles du site courant. Il n’y a pas d’agrégation explicite du réseau ; le nombre de plugins actifs utilise uniquement l’option `active_plugins`.
- **Audience :** la détection repose sur les tables présentes, pas sur l’état d’activation de WP Statistics. Des tables conservées après désactivation peuvent donc encore être exportées.
- **Limite mémoire :** la métrique indique la configuration WordPress, pas la consommation mémoire réelle du processus.

## Dépannage

| Symptôme | Vérification |
| --- | --- |
| Réponse `401` / « Token invalide » | Vérifiez le token effectif, une éventuelle constante dans `wp-config.php` et la transmission de l’en-tête `Authorization` par le serveur ou le proxy. |
| Réponse `404` | Vérifiez l’activation du plugin, l’URL affichée dans l’administration, le sous-dossier éventuel et l’accessibilité de l’API REST. |
| Cible Prometheus `DOWN` | Testez l’URL depuis l’hôte ou le conteneur Prometheus ; vérifiez le domaine, le port, TLS, le chemin et le token. |
| Délai de collecte dépassé | Vérifiez la charge de WordPress, les requêtes SQL et l’accès aux services de mises à jour ; ajustez l’intervalle et le délai de collecte si nécessaire. |
| Absence de métriques d’audience | Consultez `wp_statistics_available` et vérifiez la présence des tables et colonnes attendues de WP Statistics. |
| Données d’audience inchangées | Vérifiez que WP Statistics continue d’alimenter ses tables. |
| Valeurs identiques malgré des changements sur le site | Vérifiez qu’aucun cache HTTP ou CDN ne met en cache l’endpoint de métriques. |

## Structure du projet

```text
WP_Exporter/
├── readme.md
└── src/
    └── wp-grafana-exporter.php
```

Le fichier PHP regroupe l’endpoint REST, l’authentification, la page d’administration et la collecte des métriques WordPress et WP Statistics.
