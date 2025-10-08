<?php
if (!defined('ABSPATH')) exit;

class UC_Expo_QR_Admin {
    private static $instance = null;
    public static function instance() { if (!self::$instance) self::$instance = new self(); return self::$instance; }

    private function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue($hook) {
        if (strpos($hook, 'uc-expo-qr') === false) return;
        wp_enqueue_style('uc-expo-qr-admin', UC_EXPO_QR_URL . 'assets/admin.css', [], UC_Expo_QR_Checkins::VERSION);
        wp_enqueue_script('uc-expo-qrcode-lib', 'https://unpkg.com/qrcodejs@1.0.0/qrcode.min.js', [], '1.0.0', true);
        wp_enqueue_script('uc-expo-qr-admin', UC_EXPO_QR_URL . 'assets/admin.js', ['uc-expo-qrcode-lib'], UC_Expo_QR_Checkins::VERSION, true);
        wp_localize_script('uc-expo-qr-admin', 'UCExpoQR', [
            'printNote' => __('Use your browser’s Print dialog for printable QR sheets.', 'uc-expo'),
            'confirmDelete' => __('Delete selected check-ins? This cannot be undone.', 'uc-expo'),
        ]);
    }

    public function menu() {
        add_menu_page(__('Expo Check-ins', 'uc-expo'), __('Expo Check-ins', 'uc-expo'),'manage_options','uc-expo-qr',[$this,'render_settings'],'dashicons-tickets-alt',56);
        add_submenu_page('uc-expo-qr', __('Settings', 'uc-expo'), __('Settings', 'uc-expo'), 'manage_options', 'uc-expo-qr', [$this, 'render_settings']);
        add_submenu_page('uc-expo-qr', __('QR Printer', 'uc-expo'), __('QR Printer', 'uc-expo'), 'manage_options', 'uc-expo-qr-printer', [$this, 'render_qr_printer']);
        add_submenu_page('uc-expo-qr', __('Check-in Log', 'uc-expo'), __('Check-in Log', 'uc-expo'), 'manage_options', 'uc-expo-qr-log', [$this, 'render_log']);
        add_submenu_page('uc-expo-qr', __('Leaderboard', 'uc-expo'), __('Leaderboard', 'uc-expo'), 'manage_options', 'uc-expo-qr-leaderboard', [UC_Expo_QR_Leaderboard::instance(), 'render_admin']);
        add_submenu_page('uc-expo-qr', __('User Guide', 'uc-expo'), __('User Guide', 'uc-expo'), 'manage_options', 'uc-expo-qr-docs', [UC_Expo_QR_Docs::instance(), 'render']);
    }

    public function register_settings() {
        register_setting('uc_expo_qr', UC_Expo_QR_Checkins::OPTION_EVENT, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('uc_expo_qr', UC_Expo_QR_Checkins::OPTION_SECRET, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('uc_expo_qr', UC_Expo_QR_Nursery::OPTION_ENABLE, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox']]);

        add_settings_section('uc_expo_qr_main', __('General', 'uc-expo'), function(){
            echo '<p>'.esc_html__('Set the current event id (e.g., 2025-ATL). Use "Rotate Secret" to invalidate old QR signatures and generate new ones.', 'uc-expo').'</p>';
        }, 'uc_expo_qr');

        add_settings_field('event_id', __('Current Event ID', 'uc-expo'), function(){
            $val = esc_attr( get_option(UC_Expo_QR_Checkins::OPTION_EVENT, (string) date('Y')) );
            echo '<input type="text" name="'.esc_attr(UC_Expo_QR_Checkins::OPTION_EVENT).'" value="'.$val.'" class="regular-text" />';
        }, 'uc_expo_qr', 'uc_expo_qr_main');

        add_settings_field('secret', __('QR Signing Secret', 'uc-expo'), function(){
            $val = esc_attr( get_option(UC_Expo_QR_Checkins::OPTION_SECRET, '') );
            echo '<input type="text" readonly value="'.$val.'" class="regular-text code" />';
            submit_button(__('Rotate Secret', 'uc-expo'), 'secondary', 'uc_expo_rotate_secret', false, ['style'=>'margin-left:10px']);
        }, 'uc_expo_qr', 'uc_expo_qr_main');

        add_settings_field('nursery_mode', __('Nursery Mode', 'uc-expo'), function(){
            $enabled = UC_Expo_QR_Nursery::instance()->is_enabled();
            echo '<label><input type="checkbox" name="'.esc_attr(UC_Expo_QR_Nursery::OPTION_ENABLE).'" value="1" '.checked($enabled, true, false).' /> '.esc_html__('Enable UC Church Nursery check-ins (beta)', 'uc-expo').'</label>';
        }, 'uc_expo_qr', 'uc_expo_qr_main');
    }

    public function sanitize_checkbox($value) {
        return $value ? 1 : 0;
    }

    public function render_settings() {
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['uc_expo_rotate_secret']) && check_admin_referer('uc_expo_qr-options')) {
            update_option(UC_Expo_QR_Checkins::OPTION_SECRET, wp_generate_password(64, true, true));
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Secret rotated. Reprint QR codes.', 'uc-expo').'</p></div>';
        }
        echo '<div class="wrap uc-app"><div class="uc-app-header"><h1>'.esc_html__('Expo Check-ins — Settings', 'uc-expo').'</h1></div>';
        echo '<div class="uc-card"><form method="post" action="options.php">';
        settings_fields('uc_expo_qr'); wp_nonce_field('uc_expo_qr-options'); do_settings_sections('uc_expo_qr'); submit_button();
        echo '</form></div></div>';
    }

    public function render_qr_printer() {
        if (!current_user_can('manage_options')) return;
        $event_id = UC_Expo_QR_Checkins::instance()->get_current_event_id();
        $map = UC_Expo_QR_Checkins::instance()->supported_post_types();

        $type = sanitize_key($_GET['type'] ?? 'exhibitors');
        $type = array_key_exists($type, $map) ? $type : 'exhibitors';
        $search = sanitize_text_field($_GET['s'] ?? ''); $paged  = max(1, intval($_GET['paged'] ?? 1)); $ppp = 30;

        $args = [ 'post_type'=>$type, 'post_status'=>'publish', 's'=>$search, 'posts_per_page'=>$ppp, 'paged'=>$paged, 'orderby'=>'title', 'order'=>'ASC' ];
        $q = new WP_Query($args);

        echo '<div class="wrap uc-app"><div class="uc-app-header"><h1>'.esc_html__('QR Printer', 'uc-expo').'</h1></div>';
        echo '<form class="uc-toolbar" method="get">';
        echo '<input type="hidden" name="page" value="uc-expo-qr-printer" />';
        echo '<select name="type">';
        foreach ($map as $pt => $seg) { echo '<option value="'.esc_attr($pt).'" '.selected($pt, $type,false).'>'.esc_html(ucfirst($pt)).'</option>'; }
        echo '</select>';
        echo '<input type="search" name="s" value="'.esc_attr($search).'" placeholder="'.esc_attr__('Search by title…','uc-expo').'" />';
        submit_button(__('Filter'), 'secondary', '', false);
        echo '<button type="button" class="button button-primary" onclick="window.print()">'.esc_html__('Print', 'uc-expo').'</button>';
        echo '</form>';

        echo '<div class="uc-qr-grid">';
        if ($q->have_posts()) {
            while ($q->have_posts()) { $q->the_post();
                $post_id = get_the_ID(); $url = UC_Expo_QR_Checkins::instance()->qr_url_for_post($post_id, $type, $event_id); $thumb = get_the_post_thumbnail_url($post_id, 'medium');
                echo '<div class="uc-qr-card">';
                if ($thumb) echo '<div class="uc-qr-thumb" style="background-image:url('.esc_url($thumb).')"></div>';
                echo '<div class="uc-qr-img" data-url="'.esc_attr($url).'" data-size="220"></div>';
                echo '<div class="uc-qr-meta"><strong>'.esc_html(get_the_title()).'</strong><br/><small>'.esc_html(ucfirst($type)).'</small><br/><code>'.esc_html($url).'</code></div>';
                echo '</div>';
            } wp_reset_postdata();
        } else { echo '<p>'.esc_html__('No items found.', 'uc-expo').'</p>'; }
        echo '</div>';

        $total_pages = $q->max_num_pages ?: 1;
        if ($total_pages > 1) {
            $base = add_query_arg(['paged' => '%#%', 's' => $search, 'type'=>$type]);
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([ 'base'=>$base, 'format'=>'', 'prev_text'=>__('&laquo;'), 'next_text'=>__('&raquo;'), 'total'=>$total_pages, 'current'=>$paged ]);
            echo '</div></div>';
        }
        echo '</div>';
    }

    public function render_log() {
        if (!current_user_can('manage_options')) return;
        // CSV export first (stream + exit)
        if (!empty($_GET['uc_expo_export']) && check_admin_referer('uc_expo_qr_log')) {
            $this->export_csv_now(); exit;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        require_once UC_EXPO_QR_DIR . 'includes/class-list-table.php';

        $list = new UC_Expo_QR_List_Table();

        // Deletes (row/bulk)
        $action = $list->current_action();
        if ($action === 'delete' && check_admin_referer('uc_expo_qr_log')) {
            $ids = [];
            if (!empty($_REQUEST['checkin'])) $ids = array_map('absint', (array) $_REQUEST['checkin']);
            elseif (!empty($_GET['id'])) $ids = [absint($_GET['id'])];
            $deleted = 0;
            if ($ids) {
                global $wpdb;
                $table = UC_Expo_QR_Checkins::instance()->table_name();
                foreach ($ids as $id) { $ok = $wpdb->delete($table, ['id'=>$id], ['%d']); if ($ok) $deleted++; }
                do_action('uc_expo_checkin_deleted', $ids);
            }
            $redirect = remove_query_arg(['action','action2','id','checkin','_wpnonce']); $redirect = add_query_arg(['deleted'=>$deleted], $redirect);
            wp_safe_redirect($redirect); exit;
        }

        $list->prepare_items();

        echo '<div class="wrap uc-app"><div class="uc-app-header"><h1>'.esc_html__('Check-in Log', 'uc-expo').'</h1></div>';
        if (!empty($_GET['deleted'])) { $d = (int) $_GET['deleted']; echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(sprintf(_n('%d row deleted.', '%d rows deleted.', $d, 'uc-expo'), $d)).'</p></div>'; }
        echo '<form method="get" class="uc-card uc-list">';
        echo '<input type="hidden" name="page" value="uc-expo-qr-log" />'; wp_nonce_field('uc_expo_qr_log');
        $list->search_box(__('Search users/titles', 'uc-expo'), 'uc-expo-qr'); $list->views(); $list->display(); echo '</form>';
        $export_url = wp_nonce_url(add_query_arg(['uc_expo_export'=>1]), 'uc_expo_qr_log');
        echo '<p><a href="'.esc_url($export_url).'" class="button button-secondary">'.esc_html__('Export CSV', 'uc-expo').'</a></p>';
        echo '</div>';
    }

    private function export_csv_now() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', '', ['response'=>403]);
        global $wpdb;
        $table = UC_Expo_QR_Checkins::instance()->table_name();

        $search  = trim($_GET['s'] ?? '');
        $event   = trim($_GET['event'] ?? '');
        $etype   = sanitize_key($_GET['etype'] ?? '');
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to   = sanitize_text_field($_GET['date_to'] ?? '');

        $where = '1=1'; $params = [];
        if ($event !== '') { $where .= ' AND event_id = %s'; $params[] = $event; }
        if ($etype !== '') { $where .= ' AND entity_type = %s'; $params[] = $etype; }
        if ($date_from) { $where .= ' AND created_at >= %s'; $params[] = $date_from . ' 00:00:00'; }
        if ($date_to)   { $where .= ' AND created_at <= %s'; $params[] = $date_to . ' 23:59:59'; }

        if ($search !== '') {
            $user_ids = get_users([ 'search' => '*' . esc_attr($search) . '*', 'fields' => 'ID', 'search_columns' => ['user_email','display_name','user_login'] ]);
            if (!empty($user_ids)) { $where .= ' AND user_id IN (' . implode(',', array_map('intval', $user_ids)) . ')'; }
            else {
                $posts = get_posts([ 'post_type'=>array_keys(UC_Expo_QR_Checkins::instance()->supported_post_types()), 's'=>$search, 'numberposts'=>200, 'fields'=>'ids' ]);
                if (!empty($posts)) { $where .= ' AND entity_id IN (' . implode(',', array_map('intval', $posts)) . ')'; } else { $where .= ' AND 1=0'; }
            }
        }

        $sql = "SELECT id, user_id, entity_type, entity_id, event_id, INET6_NTOA(ip) AS ip, user_agent, created_at FROM $table WHERE $where ORDER BY created_at DESC LIMIT 200000";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=uc-expo-checkins-' . date('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','user_id','user','user_email','entity_type','entity_id','entity_title','event_id','ip','user_agent','created_at']);
        foreach ($rows as $r) {
            $u = $r['user_id'] ? get_user_by('id', (int)$r['user_id']) : null;
            $p = $r['entity_id'] ? get_post((int)$r['entity_id']) : null;
            fputcsv($out, [
                $r['id'], $r['user_id'], ($u?$u->display_name:''), ($u?$u->user_email:''), $r['entity_type'], $r['entity_id'], ($p?$p->post_title:''), $r['event_id'], $r['ip'], $r['user_agent'], $r['created_at'],
            ]);
        }
        fclose($out);
    }
}
