<?php
if (!defined('ABSPATH')) exit;

final class UC_Expo_QR_Checkins {
    const VERSION = '1.6.0';
    const OPTION_SECRET = 'uc_expo_qr_secret';
    const OPTION_EVENT  = 'uc_expo_current_event_id';
    const COOKIE_PENDING = 'uc_qr_pending_checkin';
    const QV_FLAG = 'uc_qr';
    const QV_TP   = 'tp';

    private static $instance = null;
    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(UC_EXPO_QR_PLUGIN_FILE, [$this, 'on_activate']);
        register_deactivation_hook(UC_EXPO_QR_PLUGIN_FILE, [$this, 'on_deactivate']);

        add_action('init', [$this, 'add_rewrite']);
        add_filter('query_vars', function($vars){ $vars[] = self::QV_FLAG; $vars[]='ex'; $vars[]='ev'; $vars[]='sig'; $vars[] = self::QV_TP; return $vars; });
        add_action('template_redirect', [$this, 'handle_qr_route']);
        add_action('wp_login', [$this, 'complete_post_login'], 10, 2);
    }

    public function on_activate() {
        $this->maybe_set_defaults();
        $this->create_table();
        $this->upgrade_schema();
        $this->add_rewrite();
        flush_rewrite_rules();
    }
    public function on_deactivate() { flush_rewrite_rules(); }

    private function maybe_set_defaults() {
        if (!get_option(self::OPTION_SECRET)) update_option(self::OPTION_SECRET, wp_generate_password(64, true, true));
        if (!get_option(self::OPTION_EVENT)) update_option(self::OPTION_EVENT, (string) date('Y'));
    }

    public function add_rewrite() {
        add_rewrite_rule(
            '^qr/(exhibitor|session|panel|speaker)/([0-9]+)/([^/]+)/([^/]+)$',
            'index.php?' . self::QV_FLAG . '=1&'. self::QV_TP .'=$matches[1]&ex=$matches[2]&ev=$matches[3]&sig=$matches[4]',
            'top'
        );
        add_rewrite_rule(
            '^qr/exhibitor/([0-9]+)/([^/]+)/([^/]+)$',
            'index.php?' . self::QV_FLAG . '=1&'. self::QV_TP .'=exhibitor&ex=$matches[1]&ev=$matches[2]&sig=$matches[3]',
            'top'
        );
    }

    public function table_name() { global $wpdb; return $wpdb->prefix . 'uc_expo_checkins'; }
    private function get_secret(): string { return (string) get_option(self::OPTION_SECRET, AUTH_KEY); }
    public function get_current_event_id(): string { return (string) get_option(self::OPTION_EVENT, (string) date('Y')); }
    private function b64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
    public function supported_post_types() { return [ 'exhibitors'=>'exhibitor', 'sessions'=>'session', 'panels'=>'panel', 'speakers'=>'speaker' ]; }
    private function segment_for_post_type($pt) { $map = $this->supported_post_types(); return $map[$pt] ?? 'exhibitor'; }

    public function sig_for(string $type_seg, int $post_id, string $event_id): string {
        $data = "type:$type_seg|id:$post_id|event:$event_id";
        return $this->b64url(hash_hmac('sha256', $data, $this->get_secret(), true));
    }
    public function verify_sig(string $type_seg, int $post_id, string $event_id, string $sig): bool {
        $calc = $this->sig_for($type_seg, $post_id, $event_id);
        if (hash_equals($calc, $sig)) return true;
        if ($type_seg === 'exhibitor') {
            $legacy = $this->b64url(hash_hmac('sha256', "exhibitor:$post_id|event:$event_id", $this->get_secret(), true));
            if (hash_equals($legacy, $sig)) return true;
        }
        return false;
    }
    public function qr_url_for_post(int $post_id, string $post_type, ?string $event_id = null): string {
        $event_id = $event_id ?: $this->get_current_event_id();
        $seg = $this->segment_for_post_type($post_type);
        $sig = $this->sig_for($seg, $post_id, $event_id);
        return home_url("/qr/$seg/$post_id/$event_id/$sig");
    }

    public function handle_qr_route() {
        if (!get_query_var(self::QV_FLAG)) return;
        $type_seg = sanitize_key((string) get_query_var(self::QV_TP));
        $post_id  = (int) get_query_var('ex');
        $event_id = sanitize_text_field((string) get_query_var('ev'));
        $sig      = sanitize_text_field((string) get_query_var('sig'));

        if (!$post_id || !$event_id || !$sig || !$type_seg || !$this->verify_sig($type_seg, $post_id, $event_id, $sig)) {
            status_header(403); wp_die('Invalid or expired QR link.', 'QR Forbidden', ['response' => 403]);
        }

        $dest = get_permalink($post_id);
        if (!$dest) { status_header(404); wp_die('Content not found.', 'Not Found', ['response' => 404]); }

        if (is_user_logged_in()) {
            $ok = $this->record_checkin(get_current_user_id(), $type_seg, $post_id, $event_id);
            wp_safe_redirect(add_query_arg('checked_in', $ok ? '1' : '0', $dest)); exit;
        }

        $payload = [ 'tp'=>$type_seg,'ex'=>$post_id,'ev'=>$event_id,'sig'=>$sig,'ts'=>time() ];
        $cookie_val = wp_json_encode($payload);
        setcookie(self::COOKIE_PENDING, $cookie_val, time()+900, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $return = home_url($_SERVER['REQUEST_URI'] ?? '');
        wp_safe_redirect( wp_login_url($return) ); exit;
    }

    public function complete_post_login($user_login, $user) {
        if (empty($_COOKIE[self::COOKIE_PENDING])) return;
        $raw = wp_unslash($_COOKIE[self::COOKIE_PENDING]);
        $data = json_decode($raw, true);
        if (!$data || empty($data['ex']) || empty($data['ev']) || empty($data['sig'])) { $this->clear_cookie(); return; }
        if (!empty($data['ts']) && (time() - (int)$data['ts']) > 900) { $this->clear_cookie(); return; }
        $post_id = (int) $data['ex']; $event_id = (string) $data['ev']; $sig = (string) $data['sig']; $type_seg = !empty($data['tp']) ? sanitize_key($data['tp']) : 'exhibitor';

        if ($this->verify_sig($type_seg, $post_id, $event_id, $sig)) {
            $this->record_checkin($user->ID, $type_seg, $post_id, $event_id);
            $this->clear_cookie();
            $dest = get_permalink($post_id) ?: home_url('/');
            wp_safe_redirect(add_query_arg('checked_in', '1', $dest)); exit;
        }
        $this->clear_cookie();
    }

    private function clear_cookie() { setcookie(self::COOKIE_PENDING, '', time()-3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true); }

    private function create_table() {
        global $wpdb; $table = $this->table_name(); $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(20) NOT NULL DEFAULT 'exhibitor',
            entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_id VARCHAR(64) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'qr',
            ip VARBINARY(16) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_user_entity_event (user_id, entity_type, entity_id, event_id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_user (user_id),
            KEY idx_event (event_id)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; dbDelta($sql);
    }

    private function upgrade_schema() {
        global $wpdb; $table = $this->table_name();
        $cols = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        $have_entity = false; $have_exhibitor = false;
        foreach ($cols as $c) { if ($c['Field']==='entity_type') $have_entity=true; if ($c['Field']==='exhibitor_id') $have_exhibitor=true; }
        if (!$have_entity) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN entity_type VARCHAR(20) NOT NULL DEFAULT 'exhibitor' AFTER user_id");
            $wpdb->query("ALTER TABLE $table ADD COLUMN entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER entity_type");
            if ($have_exhibitor) $wpdb->query("UPDATE $table SET entity_type='exhibitor', entity_id=exhibitor_id WHERE entity_id=0");
        }
        $idx = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name='uniq_user_entity_event'", ARRAY_A);
        if (empty($idx)) { $wpdb->query("ALTER TABLE $table DROP INDEX uniq_user_exh_event"); $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY uniq_user_entity_event (user_id, entity_type, entity_id, event_id)"); }
    }

    public function record_checkin(int $user_id, string $type_seg, int $entity_id, string $event_id): bool {
        global $wpdb;
        $this->create_table(); $this->upgrade_schema();
        $table = $this->table_name();
        $ip_text = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null;
        $ua = !empty($_SERVER['HTTP_USER_AGENT']) ? substr(wp_unslash($_SERVER['HTTP_USER_AGENT']), 0, 65535) : null;

        $sql = "INSERT INTO $table (user_id, entity_type, entity_id, event_id, source, ip, user_agent, created_at)
                VALUES (%d, %s, %d, %s, %s, INET6_ATON(%s), %s, %s)
                ON DUPLICATE KEY UPDATE created_at = created_at";
        $ok = $wpdb->query($wpdb->prepare($sql, $user_id, $type_seg, $entity_id, $event_id, 'qr', $ip_text, $ua, current_time('mysql')));
        if ($ok !== false) do_action('uc_expo_checkin_recorded', $user_id, $type_seg, $entity_id, $event_id);
        return $ok !== false;
    }
}
