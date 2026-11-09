/**
 * Replace Bootstrap icon font elements with local SVG symbols.
 * This keeps the existing bi bi-* markup working when webfonts are blocked
 * or unavailable in a browser.
 */
(function () {
    'use strict';

    function replaceIcons() {
        var baseMeta = document.querySelector('meta[name="webmail-base-url"]');
        var baseUrl = baseMeta ? (baseMeta.getAttribute('content') || '') : '';
        var spriteUrl = baseUrl + '/assets/icons/bootstrap-icons.svg';

        document.querySelectorAll('i.bi').forEach(function (icon) {
            if (icon.dataset.svgIcon === 'true') {
                return;
            }

            var iconClass = Array.from(icon.classList).find(function (name) {
                return name.indexOf('bi-') === 0;
            });

            if (!iconClass || !/^[a-z0-9-]+$/.test(iconClass.slice(3))) {
                return;
            }

            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
            var iconName = iconClass.slice(3);

            svg.setAttribute('class', icon.className);
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('focusable', 'false');
            svg.setAttribute('viewBox', '0 0 16 16');
            use.setAttribute('href', spriteUrl + '#' + iconName);
            svg.appendChild(use);

            Array.from(icon.attributes).forEach(function (attribute) {
                if (attribute.name !== 'class') {
                    svg.setAttribute(attribute.name, attribute.value);
                }
            });

            icon.replaceWith(svg);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', replaceIcons);
    } else {
        replaceIcons();
    }
})();
