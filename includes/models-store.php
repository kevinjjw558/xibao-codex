<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Xibao_AIGC_Models_Store {
    const OPTION = 'xibao_aigc_models';

    public static function get_models() {
        $models = get_option(self::OPTION);
        if (!is_array($models) || empty($models)) {
            $models = self::default_models();
            update_option(self::OPTION, $models);
        }

        return self::normalize_models($models);
    }

    public static function get_enabled_models() {
        $models = array_values(array_filter(self::get_models(), function ($model) {
            return !empty($model['enabled']);
        }));

        usort($models, function ($left, $right) {
            return (int) $left['sort'] <=> (int) $right['sort'];
        });

        return $models;
    }

    public static function get_model($id) {
        $id = sanitize_key($id);
        foreach (self::get_models() as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
        }

        return null;
    }

    public static function upsert_model($model, $original_id = '') {
        $models = self::get_models();
        $model = self::sanitize_model($model);
        $original_id = sanitize_key($original_id);

        if (empty($model['id'])) {
            return new WP_Error('xibao_models_missing_id', '模型 ID 不能为空。');
        }

        if (empty($model['name'])) {
            return new WP_Error('xibao_models_missing_name', '模型名称不能为空。');
        }

        $existing_ids = array_column($models, 'id');
        if ($original_id && $original_id !== $model['id'] && in_array($model['id'], $existing_ids, true)) {
            return new WP_Error('xibao_models_duplicate_id', '模型 ID 已存在，请使用其他 ID。');
        }

        $updated = false;
        foreach ($models as $index => $existing_model) {
            if ($existing_model['id'] === $original_id || $existing_model['id'] === $model['id']) {
                $models[$index] = $model;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $models[] = $model;
        }

        update_option(self::OPTION, $models);

        return $model;
    }

    public static function delete_model($id) {
        $id = sanitize_key($id);
        $models = self::get_models();
        $filtered = array_values(array_filter($models, function ($model) use ($id) {
            return $model['id'] !== $id;
        }));

        if (count($filtered) === count($models)) {
            return false;
        }

        update_option(self::OPTION, $filtered);
        return true;
    }

    private static function normalize_models($models) {
        $normalized = [];
        foreach ($models as $model) {
            $normalized[] = self::sanitize_model($model);
        }

        return $normalized;
    }

    private static function sanitize_model($model) {
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

        $type = in_array($model['type'], ['image', 'video'], true) ? $model['type'] : 'image';
        $badge = in_array($model['badge'], ['', 'NEW', 'HOT'], true) ? $model['badge'] : '';

        return [
            'id' => sanitize_key($model['id']),
            'name' => sanitize_text_field($model['name']),
            'desc' => sanitize_textarea_field($model['desc']),
            'type' => $type,
            'badge' => $badge,
            'enabled' => !empty($model['enabled']),
            'sort' => (int) $model['sort'],
            'icon_url' => esc_url_raw($model['icon_url']),
            'credits_base' => (float) $model['credits_base'],
            'note' => sanitize_textarea_field($model['note']),
        ];
    }

    private static function default_models() {
        return [
            [
                'id' => 'nano-banana',
                'name' => 'Nano Banana',
                'desc' => 'Fast, crisp image generation with balanced detail.',
                'badge' => 'NEW',
                'icon_url' => 'https://via.placeholder.com/40',
                'type' => 'image',
                'enabled' => true,
                'sort' => 10,
                'credits_base' => 1,
                'note' => '',
            ],
            [
                'id' => 'nano-banana-2',
                'name' => 'Nano Banana 2',
                'desc' => 'Sharper textures and improved lighting control.',
                'badge' => '',
                'icon_url' => 'https://via.placeholder.com/40',
                'type' => 'image',
                'enabled' => true,
                'sort' => 20,
                'credits_base' => 1.5,
                'note' => '',
            ],
            [
                'id' => 'sora2',
                'name' => 'Sora2',
                'desc' => 'Cinematic video generation with rich motion.',
                'badge' => 'HOT',
                'icon_url' => 'https://via.placeholder.com/40',
                'type' => 'video',
                'enabled' => true,
                'sort' => 30,
                'credits_base' => 3,
                'note' => '',
            ],
        ];
    }
}
