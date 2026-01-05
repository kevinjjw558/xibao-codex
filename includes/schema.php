<?php
if (!defined('ABSPATH')) exit;

/**
 * XIBAO-AIGC Parameter Schema Registry (Milestone 3 - Step 1)
 * - Defines reusable parameter schemas for different model types/modes.
 * - Later we will bind a model -> schema_key, and frontend renders by schema.
 */

function xibao_aigc_get_schema_registry() {
  return [
    // ===== Image: default (txt2img) =====
    'image_default' => [
      'title' => 'Image Default',
      'fields' => [
        [
          'key' => 'prompt',
          'label' => 'Prompt',
          'type' => 'textarea',
          'required' => true,
          'placeholder' => 'Describe what you want to generate...'
        ],
        [
          'key' => 'negative_prompt',
          'label' => 'Negative Prompt',
          'type' => 'textarea',
          'required' => false,
          'placeholder' => 'Things to avoid...'
        ],
        [
          'key' => 'aspect_ratio',
          'label' => 'Aspect Ratio',
          'type' => 'select',
          'default' => '1:1',
          'options' => [
            ['value'=>'1:1','label'=>'1:1'],
            ['value'=>'16:9','label'=>'16:9'],
            ['value'=>'9:16','label'=>'9:16'],
            ['value'=>'4:3','label'=>'4:3'],
            ['value'=>'3:4','label'=>'3:4'],
          ],
        ],
        [
          'key' => 'quality',
          'label' => 'Quality',
          'type' => 'select',
          'default' => 'standard',
          'options' => [
            ['value'=>'standard','label'=>'Standard'],
            ['value'=>'high','label'=>'High'],
          ],
        ],
        [
          'key' => 'seed',
          'label' => 'Seed',
          'type' => 'number',
          'default' => 0,
          'min' => 0,
          'max' => 2147483647,
          'help' => '0 means random seed.',
        ],
      ],
    ],

    // ===== Video: default (t2v) =====
    'video_default' => [
      'title' => 'Video Default',
      'fields' => [
        [
          'key' => 'prompt',
          'label' => 'Prompt',
          'type' => 'textarea',
          'required' => true,
          'placeholder' => 'Describe the video scene...'
        ],
        [
          'key' => 'duration',
          'label' => 'Duration',
          'type' => 'select',
          'default' => '10',
          'options' => [
            ['value'=>'10','label'=>'10s'],
            ['value'=>'15','label'=>'15s'],
          ],
        ],
        [
          'key' => 'hd',
          'label' => 'HD',
          'type' => 'toggle',
          'default' => 0,
          'help' => 'Enable HD if supported by the model.'
        ],
        [
          'key' => 'aspect_ratio',
          'label' => 'Aspect Ratio',
          'type' => 'select',
          'default' => '16:9',
          'options' => [
            ['value'=>'16:9','label'=>'16:9'],
            ['value'=>'9:16','label'=>'9:16'],
            ['value'=>'1:1','label'=>'1:1'],
          ],
        ],
      ],
    ],
  ];
}

/**
 * Helper: get a schema by key.
 */
function xibao_aigc_get_schema($schema_key) {
  $all = xibao_aigc_get_schema_registry();
  return isset($all[$schema_key]) ? $all[$schema_key] : null;
}
