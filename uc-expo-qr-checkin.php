<?php
/**
 * Plugin Name: UC Expo — QR Check-ins
 * Description: The ultimate app for running an expo. Allow attendees to check in to exhibitors booths, sessions, display leaderboarads, gamification, and more 
 * Version:     1.6.0
 * Author:      UC Dev Team
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('UC_EXPO_QR_PLUGIN_FILE', __FILE__);
define('UC_EXPO_QR_DIR', plugin_dir_path(__FILE__));
define('UC_EXPO_QR_URL', plugin_dir_url(__FILE__));

require_once UC_EXPO_QR_DIR . 'includes/class-qr-checkins.php';
require_once UC_EXPO_QR_DIR . 'includes/class-cpts.php';
require_once UC_EXPO_QR_DIR . 'includes/class-admin.php';
require_once UC_EXPO_QR_DIR . 'includes/class-docs.php';
require_once UC_EXPO_QR_DIR . 'includes/class-leaderboard.php';
require_once UC_EXPO_QR_DIR . 'includes/class-blocks.php';
require_once UC_EXPO_QR_DIR . 'includes/class-dashboard-widget.php';

add_action('plugins_loaded', function(){
    UC_Expo_QR_Checkins::instance();
    UC_Expo_QR_CPTs::instance();
    UC_Expo_QR_Leaderboard::instance();
    if (is_admin()) {
        UC_Expo_QR_Admin::instance();
        UC_Expo_QR_Docs::instance();
        UC_Expo_QR_Dashboard_Widget::instance();
    }
    UC_Expo_QR_Blocks::instance();
});