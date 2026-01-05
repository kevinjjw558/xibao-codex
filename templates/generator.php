<?php
if (!defined('ABSPATH')) {
    exit;
}

$models = $models ?? [];
$active_model = !empty($models) ? $models[0]['id'] : '';
?>
<div class="xibao-aigc" data-active-model="<?php echo esc_attr($active_model); ?>">
    <div class="xibao-aigc__shell">
        <aside class="xibao-aigc__sidebar">
            <div class="xibao-aigc__panel-header">
                <div>
                    <p class="xibao-aigc__eyebrow">Model Gallery</p>
                    <h2 class="xibao-aigc__title">Choose a foundation model</h2>
                </div>
                <button class="xibao-aigc__toggle" type="button" data-xibao-toggle="params">
                    Toggle Params
                </button>
            </div>

            <div class="xibao-aigc__models">
                <?php foreach ($models as $index => $model) : ?>
                    <button
                        class="xibao-model-card<?php echo $index === 0 ? ' is-active' : ''; ?>"
                        type="button"
                        data-model-id="<?php echo esc_attr($model['id']); ?>"
                        data-model-type="<?php echo esc_attr($model['type']); ?>"
                    >
                        <span class="xibao-model-card__icon">
                            <img
                                src="<?php echo esc_url($model['icon_url']); ?>"
                                alt=""
                                width="40"
                                height="40"
                                loading="lazy"
                            />
                        </span>
                        <span class="xibao-model-card__content">
                            <span class="xibao-model-card__name">
                                <?php echo esc_html($model['name']); ?>
                            </span>
                            <span class="xibao-model-card__desc">
                                <?php echo esc_html($model['desc']); ?>
                            </span>
                            <span class="xibao-model-card__meta">
                                <span class="xibao-model-card__type">
                                    <?php echo esc_html(ucfirst($model['type'])); ?>
                                </span>
                                <?php if (!empty($model['badge'])) : ?>
                                    <span class="xibao-badge xibao-badge--<?php echo esc_attr(strtolower($model['badge'])); ?>">
                                        <?php echo esc_html($model['badge']); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="xibao-aigc__params" data-xibao-params>
                <div class="xibao-aigc__params-header">
                    <h3 class="xibao-aigc__section-title">Generation Parameters</h3>
                    <span class="xibao-aigc__section-hint">Static mockup</span>
                </div>
                <div class="xibao-aigc__field">
                    <label class="xibao-aigc__label">Aspect Ratio</label>
                    <div class="xibao-aigc__chips">
                        <span class="xibao-aigc__chip is-active">1:1</span>
                        <span class="xibao-aigc__chip">2:3</span>
                        <span class="xibao-aigc__chip">16:9</span>
                    </div>
                </div>
                <div class="xibao-aigc__field">
                    <label class="xibao-aigc__label">Quality</label>
                    <div class="xibao-aigc__slider">
                        <span>Standard</span>
                        <div class="xibao-aigc__bar"><span class="xibao-aigc__bar-fill"></span></div>
                        <span>Ultra</span>
                    </div>
                </div>
                <div class="xibao-aigc__field">
                    <label class="xibao-aigc__label">Style Preset</label>
                    <div class="xibao-aigc__select">Cinematic</div>
                </div>
            </div>
        </aside>

        <main class="xibao-aigc__workspace">
            <div class="xibao-aigc__workspace-header">
                <div>
                    <p class="xibao-aigc__eyebrow">Workspace</p>
                    <h2 class="xibao-aigc__title">Compose your prompt</h2>
                </div>
                <div class="xibao-aigc__status">
                    <span class="xibao-status-dot"></span>
                    Ready
                </div>
            </div>

            <div class="xibao-aigc__prompt">
                <textarea class="xibao-aigc__textarea" placeholder="Describe the scene you want to generate..."></textarea>
                <div class="xibao-aigc__prompt-actions">
                    <div class="xibao-aigc__pill">Seed: 21842</div>
                    <div class="xibao-aigc__pill">Steps: 30</div>
                    <div class="xibao-aigc__pill">CFG: 7.5</div>
                </div>
            </div>

            <div class="xibao-aigc__workspace-body">
                <div class="xibao-aigc__preview">
                    <div class="xibao-aigc__preview-inner">
                        <span class="xibao-aigc__preview-label">Preview Output</span>
                        <div class="xibao-aigc__preview-frame">
                            <span class="xibao-aigc__preview-placeholder">Image / Video will appear here</span>
                        </div>
                        <div class="xibao-aigc__preview-actions">
                            <button class="xibao-aigc__button" type="button">Generate</button>
                            <button class="xibao-aigc__button xibao-aigc__button--ghost" type="button">Reset</button>
                        </div>
                    </div>
                </div>
                <div class="xibao-aigc__queue">
                    <h3 class="xibao-aigc__section-title">Recent Generations</h3>
                    <div class="xibao-aigc__queue-item">
                        <span class="xibao-aigc__queue-title">Neon skyline</span>
                        <span class="xibao-aigc__queue-meta">Nano Banana · 2 mins ago</span>
                    </div>
                    <div class="xibao-aigc__queue-item">
                        <span class="xibao-aigc__queue-title">Ambient forest</span>
                        <span class="xibao-aigc__queue-meta">Sora2 · 12 mins ago</span>
                    </div>
                    <div class="xibao-aigc__queue-item">
                        <span class="xibao-aigc__queue-title">Tech portrait</span>
                        <span class="xibao-aigc__queue-meta">Nano Banana 2 · 30 mins ago</span>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
