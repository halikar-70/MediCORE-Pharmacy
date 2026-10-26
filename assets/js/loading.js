/**
 * assets/js/loading.js
 * MediPro HMS — Centralized Shimmer Skeleton Loading Controller
 * 
 * Provides a unified API for:
 * - Table Skeletons
 * - Card / KPI Skeletons
 * - Form & Button Loading States
 * - Async Promise Wrappers with Delay Threshold
 * - Screen Reader Accessibility (aria-busy & announcements)
 * - Safe Navigation Progress Bar
 */

(function (window, document) {
    'use strict';

    // 1. Central Configuration
    const Config = {
        delayThreshold: 180,       // ms delay to avoid flashing on ultra-fast requests
        maxTimeout: 10000,          // ms safety timeout to prevent infinite skeletons
        shimmerSpeed: 1800,        // ms
        announceLoading: 'Loading content, please wait...',
        announceLoaded: 'Content loaded successfully.'
    };

    // 2. Accessibility Live Region Helper
    let liveRegion = null;
    function getLiveRegion() {
        if (!liveRegion) {
            liveRegion = document.getElementById('medipro-a11y-status');
            if (!liveRegion) {
                liveRegion = document.createElement('div');
                liveRegion.id = 'medipro-a11y-status';
                liveRegion.setAttribute('role', 'status');
                liveRegion.setAttribute('aria-live', 'polite');
                liveRegion.className = 'visually-hidden';
                liveRegion.style.position = 'absolute';
                liveRegion.style.width = '1px';
                liveRegion.style.height = '1px';
                liveRegion.style.padding = '0';
                liveRegion.style.margin = '-1px';
                liveRegion.style.overflow = 'hidden';
                liveRegion.style.clip = 'rect(0, 0, 0, 0)';
                liveRegion.style.whiteSpace = 'nowrap';
                liveRegion.style.border = '0';
                document.body.appendChild(liveRegion);
            }
        }
        return liveRegion;
    }

    function announce(msg) {
        try {
            const region = getLiveRegion();
            if (region) {
                region.textContent = '';
                setTimeout(() => {
                    region.textContent = msg;
                }, 50);
            }
        } catch (e) {
            // Ignore error
        }
    }

    // 3. Navigation Progress Bar Controller
    let navProgressBar = null;
    function getNavProgressBar() {
        if (!navProgressBar) {
            navProgressBar = document.getElementById('medipro-nav-progress');
            if (!navProgressBar) {
                navProgressBar = document.createElement('div');
                navProgressBar.id = 'medipro-nav-progress';
                navProgressBar.className = 'medipro-nav-progress';
                document.body.appendChild(navProgressBar);
            }
        }
        return navProgressBar;
    }

    const PageLoader = {
        timer: null,
        start: function (delay = Config.delayThreshold) {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => {
                const bar = getNavProgressBar();
                bar.classList.remove('done');
                bar.classList.add('active');
            }, delay);
        },
        done: function () {
            clearTimeout(this.timer);
            const bar = getNavProgressBar();
            bar.classList.remove('active');
            bar.classList.add('done');
            setTimeout(() => {
                bar.classList.remove('done');
            }, 300);
        }
    };

    // 4. Skeleton HTML Generators
    const Templates = {
        table: function (options = {}) {
            const rows = options.rows || 5;
            const cols = options.cols || 5;
            const widths = options.colWidths || [];

            let html = '<div class="skeleton-table-wrap" aria-hidden="true">';
            
            // Header Row
            html += '<div class="skeleton-table-header">';
            for (let c = 0; c < cols; c++) {
                const w = widths[c] || (100 / cols) + '%';
                html += `<div class="skeleton-cell" style="width: ${w}; height: 16px; flex: none;"></div>`;
            }
            html += '</div>';

            // Data Rows
            for (let r = 0; r < rows; r++) {
                html += '<div class="skeleton-table-row">';
                for (let c = 0; c < cols; c++) {
                    const w = widths[c] || (100 / cols) + '%';
                    const h = (c === 0) ? '16px' : '14px';
                    html += `<div class="skeleton-cell" style="width: ${w}; height: ${h}; flex: none;"></div>`;
                }
                html += '</div>';
            }

            html += '</div>';
            return html;
        },

        kpi: function (options = {}) {
            const count = options.count || 4;
            let html = '<div class="row g-3" aria-hidden="true">';
            for (let i = 0; i < count; i++) {
                html += `
                    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
                        <div class="skeleton-kpi-card">
                            <div class="skeleton-kpi-header">
                                <div class="skeleton-avatar" style="width: 38px; height: 38px;"></div>
                                <div class="skeleton-badge" style="width: 50px;"></div>
                            </div>
                            <div class="skeleton-heading" style="width: 65%; height: 1.5rem; margin-bottom: 6px;"></div>
                            <div class="skeleton-text" style="width: 45%; height: 0.8rem; margin-bottom: 0;"></div>
                        </div>
                    </div>
                `;
            }
            html += '</div>';
            return html;
        },

        card: function (options = {}) {
            return `
                <div class="skeleton-card" aria-hidden="true">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="skeleton-heading mb-0" style="width: 40%; height: 1.2rem;"></div>
                        <div class="skeleton-button" style="width: 70px; height: 26px;"></div>
                    </div>
                    <div class="skeleton-text w-90"></div>
                    <div class="skeleton-text w-75"></div>
                    <div class="skeleton-text w-50 mb-0"></div>
                </div>
            `;
        },

        chart: function (options = {}) {
            return `
                <div class="skeleton-chart-box" aria-hidden="true">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="skeleton-heading mb-0" style="width: 35%; height: 1.2rem;"></div>
                        <div class="skeleton-badge" style="width: 80px;"></div>
                    </div>
                    <div class="skeleton-chart-bars">
                        <div class="skeleton-chart-bar" style="height: 40%;"></div>
                        <div class="skeleton-chart-bar" style="height: 75%;"></div>
                        <div class="skeleton-chart-bar" style="height: 55%;"></div>
                        <div class="skeleton-chart-bar" style="height: 90%;"></div>
                        <div class="skeleton-chart-bar" style="height: 65%;"></div>
                        <div class="skeleton-chart-bar" style="height: 80%;"></div>
                        <div class="skeleton-chart-bar" style="height: 45%;"></div>
                    </div>
                </div>
            `;
        }
    };

    // Store active targets and their original HTML / timers
    const activeStates = new WeakMap();

    // 5. Main MediProLoading API
    const MediProLoading = {
        config: Config,
        page: PageLoader,
        templates: Templates,

        /**
         * Render a skeleton placeholder in a container element
         * @param {HTMLElement|string} target Container element or selector
         * @param {string} type 'table' | 'kpi' | 'card' | 'chart' | 'custom'
         * @param {Object} options Configuration options for generator
         */
        show: function (target, type = 'card', options = {}) {
            const el = typeof target === 'string' ? document.querySelector(target) : target;
            if (!el) return null;

            // If already loading on this element, return existing handle
            if (activeStates.has(el)) {
                return activeStates.get(el);
            }

            const state = {
                originalHTML: el.innerHTML,
                originalAriaBusy: el.getAttribute('aria-busy'),
                timeoutTimer: null
            };

            // Set accessibility attributes
            el.setAttribute('aria-busy', 'true');
            el.classList.add('skeleton-wrapper');
            announce(options.announceText || Config.announceLoading);

            // Generate skeleton markup
            let skeletonMarkup = '';
            if (typeof type === 'function') {
                skeletonMarkup = type(options);
            } else if (Templates[type]) {
                skeletonMarkup = Templates[type](options);
            } else {
                skeletonMarkup = Templates.card(options);
            }

            el.innerHTML = skeletonMarkup;

            // Maximum Safety Timeout: auto-restore if request hangs forever
            const maxTimeout = options.maxTimeout || Config.maxTimeout;
            state.timeoutTimer = setTimeout(() => {
                console.warn('MediProLoading: Safety timeout triggered for element', el);
                MediProLoading.hide(el);
            }, maxTimeout);

            activeStates.set(el, state);
            return state;
        },

        /**
         * Restore container element to loaded state
         * @param {HTMLElement|string} target 
         * @param {string|null} newContent Optional replacement content
         */
        hide: function (target, newContent = null) {
            const el = typeof target === 'string' ? document.querySelector(target) : target;
            if (!el) return;

            const state = activeStates.get(el);
            if (state) {
                clearTimeout(state.timeoutTimer);
                if (newContent !== null) {
                    el.innerHTML = newContent;
                } else if (state.originalHTML !== undefined) {
                    el.innerHTML = state.originalHTML;
                }
                if (state.originalAriaBusy !== null) {
                    el.setAttribute('aria-busy', state.originalAriaBusy);
                } else {
                    el.removeAttribute('aria-busy');
                }
                activeStates.delete(el);
            } else {
                if (newContent !== null) el.innerHTML = newContent;
                el.removeAttribute('aria-busy');
            }

            el.classList.remove('skeleton-wrapper');
            announce(Config.announceLoaded);
        },

        /**
         * Table Skeleton Convenience Method
         */
        table: function (target, options = {}) {
            return this.show(target, 'table', options);
        },

        /**
         * Card / KPI Skeleton Convenience Method
         */
        card: function (target, options = {}) {
            return this.show(target, options.type || 'card', options);
        },

        /**
         * Button Loading State Helper
         */
        button: function (buttonEl, loadingText = 'Processing...') {
            const btn = typeof buttonEl === 'string' ? document.querySelector(buttonEl) : buttonEl;
            if (!btn || btn.disabled) return null;

            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.setAttribute('data-original-html', originalHTML);
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1.5" role="status" aria-hidden="true" style="width: 0.85rem; height: 0.85rem;"></span>${loadingText}`;
            return btn;
        },

        /**
         * Restore Button State
         */
        buttonRestore: function (buttonEl) {
            const btn = typeof buttonEl === 'string' ? document.querySelector(buttonEl) : buttonEl;
            if (!btn) return;

            const originalHTML = btn.getAttribute('data-original-html');
            if (originalHTML) {
                btn.innerHTML = originalHTML;
                btn.removeAttribute('data-original-html');
            }
            btn.disabled = false;
            btn.classList.remove('is-loading');
        },

        /**
         * Execute an async promise with delay threshold (only displays skeleton if duration > threshold)
         */
        wrap: function (target, promiseOrAsyncFn, type = 'card', options = {}) {
            const el = typeof target === 'string' ? document.querySelector(target) : target;
            const threshold = options.delayThreshold || Config.delayThreshold;
            let isDone = false;
            let skeletonActive = false;

            const timer = setTimeout(() => {
                if (!isDone && el) {
                    skeletonActive = true;
                    MediProLoading.show(el, type, options);
                }
            }, threshold);

            const p = typeof promiseOrAsyncFn === 'function' ? promiseOrAsyncFn() : promiseOrAsyncFn;

            return Promise.resolve(p)
                .then((result) => {
                    isDone = true;
                    clearTimeout(timer);
                    if (skeletonActive && el) {
                        MediProLoading.hide(el);
                    }
                    return result;
                })
                .catch((error) => {
                    isDone = true;
                    clearTimeout(timer);
                    if (skeletonActive && el) {
                        MediProLoading.hide(el);
                    }
                    throw error;
                });
        }
    };

    // 6. Global Navigation Link Interceptor
    document.addEventListener('DOMContentLoaded', function () {
        document.addEventListener('click', function (e) {
            // Find closest <a> tag
            const link = e.target.closest('a');
            if (!link) return;

            const href = link.getAttribute('href');
            if (!href) return;

            // Exclusion filters:
            if (
                href.startsWith('#') ||
                href.startsWith('javascript:') ||
                href.startsWith('mailto:') ||
                href.startsWith('tel:') ||
                link.target === '_blank' ||
                link.hasAttribute('download') ||
                link.classList.contains('no-loader') ||
                e.ctrlKey || e.metaKey || e.shiftKey || e.which === 2 ||
                href.includes('logout.php') ||
                href.includes('print_') ||
                href.includes('preview_') ||
                href.includes('export')
            ) {
                return;
            }

            // Normal internal navigation -> start progress bar with threshold
            PageLoader.start(Config.delayThreshold);
        }, true);

        // Reset progress on page show (Back/Forward navigation cache)
        window.addEventListener('pageshow', function () {
            PageLoader.done();
        });
    });

    // Expose to window
    window.MediProLoading = MediProLoading;

})(window, document);
