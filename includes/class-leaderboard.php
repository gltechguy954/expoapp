<?php
if (!defined('ABSPATH')) exit;

class UC_Expo_QR_Leaderboard {
    const OPT = 'uc_expo_lb_settings';
    const USER_META_OPTOUT = 'uc_expo_lb_optout';
    private static $instance = null;

    public static function instance(){ if (!self::$instance) self::$instance = new self(); return self::$instance; }

    private function __construct() {
        add_shortcode('uc_expo_leaderboard', [$this, 'sc_leaderboard']);
        add_shortcode('uc_expo_my_checkins', [$this, 'sc_my_checkins']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('uc_expo_checkin_recorded', [$this, 'bust_cache']);
        add_action('uc_expo_checkin_deleted', [$this, 'bust_cache']);
    }

    public static function defaults() {
        return [
            'default_scope' => 'event', // event|all
            'points' => [ 'exhibitor'=>1, 'session'=>1, 'panel'=>1, 'speaker'=>1 ],
            'name_format' => 'first_last_initial', // or display_or_username
            'exclude_roles' => [],
            'respect_optout' => 1,
        ];
    }

    public static function get_settings() {
        $s = get_option(self::OPT, []);
        return wp_parse_args($s, self::defaults());
    }

    public function register_settings() {
        register_setting('uc_expo_lb', self::OPT, ['type'=>'array', 'sanitize_callback'=>[$this,'sanitize']]);
    }

    public function sanitize($s) {
        $out = [];
        $out['default_scope'] = in_array($s['default_scope'] ?? 'event', ['event','all'], true) ? $s['default_scope'] : 'event';
        $out['points'] = [
            'exhibitor' => max(0, intval($s['points']['exhibitor'] ?? 1)),
            'session'   => max(0, intval($s['points']['session'] ?? 1)),
            'panel'     => max(0, intval($s['points']['panel'] ?? 1)),
            'speaker'   => max(0, intval($s['points']['speaker'] ?? 1)),
        ];
        $nf = $s['name_format'] ?? 'first_last_initial';
        $out['name_format'] = in_array($nf, ['first_last_initial','display_or_username'], true) ? $nf : 'first_last_initial';
        $out['exclude_roles'] = array_values(array_filter(array_map('sanitize_key', (array)($s['exclude_roles'] ?? []))));
        $out['respect_optout'] = empty($s['respect_optout']) ? 0 : 1;
        return $out;
    }

    public function render_admin() {
        if (!current_user_can('manage_options')) return;
        $s = self::get_settings();
        echo '<div class="wrap uc-app"><div class="uc-app-header"><h1>'.esc_html__('Leaderboard & Gamification', 'uc-expo').'</h1></div>';
        echo '<div class="uc-card">';
        echo '<form method="post" action="options.php">';
        settings_fields('uc_expo_lb');
        echo '<table class="form-table"><tbody>';

        echo '<tr><th>'.esc_html__('Default Scope', 'uc-expo').'</th><td>';
        echo '<select name="'.self::OPT.'[default_scope]">';
        echo '<option value="event" '.selected($s['default_scope'],'event',false).'>'.esc_html__('Current Event Only','uc-expo').'</option>';
        echo '<option value="all" '.selected($s['default_scope'],'all',false).'>'.esc_html__('All-Time','uc-expo').'</option>';
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th>'.esc_html__('Points per Check-in', 'uc-expo').'</th><td>';
        foreach (['exhibitor','session','panel','speaker'] as $t) {
            $v = intval($s['points'][$t] ?? 1);
            echo '<label style="margin-right:10px">'.esc_html(ucfirst($t)).' <input type="number" min="0" name="'.self::OPT.'[points]['.$t.']" value="'.esc_attr($v).'" class="small-text" /></label>';
        }
        echo '</td></tr>';

        echo '<tr><th>'.esc_html__('Name Format', 'uc-expo').'</th><td>';
        echo '<label><input type="radio" name="'.self::OPT.'[name_format]" value="first_last_initial" '.checked($s['name_format'],'first_last_initial',false).' /> '.esc_html__('First + Last initial (fallback username)','uc-expo').'</label><br/>';
        echo '<label><input type="radio" name="'.self::OPT.'[name_format]" value="display_or_username" '.checked($s['name_format'],'display_or_username',false).' /> '.esc_html__('Display name (fallback username)','uc-expo').'</label>';
        echo '</td></tr>';

        echo '<tr><th>'.esc_html__('Exclude Roles', 'uc-expo').'</th><td>';
        global $wp_roles;
        foreach ($wp_roles->roles as $role_key => $role) {
            $ck = in_array($role_key, (array)$s['exclude_roles'], true) ? 'checked' : '';
            echo '<label style="display:inline-block;min-width:160px;margin-right:8px"><input type="checkbox" name="'.self::OPT.'[exclude_roles][]" value="'.esc_attr($role_key).'" '.$ck.' /> '.esc_html($role['name']).'</label>';
        }
        echo '</td></tr>';

        echo '<tr><th>'.esc_html__('Respect User Opt-out', 'uc-expo').'</th><td>';
        echo '<label><input type="checkbox" name="'.self::OPT.'[respect_optout]" value="1" '.checked($s['respect_optout'],1,false).' /> '.esc_html__('Hide users who opt out of public leaderboard','uc-expo').'</label>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form></div></div>';
    }

    public function key($args) { return 'uc_expo_lb_' . md5(wp_json_encode($args) . '|' . wp_json_encode(self::get_settings())); }
    public function bust_cache() { global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_uc_expo_lb_%' OR option_name LIKE '_transient_timeout_uc_expo_lb_%'"); }

    public static function format_name($user, $s) {
        if (!$user) return '';
        if ($s['name_format']==='display_or_username') return $user->display_name ?: $user->user_login;
        $fn = get_user_meta($user->ID, 'first_name', true);
        $ln = get_user_meta($user->ID, 'last_name', true);
        if ($fn || $ln) {
            $li = $ln ? strtoupper(mb_substr($ln,0,1)) . '.' : '';
            return trim($fn . ' ' . $li);
        }
        return $user->user_login;
    }

    public function sc_leaderboard($atts) {
        $a = shortcode_atts([ 'event' => 'current', 'scope' => '', 'top' => '3', 'per_page' => '50', 'search' => '1' ], $atts, 'uc_expo_leaderboard');
        $s = self::get_settings();
        $scope = $a['scope'] ?: $s['default_scope'];
        $event_id = ($a['event']==='current' || !$a['event']) ? UC_Expo_QR_Checkins::instance()->get_current_event_id() : sanitize_text_field($a['event']);
        $top = max(0, intval($a['top'])); $per = max(1, intval($a['per_page'])); $show_search = $a['search'] === '1';

        $search_q = isset($_GET['lb_s']) ? sanitize_text_field($_GET['lb_s']) : '';

        $cache_key = $this->key(['lb','scope'=>$scope,'event'=>$event_id,'search'=>$search_q]);
        $rows = get_transient($cache_key);
        if ($rows === false) {
            $rows = $this->compute_leaderboard($scope, $event_id, $s);
            set_transient($cache_key, $rows, 5*MINUTE_IN_SECONDS);
        }

        $filtered = [];
        foreach ($rows as $r) {
            $user = get_user_by('id', (int)$r['user_id']);
            if (!$user) continue;
            if ($this->excluded_by_role($user, $s)) continue;
            if ($s['respect_optout'] && get_user_meta($user->ID, self::USER_META_OPTOUT, true)) continue;

            $name = self::format_name($user, $s);
            if ($search_q && stripos($name, $search_q) === false) continue;

            $filtered[] = ['user'=>$user, 'name'=>$name, 'points'=>$r['points'], 'last_time'=>$r['last_time']];
        }

        usort($filtered, function($a,$b){
            if ($a['points'] === $b['points']) return strcmp($a['last_time'], $b['last_time']);
            return ($a['points'] > $b['points']) ? -1 : 1;
        });

        $page = max(1, intval($_GET['lb_page'] ?? 1));
        $offset = ($page-1)*$per; $total = count($filtered);
        $podium = array_slice($filtered, 0, $top);
        $table_rows = array_slice($filtered, $offset, $per);

        ob_start();
        ?>
        <div class="uc-lb">
          <div class="uc-lb-header">
            <?php if ($show_search): ?>
              <form method="get" class="uc-lb-search">
                <input type="text" name="lb_s" value="<?php echo esc_attr($search_q); ?>" placeholder="<?php esc_attr_e('Search participants…','uc-expo'); ?>" />
                <button type="submit"><?php esc_html_e('Search','uc-expo'); ?></button>
              </form>
            <?php endif; ?>
          </div>

          <?php if ($top > 0 && !empty($podium)): ?>
          <div class="uc-lb-podium">
            <?php foreach ($podium as $i=>$p): ?>
              <div class="uc-lb-card">
                <div class="uc-lb-medal"><?php echo $i===0?'🥇':($i===1?'🥈':'🥉'); ?></div>
                <div class="uc-lb-name"><?php echo esc_html($p['name']); ?></div>
                <div class="uc-lb-points"><?php echo intval($p['points']); ?> pts</div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="uc-lb-table">
            <table>
              <thead><tr><th><?php esc_html_e('Participant','uc-expo'); ?></th><th><?php esc_html_e('Points','uc-expo'); ?></th></tr></thead>
              <tbody>
                <?php if (empty($table_rows)): ?>
                  <tr><td colspan="2"><?php esc_html_e('No results.','uc-expo'); ?></td></tr>
                <?php else: foreach ($table_rows as $r): ?>
                  <tr><td><?php echo esc_html($r['name']); ?></td><td><?php echo intval($r['points']); ?></td></tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <?php
            $pages = max(1, ceil($total/$per));
            if ($pages > 1):
          ?>
            <div class="uc-lb-pagination">
              <?php for ($i=1;$i<=$pages;$i++):
                $url = add_query_arg(['lb_page'=>$i, 'lb_s'=>$search_q]);
              ?>
                <a class="<?php echo $i==$page?'current':''; ?>" href="<?php echo esc_url($url); ?>"><?php echo $i; ?></a>
              <?php endfor; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function compute_leaderboard($scope, $event_id, $s) {
        global $wpdb;
        $table = UC_Expo_QR_Checkins::instance()->table_name();
        $p = $s['points'];
        $where = "WHERE user_id > 0";
        $params = [];
        if ($scope === 'event') { $where .= " AND event_id = %s"; $params[] = $event_id; }

        $sql = "
            SELECT user_id,
                SUM(CASE entity_type
                        WHEN 'exhibitor' THEN %d
                        WHEN 'session'   THEN %d
                        WHEN 'panel'     THEN %d
                        WHEN 'speaker'   THEN %d
                    END) AS points,
                MAX(created_at) AS last_time
            FROM $table
            $where
            GROUP BY user_id
            HAVING points > 0
        ";
        $args = array_merge([$p['exhibitor'], $p['session'], $p['panel'], $p['speaker']], $params);
        $prepared = $wpdb->prepare($sql, $args);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return $rows ?: [];
    }

    private function excluded_by_role($user, $s) {
        if (empty($s['exclude_roles'])) return false;
        foreach ((array)$user->roles as $r) if (in_array($r, (array)$s['exclude_roles'], true)) return true;
        return false;
    }

    public function sc_my_checkins($atts) {
        if (!is_user_logged_in()) return '<p>'.esc_html__('Please log in to view your check-ins.','uc-expo').'</p>';
        $u = wp_get_current_user();

        if (!empty($_POST['uc_lb_optout_nonce']) && wp_verify_nonce($_POST['uc_lb_optout_nonce'], 'uc_lb_optout')) {
            $opt = empty($_POST['uc_lb_optout']) ? '0' : '1';
            update_user_meta($u->ID, self::USER_META_OPTOUT, $opt);
            $this->bust_cache();
        }
        $optout = get_user_meta($u->ID, self::USER_META_OPTOUT, true) ? 1 : 0;

        $etype = sanitize_key($_GET['type'] ?? '');
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to   = sanitize_text_field($_GET['date_to'] ?? '');
        $page = max(1, intval($_GET['mc_page'] ?? 1));
        $per  = 20; $offset = ($page-1)*$per;

        global $wpdb;
        $table = UC_Expo_QR_Checkins::instance()->table_name();

        $where = "WHERE user_id=%d";
        $params = [ $u->ID ];
        if ($etype && in_array($etype, ['exhibitor','session','panel','speaker'], true)) { $where .= " AND entity_type=%s"; $params[] = $etype; }
        if ($date_from) { $where .= " AND created_at >= %s"; $params[] = $date_from . ' 00:00:00'; }
        if ($date_to)   { $where .= " AND created_at <= %s"; $params[] = $date_to . ' 23:59:59'; }

        $total = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $table $where", $params) );
        $rows  = $wpdb->get_results( $wpdb->prepare("SELECT entity_type, entity_id, event_id, created_at FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge($params, [$per, $offset]) ), ARRAY_A );

        ob_start();
        ?>
        <div class="uc-my-checkins">
          <form method="post" class="uc-optout">
            <?php wp_nonce_field('uc_lb_optout','uc_lb_optout_nonce'); ?>
            <label><input type="checkbox" name="uc_lb_optout" value="1" <?php checked($optout,1); ?> /> <?php esc_html_e('Opt-out of public leaderboard','uc-expo'); ?></label>
            <button type="submit" class="button"><?php esc_html_e('Save','uc-expo'); ?></button>
          </form>

          <form method="get" class="uc-filters">
            <label><?php esc_html_e('Type','uc-expo'); ?>
              <select name="type">
                <option value=""><?php esc_html_e('All','uc-expo'); ?></option>
                <option value="exhibitor" <?php selected($etype,'exhibitor'); ?>><?php esc_html_e('Exhibitors','uc-expo'); ?></option>
                <option value="session" <?php selected($etype,'session'); ?>><?php esc_html_e('Sessions','uc-expo'); ?></option>
                <option value="panel" <?php selected($etype,'panel'); ?>><?php esc_html_e('Panels','uc-expo'); ?></option>
                <option value="speaker" <?php selected($etype,'speaker'); ?>><?php esc_html_e('Speakers','uc-expo'); ?></option>
              </select>
            </label>
            <label><?php esc_html_e('From','uc-expo'); ?> <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" /></label>
            <label><?php esc_html_e('To','uc-expo'); ?> <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" /></label>
            <button type="submit"><?php esc_html_e('Filter','uc-expo'); ?></button>
          </form>

          <div class="uc-table">
            <table>
              <thead><tr><th><?php esc_html_e('Date','uc-expo'); ?></th><th><?php esc_html_e('Item','uc-expo'); ?></th><th><?php esc_html_e('Event','uc-expo'); ?></th></tr></thead>
              <tbody>
                <?php if (empty($rows)): ?>
                  <tr><td colspan="3"><?php esc_html_e('No check-ins yet.','uc-expo'); ?></td></tr>
                <?php else: foreach ($rows as $r): ?>
                  <tr>
                    <td><?php echo esc_html($r['created_at']); ?></td>
                    <td><span class="uc-badge"><?php echo esc_html(ucfirst($r['entity_type'])); ?></span> <a href="<?php echo esc_url(get_permalink((int)$r['entity_id'])); ?>"><?php echo esc_html(get_the_title((int)$r['entity_id'])); ?></a></td>
                    <td><?php echo esc_html($r['event_id']); ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <?php $pages = max(1, ceil($total/$per)); if ($pages>1): ?>
            <div class="uc-pagination">
              <?php for ($i=1;$i<=$pages;$i++): $url = add_query_arg(['mc_page'=>$i,'type'=>$etype,'date_from'=>$date_from,'date_to'=>$date_to]); ?>
                <a class="<?php echo $i==$page?'current':''; ?>" href="<?php echo esc_url($url); ?>"><?php echo $i; ?></a>
              <?php endfor; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
