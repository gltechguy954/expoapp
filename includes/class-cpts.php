<?php
if (!defined('ABSPATH')) exit;

class UC_Expo_QR_CPTs {
    private static $instance = null;
    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register']);
    }

    public function register() {
        $types = [
            'exhibitors' => ['Exhibitors','Exhibitor','dashicons-store'],
            'sessions'   => ['Sessions','Session','dashicons-clock'],
            'panels'     => ['Panels','Panel','dashicons-megaphone'],
            'speakers'   => ['Speakers','Speaker','dashicons-microphone'],
        ];
        foreach ($types as $slug => $meta) {
            $plural = $meta[0]; $singular = $meta[1]; $icon = $meta[2];
            if (post_type_exists($slug)) continue;
            register_post_type($slug, [
                'labels' => [
                    'name' => $plural,
                    'singular_name' => $singular,
                    'add_new_item' => 'Add New ' . $singular,
                ],
                'public' => true,
                'show_in_menu' => true,
                'menu_icon' => $icon,
                'has_archive' => false,
                'supports' => ['title','editor','excerpt','thumbnail','custom-fields'],
                'show_in_rest' => true,
            ]);
        }
    }
}
