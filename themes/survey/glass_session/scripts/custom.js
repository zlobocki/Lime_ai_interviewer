/******************
    Glass Session — custom.js
    ---------------------------
    Light enhancements only. No branding text injected.
******************/

(function () {
    'use strict';

    function enhanceFocusRings() {
        document.addEventListener('focusin', function (event) {
            var el = event.target;
            if (!el || !el.classList) {
                return;
            }
            if (el.classList.contains('form-control') || el.classList.contains('ai-interview-input')) {
                el.classList.add('gs-focus-live');
            }
        });

        document.addEventListener('focusout', function (event) {
            var el = event.target;
            if (el && el.classList) {
                el.classList.remove('gs-focus-live');
            }
        });
    }

    function markReady() {
        if (document.body) {
            document.body.classList.add('glass-session-ready');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            markReady();
            enhanceFocusRings();
        });
    } else {
        markReady();
        enhanceFocusRings();
    }
})();
