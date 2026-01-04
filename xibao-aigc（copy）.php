<?php
/**
 * Plugin Name: XIBAO-AIGC V1.1 (Nano Banana + Sora2 + myCred)
 * Description: Cyber Neon AI generator for WordPress. Image (txt2img/img2img) + Video job queue + myCred credits + User Center.
 * Version: 1.1.0
 * Author: XIBAO-AIGC
 */

if (!defined('ABSPATH')) exit;

class XibaoAIGC_V1 {
  const OPT_GROUP     = 'xibao_aigc_group';
  const NONCE_ACTION  = 'xibao_aigc_nonce';
  const TABLE         = 'xibao_aigc_jobs';
  const CRON_HOOK     = 'xibao_aigc_cleanup_cron';

  public static function init() {
    register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
    register_deactivation_hook(__FILE__, [__CLASS__, 'on_deactivate']);

    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_init', [__CLASS__, 'admin_init']);

    add_shortcode('xibao_aigc', [__CLASS__, 'shortcode_generator']);
    add_shortcode('xibao_video', [__CLASS__, 'shortcode_video']);
    add_shortcode('xibao_aigc_center', [__CLASS__, 'shortcode_center']);

    add_action('wp_ajax_xibao_aigc_run', [__CLASS__, 'ajax_run']);
    add_action('wp_ajax_nopriv_xibao_aigc_run', [__CLASS__, 'ajax_run']);

    add_action('wp_ajax_xibao_aigc_poll', [__CLASS__, 'ajax_poll']);
    add_action('wp_ajax_nopriv_xibao_aigc_poll', [__CLASS__, 'ajax_poll']);

    // Cleanup cron
    add_action(self::CRON_HOOK, [__CLASS__, 'cron_cleanup_jobs']);

    // 可选：如果你的 Sora2 供应商支持回调，可填回调URL给他们
    add_action('rest_api_init', function(){
      register_rest_route('xibao-aigc/v1', '/sora2-hook', [
        'methods'  => 'POST',
        'callback' => [__CLASS__, 'rest_sora2_hook'],
        'permission_callback' => '__return_true'
      ]);
    });
  }

  public static function on_activate() {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;
    $charset = $wpdb->get_charset_collate();

    // ✅ cost_points 改为 DECIMAL(10,1) 以支持 1 位小数
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
      `type` VARCHAR(20) NOT NULL DEFAULT 'image',
      `mode` VARCHAR(20) NOT NULL DEFAULT 'txt2img',
      `model` VARCHAR(80) NOT NULL DEFAULT '',
      `prompt` LONGTEXT NOT NULL,
      `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
      `provider_job_id` VARCHAR(120) DEFAULT NULL,
      `result_url` LONGTEXT DEFAULT NULL,
      `error_msg` LONGTEXT DEFAULT NULL,
      `cost_points` DECIMAL(10,1) NOT NULL DEFAULT 0.0,
      `refunded` TINYINT(1) NOT NULL DEFAULT 0,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      KEY `status` (`status`),
      KEY `provider_job_id` (`provider_job_id`),
      KEY `created_at` (`created_at`)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // ✅ 定时清理：每 10 分钟跑一次（清理“任务缓存保留30分钟”）
    if (!wp_next_scheduled(self::CRON_HOOK)) {
      wp_schedule_event(time() + 60, 'xibao_aigc_10min', self::CRON_HOOK);
    }
  }

  public static function on_deactivate() {
    $ts = wp_next_scheduled(self::CRON_HOOK);
    if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
  }

  /* =========================
   * Admin Settings
   * ========================= */
  public static function admin_menu() {
    add_options_page(
      'XIBAO-AIGC',
      'XIBAO-AIGC',
      'manage_options',
      'xibao-aigc',
      [__CLASS__, 'settings_page']
    );
  }

  public static function admin_init() {
    // Core
    register_setting(self::OPT_GROUP, 'xibao_aigc_base_url');
    register_setting(self::OPT_GROUP, 'xibao_aigc_api_key');

    // Visibility
    register_setting(self::OPT_GROUP, 'xibao_aigc_admin_only');          // 仅管理员可见（前端）
    register_setting(self::OPT_GROUP, 'xibao_aigc_points_decimals');     // 小数位（默认 1）
    register_setting(self::OPT_GROUP, 'xibao_aigc_task_ttl_minutes');    // 任务缓存保留分钟（默认 30）

    // Models list (文本列表)
    register_setting(self::OPT_GROUP, 'xibao_aigc_image_models'); // 逗号分隔
    register_setting(self::OPT_GROUP, 'xibao_aigc_video_models'); // 逗号分隔（展示用）

    // Model switches + gray switches（后台开关+灰度）
    register_setting(self::OPT_GROUP, 'xibao_aigc_model_enable_nano_banana');
    register_setting(self::OPT_GROUP, 'xibao_aigc_model_enable_nano_banana_2');
    register_setting(self::OPT_GROUP, 'xibao_aigc_model_enable_sora2');
    register_setting(self::OPT_GROUP, 'xibao_aigc_model_enable_sora2pro');

    register_setting(self::OPT_GROUP, 'xibao_aigc_model_gray_nano_banana');
    register_setting(self::OPT_GROUP, 'xibao_aigc_model_gray_nano_banana_2');
    register_setting(self::OPT_GROUP, 'xibao_aigc_model_gray_sora2');
    register_setting(self::OPT_GROUP, 'xibao_aigc_model_gray_sora2pro');

    // 成本（图片默认 0，后台可改）
    register_setting(self::OPT_GROUP, 'xibao_aigc_cost_img_1k');
    register_setting(self::OPT_GROUP, 'xibao_aigc_cost_img_2k');
    register_setting(self::OPT_GROUP, 'xibao_aigc_cost_img_4k');

    // 成本（视频：SORA2 / SORA2PRO + 10s / 15s / HD 全部默认 0）
    register_setting(self::OPT_GROUP, 'xibao_aigc_cost_sora2_10');
    register_setting(self::OPT_GROUP, 'xibao_aigc_cost_sora2_15');
    register_setting(self::OPT_GROUP, 'xibao_aigc_cost_sora2_hd');
    register_setting(self::OPT_GROUP, 'xibao_aigc_cost_sora2pro_10');
    register_setting(self::OPT_GROUP, 'xibao_aigc_cost_sora2pro_15');
    register_setting(self::OPT_GROUP, 'xibao_aigc_cost_sora2pro_hd');

    // 备注（你要后台填，前端展示）
    register_setting(self::OPT_GROUP, 'xibao_aigc_note_sora2');
    register_setting(self::OPT_GROUP, 'xibao_aigc_note_sora2pro');

    // 可配置路径
    register_setting(self::OPT_GROUP, 'xibao_aigc_path_img_gen');   // default: /v1/images/generations
    register_setting(self::OPT_GROUP, 'xibao_aigc_path_img_edit');  // default: /v1/images/edits
    register_setting(self::OPT_GROUP, 'xibao_aigc_path_video_gen'); // 你按文档填
    register_setting(self::OPT_GROUP, 'xibao_aigc_path_video_get'); // 你按文档填（支持 {id}）
  }

  public static function settings_page() {
    $base = get_option('xibao_aigc_base_url', 'https://ai.t8star.cn');

    // Defaults
    $admin_only = intval(get_option('xibao_aigc_admin_only', 1));
    $decimals   = intval(get_option('xibao_aigc_points_decimals', 1));
    if ($decimals < 0) $decimals = 0;
    if ($decimals > 4) $decimals = 4;

    $ttl_min = intval(get_option('xibao_aigc_task_ttl_minutes', 30));
    if ($ttl_min < 5) $ttl_min = 5;

    // Models (display list)
    $img_models = get_option('xibao_aigc_image_models', 'nano-banana,nano-banana-2');
    $vid_models = get_option('xibao_aigc_video_models', 'sora2,sora-2-pro');

    // Model switches default: enabled
    $en_nb  = intval(get_option('xibao_aigc_model_enable_nano_banana', 1));
    $en_nb2 = intval(get_option('xibao_aigc_model_enable_nano_banana_2', 1));
    $en_s2  = intval(get_option('xibao_aigc_model_enable_sora2', 1));
    $en_s2p = intval(get_option('xibao_aigc_model_enable_sora2pro', 1));

    // Gray switches default: admin_only = 1 (你要求灰度保留)
    $gr_nb  = intval(get_option('xibao_aigc_model_gray_nano_banana', 0));
    $gr_nb2 = intval(get_option('xibao_aigc_model_gray_nano_banana_2', 0));
    $gr_s2  = intval(get_option('xibao_aigc_model_gray_sora2', 1));
    $gr_s2p = intval(get_option('xibao_aigc_model_gray_sora2pro', 1));

    $p_img_gen  = get_option('xibao_aigc_path_img_gen',  '/v1/images/generations');
    $p_img_edit = get_option('xibao_aigc_path_img_edit', '/v1/images/edits');
    $p_vid_gen  = get_option('xibao_aigc_path_video_gen', '/v1/videos/generations');
    $p_vid_get  = get_option('xibao_aigc_path_video_get', '/v1/videos/tasks/{id}');

    // Costs default all 0（按你要求先 0）
    $c1 = (float)get_option('xibao_aigc_cost_img_1k', 0);
    $c2 = (float)get_option('xibao_aigc_cost_img_2k', 0);
    $c4 = (float)get_option('xibao_aigc_cost_img_4k', 0);

    $s2_10   = (float)get_option('xibao_aigc_cost_sora2_10', 0);
    $s2_15   = (float)get_option('xibao_aigc_cost_sora2_15', 0);
    $s2_hd   = (float)get_option('xibao_aigc_cost_sora2_hd', 0);
    $s2p_10  = (float)get_option('xibao_aigc_cost_sora2pro_10', 0);
    $s2p_15  = (float)get_option('xibao_aigc_cost_sora2pro_15', 0);
    $s2p_hd  = (float)get_option('xibao_aigc_cost_sora2pro_hd', 0);

    $note_s2  = (string)get_option('xibao_aigc_note_sora2', '');
    $note_s2p = (string)get_option('xibao_aigc_note_sora2pro', '');

    $hook = rest_url('xibao-aigc/v1/sora2-hook');

    ?>
    <div class="wrap">
      <h1>XIBAO-AIGC Settings</h1>

      <form method="post" action="options.php">
        <?php settings_fields(self::OPT_GROUP); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Base URL</th>
            <td>
              <input class="regular-text" name="xibao_aigc_base_url" value="<?php echo esc_attr($base); ?>" />
              <p class="description">例如：<code>https://ai.t8star.cn</code>（不要末尾 /）</p>
            </td>
          </tr>
          <tr>
            <th scope="row">API Key</th>
            <td>
              <input class="regular-text" type="password" name="xibao_aigc_api_key" value="<?php echo esc_attr(get_option('xibao_aigc_api_key','')); ?>" />
              <p class="description">你正式交付会换 Key，这里就是预留输入框（已实现）。</p>
            </td>
          </tr>

          <tr><th scope="row"><strong>Access</strong></th><td></td></tr>
          <tr>
            <th scope="row">Admin Only (Front-end)</th>
            <td>
              <label>
                <input type="checkbox" name="xibao_aigc_admin_only" value="1" <?php checked($admin_only, 1); ?> />
                仅管理员可见（非管理员看到提示，不显示生成面板）
              </label>
            </td>
          </tr>
          <tr>
            <th scope="row">Points Decimals</th>
            <td>
              <input type="number" min="0" max="4" name="xibao_aigc_points_decimals" value="<?php echo esc_attr($decimals); ?>" />
              <p class="description">你已确认：默认 1 位小数。</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Task Cache TTL (minutes)</th>
            <td>
              <input type="number" min="5" name="xibao_aigc_task_ttl_minutes" value="<?php echo esc_attr($ttl_min); ?>" />
              <p class="description">你已确认：默认 30 分钟。到期会自动清理历史任务记录。</p>
            </td>
          </tr>

          <tr><th scope="row"><strong>Model Switches + Gray</strong></th><td></td></tr>
          <tr>
            <th scope="row">nano-banana</th>
            <td>
              <label><input type="checkbox" name="xibao_aigc_model_enable_nano_banana" value="1" <?php checked($en_nb, 1); ?> /> 启用</label>
              &nbsp;&nbsp;
              <label><input type="checkbox" name="xibao_aigc_model_gray_nano_banana" value="1" <?php checked($gr_nb, 1); ?> /> 灰度（仅管理员可见）</label>
            </td>
          </tr>
          <tr>
            <th scope="row">nano-banana-2</th>
            <td>
              <label><input type="checkbox" name="xibao_aigc_model_enable_nano_banana_2" value="1" <?php checked($en_nb2, 1); ?> /> 启用</label>
              &nbsp;&nbsp;
              <label><input type="checkbox" name="xibao_aigc_model_gray_nano_banana_2" value="1" <?php checked($gr_nb2, 1); ?> /> 灰度（仅管理员可见）</label>
            </td>
          </tr>
          <tr>
            <th scope="row">sora2</th>
            <td>
              <label><input type="checkbox" name="xibao_aigc_model_enable_sora2" value="1" <?php checked($en_s2, 1); ?> /> 启用</label>
              &nbsp;&nbsp;
              <label><input type="checkbox" name="xibao_aigc_model_gray_sora2" value="1" <?php checked($gr_s2, 1); ?> /> 灰度（仅管理员可见）</label>
            </td>
          </tr>
          <tr>
            <th scope="row">sora-2-pro</th>
            <td>
              <label><input type="checkbox" name="xibao_aigc_model_enable_sora2pro" value="1" <?php checked($en_s2p, 1); ?> /> 启用</label>
              &nbsp;&nbsp;
              <label><input type="checkbox" name="xibao_aigc_model_gray_sora2pro" value="1" <?php checked($gr_s2p, 1); ?> /> 灰度（仅管理员可见）</label>
            </td>
          </tr>

          <tr><th scope="row"><strong>Model Lists (Display)</strong></th><td></td></tr>
          <tr>
            <th scope="row">Image Models</th>
            <td>
              <input class="regular-text" name="xibao_aigc_image_models" value="<?php echo esc_attr($img_models); ?>" />
              <p class="description">逗号分隔（用于下拉展示）。建议先保持：<code>nano-banana,nano-banana-2</code></p>
            </td>
          </tr>
          <tr>
            <th scope="row">Video Models</th>
            <td>
              <input class="regular-text" name="xibao_aigc_video_models" value="<?php echo esc_attr($vid_models); ?>" />
              <p class="description">逗号分隔（用于下拉展示）。建议：<code>sora2,sora-2-pro</code></p>
            </td>
          </tr>

          <tr><th scope="row"><strong>API Paths</strong></th><td></td></tr>
          <tr>
            <th scope="row">Image Gen Path</th>
            <td><input class="regular-text" name="xibao_aigc_path_img_gen" value="<?php echo esc_attr($p_img_gen); ?>" /></td>
          </tr>
          <tr>
            <th scope="row">Image Edit Path</th>
            <td><input class="regular-text" name="xibao_aigc_path_img_edit" value="<?php echo esc_attr($p_img_edit); ?>" /></td>
          </tr>
          <tr>
            <th scope="row">Video Gen Path</th>
            <td><input class="regular-text" name="xibao_aigc_path_video_gen" value="<?php echo esc_attr($p_vid_gen); ?>" /></td>
          </tr>
          <tr>
            <th scope="row">Video Get Status Path</th>
            <td>
              <input class="regular-text" name="xibao_aigc_path_video_get" value="<?php echo esc_attr($p_vid_get); ?>" />
              <p class="description">支持占位符 <code>{id}</code>，例如：<code>/v1/videos/tasks/{id}</code></p>
            </td>
          </tr>
          <tr>
            <th scope="row">Optional Callback URL</th>
            <td>
              <code><?php echo esc_html($hook); ?></code>
              <p class="description">如果供应商支持回调，把这个 URL 配到他们后台。</p>
            </td>
          </tr>

          <tr><th scope="row"><strong>myCred Cost (Image)</strong></th><td></td></tr>
          <tr>
            <th scope="row">Image 1K cost</th>
            <td><input type="number" step="0.1" name="xibao_aigc_cost_img_1k" value="<?php echo esc_attr($c1); ?>" /></td>
          </tr>
          <tr>
            <th scope="row">Image 2K cost</th>
            <td><input type="number" step="0.1" name="xibao_aigc_cost_img_2k" value="<?php echo esc_attr($c2); ?>" /></td>
          </tr>
          <tr>
            <th scope="row">Image 4K cost</th>
            <td><input type="number" step="0.1" name="xibao_aigc_cost_img_4k" value="<?php echo esc_attr($c4); ?>" /></td>
          </tr>

          <tr><th scope="row"><strong>myCred Cost (Video) - default 0</strong></th><td></td></tr>
          <tr>
            <th scope="row">SORA2 cost (10s / 15s / HD)</th>
            <td>
              10s <input type="number" step="0.1" name="xibao_aigc_cost_sora2_10" value="<?php echo esc_attr($s2_10); ?>" />
              &nbsp;15s <input type="number" step="0.1" name="xibao_aigc_cost_sora2_15" value="<?php echo esc_attr($s2_15); ?>" />
              &nbsp;HD <input type="number" step="0.1" name="xibao_aigc_cost_sora2_hd" value="<?php echo esc_attr($s2_hd); ?>" />
            </td>
          </tr>
          <tr>
            <th scope="row">SORA2PRO cost (10s / 15s / HD)</th>
            <td>
              10s <input type="number" step="0.1" name="xibao_aigc_cost_sora2pro_10" value="<?php echo esc_attr($s2p_10); ?>" />
              &nbsp;15s <input type="number" step="0.1" name="xibao_aigc_cost_sora2pro_15" value="<?php echo esc_attr($s2p_15); ?>" />
              &nbsp;HD <input type="number" step="0.1" name="xibao_aigc_cost_sora2pro_hd" value="<?php echo esc_attr($s2p_hd); ?>" />
            </td>
          </tr>

          <tr><th scope="row"><strong>Video Notes (shown on front-end)</strong></th><td></td></tr>
          <tr>
            <th scope="row">SORA2 Note</th>
            <td>
              <textarea class="large-text" rows="3" name="xibao_aigc_note_sora2"><?php echo esc_textarea($note_s2); ?></textarea>
              <p class="description">你要的“后台备注，前端展示”。</p>
            </td>
          </tr>
          <tr>
            <th scope="row">SORA2PRO Note</th>
            <td>
              <textarea class="large-text" rows="3" name="xibao_aigc_note_sora2pro"><?php echo esc_textarea($note_s2p); ?></textarea>
            </td>
          </tr>

        </table>

        <?php submit_button(); ?>
      </form>

      <hr />
      <p><strong>Shortcodes：</strong></p>
      <p><code>[xibao_aigc]</code> —— 总入口（图片/视频 Tab）</p>
      <p><code>[xibao_video]</code> —— 纯视频入口（默认打开视频）</p>
      <p><code>[xibao_aigc_center]</code> —— 用户中心（任务状态/下载）</p>
    </div>
    <?php
  }

  /* =========================
   * Helpers
   * ========================= */
  private static function base_url() {
    $base = trim((string)get_option('xibao_aigc_base_url',''));
    return rtrim($base, '/');
  }

  private static function api_key() {
    return trim((string)get_option('xibao_aigc_api_key',''));
  }

  private static function is_admin_only_frontend() {
    return intval(get_option('xibao_aigc_admin_only', 1)) === 1;
  }

  private static function points_decimals() {
    $d = intval(get_option('xibao_aigc_points_decimals', 1));
    if ($d < 0) $d = 0;
    if ($d > 4) $d = 4;
    return $d;
  }

  private static function round_points($v) {
    $d = self::points_decimals();
    return round((float)$v, $d);
  }

  private static function mycred_ok() {
    return function_exists('mycred');
  }

  private static function get_balance($user_id) {
    if (!self::mycred_ok()) return 0.0;
    $mycred = mycred();
    return (float)$mycred->get_users_balance($user_id);
  }

  private static function deduct_points($user_id, $amount, $ref, $log, $entry_id=0) {
    if (!self::mycred_ok()) return false;
    $amount = self::round_points($amount);
    if ($amount <= 0) return true; // 0 也允许走流程（你现在默认都 0）
    $bal = self::get_balance($user_id);
    if ($bal < $amount) return false;

    mycred_subtract($ref, $user_id, $amount, $log, $entry_id, []);
    return true;
  }

  private static function refund_points($user_id, $amount, $ref, $log, $entry_id=0) {
    if (!self::mycred_ok()) return;
    $amount = self::round_points($amount);
    if ($amount <= 0) return; // 0 不需要退
    mycred_add($ref, $user_id, $amount, $log, $entry_id, []);
  }

  private static function model_enabled($model) {
    $m = strtolower(trim((string)$model));
    if ($m === 'nano-banana') return intval(get_option('xibao_aigc_model_enable_nano_banana', 1)) === 1;
    if ($m === 'nano-banana-2') return intval(get_option('xibao_aigc_model_enable_nano_banana_2', 1)) === 1;
    if ($m === 'sora2') return intval(get_option('xibao_aigc_model_enable_sora2', 1)) === 1;
    if ($m === 'sora-2-pro' || $m === 'sora2pro' || $m === 'sora2-pro') return intval(get_option('xibao_aigc_model_enable_sora2pro', 1)) === 1;
    return true;
  }

  private static function model_is_gray($model) {
    $m = strtolower(trim((string)$model));
    if ($m === 'nano-banana') return intval(get_option('xibao_aigc_model_gray_nano_banana', 0)) === 1;
    if ($m === 'nano-banana-2') return intval(get_option('xibao_aigc_model_gray_nano_banana_2', 0)) === 1;
    if ($m === 'sora2') return intval(get_option('xibao_aigc_model_gray_sora2', 1)) === 1;
    if ($m === 'sora-2-pro' || $m === 'sora2pro' || $m === 'sora2-pro') return intval(get_option('xibao_aigc_model_gray_sora2pro', 1)) === 1;
    return false;
  }

  private static function can_use_model($model) {
    if (!self::model_enabled($model)) return false;
    if (self::model_is_gray($model) && !current_user_can('manage_options')) return false;
    return true;
  }

  private static function cost_for_image_size($image_size) {
    $image_size = strtoupper(trim((string)$image_size));
    if ($image_size === '4K') return (float)get_option('xibao_aigc_cost_img_4k', 0);
    if ($image_size === '2K') return (float)get_option('xibao_aigc_cost_img_2k', 0);
    return (float)get_option('xibao_aigc_cost_img_1k', 0);
  }

  private static function cost_for_video($model, $duration, $hd_flag) {
    $m = strtolower(trim((string)$model));
    $dur = (string)$duration;
    $is_hd = ($hd_flag === '1' || strtolower((string)$hd_flag) === 'hd');

    // duration: 10 / 15 only (按你要求先保留 10/15/HD 都为 0 可改)
    $k = ($dur === '15') ? '15' : '10';

    if ($m === 'sora2') {
      if ($is_hd) return (float)get_option('xibao_aigc_cost_sora2_hd', 0);
      return (float)get_option('xibao_aigc_cost_sora2_' . $k, 0);
    }

    if ($m === 'sora-2-pro' || $m === 'sora2pro' || $m === 'sora2-pro') {
      if ($is_hd) return (float)get_option('xibao_aigc_cost_sora2pro_hd', 0);
      return (float)get_option('xibao_aigc_cost_sora2pro_' . $k, 0);
    }

    return 0.0;
  }

  private static function admin_only_gate_html() {
    return '<div style="max-width:920px;margin:18px auto;padding:14px;border:1px solid rgba(148,163,184,.25);border-radius:16px;background:rgba(2,6,23,.35);color:rgba(226,232,240,.9);">当前为灰度/内测阶段：仅管理员可见。</div>';
  }

  /* =========================
   * Cron: Cleanup (TTL minutes)
   * ========================= */
  public static function cron_cleanup_jobs() {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    $ttl_min = intval(get_option('xibao_aigc_task_ttl_minutes', 30));
    if ($ttl_min < 5) $ttl_min = 5;

    $cutoff = gmdate('Y-m-d H:i:s', time() - ($ttl_min * 60));

    // 删除超过 TTL 的任务（成功/失败/排队都删，符合你“任务缓存保留30分钟”）
    $wpdb->query(
      $wpdb->prepare("DELETE FROM `$table` WHERE created_at < %s", $cutoff)
    );
  }

  /* =========================
   * Shortcode: Generator UI (default)
   * ========================= */
  public static function shortcode_generator() {
    if (self::is_admin_only_frontend() && !current_user_can('manage_options')) {
      return self::admin_only_gate_html();
    }
    return self::render_generator_ui('image');
  }

  /* Shortcode: Video Entry */
  public static function shortcode_video() {
    if (self::is_admin_only_frontend() && !current_user_can('manage_options')) {
      return self::admin_only_gate_html();
    }
    return self::render_generator_ui('video');
  }

  private static function render_generator_ui($default_tab = 'image') {
    $uid = 'xa_' . wp_rand(10000,99999);
    $ajax = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce(self::NONCE_ACTION);

    // Build model lists but filter by enable/gray
    $img_models_raw = array_filter(array_map('trim', explode(',', (string)get_option('xibao_aigc_image_models','nano-banana,nano-banana-2'))));
    $vid_models_raw = array_filter(array_map('trim', explode(',', (string)get_option('xibao_aigc_video_models','sora2,sora-2-pro'))));

    $img_models = [];
    foreach ($img_models_raw as $m) {
      if (self::can_use_model($m)) $img_models[] = $m;
    }
    $vid_models = [];
    foreach ($vid_models_raw as $m) {
      if (self::can_use_model($m)) $vid_models[] = $m;
    }

    $note_s2  = (string)get_option('xibao_aigc_note_sora2', '');
    $note_s2p = (string)get_option('xibao_aigc_note_sora2pro', '');

    $is_logged_in = is_user_logged_in();
    $bal = 0.0;
    if ($is_logged_in && self::mycred_ok()) {
      $bal = self::get_balance(get_current_user_id());
    }

    ob_start(); ?>
    <div class="xa-wrap" id="<?php echo esc_attr($uid); ?>">
      <div class="xa-card">
        <div class="xa-head">
          <div class="xa-title">XIBAO-AIGC <span class="xa-sub">Cyber Neon Studio</span></div>
          <div class="xa-desc">
            充值积分 → 消费积分生成图片/视频 · 任务队列 · 用户中心可查状态
            <?php if ($is_logged_in): ?>
              <span style="margin-left:10px;opacity:.9;">当前积分：<strong><?php echo esc_html(number_format_i18n($bal, self::points_decimals())); ?></strong></span>
            <?php else: ?>
              <span style="margin-left:10px;opacity:.9;">（未登录：生成需要登录）</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="xa-body">
          <div class="xa-tabs">
            <button type="button" class="xa-tab" data-tab="image">图片生成</button>
            <button type="button" class="xa-tab" data-tab="video">视频生成</button>
          </div>

          <div class="xa-search">
            <input class="xa-input" data-role="prompt" placeholder="像搜索一样输入提示词…（产品广告/电影运镜/写实/赛博霓虹等）" />
            <button class="xa-btn" data-role="run"><span class="dot"></span><span data-role="runText">生成</span></button>
          </div>

          <div class="xa-grid">
            <div class="xa-panel">
              <div class="xa-row">
                <div class="xa-field xa-only-image">
                  <div class="xa-label">模式</div>
                  <select class="xa-select" data-role="mode">
                    <option value="txt2img">文生图</option>
                    <option value="img2img">图生图</option>
                  </select>
                </div>

                <div class="xa-field xa-only-video" style="display:none;">
                  <div class="xa-label">时长</div>
                  <select class="xa-select" data-role="duration">
                    <option value="10">10s</option>
                    <option value="15">15s</option>
                  </select>
                </div>
              </div>

              <div class="xa-row">
                <div class="xa-field xa-only-image">
                  <div class="xa-label">图片模型</div>
                  <select class="xa-select" data-role="image_model">
                    <?php foreach($img_models as $m): ?>
                      <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html($m); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (empty($img_models)): ?>
                    <div class="xa-tip">当前没有可用图片模型（可能被关闭/灰度）。</div>
                  <?php endif; ?>
                </div>

                <div class="xa-field xa-only-video" style="display:none;">
                  <div class="xa-label">视频模型</div>
                  <select class="xa-select" data-role="video_model">
                    <?php foreach($vid_models as $m): ?>
                      <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html($m); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (empty($vid_models)): ?>
                    <div class="xa-tip">当前没有可用视频模型（可能被关闭/灰度）。</div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="xa-row xa-only-video" style="display:none;">
                <div class="xa-field">
                  <div class="xa-label">HD（可选）</div>
                  <select class="xa-select" data-role="hd">
                    <option value="0">默认</option>
                    <option value="1">HD</option>
                  </select>
                </div>
                <div class="xa-field">
                  <div class="xa-label">备注</div>
                  <div class="xa-tip" data-role="video_note" style="margin-top:4px;"></div>
                </div>
              </div>

              <div class="xa-row xa-only-image">
                <div class="xa-field">
                  <div class="xa-label">比例</div>
                  <select class="xa-select" data-role="aspect_ratio">
                    <option value="">不指定</option>
                    <option value="1:1">1:1</option>
                    <option value="3:4">3:4</option>
                    <option value="4:3">4:3</option>
                    <option value="9:16">9:16</option>
                    <option value="16:9">16:9</option>
                  </select>
                </div>
                <div class="xa-field">
                  <div class="xa-label">清晰度</div>
                  <select class="xa-select" data-role="image_size">
                    <option value="1K">1K</option>
                    <option value="2K">2K</option>
                    <option value="4K">4K</option>
                  </select>
                </div>
              </div>

              <div class="xa-uploader xa-only-image" data-role="uploader" style="display:none;">
                <div class="xa-label">参考图（图生图）</div>
                <input type="file" accept="image/*" data-role="file" />
                <div class="xa-tip">图生图需要上传一张参考图（V1 先做单图）。</div>
              </div>

              <div class="xa-status" data-role="status">就绪</div>
              <div class="xa-err" data-role="err" style="display:none;"></div>
            </div>

            <div class="xa-panel xa-result">
              <div class="xa-rhead">
                <div class="xa-rtitle">结果预览</div>
                <div class="xa-tools" data-role="tools" style="display:none;">
                  <button type="button" class="xa-ghost" data-role="copy">复制链接</button>
                  <a class="xa-ghost" href="#" target="_blank" rel="noopener" data-role="open">新窗口打开</a>
                </div>
              </div>
              <div class="xa-box" data-role="box">
                <div class="xa-empty">
                  <div class="t1">等待生成</div>
                  <div class="t2">图片会直接预览；视频会进入任务队列并显示进度。</div>
                </div>
              </div>
              <div class="xa-job" data-role="job" style="display:none;"></div>
            </div>
          </div>

          <div class="xa-footer">
            <div>提示：当前为任务制扣费；失败自动返还。任务缓存默认保留 30 分钟，到期自动清理。</div>
            <div>用户中心短代码：<code>[xibao_aigc_center]</code></div>
          </div>
        </div>
      </div>
    </div>

    <style>
      /* ===== Cyber Neon UI ===== */
      #<?php echo esc_attr($uid); ?> .xa-card{
        max-width:1080px;margin:18px auto;border-radius:20px;overflow:hidden;
        border:1px solid rgba(148,163,184,.22);
        background: radial-gradient(1200px 600px at 10% 0%, rgba(99,102,241,.22), transparent 60%),
                    radial-gradient(900px 500px at 90% 10%, rgba(236,72,153,.18), transparent 60%),
                    rgba(2,6,23,.62);
        backdrop-filter: blur(10px);
        box-shadow: 0 18px 70px rgba(0,0,0,.35);
      }
      #<?php echo esc_attr($uid); ?> .xa-head{
        padding:18px 18px 14px;
        background: linear-gradient(135deg, rgba(2,6,23,.95), rgba(15,23,42,.78));
        border-bottom:1px solid rgba(148,163,184,.18);
      }
      #<?php echo esc_attr($uid); ?> .xa-title{color:#fff;font-weight:900;font-size:20px;letter-spacing:.3px}
      #<?php echo esc_attr($uid); ?> .xa-sub{font-size:12px;font-weight:800;color:rgba(226,232,240,.75);margin-left:10px}
      #<?php echo esc_attr($uid); ?> .xa-desc{margin-top:8px;color:rgba(226,232,240,.78);font-size:12px}
      #<?php echo esc_attr($uid); ?> .xa-body{padding:16px}
      #<?php echo esc_attr($uid); ?> .xa-tabs{display:flex;gap:10px;margin-bottom:14px}
      #<?php echo esc_attr($uid); ?> .xa-tab{
        border:1px solid rgba(148,163,184,.20);
        background: rgba(2,6,23,.35);
        color: rgba(226,232,240,.86);
        padding:10px 12px;border-radius:14px;font-weight:900;cursor:pointer;
        transition: transform .12s ease, box-shadow .12s ease;
      }
      #<?php echo esc_attr($uid); ?> .xa-tab:hover{transform: translateY(-1px)}
      #<?php echo esc_attr($uid); ?> .xa-tab.is-active{
        background: linear-gradient(135deg, rgba(34,211,238,.45), rgba(99,102,241,.55), rgba(236,72,153,.45));
        border-color: transparent;
        box-shadow: 0 0 0 4px rgba(34,211,238,.10);
        color:#fff;
      }
      #<?php echo esc_attr($uid); ?> .xa-search{
        display:flex;gap:10px;align-items:center;
        padding:12px;border-radius:18px;
        border:1px solid rgba(148,163,184,.18);
        background: rgba(2,6,23,.30);
        margin-bottom:14px;
      }
      #<?php echo esc_attr($uid); ?> .xa-input{
        flex:1;border:1px solid rgba(148,163,184,.22);
        background: rgba(15,23,42,.55);
        color: rgba(226,232,240,.94);
        padding:12px 14px;border-radius:16px;outline:none;
      }
      #<?php echo esc_attr($uid); ?> .xa-btn{
        border:0;border-radius:16px;
        padding:12px 16px;cursor:pointer;font-weight:900;color:#fff;
        background: linear-gradient(135deg, rgba(34,211,238,.85), rgba(99,102,241,.90), rgba(236,72,153,.85));
        position:relative; overflow:hidden;
        box-shadow: 0 10px 30px rgba(99,102,241,.25);
      }
      #<?php echo esc_attr($uid); ?> .xa-btn .dot{
        width:10px;height:10px;border-radius:999px;background:#fff;display:none;
        margin-right:8px;box-shadow:0 0 0 6px rgba(255,255,255,.12);
        animation: xaPulse 1.1s infinite ease-in-out;
      }
      #<?php echo esc_attr($uid); ?> .xa-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
      @media(max-width: 900px){#<?php echo esc_attr($uid); ?> .xa-grid{grid-template-columns:1fr}}
      #<?php echo esc_attr($uid); ?> .xa-panel{
        border:1px solid rgba(148,163,184,.18);
        border-radius:18px;padding:14px;
        background: rgba(2,6,23,.28);
      }
      #<?php echo esc_attr($uid); ?> .xa-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
      @media(max-width: 520px){#<?php echo esc_attr($uid); ?> .xa-row{grid-template-columns:1fr}}
      #<?php echo esc_attr($uid); ?> .xa-label{color:rgba(226,232,240,.85);font-weight:900;font-size:12px;margin-bottom:6px}
      #<?php echo esc_attr($uid); ?> .xa-select{
        width:100%;border-radius:14px;outline:none;
        border:1px solid rgba(148,163,184,.22);
        background: rgba(15,23,42,.55);
        color: rgba(226,232,240,.94);
        padding:10px 12px;
      }
      #<?php echo esc_attr($uid); ?> .xa-tip{margin-top:8px;color:rgba(226,232,240,.70);font-size:12px;white-space:pre-wrap}
      #<?php echo esc_attr($uid); ?> .xa-status{margin-top:10px;color:rgba(226,232,240,.78);font-size:12px;font-weight:800}
      #<?php echo esc_attr($uid); ?> .xa-err{
        margin-top:10px;border-radius:16px;padding:10px 12px;
        border:1px solid rgba(239,68,68,.35);
        background: rgba(239,68,68,.10);
        color: rgba(254,226,226,.95);
        font-size:12px;white-space:pre-wrap;
      }
      #<?php echo esc_attr($uid); ?> .xa-rhead{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
      #<?php echo esc_attr($uid); ?> .xa-rtitle{color:rgba(226,232,240,.92);font-weight:900}
      #<?php echo esc_attr($uid); ?> .xa-tools{display:flex;gap:8px;flex-wrap:wrap}
      #<?php echo esc_attr($uid); ?> .xa-ghost{
        border:1px solid rgba(148,163,184,.20);
        background: rgba(2,6,23,.25);
        color: rgba(226,232,240,.92);
        padding:8px 10px;border-radius:14px;font-weight:900;font-size:12px;
        text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;
      }
      #<?php echo esc_attr($uid); ?> .xa-box{
        min-height:360px;border-radius:18px;
        border:1px solid rgba(148,163,184,.18);
        background: rgba(15,23,42,.32);
        display:flex;align-items:center;justify-content:center;overflow:hidden;
      }
      #<?php echo esc_attr($uid); ?> .xa-box img, #<?php echo esc_attr($uid); ?> .xa-box video{
        width:100%;height:auto;display:block;
      }
      #<?php echo esc_attr($uid); ?> .xa-empty{text-align:center;padding:18px}
      #<?php echo esc_attr($uid); ?> .xa-empty .t1{color:rgba(226,232,240,.90);font-weight:900}
      #<?php echo esc_attr($uid); ?> .xa-empty .t2{margin-top:6px;color:rgba(226,232,240,.70);font-size:12px}
      #<?php echo esc_attr($uid); ?> .xa-job{margin-top:10px;color:rgba(226,232,240,.82);font-size:12px}
      #<?php echo esc_attr($uid); ?> .xa-footer{
        margin-top:14px;color:rgba(226,232,240,.68);
        font-size:12px;line-height:1.6;
      }
      @keyframes xaPulse{0%{transform:scale(.85);opacity:.6}50%{transform:scale(1);opacity:1}100%{transform:scale(.85);opacity:.6}}
    </style>

    <script>
    (function(){
      const root = document.getElementById('<?php echo esc_js($uid); ?>');
      if(!root) return;

      const ajaxUrl = '<?php echo esc_js($ajax); ?>';
      const nonce   = '<?php echo esc_js($nonce); ?>';

      const tabs = root.querySelectorAll('.xa-tab');
      const promptEl = root.querySelector('[data-role="prompt"]');
      const runBtn = root.querySelector('[data-role="run"]');
      const runText = root.querySelector('[data-role="runText"]');
      const dot = runBtn.querySelector('.dot');

      const modeEl = root.querySelector('[data-role="mode"]');
      const aspectEl = root.querySelector('[data-role="aspect_ratio"]');
      const imgSizeEl = root.querySelector('[data-role="image_size"]');
      const imgModelEl = root.querySelector('[data-role="image_model"]');
      const vidModelEl = root.querySelector('[data-role="video_model"]');
      const durationEl = root.querySelector('[data-role="duration"]');
      const hdEl = root.querySelector('[data-role="hd"]');
      const noteEl = root.querySelector('[data-role="video_note"]');

      const uploader = root.querySelector('[data-role="uploader"]');
      const fileEl = root.querySelector('[data-role="file"]');

      const statusEl = root.querySelector('[data-role="status"]');
      const errEl = root.querySelector('[data-role="err"]');
      const box = root.querySelector('[data-role="box"]');
      const tools = root.querySelector('[data-role="tools"]');
      const copyBtn = root.querySelector('[data-role="copy"]');
      const openA = root.querySelector('[data-role="open"]');
      const jobEl = root.querySelector('[data-role="job"]');

      const onlyImage = root.querySelectorAll('.xa-only-image');
      const onlyVideo = root.querySelectorAll('.xa-only-video');

      const NOTE_SORA2 = <?php echo wp_json_encode($note_s2, JSON_UNESCAPED_UNICODE); ?>;
      const NOTE_SORA2PRO = <?php echo wp_json_encode($note_s2p, JSON_UNESCAPED_UNICODE); ?>;

      let currentTab = 'image';
      let lastUrl = '';
      let lastJobId = 0;
      let pollTimer = null;

      function setStatus(t, isErr){
        statusEl.textContent = t;
        statusEl.style.color = isErr ? 'rgba(254,226,226,.95)' : 'rgba(226,232,240,.78)';
      }
      function showErr(msg){
        errEl.style.display = '';
        errEl.textContent = msg || '未知错误';
      }
      function clearErr(){
        errEl.style.display = 'none';
        errEl.textContent = '';
      }
      function loading(on){
        runBtn.disabled = !!on;
        dot.style.display = on ? 'inline-block' : 'none';
      }
      function resetBox(){
        tools.style.display = 'none';
        jobEl.style.display = 'none';
        lastUrl = '';
        box.innerHTML = '<div class="xa-empty"><div class="t1">生成中…</div><div class="t2">请稍等</div></div>';
      }
      function renderImage(url){
        lastUrl = url || '';
        if(!lastUrl){
          box.innerHTML = '<div class="xa-empty"><div class="t1">成功但无URL</div><div class="t2">请检查接口返回字段</div></div>';
          return;
        }
        box.innerHTML = '<img src="'+encodeURI(lastUrl)+'" alt="result">';
        tools.style.display = 'flex';
        openA.href = lastUrl;
      }
      function renderVideo(url){
        lastUrl = url || '';
        if(!lastUrl){
          box.innerHTML = '<div class="xa-empty"><div class="t1">视频就绪但无URL</div><div class="t2">请检查接口返回字段</div></div>';
          return;
        }
        box.innerHTML = '<video controls src="'+encodeURI(lastUrl)+'"></video>';
        tools.style.display = 'flex';
        openA.href = lastUrl;
      }

      function stopPoll(){
        if(pollTimer){ clearInterval(pollTimer); pollTimer=null; }
      }

      async function pollJob(jobId){
        stopPoll();
        pollTimer = setInterval(async ()=>{
          try{
            const fd = new FormData();
            fd.append('action', 'xibao_aigc_poll');
            fd.append('nonce', nonce);
            fd.append('job_id', String(jobId));
            const r = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
            const j = await r.json();
            if(!j || !j.success) return;

            const d = j.data || {};
            const st = d.status || '';
            const msg = d.message || '';
            jobEl.style.display = '';
            jobEl.textContent = '任务状态：' + st + (msg ? (' · ' + msg) : '');

            if(st === 'succeeded'){
              stopPoll();
              setStatus('完成');
              renderVideo(d.result_url || '');
            }
            if(st === 'failed'){
              stopPoll();
              setStatus('失败', true);
              showErr(d.error_msg || '任务失败');
            }
          }catch(e){}
        }, 2000);
      }

      function applyTab(tab){
        currentTab = tab;
        tabs.forEach(b=>b.classList.toggle('is-active', b.dataset.tab===tab));

        const isVideo = (tab === 'video');
        onlyImage.forEach(el => el.style.display = isVideo ? 'none' : '');
        onlyVideo.forEach(el => el.style.display = isVideo ? '' : 'none');

        if(!isVideo){
          uploader.style.display = (modeEl.value === 'img2img') ? '' : 'none';
          runText.textContent = (modeEl.value === 'img2img') ? '生成（图生图）' : '生成（文生图）';
        }else{
          uploader.style.display = 'none';
          runText.textContent = '生成（视频任务）';

          const vm = (vidModelEl && vidModelEl.value) ? String(vidModelEl.value).toLowerCase() : '';
          if (noteEl){
            if (vm === 'sora2') noteEl.textContent = NOTE_SORA2 || '';
            else noteEl.textContent = NOTE_SORA2PRO || '';
          }
        }

        setStatus('就绪');
        clearErr();
      }

      tabs.forEach(btn => btn.addEventListener('click', ()=>applyTab(btn.dataset.tab)));

      if (modeEl){
        modeEl.addEventListener('change', ()=>{
          if(currentTab!=='image') return;
          uploader.style.display = (modeEl.value === 'img2img') ? '' : 'none';
          runText.textContent = (modeEl.value === 'img2img') ? '生成（图生图）' : '生成（文生图）';
        });
      }

      if (vidModelEl){
        vidModelEl.addEventListener('change', ()=>{
          if(currentTab!=='video') return;
          const vm = String(vidModelEl.value||'').toLowerCase();
          if (noteEl){
            if (vm === 'sora2') noteEl.textContent = NOTE_SORA2 || '';
            else noteEl.textContent = NOTE_SORA2PRO || '';
          }
        });
      }

      if (copyBtn){
        copyBtn.addEventListener('click', async ()=>{
          if(!lastUrl) return;
          try{
            await navigator.clipboard.writeText(lastUrl);
            setStatus('已复制链接');
            setTimeout(()=>setStatus('就绪'), 1200);
          }catch(e){
            setStatus('复制失败（浏览器限制）', true);
          }
        });
      }

      runBtn.addEventListener('click', async ()=>{
        clearErr();
        stopPoll();

        const prompt = (promptEl.value||'').trim();
        if(!prompt){
          showErr('提示词不能为空');
          return;
        }

        loading(true);
        resetBox();
        setStatus('请求中…');

        const fd = new FormData();
        fd.append('action', 'xibao_aigc_run');
        fd.append('nonce', nonce);
        fd.append('tab', currentTab);
        fd.append('prompt', prompt);

        if(currentTab === 'image'){
          fd.append('mode', modeEl.value);
          fd.append('model', imgModelEl.value);
          fd.append('aspect_ratio', aspectEl.value || '');
          fd.append('image_size', imgSizeEl.value || '1K');

          if(modeEl.value === 'img2img'){
            if(!fileEl.files || !fileEl.files[0]){
              loading(false);
              showErr('图生图需要上传参考图');
              setStatus('失败', true);
              return;
            }
            fd.append('image', fileEl.files[0], fileEl.files[0].name);
          }
        }else{
          fd.append('model', vidModelEl.value);
          fd.append('duration', durationEl.value || '10');
          fd.append('hd', hdEl ? (hdEl.value || '0') : '0');
        }

        try{
          const res = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
          const json = await res.json();

          if(!json || !json.success){
            const msg = json && json.data && (json.data.message || json.data.raw) ? (json.data.message || json.data.raw) : '生成失败';
            showErr(typeof msg === 'string' ? msg : JSON.stringify(msg, null, 2));
            setStatus('失败', true);
            return;
          }

          const d = json.data || {};
          if(currentTab === 'image'){
            setStatus('完成');
            renderImage(d.result_url || '');
          }else{
            lastJobId = d.job_id || 0;
            jobEl.style.display = '';
            jobEl.textContent = '任务已提交：#' + lastJobId + ' · 正在轮询进度…';
            setStatus('排队中…');
            pollJob(lastJobId);
          }
        }catch(e){
          showErr('请求异常：' + (e && e.message ? e.message : e));
          setStatus('异常', true);
        }finally{
          loading(false);
        }
      });

      applyTab('<?php echo esc_js($default_tab === 'video' ? 'video' : 'image'); ?>');
    })();
    </script>
    <?php
    return ob_get_clean();
  }

  /* =========================
   * Shortcode: User Center
   * ========================= */
  public static function shortcode_center() {
    if (self::is_admin_only_frontend() && !current_user_can('manage_options')) {
      return self::admin_only_gate_html();
    }

    if (!is_user_logged_in()) {
      return '<div style="max-width:920px;margin:18px auto;padding:14px;border:1px solid rgba(148,163,184,.25);border-radius:16px;background:rgba(2,6,23,.35);color:rgba(226,232,240,.9);">请先登录后查看用户中心。</div>';
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table = $wpdb->prefix . self::TABLE;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM `$table` WHERE user_id=%d ORDER BY id DESC LIMIT 50",
      $user_id
    ), ARRAY_A);

    $bal = self::mycred_ok() ? self::get_balance($user_id) : 0.0;

    ob_start(); ?>
    <div class="xc-wrap" style="max-width:1080px;margin:18px auto;">
      <div style="border:1px solid rgba(148,163,184,.22);border-radius:18px;background:rgba(2,6,23,.55);backdrop-filter:blur(10px);box-shadow:0 18px 70px rgba(0,0,0,.25);overflow:hidden;">
        <div style="padding:16px 18px;background:linear-gradient(135deg, rgba(2,6,23,.95), rgba(15,23,42,.80));border-bottom:1px solid rgba(148,163,184,.18);color:#fff;font-weight:900;">
          用户中心 · 任务状态
          <span style="font-size:12px;font-weight:800;color:rgba(226,232,240,.75);margin-left:10px;">当前积分：<?php echo esc_html(number_format_i18n($bal, self::points_decimals())); ?></span>
        </div>
        <div style="padding:14px;color:rgba(226,232,240,.90);">
          <div style="color:rgba(226,232,240,.70);font-size:12px;margin-bottom:10px;">
            任务缓存默认保留 <?php echo intval(get_option('xibao_aigc_task_ttl_minutes',30)); ?> 分钟，过期自动清理（符合你要求）。
          </div>

          <div style="overflow:auto;border:1px solid rgba(148,163,184,.18);border-radius:16px;">
            <table style="width:100%;border-collapse:collapse;min-width:860px;">
              <thead>
                <tr style="background:rgba(15,23,42,.55);">
                  <th style="text-align:left;padding:10px 12px;font-size:12px;">ID</th>
                  <th style="text-align:left;padding:10px 12px;font-size:12px;">类型</th>
                  <th style="text-align:left;padding:10px 12px;font-size:12px;">模型</th>
                  <th style="text-align:left;padding:10px 12px;font-size:12px;">状态</th>
                  <th style="text-align:left;padding:10px 12px;font-size:12px;">积分</th>
                  <th style="text-align:left;padding:10px 12px;font-size:12px;">结果</th>
                  <th style="text-align:left;padding:10px 12px;font-size:12px;">时间</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rows)): ?>
                  <tr><td colspan="7" style="padding:14px;color:rgba(226,232,240,.70);">暂无任务记录。</td></tr>
                <?php else: foreach($rows as $r): ?>
                  <tr style="border-top:1px solid rgba(148,163,184,.12);">
                    <td style="padding:10px 12px;font-size:12px;"><?php echo intval($r['id']); ?></td>
                    <td style="padding:10px 12px;font-size:12px;"><?php echo esc_html($r['type'] . '/' . $r['mode']); ?></td>
                    <td style="padding:10px 12px;font-size:12px;"><?php echo esc_html($r['model']); ?></td>
                    <td style="padding:10px 12px;font-size:12px;"><?php echo esc_html($r['status']); ?></td>
                    <td style="padding:10px 12px;font-size:12px;"><?php echo esc_html(number_format_i18n((float)$r['cost_points'], self::points_decimals())); ?><?php echo $r['refunded'] ? '（已退）' : ''; ?></td>
                    <td style="padding:10px 12px;font-size:12px;">
                      <?php if (!empty($r['result_url'])): ?>
                        <a href="<?php echo esc_url($r['result_url']); ?>" target="_blank" rel="noopener" style="color:#7dd3fc;font-weight:900;text-decoration:none;">打开</a>
                      <?php else: ?>
                        <span style="color:rgba(226,232,240,.55);">-</span>
                      <?php endif; ?>
                      <?php if (!empty($r['error_msg'])): ?>
                        <div style="margin-top:6px;color:rgba(254,226,226,.95);white-space:pre-wrap;"><?php echo esc_html(mb_strimwidth($r['error_msg'],0,120,'…','UTF-8')); ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="padding:10px 12px;font-size:12px;color:rgba(226,232,240,.75);"><?php echo esc_html($r['created_at']); ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  /* =========================
   * AJAX: Run (Image/Video)
   * ========================= */
  public static function ajax_run() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::NONCE_ACTION)) {
      wp_send_json_error(['message'=>'安全校验失败（nonce）']);
    }

    $base = self::base_url();
    $key  = self::api_key();
    if (!$base || !$key) wp_send_json_error(['message'=>'请先在 Settings → XIBAO-AIGC 填写 Base URL / API Key']);

    $tab = sanitize_text_field($_POST['tab'] ?? 'image');
    $prompt = sanitize_textarea_field($_POST['prompt'] ?? '');
    if (!$prompt) wp_send_json_error(['message'=>'提示词不能为空']);

    $user_id = get_current_user_id();
    if (!$user_id) wp_send_json_error(['message'=>'请先登录（生成需要登录扣积分）']);

    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    // ✅ 统一：创建任务成功后才扣费（任务制更可控）
    if ($tab === 'video') {
      $model = sanitize_text_field($_POST['model'] ?? '');
      $duration = sanitize_text_field($_POST['duration'] ?? '10');
      $hd = sanitize_text_field($_POST['hd'] ?? '0');

      if (!$model) wp_send_json_error(['message'=>'视频模型不能为空']);
      if (!self::can_use_model($model)) wp_send_json_error(['message'=>'该视频模型已关闭或灰度不可见']);

      // 先插入任务（queued）
      $wpdb->insert($table, [
        'user_id' => $user_id,
        'type' => 'video',
        'mode' => 't2v',
        'model' => $model,
        'prompt' => $prompt,
        'status' => 'queued',
        'cost_points' => 0.0,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
      ]);
      $job_id = (int)$wpdb->insert_id;

      // 计算成本（默认 0，可后台改）
      $cost = self::round_points(self::cost_for_video($model, $duration, $hd));

      // 提交到供应商（路径可配置）
      $path = (string)get_option('xibao_aigc_path_video_gen', '/v1/videos/generations');
      $endpoint = $base . $path;

      $body = [
        'model' => $model,
        'prompt' => $prompt,
      ];
      if ($duration !== '') $body['duration'] = $duration;
      if ($hd === '1') $body['quality'] = 'hd'; // 常见字段，供应商不支持也不影响（可后续按文档改）

      $resp = wp_remote_post($endpoint, [
        'timeout' => 180,
        'headers' => [
          'Authorization' => 'Bearer ' . $key,
          'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($body),
      ]);

      if (is_wp_error($resp)) {
        self::fail_and_refund($job_id, $user_id, $cost, '请求失败：'.$resp->get_error_message());
        wp_send_json_error(['message'=>'视频请求失败', 'raw'=>$resp->get_error_message()]);
      }

      $code = wp_remote_retrieve_response_code($resp);
      $raw  = wp_remote_retrieve_body($resp);
      $json = json_decode($raw, true);

      if ($code < 200 || $code >= 300) {
        $msg = 'API 错误（HTTP '.$code.'）';
        if (is_array($json) && isset($json['error']['message'])) $msg .= '：'.$json['error']['message'];
        self::fail_and_refund($job_id, $user_id, $cost, $msg."\n".$raw);
        wp_send_json_error(['message'=>$msg, 'raw'=>$raw]);
      }

      // 兼容不同供应商字段：优先 job_id/task_id/id
      $provider_id = '';
      if (is_array($json)) {
        $provider_id = $json['id'] ?? ($json['task_id'] ?? ($json['job_id'] ?? ''));
        if (!$provider_id && isset($json['data']['id'])) $provider_id = $json['data']['id'];
      }

      if (!$provider_id) {
        self::fail_and_refund($job_id, $user_id, $cost, '未返回任务ID（请根据文档调整字段映射）'."\n".$raw);
        wp_send_json_error(['message'=>'未返回任务ID，请把文档返回示例发我，我来固定字段', 'raw'=>$raw]);
      }

      // ✅ 到这里：任务创建成功 → 扣费（你要的“任务制更可控”）
      if (!self::deduct_points($user_id, $cost, 'xibao_video', 'XIBAO 视频生成扣积分', $job_id)) {
        // 积分不足：标失败但不需要退款（未扣）
        self::fail_and_refund($job_id, $user_id, 0.0, '积分不足，无法生成视频');
        wp_send_json_error(['message'=>'积分不足，无法生成视频']);
      }

      $wpdb->update($table, [
        'status' => 'running',
        'provider_job_id' => sanitize_text_field($provider_id),
        'cost_points' => $cost,
        'updated_at' => current_time('mysql'),
      ], ['id'=>$job_id]);

      wp_send_json_success([
        'job_id' => $job_id,
        'provider_job_id' => $provider_id
      ]);
    }

    // ===== image =====
    $mode = sanitize_text_field($_POST['mode'] ?? 'txt2img');
    $model = sanitize_text_field($_POST['model'] ?? '');
    $aspect_ratio = sanitize_text_field($_POST['aspect_ratio'] ?? '');
    $image_size = sanitize_text_field($_POST['image_size'] ?? '1K');

    if (!$model) wp_send_json_error(['message'=>'图片模型不能为空']);
    if (!self::can_use_model($model)) wp_send_json_error(['message'=>'该图片模型已关闭或灰度不可见']);

    // 先插入任务（queued）
    $wpdb->insert($table, [
      'user_id'=>$user_id,
      'type'=>'image',
      'mode'=>$mode,
      'model'=>$model,
      'prompt'=>$prompt,
      'status'=>'queued',
      'cost_points'=>0.0,
      'created_at'=>current_time('mysql'),
      'updated_at'=>current_time('mysql'),
    ]);
    $job_id = (int)$wpdb->insert_id;

    $cost = self::round_points(self::cost_for_image_size($image_size));
    $resp_fmt = 'url';

    if ($mode === 'img2img') {
      $path = (string)get_option('xibao_aigc_path_img_edit', '/v1/images/edits');
      $endpoint = $base . $path;

      if (!isset($_FILES['image']) || empty($_FILES['image']['tmp_name'])) {
        self::fail_and_refund($job_id, $user_id, 0.0, '图生图需要上传参考图');
        wp_send_json_error(['message'=>'图生图需要上传参考图']);
      }

      $fields = [
        'model' => $model,
        'prompt' => $prompt,
        'response_format' => $resp_fmt,
      ];
      if ($aspect_ratio !== '') $fields['aspect_ratio'] = $aspect_ratio;
      if ($image_size !== '')   $fields['image_size'] = $image_size;

      $tmp = $_FILES['image']['tmp_name'];
      $files = [[
        'fieldname' => 'image',
        'filename'  => sanitize_file_name($_FILES['image']['name']),
        'type'      => !empty($_FILES['image']['type']) ? sanitize_text_field($_FILES['image']['type']) : 'application/octet-stream',
        'content'   => file_get_contents($tmp),
      ]];

      $mp = self::build_multipart($fields, $files);

      $resp = wp_remote_post($endpoint, [
        'timeout' => 180,
        'headers' => [
          'Authorization' => 'Bearer ' . $key,
          'Content-Type'  => 'multipart/form-data; boundary=' . $mp['boundary'],
        ],
        'body' => $mp['body'],
      ]);

    } else {
      $path = (string)get_option('xibao_aigc_path_img_gen', '/v1/images/generations');
      $endpoint = $base . $path;

      $body = [
        'model' => $model,
        'prompt' => $prompt,
        'response_format' => $resp_fmt,
      ];
      if ($aspect_ratio !== '') $body['aspect_ratio'] = $aspect_ratio;
      if ($image_size !== '')   $body['image_size']   = $image_size;

      $resp = wp_remote_post($endpoint, [
        'timeout' => 180,
        'headers' => [
          'Authorization' => 'Bearer ' . $key,
          'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($body),
      ]);
    }

    if (is_wp_error($resp)) {
      self::fail_and_refund($job_id, $user_id, 0.0, '请求失败：'.$resp->get_error_message());
      wp_send_json_error(['message'=>'请求失败：'.$resp->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);

    if ($code < 200 || $code >= 300) {
      $msg = 'API 错误（HTTP '.$code.'）';
      if (is_array($json) && isset($json['error']['message'])) $msg .= '：'.$json['error']['message'];
      self::fail_and_refund($job_id, $user_id, 0.0, $msg."\n".$raw);
      wp_send_json_error(['message'=>$msg, 'raw'=>$raw]);
    }

    $url = $json['data'][0]['url'] ?? '';
    if (!$url && isset($json['url'])) $url = $json['url'];

    if (!$url) {
      self::fail_and_refund($job_id, $user_id, 0.0, '成功但未返回 url 字段，请根据文档调整映射'."\n".$raw);
      wp_send_json_error(['message'=>'成功但未返回 url 字段（请把文档返回示例发我）', 'raw'=>$raw]);
    }

    // ✅ 到这里：图片成功 → 扣费（任务制）
    if (!self::deduct_points($user_id, $cost, 'xibao_image', 'XIBAO 图片生成扣积分', $job_id)) {
      self::fail_and_refund($job_id, $user_id, 0.0, '积分不足，无法生成图片');
      wp_send_json_error(['message'=>'积分不足，无法生成图片']);
    }

    $wpdb->update($table, [
      'status'=>'succeeded',
      'result_url'=>$url,
      'cost_points'=>$cost,
      'updated_at'=>current_time('mysql'),
    ], ['id'=>$job_id]);

    wp_send_json_success([
      'job_id'=>$job_id,
      'result_url'=>$url
    ]);
  }

  /* =========================
   * AJAX: Poll video job
   * ========================= */
  public static function ajax_poll() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::NONCE_ACTION)) {
      wp_send_json_error(['message'=>'安全校验失败（nonce）']);
    }
    $job_id = intval($_POST['job_id'] ?? 0);
    if ($job_id <= 0) wp_send_json_error(['message'=>'job_id 无效']);

    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $job_id), ARRAY_A);
    if (!$row) wp_send_json_error(['message'=>'任务不存在']);

    $user_id = get_current_user_id();
    if (!$user_id || intval($row['user_id']) !== $user_id) {
      wp_send_json_error(['message'=>'无权限']);
    }

    if ($row['status'] === 'succeeded') {
      wp_send_json_success([
        'status'=>'succeeded',
        'result_url'=>$row['result_url'],
        'message'=>'完成'
      ]);
    }
    if ($row['status'] === 'failed') {
      wp_send_json_success([
        'status'=>'failed',
        'error_msg'=>$row['error_msg'],
        'message'=>'失败'
      ]);
    }

    $base = self::base_url();
    $key  = self::api_key();
    $provider_id = (string)$row['provider_job_id'];
    if (!$provider_id) {
      wp_send_json_success(['status'=>'running','message'=>'等待任务ID']);
    }

    $path = (string)get_option('xibao_aigc_path_video_get', '/v1/videos/tasks/{id}');
    $path = str_replace('{id}', rawurlencode($provider_id), $path);
    $endpoint = $base . $path;

    $resp = wp_remote_get($endpoint, [
      'timeout' => 60,
      'headers' => [
        'Authorization' => 'Bearer ' . $key,
        'Content-Type'  => 'application/json',
      ]
    ]);

    if (is_wp_error($resp)) {
      wp_send_json_success(['status'=>'running','message'=>'轮询失败：'.$resp->get_error_message()]);
    }

    $raw = wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);

    $status = '';
    $result_url = '';

    if (is_array($json)) {
      $status = $json['status'] ?? ($json['state'] ?? '');
      $result_url = $json['result_url'] ?? ($json['url'] ?? '');
      if (!$result_url && isset($json['data']['url'])) $result_url = $json['data']['url'];
      if (!$result_url && isset($json['output'][0]['url'])) $result_url = $json['output'][0]['url'];
    }

    $status_l = strtolower((string)$status);

    if (in_array($status_l, ['succeeded','success','completed','done'], true) && $result_url) {
      $wpdb->update($table, [
        'status'=>'succeeded',
        'result_url'=>$result_url,
        'updated_at'=>current_time('mysql'),
      ], ['id'=>$job_id]);

      wp_send_json_success([
        'status'=>'succeeded',
        'result_url'=>$result_url,
        'message'=>'完成'
      ]);
    }

    if (in_array($status_l, ['failed','error'], true)) {
      $err = is_array($json) ? (wp_json_encode($json, JSON_UNESCAPED_UNICODE)) : (string)$raw;
      self::fail_and_refund($job_id, $user_id, (float)$row['cost_points'], '视频失败：'.$err);
      wp_send_json_success(['status'=>'failed','error_msg'=>'视频失败','message'=>'失败']);
    }

    wp_send_json_success([
      'status'=>'running',
      'message'=> $status ? ('进度：'.$status) : '生成中…'
    ]);
  }

  private static function fail_and_refund($job_id, $user_id, $cost, $err) {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    $wpdb->update($table, [
      'status' => 'failed',
      'error_msg' => $err,
      'updated_at' => current_time('mysql'),
    ], ['id'=>$job_id]);

    $row = $wpdb->get_row($wpdb->prepare("SELECT refunded FROM `$table` WHERE id=%d", $job_id), ARRAY_A);
    if ($row && intval($row['refunded']) === 0) {
      self::refund_points($user_id, (float)$cost, 'xibao_refund', 'XIBAO 失败退回积分', $job_id);
      $wpdb->update($table, ['refunded'=>1, 'updated_at'=>current_time('mysql')], ['id'=>$job_id]);
    }
  }

  /* =========================
   * REST callback (optional)
   * ========================= */
  public static function rest_sora2_hook(\WP_REST_Request $req) {
    $payload = $req->get_json_params();
    if (!is_array($payload)) $payload = [];

    $provider_id = $payload['id'] ?? ($payload['job_id'] ?? ($payload['task_id'] ?? ''));
    $status = $payload['status'] ?? ($payload['state'] ?? '');
    $url = $payload['result_url'] ?? ($payload['url'] ?? '');

    if (!$provider_id) return new \WP_REST_Response(['ok'=>false,'message'=>'missing id'], 400);

    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE provider_job_id=%s LIMIT 1", $provider_id), ARRAY_A);
    if (!$row) return new \WP_REST_Response(['ok'=>true,'message'=>'no matched job'], 200);

    $job_id = intval($row['id']);

    $st = strtolower((string)$status);
    if (in_array($st, ['succeeded','success','completed','done'], true) && $url) {
      $wpdb->update($table, [
        'status'=>'succeeded',
        'result_url'=>$url,
        'updated_at'=>current_time('mysql'),
      ], ['id'=>$job_id]);
      return new \WP_REST_Response(['ok'=>true,'message'=>'updated success'], 200);
    }

    if (in_array($st, ['failed','error'], true)) {
      $user_id = intval($row['user_id']);
      self::fail_and_refund($job_id, $user_id, (float)$row['cost_points'], '回调失败：'.wp_json_encode($payload, JSON_UNESCAPED_UNICODE));
      return new \WP_REST_Response(['ok'=>true,'message'=>'updated failed'], 200);
    }

    $wpdb->update($table, [
      'status'=>'running',
      'updated_at'=>current_time('mysql'),
    ], ['id'=>$job_id]);

    return new \WP_REST_Response(['ok'=>true,'message'=>'running'], 200);
  }

  /* =========================
   * multipart builder
   * ========================= */
  private static function build_multipart($fields, $files) {
    $boundary = '--------------------------' . wp_generate_password(24, false, false);
    $eol = "\r\n";
    $body = '';

    foreach ($fields as $name=>$value) {
      $body .= '--'.$boundary.$eol;
      $body .= 'Content-Disposition: form-data; name="'.$name.'"'.$eol.$eol;
      $body .= (string)$value.$eol;
    }
    foreach ($files as $f) {
      $body .= '--'.$boundary.$eol;
      $body .= 'Content-Disposition: form-data; name="'.$f['fieldname'].'"; filename="'.$f['filename'].'"'.$eol;
      $body .= 'Content-Type: '.$f['type'].$eol.$eol;
      $body .= $f['content'].$eol;
    }
    $body .= '--'.$boundary.'--'.$eol;

    return ['boundary'=>$boundary,'body'=>$body];
  }
}

/**
 * Custom cron interval: 10 minutes
 */
add_filter('cron_schedules', function($schedules){
  if (!isset($schedules['xibao_aigc_10min'])) {
    $schedules['xibao_aigc_10min'] = [
      'interval' => 600,
      'display'  => 'Every 10 Minutes (XIBAO-AIGC)'
    ];
  }
  return $schedules;
});

XibaoAIGC_V1::init();
