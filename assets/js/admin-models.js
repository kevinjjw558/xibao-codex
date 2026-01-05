jQuery(($) => {
    const $doc = $(document);

    const createPreview = ($field, url) => {
        const $preview = $field.closest('td').find('.xibao-models-admin__icon-preview');
        if (!$preview.length) {
            return;
        }

        $preview.empty();
        if (url) {
            $preview.append(`<img src="${url}" alt="" width="48" height="48" />`);
        }
    };

    $doc.on('click', '.xibao-models-icon-button', function (event) {
        event.preventDefault();
        const $button = $(this);
        const $input = $button.closest('.xibao-models-admin__icon-field').find('input');

        const frame = wp.media({
            title: xibaoAigcModels.iconTitle,
            button: { text: xibaoAigcModels.iconButton },
            library: { type: 'image' },
            multiple: false,
        });

        frame.on('select', () => {
            const attachment = frame.state().get('selection').first().toJSON();
            if (attachment && attachment.url) {
                $input.val(attachment.url).trigger('change');
                createPreview($input, attachment.url);
            }
        });

        frame.open();
    });

    $doc.on('change', '#xibao-model-icon', function () {
        const $input = $(this);
        createPreview($input, $input.val());
    });

    $doc.on('submit', '.xibao-models-admin__inline-form', function () {
        return window.confirm(xibaoAigcModels.confirmDelete);
    });
});
