<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Xibao_AIGC_Admin_Models {
    const MENU_SLUG = 'xibao-aigc';
    const CAPABILITY = 'manage_options';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_xibao_aigc_save_model', [__CLASS__, 'handle_save']);
        add_action('admin_post_xibao_aigc_delete_model', [__CLASS__, 'handle_delete']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function register_menu() {
        add_menu_page(
            '模型管理',
            'XIBAO-AIGC',
            self::CAPABILITY,
            self::MENU_SLUG,
            [__CLASS__, 'render_page'],
            'dashicons-art',
            56
        );

        add_submenu_page(
            self::MENU_SLUG,
            '模型管理',
            '模型管理',
            self::CAPABILITY,
            self::MENU_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        $base_url = plugin_dir_url(dirname(__FILE__));
        wp_enqueue_media();
        wp_enqueue_style('xibao-aigc-admin-models', $base_url . 'assets/css/admin-models.css', [], Xibao_AIGC::VERSION);
        wp_enqueue_script('xibao-aigc-admin-models', $base_url . 'assets/js/admin-models.js', ['jquery'], Xibao_AIGC::VERSION, true);
        wp_localize_script('xibao-aigc-admin-models', 'xibaoAigcModels', [
            'iconTitle' => '选择图标',
            'iconButton' => '使用该图标',
            'confirmDelete' => '确定要删除该模型吗？',
        ]);
    }

    public static function render_page() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $models = Xibao_AIGC_Models_Store::get_models();
        $edit_id = isset($_GET['edit']) ? sanitize_key(wp_unslash($_GET['edit'])) : '';
        $model = $edit_id ? Xibao_AIGC_Models_Store::get_model($edit_id) : null;
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $message = '';
        $notice_class = 'notice-success';

        if ($status === 'saved') {
            $message = '模型已保存。';
        } elseif ($status === 'deleted') {
            $message = '模型已删除。';
        } elseif ($status === 'error') {
            $message = '保存失败，请检查输入。';
            $notice_class = 'notice-error';
        }

        $page_title = $model ? '编辑模型' : '新增模型';
        $model = wp_parse_args($model, [
            'id' => '',
            'name' => '',
            'desc' => '',
            'type' => 'image',
            'badge' => '',
            'enabled' => false,
            'sort' => 0,
            'icon_url' => '',
            'credits_base' => 0,
            'note' => '',
        ]);
        ?>
        <div class="wrap xibao-models-admin">
            <h1>模型管理</h1>
            <?php if ($message) : ?>
                <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <div class="xibao-models-admin__layout">
                <div class="xibao-models-admin__list">
                    <h2>模型列表</h2>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th>排序</th>
                            <th>模型</th>
                            <th>类型</th>
                            <th>状态</th>
                            <th>徽章</th>
                            <th>基础积分</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($models)) : ?>
                            <tr>
                                <td colspan="7">暂无模型，请添加。</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($models as $entry) : ?>
                                <tr>
                                    <td><?php echo esc_html($entry['sort']); ?></td>
                                    <td class="xibao-models-admin__model">
                                        <?php if (!empty($entry['icon_url'])) : ?>
                                            <img src="<?php echo esc_url($entry['icon_url']); ?>" alt="" width="32" height="32" />
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo esc_html($entry['name']); ?></strong>
                                            <div class="description"><?php echo esc_html($entry['id']); ?></div>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($entry['type']); ?></td>
                                    <td><?php echo $entry['enabled'] ? '启用' : '停用'; ?></td>
                                    <td><?php echo esc_html($entry['badge'] ?: '—'); ?></td>
                                    <td><?php echo esc_html($entry['credits_base']); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&edit=' . $entry['id'])); ?>">编辑</a>
                                        |
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="xibao-models-admin__inline-form">
                                            <?php wp_nonce_field('xibao_aigc_delete_model'); ?>
                                            <input type="hidden" name="action" value="xibao_aigc_delete_model" />
                                            <input type="hidden" name="model_id" value="<?php echo esc_attr($entry['id']); ?>" />
                                            <button type="submit" class="button-link-delete xibao-models-delete">删除</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="xibao-models-admin__form">
                    <h2><?php echo esc_html($page_title); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('xibao_aigc_save_model'); ?>
                        <input type="hidden" name="action" value="xibao_aigc_save_model" />
                        <input type="hidden" name="original_id" value="<?php echo esc_attr($edit_id); ?>" />

                        <table class="form-table" role="presentation">
                            <tbody>
                            <tr>
                                <th scope="row"><label for="xibao-model-id">模型 ID</label></th>
                                <td>
                                    <input name="model[id]" id="xibao-model-id" type="text" class="regular-text" value="<?php echo esc_attr($model['id']); ?>" required />
                                    <p class="description">唯一标识，可使用小写字母、数字、连字符。</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="xibao-model-name">模型名称</label></th>
                                <td><input name="model[name]" id="xibao-model-name" type="text" class="regular-text" value="<?php echo esc_attr($model['name']); ?>" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="xibao-model-desc">描述</label></th>
                                <td><textarea name="model[desc]" id="xibao-model-desc" rows="3" class="large-text"><?php echo esc_textarea($model['desc']); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="xibao-model-type">类型</label></th>
                                <td>
                                    <select name="model[type]" id="xibao-model-type">
                                        <option value="image" <?php selected($model['type'], 'image'); ?>>image</option>
                                        <option value="video" <?php selected($model['type'], 'video'); ?>>video</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="xibao-model-badge">徽章</label></th>
                                <td>
                                    <select name="model[badge]" id="xibao-model-badge">
                                        <option value="" <?php selected($model['badge'], ''); ?>>无</option>
                                        <option value="NEW" <?php selected($model['badge'], 'NEW'); ?>>NEW</option>
                                        <option value="HOT" <?php selected($model['badge'], 'HOT'); ?>>HOT</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">启用</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="model[enabled]" value="1" <?php checked($model['enabled']); ?> />
                                        启用此模型
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="xibao-model-sort">排序</label></th>
                                <td><input name="model[sort]" id="xibao-model-sort" type="number" class="small-text" value="<?php echo esc_attr($model['sort']); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="xibao-model-icon">图标</label></th>
                                <td>
                                    <div class="xibao-models-admin__icon-field">
                                        <input name="model[icon_url]" id="xibao-model-icon" type="url" class="regular-text" value="<?php echo esc_attr($model['icon_url']); ?>" />
                                        <button type="button" class="button xibao-models-icon-button">选择图标</button>
                                    </div>
                                    <div class="xibao-models-admin__icon-preview">
                                        <?php if (!empty($model['icon_url'])) : ?>
                                            <img src="<?php echo esc_url($model['icon_url']); ?>" alt="" width="48" height="48" />
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="xibao-model-credits">基础积分</label></th>
                                <td><input name="model[credits_base]" id="xibao-model-credits" type="number" step="0.1" class="small-text" value="<?php echo esc_attr($model['credits_base']); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="xibao-model-note">备注</label></th>
                                <td><textarea name="model[note]" id="xibao-model-note" rows="3" class="large-text"><?php echo esc_textarea($model['note']); ?></textarea></td>
                            </tr>
                            </tbody>
                        </table>

                        <?php submit_button($edit_id ? '保存模型' : '新增模型'); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public static function handle_save() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('权限不足。');
        }

        check_admin_referer('xibao_aigc_save_model');

        $model = isset($_POST['model']) ? wp_unslash($_POST['model']) : [];
        $original_id = isset($_POST['original_id']) ? wp_unslash($_POST['original_id']) : '';

        $result = Xibao_AIGC_Models_Store::upsert_model($model, $original_id);

        if (is_wp_error($result)) {
            $redirect = add_query_arg('status', 'error', admin_url('admin.php?page=' . self::MENU_SLUG));
            wp_safe_redirect($redirect);
            exit;
        }

        $redirect = add_query_arg('status', 'saved', admin_url('admin.php?page=' . self::MENU_SLUG));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('权限不足。');
        }

        check_admin_referer('xibao_aigc_delete_model');

        $model_id = isset($_POST['model_id']) ? sanitize_key(wp_unslash($_POST['model_id'])) : '';
        if ($model_id) {
            Xibao_AIGC_Models_Store::delete_model($model_id);
        }

        $redirect = add_query_arg('status', 'deleted', admin_url('admin.php?page=' . self::MENU_SLUG));
        wp_safe_redirect($redirect);
        exit;
    }
}
