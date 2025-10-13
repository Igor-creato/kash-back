<?php

declare(strict_types=1);

namespace KashBack;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Affiliate Tracker class
 */
class AffiliateTracker
{
    /**
     * Initialize the tracker.
     */
    public function init(): void
    {
        \add_action('template_redirect', [$this, 'track_external_link_click']);
    }

    /**
     * Track external link clicks.
     *
     * This method checks if the current request is for an external product URL
     * and logs the click in the database.
     */
    public function track_external_link_click(): void
    {
        // We are looking for a specific query var that we will add to the URL.
        if (! isset($_GET['kash_back_redirect']) || ! isset($_GET['product_id'])) {
            return;
        }

        $product_id = \absint($_GET['product_id']);
        $product    = \wc_get_product($product_id);

        if (! $product || ! $product->is_type('external')) {
            return;
        }

        $external_url = $product->get_product_url();
        $user_id      = \get_current_user_id();

        if (empty($external_url)) {
            return;
        }

        $this->log_click($user_id, $external_url, $product_id);

        if ($user_id > 0) {
            $external_url = \add_query_arg('user_id', (string) $user_id, $external_url);
        }

        \wp_redirect($external_url);
        exit;
    }

    /**
     * Log the click data to the database.
     *
     * @param int    $user_id The user ID.
     * @param string $external_url The external URL.
     * @param int    $product_id The product ID.
     */
    private function log_click(int $user_id, string $external_url, int $product_id): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'affiliate_tracking';

        $data = [
            'user_id'      => $user_id > 0 ? $user_id : null,
            'external_url' => $external_url,
            'internal_url' => isset($_GET['internal_url']) ? \esc_url_raw(rawurldecode($_GET['internal_url'])) : '',
            'product_id'   => $product_id,
            'referrer_url' => $_SERVER['HTTP_REFERER'] ?? '',
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address'   => $this->get_ip_address(),
            'partner_id'   => $this->get_partner_id_from_url($external_url),
        ];

        $wpdb->insert($table_name, $data, [
            '%d', // user_id
            '%s', // external_url
            '%s', // internal_url
            '%d', // product_id
            '%s', // referrer_url
            '%s', // user_agent
            '%s', // ip_address
            '%s', // partner_id
        ]);
    }

    /**
     * Get the user's IP address.
     *
     * @return string
     */
    private function get_ip_address(): string
    {
        if (! empty($_SERVER['HTTP_CLIENT_IP'])) {
            return \sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        }
        if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return \sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']);
        }
        if (! empty($_SERVER['REMOTE_ADDR'])) {
            return \sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }
        return '';
    }

    /**
     * Extracts a partner ID from the URL if it exists.
     *
     * @param string $url The URL to parse.
     * @return string|null The partner ID or null.
     */
    private function get_partner_id_from_url(string $url): ?string
    {
        $query = \wp_parse_url($url, PHP_URL_QUERY);
        if (! $query) {
            return null;
        }

        parse_str($query, $params);

        // Common affiliate query parameters. This can be extended.
        $affiliate_keys = ['aff_id', 'affid', 'ref', 'referral', 'partner_id', 'user_id'];

        foreach ($affiliate_keys as $key) {
            if (isset($params[$key])) {
                return \sanitize_text_field($params[$key]);
            }
        }

        return null;
    }
}
