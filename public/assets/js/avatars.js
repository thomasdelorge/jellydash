(function () {
    'use strict';

    function reveal(img) {
        img.hidden = false;
        const avatar = img.closest('.watcher-avatar');
        if (avatar) {
            avatar.classList.add('has-image');
        }
    }

    function bind(root) {
        const images = (root || document).querySelectorAll('[data-avatar-img]');
        images.forEach(function (img) {
            if (img.dataset.avatarBound === '1') {
                return;
            }
            img.dataset.avatarBound = '1';

            if (img.complete && img.naturalWidth > 0) {
                reveal(img);
                return;
            }

            img.addEventListener('load', function () {
                reveal(img);
            });
            img.addEventListener('error', function () {
                img.remove();
            });
        });
    }

    bind();

    if (typeof MutationObserver === 'function' && document.body) {
        new MutationObserver(function () {
            bind();
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
