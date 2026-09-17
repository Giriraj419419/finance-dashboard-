/**
 * Finance Dashboard — vanilla JS shell.
 *
 * Everything is namespaced under window.App and initialised on DOMContentLoaded.
 * No external libraries. No bundling.
 */
(function () {
    'use strict';

    var App = (window.App = window.App || {});

    // --------------------------------------------------------------------
    // Sidebar (mobile) toggle
    // --------------------------------------------------------------------
    App.sidebar = {
        init: function () {
            var toggle = document.querySelector('[data-sidebar-toggle]');
            var sidebar = document.querySelector('[data-sidebar]');
            var backdrop = document.querySelector('[data-sidebar-backdrop]');
            if (!toggle || !sidebar) return;

            var close = function () {
                sidebar.classList.remove('is-open');
                if (backdrop) backdrop.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            };
            var open = function () {
                sidebar.classList.add('is-open');
                if (backdrop) backdrop.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
            };

            toggle.addEventListener('click', function () {
                if (sidebar.classList.contains('is-open')) {
                    close();
                } else {
                    open();
                }
            });
            if (backdrop) {
                backdrop.addEventListener('click', close);
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') close();
            });
            window.addEventListener('resize', function () {
                if (window.innerWidth > 768) close();
            });
        }
    };

    // --------------------------------------------------------------------
    // User dropdown
    // --------------------------------------------------------------------
    App.userMenu = {
        init: function () {
            var trigger = document.querySelector('[data-user-menu-trigger]');
            var panel = document.querySelector('[data-user-menu-panel]');
            if (!trigger || !panel) return;

            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                panel.classList.toggle('is-open');
                trigger.setAttribute(
                    'aria-expanded',
                    panel.classList.contains('is-open') ? 'true' : 'false'
                );
            });
            document.addEventListener('click', function (e) {
                if (!panel.contains(e.target) && e.target !== trigger) {
                    panel.classList.remove('is-open');
                    trigger.setAttribute('aria-expanded', 'false');
                }
            });
        }
    };

    // --------------------------------------------------------------------
    // Flash message dismissal
    // --------------------------------------------------------------------
    App.flash = {
        init: function () {
            var flashes = document.querySelectorAll('[data-flash]');
            flashes.forEach(function (node) {
                var close = node.querySelector('[data-flash-close]');
                if (close) {
                    close.addEventListener('click', function () {
                        node.remove();
                    });
                }
                setTimeout(function () {
                    if (node.parentNode) node.remove();
                }, 6000);
            });
        }
    };

    // --------------------------------------------------------------------
    // Simple form validation hook (client-side aid; server still validates)
    // --------------------------------------------------------------------
    App.forms = {
        init: function () {
            var forms = document.querySelectorAll('form[data-validate]');
            forms.forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    var invalid = false;
                    form.querySelectorAll('[required]').forEach(function (field) {
                        var wrap = field.closest('.field');
                        var errEl = wrap ? wrap.querySelector('.error') : null;
                        var value = String(field.value || '').trim();
                        if (!value) {
                            invalid = true;
                            if (wrap) wrap.classList.add('field--error');
                            if (errEl) errEl.textContent = 'This field is required.';
                        } else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                            invalid = true;
                            if (wrap) wrap.classList.add('field--error');
                            if (errEl) errEl.textContent = 'Enter a valid email address.';
                        } else {
                            if (wrap) wrap.classList.remove('field--error');
                            if (errEl) errEl.textContent = '';
                        }
                    });
                    if (invalid) e.preventDefault();
                });
            });
        }
    };

    // --------------------------------------------------------------------
    // Lightweight inline SVG line chart for the dashboard placeholder.
    // Renders from data-values="1,2,3,..." on a <div class="chart"> element.
    // --------------------------------------------------------------------
    App.chart = {
        init: function () {
            document.querySelectorAll('[data-chart]').forEach(function (host) {
                var raw = host.getAttribute('data-values') || '';
                var values = raw.split(',').map(function (v) { return parseFloat(v); }).filter(function (v) { return !isNaN(v); });
                if (values.length < 2) return;

                var w = host.clientWidth || 600;
                var h = host.clientHeight || 220;
                var pad = 24;
                var min = Math.min.apply(null, values);
                var max = Math.max.apply(null, values);
                var range = (max - min) || 1;

                var stepX = (w - pad * 2) / (values.length - 1);
                var points = values.map(function (v, i) {
                    var x = pad + i * stepX;
                    var y = h - pad - ((v - min) / range) * (h - pad * 2);
                    return x + ',' + y;
                });

                var linePath = 'M ' + points.join(' L ');
                var areaPath = linePath + ' L ' + (w - pad) + ',' + (h - pad) + ' L ' + pad + ',' + (h - pad) + ' Z';

                host.innerHTML =
                    '<svg viewBox="0 0 ' + w + ' ' + h + '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Chart">' +
                        '<defs>' +
                            '<linearGradient id="chartFill" x1="0" y1="0" x2="0" y2="1">' +
                                '<stop offset="0%" stop-color="#2563eb" stop-opacity="0.28"/>' +
                                '<stop offset="100%" stop-color="#2563eb" stop-opacity="0"/>' +
                            '</linearGradient>' +
                        '</defs>' +
                        '<path d="' + areaPath + '" fill="url(#chartFill)"/>' +
                        '<path d="' + linePath + '" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>' +
                    '</svg>';
            });
        }
    };

    // --------------------------------------------------------------------
    // Progress bars — hydrate width from data-percent so templates stay
    // free of inline style attributes.
    // --------------------------------------------------------------------
    App.progress = {
        init: function () {
            document.querySelectorAll('.progress__bar[data-percent]').forEach(function (bar) {
                var pct = parseFloat(bar.getAttribute('data-percent'));
                if (isNaN(pct)) return;
                if (pct < 0) pct = 0;
                if (pct > 100) pct = 100;
                bar.style.width = pct + '%';
            });
        }
    };

    // --------------------------------------------------------------------
    // Boot
    // --------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        App.sidebar.init();
        App.userMenu.init();
        App.flash.init();
        App.forms.init();
        App.chart.init();
        App.progress.init();
        // Re-render charts on resize so they stay crisp.
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(App.chart.init, 200);
        });
    });
})();
