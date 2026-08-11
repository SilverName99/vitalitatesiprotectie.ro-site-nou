/*
 * Comportament pentru conținutul migrat din WordPress (marcaj `wk-*`).
 *
 * Tema veche folosea JavaScript pentru acordeoane și carusele. Aici e
 * reimplementat strictul necesar, fără dependențe.
 *
 * Principiu: fără acest fișier pagina rămâne complet lizibilă (CSS-ul lasă
 * tot conținutul vizibil). Scriptul doar adaugă interactivitate peste.
 */
(function () {
    'use strict';

    function initAccordions(root) {
        root.querySelectorAll('.wk-scope .wk-accordion').forEach(function (accordion) {
            if (accordion.dataset.wkReady === '1') {
                return;
            }
            accordion.dataset.wkReady = '1';

            var items = Array.prototype.filter.call(accordion.children, function (el) {
                return el.querySelector(':scope > .wk-accordion-title');
            });
            if (items.length === 0) {
                return;
            }

            // Atributul `hidden` din marcajul vechi ar ascunde conținutul chiar
            // și când elementul e deschis; vizibilitatea o controlează CSS-ul.
            accordion.querySelectorAll('.wk-accordion-content[hidden]').forEach(function (content) {
                content.removeAttribute('hidden');
            });

            accordion.classList.add('is-interactive');
            if (!items.some(function (item) { return item.classList.contains('wk-open'); })) {
                items[0].classList.add('wk-open');
            }

            items.forEach(function (item) {
                var title = item.querySelector(':scope > .wk-accordion-title');
                if (!title) {
                    return;
                }
                title.setAttribute('role', 'button');
                title.setAttribute('tabindex', '0');
                title.setAttribute('aria-expanded', item.classList.contains('wk-open') ? 'true' : 'false');

                function toggle(event) {
                    event.preventDefault();
                    var willOpen = !item.classList.contains('wk-open');
                    items.forEach(function (other) {
                        other.classList.remove('wk-open');
                        var otherTitle = other.querySelector(':scope > .wk-accordion-title');
                        if (otherTitle) {
                            otherTitle.setAttribute('aria-expanded', 'false');
                        }
                    });
                    if (willOpen) {
                        item.classList.add('wk-open');
                        title.setAttribute('aria-expanded', 'true');
                    }
                }

                title.addEventListener('click', toggle);
                title.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        toggle(event);
                    }
                });
            });
        });
    }

    function initSliders(root) {
        root.querySelectorAll('.wk-scope .wk-slider-items').forEach(function (track) {
            if (track.dataset.wkReady === '1' || track.children.length < 2) {
                return;
            }
            track.dataset.wkReady = '1';

            var wrap = document.createElement('div');
            wrap.className = 'wk-compat-slider';
            track.parentNode.insertBefore(wrap, track);
            wrap.appendChild(track);

            [['‹', -1], ['›', 1]].forEach(function (pair) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'wk-compat-slider__nav wk-compat-slider__nav--' + (pair[1] < 0 ? 'prev' : 'next');
                button.setAttribute('aria-label', pair[1] < 0 ? 'Imaginea anterioară' : 'Imaginea următoare');
                button.textContent = pair[0];
                button.addEventListener('click', function () {
                    track.scrollBy({ left: pair[1] * track.clientWidth, behavior: 'smooth' });
                });
                wrap.appendChild(button);
            });
        });
    }

    function init() {
        initAccordions(document);
        initSliders(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
