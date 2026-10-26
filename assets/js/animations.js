/**
 * assets/js/animations.js
 * MediPro HMS — UI Micro-Animations, Tactile Button Ripples, Count-Up & Toasts
 */

(function (window, document) {
    'use strict';

    // -------------------------------------------------------------------------
    // 1. SMOOTH TOAST NOTIFICATION CONTROLLER
    // -------------------------------------------------------------------------
    let toastContainer = null;

    function getToastContainer() {
        if (!toastContainer) {
            toastContainer = document.getElementById('medipro-toast-container');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.id = 'medipro-toast-container';
                document.body.appendChild(toastContainer);
            }
        }
        return toastContainer;
    }

    const ToastIcons = {
        success: 'bi-check-circle-fill',
        error: 'bi-x-circle-fill',
        danger: 'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill'
    };

    /**
     * Show a modern floating toast notification
     * @param {string} message Text or HTML content
     * @param {string} type 'success' | 'error' | 'warning' | 'info'
     * @param {number} duration Milliseconds before auto-dismiss (default 3500)
     */
    function showToast(message, type = 'success', duration = 3500) {
        if (!message) return;
        const container = getToastContainer();

        const toast = document.createElement('div');
        toast.className = `medipro-toast toast-${type}`;

        const iconClass = ToastIcons[type] || ToastIcons.info;

        toast.innerHTML = `
            <div class="medipro-toast-icon"><i class="bi ${iconClass}"></i></div>
            <div class="medipro-toast-content">${message}</div>
            <button class="medipro-toast-close" aria-label="Close">&times;</button>
        `;

        function dismissToast() {
            if (toast.classList.contains('toast-hide')) return;
            toast.classList.add('toast-hide');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 260);
        }

        toast.querySelector('.medipro-toast-close').addEventListener('click', (e) => {
            e.stopPropagation();
            dismissToast();
        });

        toast.addEventListener('click', dismissToast);

        container.appendChild(toast);

        if (duration > 0) {
            setTimeout(dismissToast, duration);
        }

        return toast;
    }

    // Expose globally
    window.showToast = showToast;
    window.flashToast = showToast;

    // -------------------------------------------------------------------------
    // 2. BUTTON RIPPLE EFFECT (Delegated)
    // -------------------------------------------------------------------------
    function initButtonRipples() {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn');
            if (!btn || btn.disabled || btn.classList.contains('disabled')) return;

            // Remove any existing ripple inside this button
            const existingRipple = btn.querySelector('.medipro-ripple');
            if (existingRipple) existingRipple.remove();

            const rect = btn.getBoundingClientRect();
            const circle = document.createElement('span');
            const diameter = Math.max(rect.width, rect.height);
            const radius = diameter / 2;

            circle.style.width = circle.style.height = `${diameter}px`;
            circle.style.left = `${e.clientX - rect.left - radius}px`;
            circle.style.top = `${e.clientY - rect.top - radius}px`;
            circle.classList.add('medipro-ripple');

            btn.appendChild(circle);

            setTimeout(() => {
                circle.remove();
            }, 600);
        });
    }

    // -------------------------------------------------------------------------
    // 3. LIVE STATUS PULSE INDICATOR INJECTOR
    // -------------------------------------------------------------------------
    function injectLivePulseDots() {
        // Target badges indicating active inpatient status
        const badges = document.querySelectorAll('.badge');
        badges.forEach(function (badge) {
            if (badge.dataset.pulseBound) return;

            // Prevent duplicate indicators if badge already contains an icon or dot
            if (badge.querySelector('.status-pulse-dot, i, svg, [class*="bi-"], [class*="ti-"]')) {
                badge.dataset.pulseBound = 'true';
                return;
            }

            const text = (badge.textContent || '').trim().toLowerCase();

            let pulseType = null;
            if (text === 'currently admitted' || text === 'admitted' || text.includes('still admitted')) {
                pulseType = 'pulse-amber';
            } else if (text === 'active' || text.includes('live')) {
                pulseType = 'pulse-green';
            } else if (text === 'shifted to ipd') {
                pulseType = 'pulse-blue';
            }

            if (pulseType && !badge.querySelector('.status-pulse-dot')) {
                badge.dataset.pulseBound = 'true';
                const dot = document.createElement('span');
                dot.className = `status-pulse-dot ${pulseType}`;
                badge.insertBefore(dot, badge.firstChild);
            }
        });
    }

    // -------------------------------------------------------------------------
    // 4. NUMBER COUNT-UP ANIMATIONS (GPU RequestAnimationFrame)
    // -------------------------------------------------------------------------
    function animateCountUp(element, endVal, prefix = '', suffix = '', decimals = 0, duration = 800) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            element.textContent = `${prefix}${endVal.toLocaleString('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}${suffix}`;
            return;
        }

        const startTime = performance.now();
        const startVal = 0;

        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);

            // Ease-out expo curve for ultra smooth deceleration
            const easeOutProgress = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);
            const currentVal = startVal + (endVal - startVal) * easeOutProgress;

            const formatted = currentVal.toLocaleString('en-IN', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });

            element.textContent = `${prefix}${formatted}${suffix}`;

            if (progress < 1) {
                requestAnimationFrame(update);
            } else {
                element.textContent = `${prefix}${endVal.toLocaleString('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}${suffix}`;
            }
        }

        requestAnimationFrame(update);
    }

    function initCountUpAnimations() {
        const targets = document.querySelectorAll('.stat-value, .count-up, .kpi-number, [data-countup]');
        targets.forEach(function (el) {
            if (el.dataset.countupBound) return;

            const text = (el.textContent || '').trim();
            // Match potential currency, numbers with commas, decimals
            const match = text.match(/^([^\d.-]*)([\d,]+(?:\.\d+)?)([^\d]*)$/);
            if (!match) return;

            const prefix = match[1] || '';
            const rawNum = match[2].replace(/,/g, '');
            const suffix = match[3] || '';
            const val = parseFloat(rawNum);

            if (isNaN(val) || val === 0) return;

            const decimals = rawNum.includes('.') ? (rawNum.split('.')[1].length) : 0;

            el.dataset.countupBound = 'true';
            animateCountUp(el, val, prefix, suffix, decimals, 750);
        });
    }

    // -------------------------------------------------------------------------
    // 5. AUTO-INITIALIZE ON DOM READY
    // -------------------------------------------------------------------------
    function init() {
        initButtonRipples();
        injectLivePulseDots();
        initCountUpAnimations();

        // Check URL query parameters for message feedback (e.g. ?msg=Saved+Successfully)
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const msg = urlParams.get('msg') || urlParams.get('success');
            if (msg) {
                showToast(decodeURIComponent(msg), 'success');
            }
            const err = urlParams.get('error');
            if (err) {
                showToast(decodeURIComponent(err), 'error');
            }
        } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose helpers
    window.MediProAnimations = {
        showToast: showToast,
        injectPulse: injectLivePulseDots,
        runCountUp: initCountUpAnimations
    };

})(window, document);
