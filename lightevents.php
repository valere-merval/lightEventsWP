<?php
/**
 * Plugin Name: LightEvents for WordPress
 * Plugin URI: https://lightevents.app
 * Description: Affiche les événements LightEvents, vend des billets et synchronise WordPress avec LightEvents API.
 * Version: 0.1.0
 * Author: LightEvents
 * Text Domain: lightevents
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LIGHTEVENTS_WP_VERSION', '0.1.0');
define('LIGHTEVENTS_WP_FILE', __FILE__);
define('LIGHTEVENTS_WP_DIR', plugin_dir_path(__FILE__));
define('LIGHTEVENTS_WP_URL', plugin_dir_url(__FILE__));

require_once LIGHTEVENTS_WP_DIR . 'includes/class-lightevents-api.php';
require_once LIGHTEVENTS_WP_DIR . 'includes/class-lightevents-renderer.php';
require_once LIGHTEVENTS_WP_DIR . 'includes/class-lightevents-plugin.php';

add_action('plugins_loaded', static function () {
    LightEvents_WP_Plugin::instance()->boot();
});
