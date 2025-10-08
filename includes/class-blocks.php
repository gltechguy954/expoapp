<?php
if (!defined('ABSPATH')) exit;

class UC_Expo_QR_Blocks {
    private static $instance = null;
    public static function instance() { if (!self::$instance) self::$instance = new self(); return self::$instance; }

    private function __construct() {
        add_shortcode('uc_expo_checkin_badge', [$this, 'shortcode_badge']);
        add_action('init', [$this, 'register_block']);
        add_shortcode('uc_expo_qr_link', [$this, 'shortcode_qr_link']);
    }

    public function shortcode_badge($atts = []) {
        $checked = isset($_GET['checked_in']) && $_GET['checked_in'] === '1';
        if (!$checked) return '';
        $html = '<div class="uc-expo-badge"><span>✅</span><strong>'.esc_html__('Check-in recorded!', 'uc-expo').'</strong></div>';
        return $html;
    }

    public function register_block() {
        register_block_type('uc-expo/checkin-badge', [
            'render_callback' => function($attrs, $content){ return $this->shortcode_badge(); },
            'attributes' => []
        ]);
    }

    public function shortcode_qr_link($atts) {
        $atts = shortcode_atts(['id' => 0, 'event' => '', 'type' => 'exhibitors'], $atts);
        $post_id = (int) $atts['id'];
        $pt = sanitize_key($atts['type']);
        if (!$post_id) return '';
        $url = esc_url( UC_Expo_QR_Checkins::instance()->qr_url_for_post($post_id, $pt, $atts['event'] ?: null) );
        return '<a href="'. $url .'">'. $url .'</a>';
    }
}
