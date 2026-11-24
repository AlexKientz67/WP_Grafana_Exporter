# WP Grafana Exporter

![Version](https://img.shields.io/badge/version-1.1.0-2563eb)
![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

**Monitor your WordPress site with Prometheus and Grafana.**

A simple WordPress plugin that exposes site metrics through a token-protected endpoint. Prometheus collects the data, and Grafana displays it in your dashboards.

## Features

- Posts, pages, comments, media, and users by role.
- Available updates for WordPress, plugins, and themes.
- Scheduled and overdue cron events.
- Database size, autoloaded options, and transients.
- WordPress and PHP versions, memory limit, debug mode, and HTTPS status.
- Optional **WP Statistics** integration for visitors, page views, traffic sources, and popular pages.

## Installation

Requires WordPress with **PHP 7.4+**, plus Prometheus and Grafana for collection and visualization.

1. Download the plugin ZIP attached to the latest release.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Select the ZIP file, click **Install Now**, then **Activate Plugin**.
4. Open **Tools → Grafana Exporter** to find your endpoint URL and token.

The token is generated automatically on activation.

## Quick test

Replace the domain and token with your own values:

```bash
curl --fail --show-error \
  --header 'Authorization: Bearer YOUR_TOKEN' \
  'https://example.com/wp-json/prometheus/v1/metrics'
```

The endpoint returns metrics in Prometheus text format.

## Prometheus setup

Add this job to your `prometheus.yml`. If `scrape_configs` already exists, append the job to its list.

```yaml
scrape_configs:
  - job_name: wordpress
    scheme: https
    metrics_path: /wp-json/prometheus/v1/metrics
    scrape_interval: 5m
    scrape_timeout: 60s
    authorization:
      type: Bearer
      credentials: 'YOUR_TOKEN'
    static_configs:
      - targets: ['example.com']
```

Replace the domain and token, then reload Prometheus. Your target should appear as **UP**.

If WordPress is installed in a subdirectory, adjust `metrics_path` accordingly. Use the endpoint shown in the plugin settings as your reference.

> Each scrape queries WordPress data and checks for updates, which can involve external requests. Start with the five-minute interval above and adjust it to your site's load.

## Grafana examples

Add Prometheus as a data source in Grafana, then use these queries to create panels:

| Panel | PromQL query |
| --- | --- |
| Collection status | `up{job="wordpress"}` |
| Published posts | `wp_posts_total{job="wordpress",type="post",status="publish"}` |
| Available updates | `sum by (instance) (wp_updates_available{job="wordpress"})` |
| Overdue cron events | `wp_cron_events{job="wordpress",state="overdue"}` |
| Database size | `wp_db_size_bytes{job="wordpress"}` |
| Today's visitors | `wp_stats_visitors{job="wordpress",period="today"}` |
| Page views over 30 days | `wp_stats_visits{job="wordpress",period="30d"}` |

Visitor and page-view metrics require the corresponding WP Statistics tables. The plugin detects these tables automatically; `wp_statistics_available` reports whether the visitor table exists.

All plugin metrics are **gauges**, including those ending in `_total`. Use their values directly rather than applying `rate()` or `increase()`. Visitor totals across multiple days can count the same visitor on each day.

## Token management

View or regenerate your token under **Tools → Grafana Exporter**. After regenerating it, update your Prometheus configuration.

You can also set a fixed token in `wp-config.php`, before WordPress loads:

```php
define('WP_GRAFANA_EXPORTER_TOKEN', 'YOUR_LONG_RANDOM_SECRET');
```

This overrides the stored token and disables regeneration from the admin page. Use HTTPS and keep your token private.

## Troubleshooting

| Problem | What to check |
| --- | --- |
| `401` response | Check the token and make sure your server forwards the `Authorization` header. |
| `404` response | Check that the plugin is active and use the endpoint URL shown in its settings. |
| Prometheus target is `DOWN` | Test the endpoint from the Prometheus host and check connectivity, TLS, and authentication. |
| Missing traffic metrics | Check `wp_statistics_available` and confirm that WP Statistics has created and populated its tables. |

## License

[MIT](LICENSE)
