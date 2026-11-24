<?php
/**
 * Plugin Name: WP Grafana Exporter
 * Description: Expose les métriques WordPress (contenu, utilisateurs, commentaires, mises à jour, santé, cron, BDD) au format Prometheus pour Grafana.
 * Version: 1.1.0
 * Requires PHP: 7.4
 *
 * Token : généré à l'activation, visible et régénérable dans Outils > Grafana Exporter.
 * (Optionnel : define('WP_GRAFANA_EXPORTER_TOKEN', '...') dans wp-config.php l'impose.)
 * Scrape : GET /wp-json/prometheus/v1/metrics   (Authorization: Bearer <token>)
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('prometheus/v1', '/metrics', [
        'methods'             => 'GET',
        'callback'            => 'wpge_metrics',
        'permission_callback' => 'wpge_authorized',
    ]);
});

function wpge_generate_token() {
    return wp_generate_password(40, false, false);
}

function wpge_get_token() {
    if (defined('WP_GRAFANA_EXPORTER_TOKEN') && WP_GRAFANA_EXPORTER_TOKEN !== '') {
        return WP_GRAFANA_EXPORTER_TOKEN;
    }
    $t = get_option('wpge_token');
    if (!$t) {
        $t = wpge_generate_token();
        update_option('wpge_token', $t, false);
    }
    return $t;
}

register_activation_hook(__FILE__, 'wpge_get_token');

add_action('admin_menu', function () {
    add_management_page('Grafana Exporter', 'Grafana Exporter', 'manage_options', 'wpge', 'wpge_admin_page');
});

add_action('admin_post_wpge_reset', function () {
    if (!current_user_can('manage_options')) wp_die('Accès refusé', 403);
    check_admin_referer('wpge_reset');
    update_option('wpge_token', wpge_generate_token(), false);
    wp_safe_redirect(add_query_arg(['page' => 'wpge', 'reset' => 1], admin_url('tools.php')));
    exit;
});

function wpge_admin_page() {
    if (!current_user_can('manage_options')) return;
    $token    = wpge_get_token();
    $locked   = defined('WP_GRAFANA_EXPORTER_TOKEN') && WP_GRAFANA_EXPORTER_TOKEN !== '';
    $endpoint = rest_url('prometheus/v1/metrics');
    $curl     = 'curl -H "Authorization: Bearer ' . $token . '" ' . $endpoint;
    $yaml     = "scrape_configs:\n  - job_name: wordpress\n    metrics_path: /wp-json/prometheus/v1/metrics\n    scheme: " .
                (wp_parse_url($endpoint, PHP_URL_SCHEME) ?: 'https') . "\n    scrape_interval: 5m\n    scrape_timeout: 60s\n    authorization:\n      type: Bearer\n      credentials: " .
                $token . "\n    static_configs:\n      - targets: ['" . wp_parse_url($endpoint, PHP_URL_HOST) . "']";
    ?>
    <div class="wrap">
        <h1>Grafana Exporter</h1>
        <?php if (isset($_GET['reset'])): ?>
            <div class="notice notice-success is-dismissible"><p>Token régénéré. Mets à jour ta config Prometheus, l'ancien token ne fonctionne plus.</p></div>
        <?php endif; ?>

        <h2>Endpoint</h2>
        <p><input type="text" class="large-text code" readonly value="<?php echo esc_attr($endpoint); ?>" onclick="this.select()"></p>

        <h2>Token</h2>
        <p><input type="text" class="large-text code" readonly value="<?php echo esc_attr($token); ?>" onclick="this.select()"></p>
        <?php if ($locked): ?>
            <p><em>Le token est imposé par <code>WP_GRAFANA_EXPORTER_TOKEN</code> dans wp-config.php. Retire cette ligne pour pouvoir le régénérer ici.</em></p>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('Régénérer le token ? Prometheus ne pourra plus se connecter tant que tu ne l\'auras pas mis à jour.');">
                <input type="hidden" name="action" value="wpge_reset">
                <?php wp_nonce_field('wpge_reset'); ?>
                <?php submit_button('Régénérer le token', 'secondary', 'submit', false); ?>
            </form>
        <?php endif; ?>

        <h2>Tester</h2>
        <p><textarea class="large-text code" rows="2" readonly onclick="this.select()"><?php echo esc_textarea($curl); ?></textarea></p>

        <h2>Config Prometheus</h2>
        <p><textarea class="large-text code" rows="12" readonly onclick="this.select()"><?php echo esc_textarea($yaml); ?></textarea></p>
    </div>
    <?php
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('tools.php?page=wpge')) . '">Token</a>');
    return $links;
});

function wpge_authorized(WP_REST_Request $req) {
    $token = wpge_get_token();
    $auth = $req->get_header('authorization');
    $given = $auth && stripos($auth, 'Bearer ') === 0 ? substr($auth, 7) : (string) $req->get_param('token');
    return hash_equals($token, $given)
        ? true
        : new WP_Error('forbidden', 'Token invalide', ['status' => 401]);
}

function wpge_esc($v) {
    return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v);
}

function wpge_wp_statistics(callable $add) {
    global $wpdb;
    $p = $wpdb->prefix . 'statistics_';

    $has = function ($table) use ($wpdb, $p) {
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($p . $table))) === $p . $table;
    };
    $cols = function ($table) use ($wpdb, $p) {
        return $wpdb->get_col("SHOW COLUMNS FROM `{$p}{$table}`", 0);
    };

    $available = $has('visitor');
    $add('wp_statistics_available', 'WP Statistics détecté (tables de visiteurs présentes)', 'gauge', [[[], $available ? 1 : 0]]);
    if (!$available) return;

    $vc = $cols('visitor');
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $since = [
        'today' => $now->format('Y-m-d'),
        '7d'    => (clone $now)->modify('-6 days')->format('Y-m-d'),
        '30d'   => (clone $now)->modify('-29 days')->format('Y-m-d'),
    ];
    $d30 = $since['30d'];

    $s = [];
    foreach ($since as $period => $date) {
        $s[] = [['period' => $period], (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}visitor WHERE last_counter >= %s", $date
        ))];
    }
    $add('wp_stats_visitors', 'Visiteurs uniques par jour, cumulés sur la période', 'gauge', $s);

    if ($has('visit')) {
        $s = [];
        foreach ($since as $period => $date) {
            $s[] = [['period' => $period], (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(visit),0) FROM {$p}visit WHERE last_counter >= %s", $date
            ))];
        }
        $add('wp_stats_visits', 'Pages vues cumulées sur la période', 'gauge', $s);
    }

    // Pays (carte)
    if (in_array('location', $vc, true)) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT location AS k, COUNT(*) AS n FROM {$p}visitor
             WHERE last_counter >= %s AND location <> '' AND location <> '000'
             GROUP BY location ORDER BY n DESC LIMIT 60", $d30
        ));
        $s = [];
        foreach ($rows as $r) $s[] = [['country' => strtoupper($r->k)], (int) $r->n];
        $add('wp_stats_visitors_by_country', 'Visiteurs sur 30 jours par pays (ISO 3166-1 alpha-2)', 'gauge', $s);
    }

    $groups = [
        'city'     => ['wp_stats_visitors_by_city',     'city',     20],
        'agent'    => ['wp_stats_visitors_by_browser',  'browser',  10],
        'platform' => ['wp_stats_visitors_by_os',       'os',       10],
    ];
    foreach ($groups as $col => [$metric, $label, $limit]) {
        if (!in_array($col, $vc, true)) continue;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$col} AS k, COUNT(*) AS n FROM {$p}visitor
             WHERE last_counter >= %s AND {$col} <> '' AND {$col} IS NOT NULL
             GROUP BY {$col} ORDER BY n DESC LIMIT %d", $d30, $limit
        ));
        $s = [];
        foreach ($rows as $r) $s[] = [[$label => $r->k], (int) $r->n];
        $add($metric, "Visiteurs sur 30 jours par $label", 'gauge', $s);
    }

    if (in_array('referred', $vc, true)) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT referred AS k, COUNT(*) AS n FROM {$p}visitor
             WHERE last_counter >= %s AND referred <> '' GROUP BY referred ORDER BY n DESC LIMIT 300", $d30
        ));
        $hosts = [];
        foreach ($rows as $r) {
            $h = wp_parse_url($r->k, PHP_URL_HOST) ?: $r->k;
            $h = preg_replace('/^www\./', '', strtolower($h));
            $hosts[$h] = ($hosts[$h] ?? 0) + (int) $r->n;
        }
        arsort($hosts);
        $s = [];
        foreach (array_slice($hosts, 0, 10, true) as $h => $n) $s[] = [['referrer' => $h], $n];
        $add('wp_stats_visitors_by_referrer', 'Visiteurs sur 30 jours par source', 'gauge', $s);
    }

    if ($has('pages')) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT uri AS k, SUM(count) AS n FROM {$p}pages WHERE date >= %s
             GROUP BY uri ORDER BY n DESC LIMIT 10", $d30
        ));
        $s = [];
        foreach ($rows as $r) $s[] = [['uri' => $r->k], (int) $r->n];
        $add('wp_stats_top_pages', 'Pages les plus vues sur 30 jours', 'gauge', $s);
    }

    if ($has('useronline')) {
        $add('wp_stats_online', 'Visiteurs en ligne', 'gauge', [[[], (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}useronline")]]);
    }
}

function wpge_metrics() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/update.php';

    $out = [];
    $add = function ($name, $help, $type, array $samples) use (&$out) {
        $out[] = "# HELP $name $help";
        $out[] = "# TYPE $name $type";
        foreach ($samples as [$labels, $value]) {
            $l = '';
            if ($labels) {
                $parts = [];
                foreach ($labels as $k => $v) $parts[] = $k . '="' . wpge_esc($v) . '"';
                $l = '{' . implode(',', $parts) . '}';
            }
            $out[] = $name . $l . ' ' . $value;
        }
    };

    global $wp_version;
    $add('wp_info', 'Version de WordPress et PHP', 'gauge', [[
        ['wp_version' => $wp_version, 'php_version' => PHP_VERSION, 'multisite' => is_multisite() ? '1' : '0'], 1,
    ]]);

    $s = [];
    foreach (get_post_types(['public' => true]) as $type) {
        foreach ((array) wp_count_posts($type) as $status => $n) {
            $s[] = [['type' => $type, 'status' => $status], (int) $n];
        }
    }
    $add('wp_posts_total', 'Nombre de contenus par type et statut', 'gauge', $s);

    $c = wp_count_comments();
    $s = [];
    foreach (['approved', 'moderated', 'spam', 'trash', 'post-trashed'] as $k) {
        $s[] = [['status' => $k], (int) $c->$k];
    }
    $add('wp_comments_total', 'Commentaires par statut', 'gauge', $s);

    $u = count_users();
    $s = [];
    foreach ($u['avail_roles'] as $role => $n) $s[] = [['role' => $role], (int) $n];
    $add('wp_users_total', 'Utilisateurs par rôle', 'gauge', $s);

    $media = wp_count_attachments();
    $n = 0; foreach ((array) $media as $v) $n += (int) $v;
    $add('wp_media_total', 'Fichiers dans la médiathèque', 'gauge', [[[], $n]]);

    // Mises à jour : core, plugins, thèmes
    wp_version_check([], true);
    wp_update_plugins();
    wp_update_themes();

    $core = get_core_updates();
    $core_n = 0;
    if (is_array($core)) foreach ($core as $c) if (isset($c->response) && $c->response === 'upgrade') $core_n++;
    $plugins = get_plugin_updates();
    $themes  = get_theme_updates();

    $add('wp_updates_available', 'Mises à jour disponibles par type', 'gauge', [
        [['type' => 'core'],   $core_n],
        [['type' => 'plugin'], count($plugins)],
        [['type' => 'theme'],  count($themes)],
    ]);

    $s = [];
    foreach ($plugins as $file => $p) {
        $s[] = [['plugin' => $file, 'current' => $p->Version, 'new' => $p->update->new_version ?? ''], 1];
    }
    $add('wp_plugin_update_pending', 'Détail des plugins à mettre à jour', 'gauge', $s);

    // Plugins installés / actifs
    $all = get_plugins();
    $active = count(get_option('active_plugins', []));
    $add('wp_plugins_total', 'Plugins installés / actifs', 'gauge', [
        [['state' => 'installed'], count($all)],
        [['state' => 'active'],    $active],
    ]);

    $crons = _get_cron_array() ?: [];
    $jobs = 0; $overdue = 0; $now = time();
    foreach ($crons as $ts => $hooks) {
        foreach ($hooks as $events) { $jobs += count($events); if ($ts < $now - 300) $overdue += count($events); }
    }
    $add('wp_cron_events', 'Tâches cron planifiées / en retard (>5 min)', 'gauge', [
        [['state' => 'scheduled'], $jobs],
        [['state' => 'overdue'],   $overdue],
    ]);

    // Base de données
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT COALESCE(SUM(data_length+index_length),0) AS size FROM information_schema.TABLES WHERE table_schema = %s",
        DB_NAME
    ));
    $add('wp_db_size_bytes', 'Taille de la base de données', 'gauge', [[[], (int) ($row->size ?? 0)]]);
    $autoload = $wpdb->get_var("SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE autoload='yes'");
    $add('wp_autoload_bytes', 'Taille des options autoload (impacte les perfs)', 'gauge', [[[], (int) $autoload]]);
    $add('wp_transients_total', 'Transients en base', 'gauge', [[[], (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'"
    )]]);

    $add('wp_memory_limit_bytes', 'Limite mémoire WordPress', 'gauge', [[[], wp_convert_hr_to_bytes(WP_MEMORY_LIMIT)]]);
    $last = $wpdb->get_var("SELECT UNIX_TIMESTAMP(MAX(post_modified_gmt)) FROM {$wpdb->posts} WHERE post_status='publish'");
    $add('wp_last_content_update_timestamp', 'Dernière modification de contenu publié', 'gauge', [[[], (int) $last]]);

    $add('wp_debug_enabled', 'WP_DEBUG actif', 'gauge', [[[], (defined('WP_DEBUG') && WP_DEBUG) ? 1 : 0]]);
    $add('wp_ssl_enabled', 'Site servi en HTTPS', 'gauge', [[[], is_ssl() || strpos(home_url(), 'https://') === 0 ? 1 : 0]]);

    wpge_wp_statistics($add);

    $resp = new WP_REST_Response(implode("\n", $out) . "\n");
    $resp->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    add_filter('rest_pre_serve_request', function ($served, $result) {
        if ($result->get_headers()['Content-Type'] ?? false) {
            echo $result->get_data();
            return true;
        }
        return $served;
    }, 10, 2);
    return $resp;
}
