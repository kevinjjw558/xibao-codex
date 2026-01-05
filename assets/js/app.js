(() => {
    const setupModelSelection = (root) => {
        const cards = Array.from(root.querySelectorAll('.xibao-model-card'));
        if (!cards.length) {
            return;
        }

        const activateCard = (card) => {
            cards.forEach((item) => item.classList.remove('is-active'));
            card.classList.add('is-active');
            root.dataset.activeModel = card.dataset.modelId || '';
        };

        cards.forEach((card) => {
            card.addEventListener('click', () => activateCard(card));
        });
    };

    const setupParamsToggle = (root) => {
        const toggle = root.querySelector('[data-xibao-toggle="params"]');
        const params = root.querySelector('[data-xibao-params]');
        if (!toggle || !params) {
            return;
        }

        toggle.addEventListener('click', () => {
            params.classList.toggle('is-collapsed');
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        const blocks = document.querySelectorAll('.xibao-aigc');
        blocks.forEach((block) => {
            setupModelSelection(block);
            setupParamsToggle(block);
        });
    });
})();
