<?php
if (!defined('ABSPATH')) exit;

class UC_Expo_QR_Dashboard_Widget {
    private static $instance = null;
    public static function instance() { if (!self::$instance) self::$instance = new self(); return self::$instance; }

    private function __construct() { add_action('wp_dashboard_setup', [$this, 'add_widget']); }

    public function add_widget() {
        if (!current_user_can('manage_options')) return;
        wp_add_dashboard_widget('uc_expo_qr_widget', __('Expo Check-ins (today)', 'uc-expo'), [$this, 'render']);
    }

    public function render() {
        global $wpdb;
        $table = UC_Expo_QR_Checkins::instance()->table_name();
        $today = current_time('Y-m-d');
        $event = UC_Expo_QR_Checkins::instance()->get_current_event_id();

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE DATE(created_at)=%s AND event_id=%s",
            $today, $event
        ));

        echo '<p><strong>'.esc_html__('Total check-ins today', 'uc-expo').': '.$total.'</strong></p>';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT entity_type, entity_id, COUNT(*) as c FROM $table WHERE DATE(created_at)=%s AND event_id=%s GROUP BY entity_type, entity_id ORDER BY c DESC LIMIT 5",
            $today, $event
        ), ARRAY_A);

        if (!$rows) { echo '<p>'.esc_html__('No check-ins yet today.', 'uc-expo'); return; }

        echo '<table class="widefat striped"><thead><tr><th>'.esc_html__('Item', 'uc-expo').'</th><th>'.esc_html__('#', 'uc-expo').'</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $title = get_the_title((int)$r['entity_id']) ?: ('#'.$r['entity_id']);
            $link  = get_edit_post_link((int)$r['entity_id']);
            $badge = '<span class="uc-badge">'.esc_html(ucfirst($r['entity_type'])).'</span> ';
            echo '<tr><td>'.$badge.'<a href="'.esc_url($link).'">'.esc_html($title).'</a></td><td>'.intval($r['c']).'</td></tr>';
        }
        echo '</tbody></table>';
    }
}
