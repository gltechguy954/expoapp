<?php
if (!defined('ABSPATH')) exit;

class UC_Expo_QR_List_Table extends WP_List_Table {

    private $data = [];
    private $total_items = 0;

    public function __construct() {
        parent::__construct([ 'singular' => 'checkin', 'plural' => 'checkins', 'ajax' => false ]);
    }

    public function get_columns() {
        return [
            'cb'          => '<input type="checkbox" />',
            'created_at'  => __('Date', 'uc-expo'),
            'user'        => __('User', 'uc-expo'),
            'entity'      => __('Item', 'uc-expo'),
            'event_id'    => __('Event', 'uc-expo'),
            'ip'          => __('IP', 'uc-expo'),
            'user_agent'  => __('User Agent', 'uc-expo'),
            'actions'     => __('Actions', 'uc-expo'),
        ];
    }

    protected function get_sortable_columns() {
        return [ 'created_at' => ['created_at', true] ];
    }

    protected function column_cb($item) {
        return '<input type="checkbox" name="checkin[]" value="'.intval($item['id']).'" />';
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'created_at': return esc_html($item['created_at']);
            case 'event_id':   return esc_html($item['event_id']);
            case 'ip':         return esc_html($item['ip']);
            case 'user_agent': return '<span class="uc-ua" title="'.esc_attr($item['user_agent']).'">'.esc_html(wp_html_excerpt($item['user_agent'], 60, '…')).'</span>';
            case 'actions':    return $this->row_actions_html($item);
        }
        return '';
    }

    private function row_actions_html($item) {
        $del = wp_nonce_url(add_query_arg(['action'=>'delete','id'=>$item['id']]), 'uc_expo_qr_log');
        return '<a href="'.$del.'" class="button button-small button-link-delete">'.__('Delete', 'uc-expo').'</a>';
    }

    public function column_user($item) {
        if (!$item['user_id']) return '-';
        $u = get_user_by('id', $item['user_id']);
        if (!$u) return '-';
        $url = esc_url( admin_url('user-edit.php?user_id=' . $u->ID) );
        return '<a href="'.$url.'">'.esc_html($u->display_name).' ('.$u->user_email.')</a>';
    }

    public function column_entity($item) {
        $title = get_the_title($item['entity_id']);
        $url   = get_edit_post_link($item['entity_id'], '');
        $badge = '<span class="uc-badge">'.esc_html(ucfirst($item['entity_type'])).'</span> ';
        if (!$title) $title = '#'.$item['entity_id'];
        return $badge . '<a href="'.esc_url($url).'">'.esc_html($title).'</a>';
    }

    public function get_bulk_actions() {
        return [ 'delete' => __('Delete', 'uc-expo') ];
    }

    public function prepare_items() {
        global $wpdb;
        $table = UC_Expo_QR_Checkins::instance()->table_name();

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $orderby = sanitize_key($_GET['orderby'] ?? 'created_at');
        $order   = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $search  = trim($_GET['s'] ?? '');
        $event   = trim($_GET['event'] ?? '');
        $etype   = sanitize_key($_GET['etype'] ?? '');
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to   = sanitize_text_field($_GET['date_to'] ?? '');

        $where = '1=1';
        $params = [];

        if ($event !== '') { $where .= ' AND event_id = %s'; $params[] = $event; }
        if ($etype !== '') { $where .= ' AND entity_type = %s'; $params[] = $etype; }
        if ($date_from) { $where .= ' AND created_at >= %s'; $params[] = $date_from . ' 00:00:00'; }
        if ($date_to)   { $where .= ' AND created_at <= %s'; $params[] = $date_to . ' 23:59:59'; }

        if ($search !== '') {
            $user_ids = get_users([ 'search' => '*' . esc_attr($search) . '*', 'fields' => 'ID', 'search_columns' => ['user_email', 'display_name', 'user_login'] ]);
            if (!empty($user_ids)) {
                $where .= ' AND user_id IN (' . implode(',', array_map('intval', $user_ids)) . ')';
            } else {
                $posts = get_posts([ 'post_type' => array_keys(UC_Expo_QR_Checkins::instance()->supported_post_types()), 's' => $search, 'numberposts' => 200, 'fields' => 'ids' ]);
                if (!empty($posts)) { $where .= ' AND entity_id IN (' . implode(',', array_map('intval', $posts)) . ')'; } else { $where .= ' AND 1=0'; }
            }
        }

        $sql_base = "FROM $table WHERE $where";
        $sql_total = "SELECT COUNT(*) $sql_base";
        $total = (int) $wpdb->get_var($wpdb->prepare($sql_total, $params));

        $sql = "SELECT id, user_id, entity_type, entity_id, event_id, INET6_NTOA(ip) AS ip, user_agent, created_at
                $sql_base ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $params_paged = array_merge($params, [$per_page, $offset]);

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params_paged), ARRAY_A);

        $this->data = $rows ?: [];
        $this->total_items = $total;

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'created_at'];
        $this->items = $this->data;

        $this->set_pagination_args([ 'total_items' => $this->total_items, 'per_page' => $per_page, 'total_pages' => ceil($this->total_items / $per_page) ]);
    }

    public function extra_tablenav($which) {
        if ($which !== 'top') return;
        $event = esc_attr($_GET['event'] ?? '');
        $etype = esc_attr($_GET['etype'] ?? '');
        $date_from = esc_attr($_GET['date_from'] ?? '');
        $date_to   = esc_attr($_GET['date_to'] ?? '');

        echo '<div class="alignleft actions uc-filters">';
        echo '<input type="text" name="event" class="regular-text" placeholder="'.esc_attr__('Event ID','uc-expo').'" value="'.$event.'" /> ';
        echo '<select name="etype"><option value="">'.esc_html__('All Types','uc-expo').'</option>';
        $opts = UC_Expo_QR_Checkins::instance()->supported_post_types();
        foreach ($opts as $pt => $seg) { echo '<option value="'.esc_attr($seg).'" '.selected($seg,$etype,false).'>'.esc_html(ucfirst($pt)).'</option>'; }
        echo '</select> ';
        echo '<input type="date" name="date_from" value="'.$date_from.'" /> ';
        echo '<input type="date" name="date_to" value="'.$date_to.'" /> ';
        submit_button(__('Filter'), '', 'filter_action', false);
        echo '</div>';
    }
}
