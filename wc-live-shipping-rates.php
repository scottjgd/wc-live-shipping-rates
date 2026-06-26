<?php
/**
 * Plugin Name: WC Live Shipping Rates
 * Plugin URI:  https://github.com/your-repo/wc-live-shipping-rates
 * Description: Fetch live shipping rates from Canada Post, UPS, and Purolator at WooCommerce checkout.
 * Version:     1.1.2
 * Author:      Your Name
 * License:     GPL-2.0+
 * Text Domain: wc-live-shipping-rates
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WCLSR_VERSION',     '1.1.2' );
define( 'WCLSR_PATH',        plugin_dir_path( __FILE__ ) );
define( 'WCLSR_URL',         plugin_dir_url( __FILE__ ) );

/**
 * Your GitHub repo in "username/repository" format.
 * The updater will pull releases from here automatically.
 * Change this to match your own repo before uploading.
 */
define( 'WCLSR_GITHUB_REPO', 'scottjgd/wc-live-shipping-rates' );

/**
 * Check WooCommerce is active before doing anything.
 */
function wclsr_check_woocommerce() {
        if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', function () {
                        echo '<div class="error"><p><strong>WC Live Shipping Rates</strong> requires WooCommerce to be installed and active.</p></div>';
                } );
                return false;
        }
        return true;
}

/**
 * Bootstrap: load shipping method classes and register them with WooCommerce.
 */
add_action( 'woocommerce_shipping_init', function () {
        if ( ! wclsr_check_woocommerce() ) return;

        require_once WCLSR_PATH . 'includes/class-wclsr-base.php';
        require_once WCLSR_PATH . 'includes/class-wclsr-canada-post.php';
        require_once WCLSR_PATH . 'includes/class-wclsr-ups.php';
        require_once WCLSR_PATH . 'includes/class-wclsr-purolator.php';
} );

add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
        if ( ! wclsr_check_woocommerce() ) return $methods;

        $methods['wclsr_canada_post'] = 'WCLSR_Canada_Post';
        $methods['wclsr_ups']         = 'WCLSR_UPS';
        $methods['wclsr_purolator']   = 'WCLSR_Purolator';

        return $methods;
} );

/**
 * Boot the GitHub auto-updater.
 */
add_action( 'init', function () {
        require_once WCLSR_PATH . 'includes/class-wclsr-updater.php';
        new WCLSR_Updater( __FILE__, WCLSR_GITHUB_REPO, WCLSR_VERSION );
} );

/**
 * Enqueue checkout accordion assets on the frontend.
 */
add_action( 'wp_enqueue_scripts', function () {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

        wp_enqueue_style(
                'wclsr-checkout',
                WCLSR_URL . 'assets/checkout.css',
                [],
                WCLSR_VERSION
        );

        wp_enqueue_script(
                'wclsr-checkout',
                WCLSR_URL . 'assets/checkout.js',
                [ 'jquery' ],
                WCLSR_VERSION,
                true
        );
} );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
} );
