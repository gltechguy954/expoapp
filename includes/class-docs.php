<?php
if (!defined('ABSPATH')) exit;

class UC_Expo_QR_Docs {
    private static $instance = null;
    public static function instance(){ if (!self::$instance) self::$instance = new self(); return self::$instance; }

    public function render() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap uc-app"><div class="uc-app-header"><h1>'.esc_html__('UC Expo — User Guide', 'uc-expo').'</h1></div>';
        echo '<div class="uc-card uc-docs">';
        echo '<h2>Overview</h2>';
        echo '<p>This plugin provides signed QR codes for <strong>Exhibitors</strong>, <strong>Sessions</strong>, <strong>Panels</strong>, and <strong>Speakers</strong>. A check-in is recorded only when a user arrives via a valid QR link.</p>';
        echo '<h2>Quick Start</h2>';
        echo '<ol>';
        echo '<li>Go to <em>Expo Check-ins → Settings</em> and set the <strong>Current Event ID</strong> (e.g., <code>2025-ATL</code>).</li>';
        echo '<li>Use <em>Expo Check-ins → QR Printer</em> to select a type and print QR cards.</li>';
        echo '<li>Place the <em>Check-in Badge</em> block (or shortcode <code>[uc_expo_checkin_badge]</code>) on your content template to show a success banner after scans.</li>';
        echo '</ol>';
        echo '<h2>Leaderboard & Gamification</h2>';
        echo '<p>Configure points, name format, role exclusions, and opt-out behavior at <em>Expo Check-ins → Leaderboard</em>.</p>';
        echo '<h3>Shortcodes</h3>';
        echo '<pre>[uc_expo_leaderboard event="current|2025-ATL" scope="event|all" top="3" per_page="50" search="1"]</pre>';
        echo '<p>Public leaderboard shows top 3 cards and a paginated table of participants with total points. It only displays names and totals (no individual check-ins).</p>';
        echo '<pre>[uc_expo_my_checkins]</pre>';
        echo '<p>Logged-in user view with filters (type, date range) and pagination. Includes a toggle to opt out of the public leaderboard.</p>';
        echo '<h2>How Check-ins Work</h2>';
        echo '<ul>';
        echo '<li>Each QR points to a signed URL like <code>/qr/{type}/{id}/{event}/{sig}</code>.</li>';
        echo '<li>If the visitor is logged in, a check-in is recorded and they are redirected to the item.</li>';
        echo '<li>If not logged in, a short-lived cookie is set and they are redirected to the login screen; the check-in is auto-recorded after login.</li>';
        echo '<li>Direct visits to an item URL (without the QR route) do not record a check-in.</li>';
        echo '</ul>';
        echo '<h2>Security Tips</h2>';
        echo '<ul>';
        echo '<li>Use <strong>Rotate Secret</strong> in Settings to invalidate all existing QR codes if needed.</li>';
        echo '<li>The system prevents duplicates per user + item + event.</li>';
        echo '<li>Sharing a QR link won\'t inflate counts—each user is counted once per item per event.</li>';
        echo '</ul>';
        echo '</div></div>';
    }
}
