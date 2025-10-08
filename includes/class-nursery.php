<?php
if (!defined('ABSPATH')) exit;

final class UC_Expo_QR_Nursery {
    const OPTION_ENABLE = 'uc_expo_nursery_mode';

    const CPT_SERVICE = 'uc_nursery_service';
    const CPT_FAMILY  = 'uc_nursery_family';
    const CPT_CHILD   = 'uc_nursery_child';

    const META_SERVICE_START  = '_uc_nursery_start';
    const META_SERVICE_END    = '_uc_nursery_end';
    const META_SERVICE_LABEL  = '_uc_nursery_label';
    const META_FAMILY_CONTACT = '_uc_nursery_contact';
    const META_CHILD_FAMILY   = '_uc_nursery_family_id';
    const META_CHILD_ALLERGY  = '_uc_nursery_allergies';
    const META_CHILD_NOTES    = '_uc_nursery_notes';

    const TABLE_CHECKINS = 'uc_nursery_checkins';
    const TABLE_AUDIT    = 'uc_nursery_audit';

    const STATUS_CREATED     = 'created';
    const STATUS_CHECKED_IN  = 'checked_in';
    const STATUS_CHECKED_OUT = 'checked_out';
    const STATUS_EXPIRED     = 'expired';

    private static $instance = null;

    private $qr_types = ['child', 'pickup', 'label'];

    public static function instance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_post_types']);
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('save_post', [$this, 'save_post_meta'], 10, 2);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        register_activation_hook(UC_EXPO_QR_PLUGIN_FILE, [$this, 'on_activate']);
    }

    public function is_enabled(): bool {
        return (bool) get_option(self::OPTION_ENABLE, false);
    }

    public function is_nursery_qr_type(string $type): bool {
        return in_array($type, $this->qr_types, true);
    }

    public function on_activate(): void {
        $this->register_post_types();
        $this->create_tables();
        flush_rewrite_rules();
    }

    private function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $checkins = $wpdb->prefix . self::TABLE_CHECKINS;
        $audit    = $wpdb->prefix . self::TABLE_AUDIT;

        $sql = [];
        $sql[] = "CREATE TABLE IF NOT EXISTS `$checkins` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            child_id BIGINT UNSIGNED NOT NULL,
            family_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'created',
            child_token_hash CHAR(64) NOT NULL,
            pickup_token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            checkin_at DATETIME NULL,
            checkout_at DATETIME NULL,
            checkin_staff BIGINT UNSIGNED NULL,
            checkout_staff BIGINT UNSIGNED NULL,
            label_printed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_child (child_id),
            KEY idx_service (service_id),
            KEY idx_status (status),
            KEY idx_family (family_id),
            KEY idx_expires (expires_at)
        ) $charset;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `$audit` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            checkin_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            action VARCHAR(50) NOT NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_checkin (checkin_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    public function register_post_types(): void {
        $labels = [
            'labels' => [
                'name' => __('Nursery Services', 'uc-expo'),
                'singular_name' => __('Nursery Service', 'uc-expo'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title', 'editor'],
            'menu_icon' => 'dashicons-calendar-alt',
            'show_in_rest' => true,
        ];
        register_post_type(self::CPT_SERVICE, $labels);

        $family_labels = [
            'labels' => [
                'name' => __('Nursery Families', 'uc-expo'),
                'singular_name' => __('Nursery Family', 'uc-expo'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title', 'editor'],
            'menu_icon' => 'dashicons-groups',
            'show_in_rest' => true,
        ];
        register_post_type(self::CPT_FAMILY, $family_labels);

        $child_labels = [
            'labels' => [
                'name' => __('Nursery Children', 'uc-expo'),
                'singular_name' => __('Nursery Child', 'uc-expo'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title', 'editor'],
            'menu_icon' => 'dashicons-buddicons-buddypress-logo',
            'show_in_rest' => true,
        ];
        register_post_type(self::CPT_CHILD, $child_labels);
    }

    public function register_meta_boxes(): void {
        add_meta_box('uc-nursery-service', __('Service Details', 'uc-expo'), [$this, 'render_service_meta'], self::CPT_SERVICE, 'side', 'default');
        add_meta_box('uc-nursery-family', __('Family Details', 'uc-expo'), [$this, 'render_family_meta'], self::CPT_FAMILY, 'side', 'default');
        add_meta_box('uc-nursery-child', __('Child Details', 'uc-expo'), [$this, 'render_child_meta'], self::CPT_CHILD, 'side', 'default');
    }

    public function render_service_meta(WP_Post $post): void {
        $start = get_post_meta($post->ID, self::META_SERVICE_START, true);
        $end   = get_post_meta($post->ID, self::META_SERVICE_END, true);
        $label = get_post_meta($post->ID, self::META_SERVICE_LABEL, true);
        wp_nonce_field('uc_nursery_service_meta', 'uc_nursery_service_nonce');
        echo '<p><label>'.esc_html__('Service Label', 'uc-expo').'<br />';
        echo '<input type="text" name="uc_nursery[label]" value="'.esc_attr($label).'" class="widefat" /></label></p>';
        echo '<p><label>'.esc_html__('Start Time', 'uc-expo').'<br />';
        echo '<input type="datetime-local" name="uc_nursery[start]" value="'.esc_attr($this->format_datetime_local($start)).'" class="widefat" /></label></p>';
        echo '<p><label>'.esc_html__('End Time', 'uc-expo').'<br />';
        echo '<input type="datetime-local" name="uc_nursery[end]" value="'.esc_attr($this->format_datetime_local($end)).'" class="widefat" /></label></p>';
    }

    public function render_family_meta(WP_Post $post): void {
        $contact = get_post_meta($post->ID, self::META_FAMILY_CONTACT, true);
        $contact = is_array($contact) ? $contact : [];
        wp_nonce_field('uc_nursery_family_meta', 'uc_nursery_family_nonce');
        echo '<p><label>'.esc_html__('Primary Guardian Name', 'uc-expo').'<br />';
        echo '<input type="text" name="uc_nursery_family[name]" value="'.esc_attr($contact['name'] ?? '').'" class="widefat" /></label></p>';
        echo '<p><label>'.esc_html__('Email', 'uc-expo').'<br />';
        echo '<input type="email" name="uc_nursery_family[email]" value="'.esc_attr($contact['email'] ?? '').'" class="widefat" /></label></p>';
        echo '<p><label>'.esc_html__('Phone', 'uc-expo').'<br />';
        echo '<input type="tel" name="uc_nursery_family[phone]" value="'.esc_attr($contact['phone'] ?? '').'" class="widefat" /></label></p>';
    }

    public function render_child_meta(WP_Post $post): void {
        $family_id = (int) get_post_meta($post->ID, self::META_CHILD_FAMILY, true);
        $allergy   = get_post_meta($post->ID, self::META_CHILD_ALLERGY, true);
        $notes     = get_post_meta($post->ID, self::META_CHILD_NOTES, true);
        wp_nonce_field('uc_nursery_child_meta', 'uc_nursery_child_nonce');
        echo '<p><label>'.esc_html__('Family', 'uc-expo').'<br />';
        $families = get_posts([
            'post_type' => self::CPT_FAMILY,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        echo '<select name="uc_nursery_child[family]" class="widefat">';
        echo '<option value="">'.esc_html__('— Select Family —', 'uc-expo').'</option>';
        foreach ($families as $family) {
            echo '<option value="'.esc_attr($family->ID).'" '.selected($family_id, $family->ID, false).'>'.esc_html($family->post_title).'</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>'.esc_html__('Allergies', 'uc-expo').'<br />';
        echo '<textarea name="uc_nursery_child[allergies]" class="widefat" rows="3">'.esc_textarea($allergy).'</textarea></label></p>';
        echo '<p><label>'.esc_html__('Notes', 'uc-expo').'<br />';
        echo '<textarea name="uc_nursery_child[notes]" class="widefat" rows="3">'.esc_textarea($notes).'</textarea></label></p>';
    }

    private function format_datetime_local($value): string {
        if (!$value) return '';
        $ts = strtotime($value);
        if (!$ts) return '';
        return date('Y-m-d\TH:i', $ts);
    }

    public function save_post_meta(int $post_id, WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ($post->post_type === self::CPT_SERVICE) {
            if (!isset($_POST['uc_nursery_service_nonce']) || !wp_verify_nonce($_POST['uc_nursery_service_nonce'], 'uc_nursery_service_meta')) return;
            $data = $_POST['uc_nursery'] ?? [];
            $label = sanitize_text_field($data['label'] ?? '');
            $start = sanitize_text_field($data['start'] ?? '');
            $end   = sanitize_text_field($data['end'] ?? '');
            update_post_meta($post_id, self::META_SERVICE_LABEL, $label);
            update_post_meta($post_id, self::META_SERVICE_START, $start);
            update_post_meta($post_id, self::META_SERVICE_END, $end);
        }
        if ($post->post_type === self::CPT_FAMILY) {
            if (!isset($_POST['uc_nursery_family_nonce']) || !wp_verify_nonce($_POST['uc_nursery_family_nonce'], 'uc_nursery_family_meta')) return;
            $data = $_POST['uc_nursery_family'] ?? [];
            $contact = [
                'name'  => sanitize_text_field($data['name'] ?? ''),
                'email' => sanitize_email($data['email'] ?? ''),
                'phone' => sanitize_text_field($data['phone'] ?? ''),
            ];
            update_post_meta($post_id, self::META_FAMILY_CONTACT, $contact);
        }
        if ($post->post_type === self::CPT_CHILD) {
            if (!isset($_POST['uc_nursery_child_nonce']) || !wp_verify_nonce($_POST['uc_nursery_child_nonce'], 'uc_nursery_child_meta')) return;
            $data = $_POST['uc_nursery_child'] ?? [];
            $family = isset($data['family']) ? absint($data['family']) : 0;
            update_post_meta($post_id, self::META_CHILD_FAMILY, $family);
            update_post_meta($post_id, self::META_CHILD_ALLERGY, sanitize_textarea_field($data['allergies'] ?? ''));
            update_post_meta($post_id, self::META_CHILD_NOTES, sanitize_textarea_field($data['notes'] ?? ''));
        }
    }

    public function register_menu(): void {
        if (!$this->is_enabled()) return;
        add_submenu_page('uc-expo-qr', __('Nursery Check-ins', 'uc-expo'), __('Nursery Check-ins', 'uc-expo'), 'manage_options', 'uc-nursery', [$this, 'render_dashboard']);
        add_submenu_page('uc-expo-qr', __('Nursery Print', 'uc-expo'), __('Nursery Print', 'uc-expo'), 'manage_options', 'uc-nursery-print', [$this, 'render_print_page']);
    }

    public function enqueue_assets(string $hook): void {
        if (strpos($hook, 'uc-nursery') === false) return;
        wp_enqueue_style('uc-nursery-admin', UC_EXPO_QR_URL . 'assets/nursery.css', [], UC_Expo_QR_Checkins::VERSION);
        wp_enqueue_script('uc-expo-qrcode-lib', 'https://unpkg.com/qrcodejs@1.0.0/qrcode.min.js', [], '1.0.0', true);
        wp_enqueue_script('uc-nursery-admin', UC_EXPO_QR_URL . 'assets/nursery.js', ['uc-expo-qrcode-lib'], UC_Expo_QR_Checkins::VERSION, true);
        wp_localize_script('uc-nursery-admin', 'UCNursery', [
            'confirmCheckout' => __('Confirm checkout?', 'uc-expo'),
        ]);
    }

    public function register_shortcodes(): void {
        add_shortcode('uc_print_labels', [$this, 'shortcode_print_labels']);
    }

    public function register_rest_routes(): void {
        // Reserved for future kiosk integrations.
    }

    public function shortcode_print_labels($atts = []): string {
        if (!current_user_can('manage_options')) {
            return '<div class="uc-nursery-warning">'.esc_html__('Nursery printing is restricted to staff.', 'uc-expo').'</div>';
        }
        $atts = shortcode_atts([
            'service_id' => 0,
            'family_id'  => 0,
        ], $atts, 'uc_print_labels');
        $service_id = absint($atts['service_id']);
        $family_id  = absint($atts['family_id']);
        ob_start();
        $this->render_print_content($service_id, $family_id, true);
        return ob_get_clean();
    }

    public function handle_actions(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (!$this->is_enabled()) return;

        if (!empty($_POST['uc_nursery_action'])) {
            $action = sanitize_key($_POST['uc_nursery_action']);
            if ($action === 'check_in' && check_admin_referer('uc_nursery_checkin')) {
                $this->process_check_in();
            }
            if ($action === 'check_out' && check_admin_referer('uc_nursery_checkin')) {
                $this->process_check_out();
            }
            if ($action === 'regenerate' && check_admin_referer('uc_nursery_checkin')) {
                $this->process_regenerate_tokens();
            }
        }
    }

    private function process_check_in(): void {
        $child_id   = absint($_POST['child_id'] ?? 0);
        $service_id = absint($_POST['service_id'] ?? 0);
        $note       = sanitize_text_field($_POST['note'] ?? '');
        if (!$child_id || !$service_id) return;
        $result = $this->check_in_child($child_id, $service_id, get_current_user_id(), $note);
        if (is_wp_error($result)) {
            add_action('admin_notices', function() use ($result){
                echo '<div class="notice notice-error"><p>'.esc_html($result->get_error_message()).'</p></div>';
            });
            return;
        }
        $tokens = $result['tokens'];
        set_transient($this->token_transient_key($result['checkin_id']), $tokens, $result['ttl']);
        $redirect = add_query_arg([
            'page' => 'uc-nursery',
            'service' => $service_id,
            'checked_in' => $result['checkin_id'],
        ], admin_url('admin.php'));
        wp_safe_redirect($redirect); exit;
    }

    private function process_check_out(): void {
        $token   = sanitize_text_field($_POST['pickup_token'] ?? '');
        $checkin = absint($_POST['checkin_id'] ?? 0);
        $service = absint($_POST['service_id'] ?? 0);
        if ($token) {
            $res = $this->complete_pickup_by_token($token, get_current_user_id());
        } elseif ($checkin) {
            $res = $this->complete_pickup($checkin, get_current_user_id());
        } else {
            return;
        }
        if (is_wp_error($res)) {
            add_action('admin_notices', function() use ($res){
                echo '<div class="notice notice-error"><p>'.esc_html($res->get_error_message()).'</p></div>';
            });
            return;
        }
        $redirect = add_query_arg([
            'page' => 'uc-nursery',
            'service' => $service ?: $res['service_id'],
            'checked_out' => $res['checkin_id'],
        ], admin_url('admin.php'));
        wp_safe_redirect($redirect); exit;
    }

    private function process_regenerate_tokens(): void {
        $checkin_id = absint($_POST['checkin_id'] ?? 0);
        if (!$checkin_id) return;
        $res = $this->regenerate_tokens($checkin_id, get_current_user_id());
        if (is_wp_error($res)) {
            add_action('admin_notices', function() use ($res){
                echo '<div class="notice notice-error"><p>'.esc_html($res->get_error_message()).'</p></div>';
            });
            return;
        }
        set_transient($this->token_transient_key($checkin_id), $res['tokens'], $res['ttl']);
        $redirect = add_query_arg([
            'page' => 'uc-nursery',
            'service' => $res['service_id'],
            'regenerated' => $checkin_id,
        ], admin_url('admin.php'));
        wp_safe_redirect($redirect); exit;
    }

    private function check_in_child(int $child_id, int $service_id, int $staff_id, string $note = '') {
        $child = get_post($child_id);
        $service = get_post($service_id);
        if (!$child || $child->post_type !== self::CPT_CHILD) return new WP_Error('invalid_child', __('Child not found.', 'uc-expo'));
        if (!$service || $service->post_type !== self::CPT_SERVICE) return new WP_Error('invalid_service', __('Service not found.', 'uc-expo'));
        $family_id = (int) get_post_meta($child_id, self::META_CHILD_FAMILY, true);
        if (!$family_id) return new WP_Error('missing_family', __('Assign the child to a family before checking in.', 'uc-expo'));

        $active = $this->get_active_checkin($child_id, $service_id);
        if ($active && $active->status === self::STATUS_CHECKED_IN) {
            return new WP_Error('already_checked_in', __('Child is already checked in for this service.', 'uc-expo'));
        }

        $tokens = $this->generate_tokens();
        $hashes = $this->hash_tokens($tokens);
        $expires = $this->get_service_end($service_id);
        $ttl = max(1, $expires - time());

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHECKINS;
        $data = [
            'child_id' => $child_id,
            'family_id' => $family_id,
            'service_id' => $service_id,
            'status' => self::STATUS_CHECKED_IN,
            'child_token_hash' => $hashes['child'],
            'pickup_token_hash' => $hashes['pickup'],
            'expires_at' => gmdate('Y-m-d H:i:s', $expires),
            'checkin_at' => current_time('mysql'),
            'checkin_staff' => $staff_id,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        if ($active) {
            $wpdb->update($table, $data, ['id' => $active->id], ['%d','%d','%d','%s','%s','%s','%s','%s','%d','%s','%s'], ['%d']);
            $checkin_id = $active->id;
        } else {
            $wpdb->insert($table, $data, ['%d','%d','%d','%s','%s','%s','%s','%s','%d','%s','%s']);
            $checkin_id = (int) $wpdb->insert_id;
        }
        $this->log_audit($checkin_id, 'check_in', $staff_id, $note);

        return [
            'checkin_id' => $checkin_id,
            'tokens' => $tokens,
            'ttl' => $ttl,
        ];
    }

    private function regenerate_tokens(int $checkin_id, int $staff_id) {
        $record = $this->get_checkin($checkin_id);
        if (!$record) return new WP_Error('missing', __('Check-in not found.', 'uc-expo'));
        if ($record->status !== self::STATUS_CHECKED_IN) return new WP_Error('invalid', __('Only active check-ins can be regenerated.', 'uc-expo'));
        $tokens = $this->generate_tokens();
        $hashes = $this->hash_tokens($tokens);
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHECKINS;
        $wpdb->update($table, [
            'child_token_hash' => $hashes['child'],
            'pickup_token_hash' => $hashes['pickup'],
            'updated_at' => current_time('mysql'),
        ], ['id' => $checkin_id], ['%s','%s','%s'], ['%d']);
        $this->log_audit($checkin_id, 'regenerate_tokens', $staff_id, '');
        $ttl = max(1, strtotime($record->expires_at . ' UTC') - time());
        return [
            'checkin_id' => $checkin_id,
            'tokens' => $tokens,
            'ttl' => $ttl,
            'service_id' => (int) $record->service_id,
        ];
    }

    private function complete_pickup(int $checkin_id, int $staff_id) {
        $record = $this->get_checkin($checkin_id);
        if (!$record) return new WP_Error('missing', __('Check-in not found.', 'uc-expo'));
        if ($record->status !== self::STATUS_CHECKED_IN) return new WP_Error('invalid_status', __('Check-in is not active.', 'uc-expo'));
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHECKINS;
        $wpdb->update($table, [
            'status' => self::STATUS_CHECKED_OUT,
            'checkout_at' => current_time('mysql'),
            'checkout_staff' => $staff_id,
            'updated_at' => current_time('mysql'),
        ], ['id' => $checkin_id], ['%s','%s','%d','%s'], ['%d']);
        delete_transient($this->token_transient_key($checkin_id));
        $this->log_audit($checkin_id, 'check_out', $staff_id, '');
        return [
            'checkin_id' => $checkin_id,
            'service_id' => (int) $record->service_id,
        ];
    }

    private function complete_pickup_by_token(string $token, int $staff_id) {
        $hash = $this->hash_token($token);
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHECKINS;
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE pickup_token_hash = %s AND status = %s", $hash, self::STATUS_CHECKED_IN));
        if (!$record) return new WP_Error('invalid_token', __('Pickup token not found or expired.', 'uc-expo'));
        return $this->complete_pickup((int) $record->id, $staff_id);
    }

    public function handle_qr_scan(string $type, int $checkin_id, string $token): void {
        $record = $this->get_checkin($checkin_id);
        if (!$record) {
            status_header(404); wp_die(__('Check-in record not found.', 'uc-expo'), __('Not Found', 'uc-expo'), ['response' => 404]);
        }
        $valid = false;
        if ($type === 'child') {
            $valid = hash_equals($record->child_token_hash, $this->hash_token($token));
        } elseif ($type === 'pickup') {
            $valid = hash_equals($record->pickup_token_hash, $this->hash_token($token));
        } elseif ($type === 'label') {
            $valid = true; // label view uses signature verification already
        }
        if (!$valid) {
            status_header(403); wp_die(__('Token mismatch or expired.', 'uc-expo'), __('Forbidden', 'uc-expo'), ['response' => 403]);
        }
        if (!is_user_logged_in()) {
            $return = home_url($_SERVER['REQUEST_URI'] ?? '');
            wp_safe_redirect(wp_login_url($return)); exit;
        }
        if (!current_user_can('manage_options')) {
            status_header(403); wp_die(__('You do not have permission for nursery operations.', 'uc-expo'), __('Forbidden', 'uc-expo'), ['response' => 403]);
        }
        if ($type === 'pickup') {
            $res = $this->complete_pickup($checkin_id, get_current_user_id());
            if (is_wp_error($res)) {
                status_header(400); wp_die(esc_html($res->get_error_message()), __('Nursery', 'uc-expo'), ['response' => 400]);
            }
            $url = add_query_arg([
                'page' => 'uc-nursery',
                'service' => $record->service_id,
                'checked_out' => $checkin_id,
            ], admin_url('admin.php'));
            wp_safe_redirect($url); exit;
        }
        if ($type === 'label') {
            $url = add_query_arg([
                'page' => 'uc-nursery-print',
                'service' => $record->service_id,
                'checkin' => $checkin_id,
            ], admin_url('admin.php'));
            wp_safe_redirect($url); exit;
        }
        $url = add_query_arg([
            'page' => 'uc-nursery',
            'service' => $record->service_id,
            'child' => $record->child_id,
        ], admin_url('admin.php'));
        wp_safe_redirect($url); exit;
    }

    private function token_transient_key(int $checkin_id): string {
        return 'uc_nursery_tokens_' . $checkin_id;
    }

    private function generate_tokens(): array {
        return [
            'child' => $this->generate_token(),
            'pickup' => $this->generate_token(),
        ];
    }

    private function hash_tokens(array $tokens): array {
        return [
            'child' => $this->hash_token($tokens['child']),
            'pickup' => $this->hash_token($tokens['pickup']),
        ];
    }

    private function generate_token(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';
        for ($i = 0; $i < 10; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $token;
    }

    private function hash_token(string $token): string {
        return hash_hmac('sha256', $token, AUTH_SALT);
    }

    private function get_service_end(int $service_id): int {
        $end = get_post_meta($service_id, self::META_SERVICE_END, true);
        $ts = $end ? strtotime($end) : false;
        if ($ts) return $ts;
        return time() + HOUR_IN_SECONDS * 6;
    }

    private function get_active_checkin(int $child_id, int $service_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHECKINS;
        $this->expire_records();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE child_id = %d AND service_id = %d ORDER BY id DESC LIMIT 1", $child_id, $service_id));
    }

    private function get_checkin(int $checkin_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHECKINS;
        $this->expire_records();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $checkin_id));
    }

    private function expire_records(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHECKINS;
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare("UPDATE $table SET status = %s, updated_at = %s WHERE status = %s AND expires_at < %s", self::STATUS_EXPIRED, $now, self::STATUS_CHECKED_IN, $now));
    }

    private function log_audit(int $checkin_id, string $action, int $actor_id = 0, string $note = ''): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_AUDIT;
        $wpdb->insert($table, [
            'checkin_id' => $checkin_id,
            'actor_id' => $actor_id ?: null,
            'action' => $action,
            'note' => $note,
            'created_at' => current_time('mysql'),
        ], ['%d','%d','%s','%s','%s']);
    }

    public function render_dashboard(): void {
        if (!current_user_can('manage_options')) return;
        $service_id = absint($_GET['service'] ?? 0);
        $child_focus = absint($_GET['child'] ?? 0);
        $message = '';
        if (!empty($_GET['checked_in'])) {
            $message = __('Child checked in. Print and distribute the pickup pass now.', 'uc-expo');
        } elseif (!empty($_GET['checked_out'])) {
            $message = __('Child checked out successfully.', 'uc-expo');
        } elseif (!empty($_GET['regenerated'])) {
            $message = __('Tokens regenerated. Please reprint the labels.', 'uc-expo');
        }
        echo '<div class="wrap uc-app uc-nursery"><div class="uc-app-header"><h1>'.esc_html__('Nursery Check-ins', 'uc-expo').'</h1></div>';
        if ($message) {
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($message).'</p></div>';
        }
        echo '<div class="uc-card">';
        echo '<form method="get" class="uc-toolbar">';
        echo '<input type="hidden" name="page" value="uc-nursery" />';
        $services = get_posts([
            'post_type' => self::CPT_SERVICE,
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        echo '<label>'.esc_html__('Service', 'uc-expo').' '; echo '<select name="service">';
        echo '<option value="">'.esc_html__('Select service…', 'uc-expo').'</option>';
        foreach ($services as $svc) {
            echo '<option value="'.esc_attr($svc->ID).'" '.selected($service_id, $svc->ID, false).'>'.esc_html($svc->post_title).'</option>';
        }
        echo '</select></label> ';
        submit_button(__('Load', 'uc-expo'), 'secondary', '', false);
        echo '</form>';

        if ($service_id) {
            echo '<div class="uc-nursery-token-capture">';
            echo '<form method="post" class="uc-token-inline">';
            wp_nonce_field('uc_nursery_checkin');
            echo '<input type="hidden" name="uc_nursery_action" value="check_out" />';
            echo '<input type="hidden" name="service_id" value="'.esc_attr($service_id).'" />';
            echo '<label>'.esc_html__('Guardian QR Code', 'uc-expo').'<br />';
            echo '<input type="text" name="pickup_token" placeholder="'.esc_attr__('Scan or paste pickup token…', 'uc-expo').'" class="regular-text" />';
            echo '</label> ';
            submit_button(__('Validate & Check Out', 'uc-expo'), 'primary', '', false);
            echo '</form>';
            echo '</div>';
            $children = $this->get_children_for_service($service_id);
            echo '<div class="uc-nursery-grid">';
            foreach ($children as $child) {
                $status = $child['status'];
                $classes = 'uc-nursery-child status-' . esc_attr($status);
                echo '<div class="'.$classes.'">';
                echo '<h3>'.esc_html($child['name']).'</h3>';
                if ($child['allergies']) {
                    echo '<p class="uc-nursery-allergy"><strong>'.esc_html__('Allergies', 'uc-expo').':</strong> '.esc_html($child['allergies']).'</p>';
                }
                echo '<p><strong>'.esc_html__('Family', 'uc-expo').':</strong> '.esc_html($child['family']).'</p>';
                echo '<p><strong>'.esc_html__('Status', 'uc-expo').':</strong> '.esc_html(ucwords(str_replace('_', ' ', $status))).'</p>';
                echo '<div class="uc-nursery-actions">';
                if ($status !== self::STATUS_CHECKED_IN) {
                    echo '<form method="post">';
                    wp_nonce_field('uc_nursery_checkin');
                    echo '<input type="hidden" name="uc_nursery_action" value="check_in" />';
                    echo '<input type="hidden" name="child_id" value="'.esc_attr($child['id']).'" />';
                    echo '<input type="hidden" name="service_id" value="'.esc_attr($service_id).'" />';
                    echo '<button type="submit" class="button button-primary">'.esc_html__('Check in', 'uc-expo').'</button>';
                    echo '</form>';
                } else {
                    echo '<form method="post" onsubmit="return confirm(UCNursery.confirmCheckout);">';
                    wp_nonce_field('uc_nursery_checkin');
                    echo '<input type="hidden" name="uc_nursery_action" value="check_out" />';
                    echo '<input type="hidden" name="checkin_id" value="'.esc_attr($child['checkin_id']).'" />';
                    echo '<input type="hidden" name="service_id" value="'.esc_attr($service_id).'" />';
                    echo '<button type="submit" class="button">'.esc_html__('Check out', 'uc-expo').'</button>';
                    echo '</form>';
                    echo '<form method="post">';
                    wp_nonce_field('uc_nursery_checkin');
                    echo '<input type="hidden" name="uc_nursery_action" value="regenerate" />';
                    echo '<input type="hidden" name="checkin_id" value="'.esc_attr($child['checkin_id']).'" />';
                    echo '<button type="submit" class="button button-secondary">'.esc_html__('Regenerate Tokens', 'uc-expo').'</button>';
                    echo '</form>';
                    $tokens = get_transient($this->token_transient_key($child['checkin_id']));
                    if ($tokens) {
                        $child_url = UC_Expo_QR_Checkins::instance()->nursery_qr_url('child', $child['checkin_id'], $tokens['child']);
                        $pickup_url = UC_Expo_QR_Checkins::instance()->nursery_qr_url('pickup', $child['checkin_id'], $tokens['pickup']);
                        $label_url = UC_Expo_QR_Checkins::instance()->nursery_qr_url('label', $child['checkin_id'], $tokens['child']);
                        echo '<div class="uc-nursery-token-grid">';
                        echo '<div><strong>'.esc_html__('Child QR', 'uc-expo').'</strong><div class="uc-nursery-qr" data-url="'.esc_attr($child_url).'"></div><code>'.esc_html($tokens['child']).'</code></div>';
                        echo '<div><strong>'.esc_html__('Pickup QR', 'uc-expo').'</strong><div class="uc-nursery-qr" data-url="'.esc_attr($pickup_url).'"></div><code>'.esc_html($tokens['pickup']).'</code></div>';
                        echo '<p><a href="'.esc_url($label_url).'" target="_blank" class="button button-primary">'.esc_html__('Open Label View', 'uc-expo').'</a></p>';
                        echo '</div>';
                    }
                }
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<p>'.esc_html__('Select a service to manage nursery check-ins.', 'uc-expo').'</p>';
        }
        echo '</div></div>';
    }

    private function get_children_for_service(int $service_id): array {
        $args = [
            'post_type' => self::CPT_CHILD,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        $children = get_posts($args);
        $list = [];
        foreach ($children as $child) {
            $family_id = (int) get_post_meta($child->ID, self::META_CHILD_FAMILY, true);
            $family = $family_id ? get_post($family_id) : null;
            $checkin = $this->get_active_checkin($child->ID, $service_id);
            $list[] = [
                'id' => $child->ID,
                'name' => $child->post_title,
                'allergies' => get_post_meta($child->ID, self::META_CHILD_ALLERGY, true),
                'family' => $family ? $family->post_title : __('Unassigned', 'uc-expo'),
                'status' => $checkin ? $checkin->status : self::STATUS_CREATED,
                'checkin_id' => $checkin ? (int) $checkin->id : 0,
            ];
        }
        return $list;
    }

    public function render_print_page(): void {
        if (!current_user_can('manage_options')) return;
        $service_id = absint($_GET['service'] ?? 0);
        $family_id  = absint($_GET['family'] ?? 0);
        $checkin_id = absint($_GET['checkin'] ?? 0);
        echo '<div class="wrap uc-app uc-nursery-print"><div class="uc-app-header"><h1>'.esc_html__('Nursery Label Printer', 'uc-expo').'</h1></div>';
        echo '<div class="uc-card">';
        echo '<form method="get" class="uc-toolbar">';
        echo '<input type="hidden" name="page" value="uc-nursery-print" />';
        echo '<label>'.esc_html__('Service', 'uc-expo').' <select name="service">';
        echo '<option value="">'.esc_html__('Select service…', 'uc-expo').'</option>';
        $services = get_posts([
            'post_type' => self::CPT_SERVICE,
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        foreach ($services as $svc) {
            echo '<option value="'.esc_attr($svc->ID).'" '.selected($service_id, $svc->ID, false).'>'.esc_html($svc->post_title).'</option>';
        }
        echo '</select></label> ';
        echo '<label>'.esc_html__('Family', 'uc-expo').' <select name="family">';
        echo '<option value="">'.esc_html__('All families', 'uc-expo').'</option>';
        $families = get_posts([
            'post_type' => self::CPT_FAMILY,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        foreach ($families as $family) {
            echo '<option value="'.esc_attr($family->ID).'" '.selected($family_id, $family->ID, false).'>'.esc_html($family->post_title).'</option>';
        }
        echo '</select></label> ';
        if ($checkin_id) echo '<input type="hidden" name="checkin" value="'.esc_attr($checkin_id).'" />';
        submit_button(__('Load', 'uc-expo'), 'secondary', '', false);
        echo '<button type="button" class="button button-primary" onclick="window.print()">'.esc_html__('Print', 'uc-expo').'</button>';
        echo '</form>';
        $this->render_print_content($service_id, $family_id, false, $checkin_id);
        echo '</div></div>';
    }

    private function render_print_content(int $service_id, int $family_id, bool $is_shortcode, int $checkin_id = 0): void {
        if (!$service_id) {
            if ($is_shortcode) {
                echo '<p>'.esc_html__('Select a service to print nursery labels.', 'uc-expo').'</p>';
            }
            return;
        }
        $options = $this->get_print_options();
        echo '<div class="uc-label-options">';
        echo '<form method="get">';
        if (!$is_shortcode) {
            echo '<input type="hidden" name="page" value="uc-nursery-print" />';
        }
        echo '<input type="hidden" name="service" value="'.esc_attr($service_id).'" />';
        if ($family_id) echo '<input type="hidden" name="family" value="'.esc_attr($family_id).'" />';
        if ($checkin_id) echo '<input type="hidden" name="checkin" value="'.esc_attr($checkin_id).'" />';
        echo '<label>'.esc_html__('Label Size', 'uc-expo').' <select name="label_size">';
        foreach ($options['sizes'] as $key => $label) {
            $current = sanitize_key($_GET['label_size'] ?? '2x3');
            echo '<option value="'.esc_attr($key).'" '.selected($current, $key, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label> ';
        echo '<label>'.esc_html__('Columns', 'uc-expo').' <input type="number" name="columns" value="'.esc_attr(intval($_GET['columns'] ?? 2)).'" min="1" max="4" /></label> ';
        echo '<label>'.esc_html__('Bleed (px)', 'uc-expo').' <input type="number" name="bleed" value="'.esc_attr(intval($_GET['bleed'] ?? 12)).'" min="0" max="48" /></label> ';
        submit_button(__('Apply', 'uc-expo'), 'secondary', '', false);
        echo '</form>';
        echo '</div>';
        $checkins = $this->get_print_checkins($service_id, $family_id, $checkin_id);
        if (!$checkins) {
            echo '<p>'.esc_html__('No active check-ins for this selection.', 'uc-expo').'</p>';
            return;
        }
        $current_size = sanitize_key($_GET['label_size'] ?? '2x3');
        $columns = max(1, min(4, intval($_GET['columns'] ?? 2)));
        $bleed = max(0, min(48, intval($_GET['bleed'] ?? 12)));
        $class = 'label-' . $current_size;
        echo '<div class="uc-label-grid '.$class.' columns-'.$columns.' bleed-'.$bleed.'">';
        foreach ($checkins as $row) {
            $tokens = get_transient($this->token_transient_key($row['id']));
            $child_token = $tokens['child'] ?? null;
            $pickup_token = $tokens['pickup'] ?? null;
            $missing_msg = __('Token unavailable. Regenerate to print.', 'uc-expo');
            if (!$child_token) {
                // tokens not cached; offer regenerate prompt
                $child_token = $missing_msg;
            }
            $pickup_url = ($pickup_token) ? UC_Expo_QR_Checkins::instance()->nursery_qr_url('pickup', $row['id'], $pickup_token) : '';
            $child_url  = ($child_token !== $missing_msg) ? UC_Expo_QR_Checkins::instance()->nursery_qr_url('child', $row['id'], $child_token) : '';
            echo '<div class="uc-label-card">';
            echo '<h2>'.esc_html($row['child']).'</h2>';
            if ($row['allergies']) {
                echo '<p class="uc-label-allergy"><strong>'.esc_html__('Allergies', 'uc-expo').':</strong> '.esc_html($row['allergies']).'</p>';
            }
            echo '<p><strong>'.esc_html__('Service', 'uc-expo').':</strong> '.esc_html($row['service_label']).'</p>';
            echo '<p><strong>'.esc_html__('Time', 'uc-expo').':</strong> '.esc_html($row['service_time']).'</p>';
            if ($child_url) {
                echo '<div class="uc-label-qr" data-url="'.esc_attr($child_url).'"></div>';
            } else {
                echo '<p>'.esc_html__('No QR available. Regenerate tokens.', 'uc-expo').'</p>';
            }
            if ($pickup_url) {
                echo '<small>'.esc_html__('Guardian QR ready', 'uc-expo').'</small>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private function get_print_checkins(int $service_id, int $family_id, int $checkin_id = 0): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHECKINS;
        $this->expire_records();
        $sql = "SELECT id, child_id, service_id FROM $table WHERE service_id = %d AND status = %s";
        $args = [$service_id, self::STATUS_CHECKED_IN];
        if ($family_id) {
            $sql .= " AND family_id = %d";
            $args[] = $family_id;
        }
        if ($checkin_id) {
            $sql .= " AND id = %d";
            $args[] = $checkin_id;
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $child = get_post((int) $row['child_id']);
            $service = get_post((int) $row['service_id']);
            if (!$child || !$service) continue;
            $out[] = [
                'id' => (int) $row['id'],
                'child' => $child->post_title,
                'allergies' => get_post_meta($child->ID, self::META_CHILD_ALLERGY, true),
                'service_label' => get_post_meta($service->ID, self::META_SERVICE_LABEL, true) ?: $service->post_title,
                'service_time' => $this->format_service_time($service->ID),
            ];
        }
        return $out;
    }

    private function get_print_options(): array {
        return [
            'sizes' => [
                '2x3' => __('2″ × 3″', 'uc-expo'),
                '3x4' => __('3″ × 4″', 'uc-expo'),
            ],
        ];
    }

    private function format_service_time(int $service_id): string {
        $start = get_post_meta($service_id, self::META_SERVICE_START, true);
        $end   = get_post_meta($service_id, self::META_SERVICE_END, true);
        if ($start && $end) {
            $s = date_i18n(get_option('time_format'), strtotime($start));
            $e = date_i18n(get_option('time_format'), strtotime($end));
            return $s . ' – ' . $e;
        }
        if ($start) {
            return date_i18n(get_option('time_format'), strtotime($start));
        }
        return __('Time TBD', 'uc-expo');
    }
}
