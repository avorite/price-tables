<?php
/**
 * Plugin Name: Price Tables
 * Plugin URI:  https://webvector.space/
 * Description: Shows editable print price tables on WooCommerce product pages by selected categories.
 * Version:     1.0.4
 * Author:      WebVector
 * Author URI:  https://webvector.space/
 * Text Domain: webvector-price-tables
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WVPT_VERSION', '1.0.4' );
define( 'WVPT_PLUGIN_FILE', __FILE__ );
define( 'WVPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WVPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WVPT_TEXT_DOMAIN', 'webvector-price-tables' );

require_once WVPT_PLUGIN_DIR . 'includes/class-wvpt-price-tables.php';

function wvpt_load_textdomain() {
	load_plugin_textdomain( WVPT_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'wvpt_load_textdomain' );
add_action( 'init', array( 'WVPT_Price_Tables', 'register_post_type' ) );
add_action( 'init', array( 'WVPT_Price_Tables', 'maybe_seed_tables' ), 20 );
add_action( 'add_meta_boxes', array( 'WVPT_Price_Tables', 'add_meta_boxes' ) );
add_action( 'save_post_' . WVPT_Price_Tables::POST_TYPE, array( 'WVPT_Price_Tables', 'save_post' ) );
add_action( 'admin_enqueue_scripts', array( 'WVPT_Price_Tables', 'enqueue_admin_assets' ) );
add_action( 'wp_enqueue_scripts', array( 'WVPT_Price_Tables', 'enqueue_frontend_assets' ) );
add_action( 'woocommerce_after_single_product_summary', array( 'WVPT_Price_Tables', 'render_product_tables' ), 11 );
add_filter( 'manage_' . WVPT_Price_Tables::POST_TYPE . '_posts_columns', array( 'WVPT_Price_Tables', 'admin_columns' ) );
add_action( 'manage_' . WVPT_Price_Tables::POST_TYPE . '_posts_custom_column', array( 'WVPT_Price_Tables', 'render_admin_column' ), 10, 2 );
