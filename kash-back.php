<?php

/**
 * Plugin Name:       Kash Back
 * Plugin URI:        https://example.com/plugins/the-basics/
 * Description:       Adds the user ID to external/affiliate product links in WooCommerce.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      8.0
 * Author:            Your Name
 * Author URI:        https://author.example.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kash-back
 * Domain Path:       /languages
 */

declare(strict_types=1);

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}


define('KASH_BACK_VERSION', '1.0.0');

require_once __DIR__ . '/includes/class-installer.php';
require_once __DIR__ . '/includes/class-affiliate-tracker.php';

/**
 * Main plugin class.
 */
final class KashBack
{
    /**
     * The single instance of the class.
     *
     * @var KashBack|null
     */
    private static ?KashBack $instance = null;

    /**
     * Ensures only one instance of the class is loaded.
     *
     * @return KashBack
     */
    public static function instance(): KashBack
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    /**
     * Initialize the plugin.
     */
    private function init(): void
    {
        add_filter('woocommerce_product_add_to_cart_url', [$this, 'modify_external_product_url'], 10, 2);
        register_activation_hook(__FILE__, [$this, 'activate']);

        $tracker = new \KashBack\AffiliateTracker();
        $tracker->init();
    }

    /**
     * Plugin activation callback.
     */
    public function activate(): void
    {
        $installer = new \KashBack\Installer();
        $installer->run();
    }

    /**
     * Modifies the external product URL to include tracking parameters.
     *
     * Instead of adding the user_id directly, we create a redirect URL
     * on our site that will log the click and then redirect to the external URL.
     *
     * @param  string      $url     The product URL.
     * @param  \WC_Product $product The product object.
     * @return string The modified product URL.
     */
    public function modify_external_product_url(string $url, \WC_Product $product): string
    {
        if (! $product->is_type('external')) {
            return $url;
        }

        // We replace the direct external URL with a local URL that handles the tracking.
        return add_query_arg(
            [
                'kash_back_redirect' => '1',
                'product_id'         => $product->get_id(),
                'internal_url'       => rawurlencode($product->get_permalink()),
            ],
            home_url('/')
        );
    }

    /**
     * Cloning is forbidden.
     */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, esc_html__('Cheatin&#8217; huh?', 'kash-back'), '1.0.0');
    }

    /**
     * Unserializing instances of this class is forbidden.
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, esc_html__('Cheatin&#8217; huh?', 'kash-back'), '1.0.0');
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct() {}
}

/**
 *  Kicks off the plugin.
 *
 * @return KashBack
 */
function kash_back(): KashBack
{
    return KashBack::instance();
}

// Get the plugin running.
kash_back();
