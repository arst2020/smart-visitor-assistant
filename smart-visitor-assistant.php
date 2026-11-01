<?php
/**
 * Plugin Name:       Smart Visitor Assistant – دستیار هوشمند بازدیدکننده
 * Description:       دستیار هوشمند چت برای بازدیدکنندگان سایت. بعد از نصب، سایت را خودش بررسی و تحلیل می‌کند (برگه‌ها، نوشته‌ها، محصولات ووکامرس، منوها و اطلاعات تماس)، نوع سایت را تشخیص می‌دهد و بازدیدکننده را به خدمات، محصولات و بخش‌هایی مثل نوبت‌دهی راهنمایی می‌کند. با و بدون هوش مصنوعی (Claude یا سرویس‌های سازگار با OpenAI) کار می‌کند.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            nfpsmart
 * License:           GPLv2 or later
 * Text Domain:       smart-visitor-assistant
 */

defined( 'ABSPATH' ) || exit;

define( 'SVA_VERSION', '1.1.0' );
define( 'SVA_DB_VERSION', '1' );
define( 'SVA_FILE', __FILE__ );
define( 'SVA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SVA_URL', plugin_dir_url( __FILE__ ) );

require_once SVA_DIR . 'includes/class-sva-plugin.php';
require_once SVA_DIR . 'includes/class-sva-i18n.php';
require_once SVA_DIR . 'includes/class-sva-text.php';
require_once SVA_DIR . 'includes/class-sva-db.php';
require_once SVA_DIR . 'includes/class-sva-analyzer.php';
require_once SVA_DIR . 'includes/class-sva-search.php';
require_once SVA_DIR . 'includes/class-sva-ai.php';
require_once SVA_DIR . 'includes/class-sva-chat.php';
require_once SVA_DIR . 'includes/class-sva-frontend.php';
require_once SVA_DIR . 'includes/class-sva-admin.php';

register_activation_hook( __FILE__, array( 'SVA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SVA_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'SVA_Plugin', 'init' ) );
