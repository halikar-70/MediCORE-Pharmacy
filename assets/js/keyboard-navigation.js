/**
 * ==============================================================================
 * Pharmacy Management System — Central Keyboard Navigation Engine
 * File: assets/js/keyboard-navigation.js
 * 
 * Architecture:
 * - KeyboardManager: Central global orchestrator & event delegation
 * - FocusManager: Focus trapping, visible focus rings, focus recovery, text selection
 * - ShortcutManager: Global & contextual shortcut registry (F2, F4, F8, F9, Ctrl+K, Ctrl+S, ?, Esc)
 * - FormNavigator: Intelligent Enter key progression, prevent accidental submits, validation focus
 * - DropdownNavigator: WCAG-compliant combobox & suggestion list navigation (Up/Down/Home/End/Enter)
 * - ModalNavigator: Accessibility focus trapping, automatic autofocus, Esc cancel, Enter action
 * - CommandPalette: Global Ctrl+K quick-jump search modal for all pharmacy modules
 * - ShortcutsHelp: Floating '?' shortcut cheat sheet modal
 * - BarcodeScannerHandler: High-speed keystroke scanner detection & auto-routing
 * - PosWorkflowHelper: High-speed counter & IPD billing keyboard workflow
 * ==============================================================================
 */

(function (window, document) {
    'use strict';

    // ──────────────────────────────────────────────────────────────────────────
    // 1. FOCUS MANAGER
    // ──────────────────────────────────────────────────────────────────────────
    const FocusManager = {
        _lastActiveElement: null,

        saveFocus() {
            this._lastActiveElement = document.activeElement;
        },

        restoreFocus() {
            if (this._lastActiveElement && typeof this._lastActiveElement.focus === 'function') {
                try {
                    this._lastActiveElement.focus();
                } catch (e) {
                    // Ignore if element is no longer in DOM
                }
            }
        },

        highlight(element, selectText = false) {
            if (!element || typeof element.focus !== 'function') return;
            try {
                element.focus();
                if (selectText && (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA')) {
                    const type = (element.type || '').toLowerCase();
                    if (!['button', 'checkbox', 'radio', 'file', 'hidden', 'submit', 'reset'].includes(type)) {
                        element.select();
                    }
                }
                if (typeof element.scrollIntoView === 'function') {
                    element.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
                }
            } catch (e) {
                // Safe fallback
            }
        },

        getInteractiveElements(container) {
            if (!container) container = document;
            const selector = [
                'input:not([type="hidden"]):not([disabled]):not([tabindex="-1"])',
                'select:not([disabled]):not([tabindex="-1"])',
                'textarea:not([disabled]):not([tabindex="-1"])',
                'button:not([disabled]):not([tabindex="-1"])',
                'a[href]:not([tabindex="-1"])',
                '[tabindex]:not([tabindex="-1"]):not([disabled])'
            ].join(', ');

            return Array.from(container.querySelectorAll(selector)).filter(el => {
                // Must be visible
                return el.offsetWidth > 0 || el.offsetHeight > 0 || el.getClientRects().length > 0;
            });
        },

        focusFirstInteractive(container) {
            const elements = this.getInteractiveElements(container);
            if (elements.length > 0) {
                // Check if any element has explicit autofocus or data-autofocus
                const auto = elements.find(el => el.hasAttribute('autofocus') || el.hasAttribute('data-autofocus'));
                this.highlight(auto || elements[0], true);
                return true;
            }
            return false;
        },

        trapFocus(container, event) {
            if (event.key !== 'Tab') return;
            const elements = this.getInteractiveElements(container);
            if (elements.length === 0) {
                event.preventDefault();
                return;
            }

            const first = elements[0];
            const last = elements[elements.length - 1];

            if (event.shiftKey) {
                if (document.activeElement === first || !container.contains(document.activeElement)) {
                    event.preventDefault();
                    last.focus();
                }
            } else {
                if (document.activeElement === last || !container.contains(document.activeElement)) {
                    event.preventDefault();
                    first.focus();
                }
            }
        },

        focusInvalidField(form) {
            if (!form) return false;
            // Native HTML5 invalid elements or elements marked with .is-invalid
            const invalid = form.querySelector(':invalid, .is-invalid');
            if (invalid) {
                this.highlight(invalid, true);
                invalid.classList.add('keyboard-focused');
                setTimeout(() => invalid.classList.remove('keyboard-focused'), 1500);
                return true;
            }
            return false;
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 2. SHORTCUT MANAGER
    // ──────────────────────────────────────────────────────────────────────────
    const ShortcutManager = {
        _shortcuts: [],

        register(shortcut) {
            // shortcut: { key, ctrl, alt, shift, scope, description, handler }
            this._shortcuts.push(shortcut);
        },

        getAll() {
            return this._shortcuts;
        },

        handle(e) {
            // Determine active scope
            let currentScope = 'global';
            if (document.querySelector('.modal.show')) {
                currentScope = 'modal';
            } else if (document.getElementById('commandPaletteModal')?.classList.contains('show')) {
                currentScope = 'palette';
            } else if (document.getElementById('posSaleForm') || document.getElementById('chargeMedicineInput')) {
                currentScope = 'pos';
            }

            for (const s of this._shortcuts) {
                if (s.scope && s.scope !== 'all' && s.scope !== currentScope && currentScope !== 'pos' && s.scope !== 'global') {
                    continue;
                }

                const keyMatch = (e.key || '').toLowerCase() === (s.key || '').toLowerCase();
                const ctrlMatch = !!s.ctrl === (e.ctrlKey || e.metaKey);
                const altMatch = !!s.alt === e.altKey;
                const shiftMatch = s.shift === undefined ? true : !!s.shift === e.shiftKey;

                if (keyMatch && ctrlMatch && altMatch && shiftMatch) {
                    if (s.preventDefault !== false) {
                        e.preventDefault();
                    }
                    s.handler(e);
                    return true;
                }
            }
            return false;
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 3. FORM NAVIGATOR (Intelligent Enter Key Progression)
    // ──────────────────────────────────────────────────────────────────────────
    const FormNavigator = {
        init() {
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter') return;

                const target = e.target;
                if (!target || !target.form) return;

                // Do not intercept Enter inside textarea or when Ctrl/Shift/Alt are held
                if (target.tagName === 'TEXTAREA' || e.ctrlKey || e.shiftKey || e.altKey) {
                    return;
                }

                // Do not intercept if inside custom autocomplete with open suggestions
                const openSuggestions = document.querySelector('#medicineSuggestionsList:not(.d-none), .searchable-select-menu.show');
                if (openSuggestions) {
                    return;
                }

                // If element explicitly wants to submit
                if (target.type === 'submit' || target.getAttribute('data-keyboard-action') === 'submit') {
                    return;
                }

                // If button, let native enter click handle it
                if (target.tagName === 'BUTTON') {
                    return;
                }

                // If in POS charge row, POS workflow handles Qty & Search
                if (target.id === 'chargeMedicineInput' || target.id === 'chargeQtyInput' || target.id === 'modalPaidInput') {
                    return;
                }

                // Move to next logical field in the form
                const formElements = FocusManager.getInteractiveElements(target.form).filter(el => {
                    return el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA' || el.type === 'submit';
                });

                const currentIndex = formElements.indexOf(target);
                if (currentIndex !== -1 && currentIndex < formElements.length - 1) {
                    e.preventDefault();
                    const nextElement = formElements[currentIndex + 1];
                    FocusManager.highlight(nextElement, true);
                } else if (currentIndex === formElements.length - 1) {
                    // Reached the last element - if it's a submit button or final input, submit safely
                    const submitBtn = target.form.querySelector('button[type="submit"], input[type="submit"]');
                    if (submitBtn) {
                        e.preventDefault();
                        submitBtn.click();
                    }
                }
            });

            // Automatic validation focus recovery
            document.addEventListener('submit', (e) => {
                const form = e.target;
                if (form && !form.noValidate && form.checkValidity && !form.checkValidity()) {
                    // Let browser validate, but assist focus
                    setTimeout(() => {
                        FocusManager.focusInvalidField(form);
                    }, 50);
                }
            }, true);
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 4. DROPDOWN & AUTOCOMPLETE NAVIGATOR
    // ──────────────────────────────────────────────────────────────────────────
    const DropdownNavigator = {
        navigateList(container, direction, activeClass = 'active-nav') {
            if (!container) return -1;
            const items = Array.from(container.querySelectorAll('.med-suggest-item:not(.disabled), .dropdown-item:not(.disabled)'));
            if (items.length === 0) return -1;

            let currentIndex = items.findIndex(el => el.classList.contains(activeClass));
            if (direction === 'down') {
                currentIndex = (currentIndex + 1) % items.length;
            } else if (direction === 'up') {
                currentIndex = currentIndex <= 0 ? items.length - 1 : currentIndex - 1;
            } else if (direction === 'home') {
                currentIndex = 0;
            } else if (direction === 'end') {
                currentIndex = items.length - 1;
            }

            items.forEach((item, idx) => {
                if (idx === currentIndex) {
                    item.classList.add(activeClass);
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove(activeClass);
                }
            });

            return currentIndex;
        },

        getActiveItem(container, activeClass = 'active-nav') {
            if (!container) return null;
            return container.querySelector(`.${activeClass}`) || container.querySelector('.med-suggest-item:not(.disabled), .dropdown-item:not(.disabled)');
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 5. MODAL NAVIGATOR
    // ──────────────────────────────────────────────────────────────────────────
    const ModalNavigator = {
        init() {
            // Bootstrap Modal event hooks
            document.addEventListener('shown.bs.modal', (e) => {
                FocusManager.saveFocus();
                const modal = e.target;

                // Priority autofocus elements
                const paidInput = modal.querySelector('#modalPaidInput');
                if (paidInput) {
                    FocusManager.highlight(paidInput, true);
                    return;
                }

                const customAutofocus = modal.querySelector('[data-autofocus], autofocus');
                if (customAutofocus) {
                    FocusManager.highlight(customAutofocus, true);
                    return;
                }

                FocusManager.focusFirstInteractive(modal);
            });

            document.addEventListener('hidden.bs.modal', () => {
                FocusManager.restoreFocus();
            });

            // Focus trap inside any open modal
            document.addEventListener('keydown', (e) => {
                const openModal = document.querySelector('.modal.show');
                if (!openModal) return;

                if (e.key === 'Tab') {
                    FocusManager.trapFocus(openModal, e);
                }
            });
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 6. GLOBAL COMMAND PALETTE (Ctrl + K)
    // ──────────────────────────────────────────────────────────────────────────
    const CommandPalette = {
        _items: [
            // Sales / POS
            { title: 'OPD Sales & Billing (POS Counter)', category: 'Sales', icon: 'ti-shopping-cart', url: 'modules/sales/counter.php', shortcut: 'F2' },
            { title: 'Regular / IPD Sale & Ward Dispensing', category: 'Sales', icon: 'ti-user-check', url: 'modules/sales/regular.php', shortcut: '' },
            { title: 'Sales Monitoring & Live Dashboard', category: 'Sales', icon: 'ti-device-analytics', url: 'modules/sales/monitoring.php', shortcut: '' },
            { title: 'Sales Returns & Credit Notes', category: 'Sales', icon: 'ti-arrow-back-up', url: 'modules/sales/returns.php', shortcut: '' },
            
            // Inventory
            { title: 'Medicine Products Directory', category: 'Inventory', icon: 'ti-pill', url: 'modules/inventory/products.php', shortcut: '' },
            { title: 'Add New Medicine / Product', category: 'Inventory', icon: 'ti-plus', url: 'modules/inventory/add.php', shortcut: '' },
            { title: 'Batch Stock & Expiry Levels', category: 'Inventory', icon: 'ti-box', url: 'modules/inventory/batch_stock.php', shortcut: '' },
            { title: 'Stock Expiry Tracking', category: 'Inventory', icon: 'ti-calendar-time', url: 'modules/inventory/expiry.php', shortcut: '' },
            { title: 'Stock Adjustments & Write-offs', category: 'Inventory', icon: 'ti-adjustments', url: 'modules/inventory/adjustments.php', shortcut: '' },
            
            // Patients
            { title: 'Search Patients (UHID / Mobile / Name)', category: 'Patients', icon: 'ti-search', url: 'modules/patients/search.php', shortcut: 'F4' },
            { title: 'Register New Pharmacy Patient', category: 'Patients', icon: 'ti-user-plus', url: 'modules/patients/register.php', shortcut: '' },
            { title: 'Patient Directory & History', category: 'Patients', icon: 'ti-users', url: 'modules/patients/list.php', shortcut: '' },

            // Procurement / Purchases
            { title: 'Purchase Orders (PO)', category: 'Purchases', icon: 'ti-file-text', url: 'modules/purchases/orders.php', shortcut: '' },
            { title: 'Goods Received Notes (GRN)', category: 'Purchases', icon: 'ti-truck-loading', url: 'modules/purchases/grn.php', shortcut: '' },
            { title: 'Purchase Invoices & Inward Stock', category: 'Purchases', icon: 'ti-file-invoice', url: 'modules/purchases/invoices.php', shortcut: '' },
            { title: 'Supplier Directory & Management', category: 'Purchases', icon: 'ti-building-store', url: 'modules/purchases/suppliers.php', shortcut: '' },
            { title: 'Supplier Payments & Settlements', category: 'Purchases', icon: 'ti-cash', url: 'modules/purchases/payments.php', shortcut: '' },
            
            // Reports & Admin
            { title: 'Sales Summary & Register Report', category: 'Reports', icon: 'ti-chart-bar', url: 'modules/reports/sales.php', shortcut: '' },
            { title: 'Stock Valuation & Consumption Report', category: 'Reports', icon: 'ti-report-analytics', url: 'modules/reports/stock.php', shortcut: '' },
            { title: 'Audit Trail & Compliance Logs', category: 'Admin', icon: 'ti-shield-lock', url: 'modules/admin/audit_logs.php', shortcut: '' },
            { title: 'Pharmacy System Settings', category: 'Admin', icon: 'ti-settings', url: 'modules/admin/settings.php', shortcut: '' }
        ],

        init() {
            this._injectDom();
            this._bindEvents();
        },

        _injectDom() {
            if (document.getElementById('commandPaletteModal')) return;

            const modalHtml = `
            <div class="modal fade" id="commandPaletteModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(4px);">
                <div class="modal-dialog modal-dialog-centered" style="max-width: 580px;">
                    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden; background: #ffffff;">
                        <div class="p-3 border-bottom d-flex align-items-center gap-2 bg-light">
                            <i class="ti ti-search text-emerald fs-5"></i>
                            <input type="text" id="commandPaletteInput" class="form-control form-control-lg border-0 bg-transparent shadow-none px-2" placeholder="Type a command or jump to module..." autocomplete="off" style="font-size: 1rem;">
                            <span class="badge bg-white text-muted border font-monospace py-1 px-2" style="font-size: 0.72rem;">ESC to close</span>
                        </div>
                        <div id="commandPaletteResults" class="p-2" style="max-height: 380px; overflow-y: auto;">
                            <!-- Live Results -->
                        </div>
                        <div class="p-2 bg-light border-top d-flex justify-content-between align-items-center text-muted small" style="font-size: 0.74rem;">
                            <span>Navigate with <kbd class="bg-white border px-1">↑</kbd> <kbd class="bg-white border px-1">↓</kbd>, open with <kbd class="bg-white border px-1">ENTER</kbd></span>
                            <span>Quick Action</span>
                        </div>
                    </div>
                </div>
            </div>`;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
        },

        _bindEvents() {
            const input = document.getElementById('commandPaletteInput');
            const results = document.getElementById('commandPaletteResults');
            if (!input || !results) return;

            input.addEventListener('input', () => {
                this._render(input.value.trim().toLowerCase());
            });

            input.addEventListener('keydown', (e) => {
                const items = results.querySelectorAll('.command-item');
                if (items.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    DropdownNavigator.navigateList(results, 'down', 'active-cmd');
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    DropdownNavigator.navigateList(results, 'up', 'active-cmd');
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    const active = results.querySelector('.command-item.active-cmd') || items[0];
                    if (active) {
                        active.click();
                    }
                }
            });
        },

        open() {
            const modalEl = document.getElementById('commandPaletteModal');
            if (!modalEl) return;
            const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
            this._render('');
            setTimeout(() => {
                const input = document.getElementById('commandPaletteInput');
                if (input) {
                    input.value = '';
                    input.focus();
                }
            }, 100);
        },

        close() {
            const modalEl = document.getElementById('commandPaletteModal');
            if (!modalEl) return;
            const bsModal = bootstrap.Modal.getInstance(modalEl);
            if (bsModal) bsModal.hide();
        },

        _render(query) {
            const results = document.getElementById('commandPaletteResults');
            if (!results) return;

            const baseUrl = window.BASE_URL || '/';
            const filtered = this._items.filter(item => {
                if (!query) return true;
                return item.title.toLowerCase().includes(query) || item.category.toLowerCase().includes(query);
            });

            if (filtered.length === 0) {
                results.innerHTML = `
                    <div class="text-center py-4 text-muted small">
                        <i class="ti ti-search-off fs-4 d-block mb-1 opacity-50"></i>
                        No commands found matching "<strong>${this._escape(query)}</strong>"
                    </div>`;
                return;
            }

            let html = '';
            filtered.forEach((item, index) => {
                const activeCls = index === 0 ? 'active-cmd' : '';
                html += `
                    <a href="${baseUrl}${item.url}" class="command-item d-flex align-items-center justify-content-between p-2 rounded-3 text-decoration-none text-dark ${activeCls}" style="transition: all 0.12s ease; cursor: pointer; margin-bottom: 2px;">
                        <div class="d-flex align-items-center gap-2.5">
                            <div class="cmd-icon-box d-flex align-items-center justify-content-center rounded-2 bg-light border text-emerald" style="width: 34px; height: 34px; font-size: 1.1rem;">
                                <i class="ti ${item.icon}"></i>
                            </div>
                            <div>
                                <div class="fw-semibold text-dark" style="font-size: 0.88rem;">${this._escape(item.title)}</div>
                                <div class="text-muted small" style="font-size: 0.72rem;">${item.category}</div>
                            </div>
                        </div>
                        ${item.shortcut ? `<span class="badge bg-light text-muted border font-monospace" style="font-size: 0.72rem;">${item.shortcut}</span>` : '<i class="ti ti-chevron-right text-muted opacity-50"></i>'}
                    </a>`;
            });

            results.innerHTML = html;

            // Hover state sync
            results.querySelectorAll('.command-item').forEach(el => {
                el.addEventListener('mouseenter', function() {
                    results.querySelectorAll('.command-item').forEach(i => i.classList.remove('active-cmd'));
                    this.classList.add('active-cmd');
                });
            });
        },

        _escape(text) {
            return String(text || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 7. KEYBOARD SHORTCUTS HELP MODAL ('?' or Shift + /)
    // ──────────────────────────────────────────────────────────────────────────
    const ShortcutsHelp = {
        init() {
            if (document.getElementById('shortcutsHelpModal')) return;

            const modalHtml = `
            <div class="modal fade" id="shortcutsHelpModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(4px);">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden; background: #ffffff;">
                        <div class="modal-header py-3 px-4 bg-white border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <div class="d-inline-flex align-items-center justify-content-center bg-emerald-subtle text-emerald rounded-3" style="width: 36px; height: 36px;">
                                    <i class="ti ti-keyboard fs-4"></i>
                                </div>
                                <div>
                                    <h5 class="modal-title fw-bold text-dark mb-0">Keyboard Shortcuts Directory</h5>
                                    <span class="text-muted small" style="font-size: 0.78rem;">High-efficiency keyboard navigation for Pharmacy Operations</span>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4" style="max-height: 520px; overflow-y: auto;">
                            <div class="row g-4">
                                <!-- Col 1: POS Billing / Counter Sales -->
                                <div class="col-md-6">
                                    <h6 class="fw-bold text-dark mb-2 pb-1 border-bottom d-flex align-items-center gap-1.5" style="font-size: 0.86rem;">
                                        <i class="ti ti-shopping-cart text-emerald"></i> POS Billing / Counter Sales
                                    </h6>
                                    <div class="d-flex flex-column gap-2 small">
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Search Medicine / Item</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">F2</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Select Patient / Customer</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">F4</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Proceed to Payment / Preview</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">F8</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Confirm &amp; Complete Sale</span>
                                            <span class="badge bg-emerald text-white font-monospace px-2 py-1">F9</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Add Charge &amp; Return to Search</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">ENTER (on Qty)</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Navigate Suggestions / Cart</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">↑ / ↓</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1">
                                            <span>Remove Item from Cart</span>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle font-monospace px-2 py-1">Ctrl + Del</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Col 2: Global Navigation & Forms -->
                                <div class="col-md-6">
                                    <h6 class="fw-bold text-dark mb-2 pb-1 border-bottom d-flex align-items-center gap-1.5" style="font-size: 0.86rem;">
                                        <i class="ti ti-compass text-primary"></i> Global Commands &amp; Forms
                                    </h6>
                                    <div class="d-flex flex-column gap-2 small">
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Global Command / Jump Palette</span>
                                            <span class="badge bg-primary text-white font-monospace px-2 py-1">Ctrl + K</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Save / Submit Current Form</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">Ctrl + S</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Advance to Next Field</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">ENTER / TAB</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Previous Field</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">Shift + TAB</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Close Menu / Modal / Cancel</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">ESC</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Focus Sidebar Navigation</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">Alt + S</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span>Sidebar: Scroll Down to Next Tab</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">ENTER / ↓</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-1">
                                            <span>Show Keyboard Shortcuts Help</span>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">?</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer py-2 px-4 bg-light border-top d-flex justify-content-between">
                            <span class="text-muted small" style="font-size: 0.74rem;"><i class="ti ti-check text-emerald me-1"></i>All shortcuts are active across desktop &amp; POS terminals</span>
                            <button type="button" class="btn btn-sm btn-secondary px-3" data-bs-dismiss="modal">Close (Esc)</button>
                        </div>
                    </div>
                </div>
            </div>`;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
        },

        open() {
            const modalEl = document.getElementById('shortcutsHelpModal');
            if (!modalEl) return;
            const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 8. BARCODE SCANNER HANDLER
    // ──────────────────────────────────────────────────────────────────────────
    const BarcodeScannerHandler = {
        _buffer: '',
        _lastTime: 0,
        _scannerThresholdMs: 45, // typical barcode scanners output keys within 20-40ms

        handleKeyPress(e) {
            // Only active on billing/sales pages
            const isPos = !!(document.getElementById('chargeMedicineInput') && document.getElementById('posSaleForm'));
            if (!isPos) return false;

            const now = Date.now();
            const timeDiff = now - this._lastTime;
            this._lastTime = now;

            // Scanner triggers Enter at the end of the barcode
            if (e.key === 'Enter') {
                if (this._buffer.length >= 4) {
                    const scannedBarcode = this._buffer.trim();
                    this._buffer = '';
                    this._onBarcodeScanned(scannedBarcode);
                    e.preventDefault();
                    return true;
                }
                this._buffer = '';
                return false;
            }

            // Accumulate readable characters
            if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
                if (timeDiff > 120 && this._buffer.length > 0) {
                    // Reset if too slow (human typing)
                    this._buffer = '';
                }
                this._buffer += e.key;
            }

            return false;
        },

        _onBarcodeScanned(barcode) {
            const searchInput = document.getElementById('chargeMedicineInput');
            if (!searchInput) return;

            searchInput.value = barcode;
            FocusManager.highlight(searchInput, true);

            // Trigger search function
            if (typeof window.onMedicineSearchInput === 'function') {
                window.onMedicineSearchInput(barcode);
            }

            // Automatically select first match if found
            setTimeout(() => {
                const suggestionsBox = document.getElementById('medicineSuggestionsList');
                if (suggestionsBox && !suggestionsBox.classList.contains('d-none')) {
                    const firstItem = suggestionsBox.querySelector('.med-suggest-item:not(.disabled)');
                    if (firstItem) {
                        firstItem.click();
                    }
                }
            }, 60);
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 9. POS BILLING WORKFLOW HELPER (Counter & IPD Sales)
    // ──────────────────────────────────────────────────────────────────────────
    const PosWorkflowHelper = {
        init() {
            const searchInput = document.getElementById('chargeMedicineInput');
            if (!searchInput) return; // Not on POS page

            // Ensure search input is cleanly focused on page load
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    FocusManager.highlight(searchInput, true);
                }, 150);
            });

            // Cart Table Row Keyboard Navigation
            document.addEventListener('keydown', (e) => {
                const cartTable = document.getElementById('cartTable');
                if (!cartTable) return;

                const activeEl = document.activeElement;
                const isInsideCart = cartTable.contains(activeEl);

                // Ctrl + Delete or Delete on selected cart row
                if ((e.key === 'Delete' || (e.ctrlKey && e.key === 'Delete')) && isInsideCart) {
                    const row = activeEl.closest('tr');
                    if (row) {
                        const deleteBtn = row.querySelector('button[title="Remove"]');
                        if (deleteBtn) {
                            e.preventDefault();
                            deleteBtn.click();
                            // Refocus search after deletion
                            FocusManager.highlight(searchInput, true);
                        }
                    }
                }
            });
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 10. SPATIAL NAVIGATOR (4-Way Directional Navigation & Sidebar Integration)
    // ──────────────────────────────────────────────────────────────────────────
    const SpatialNavigator = {
        _lastMainFocusedElement: null,

        init() {
            this._injectStyles();
            this._bindEvents();
        },

        _injectStyles() {
            if (document.getElementById('keyboard-spatial-styles')) return;
            const style = document.createElement('style');
            style.id = 'keyboard-spatial-styles';
            style.textContent = `
                .keyboard-spatial-focus {
                    outline: 2px solid #059669 !important;
                    outline-offset: 2px !important;
                    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.22) !important;
                    transition: outline 0.12s ease, box-shadow 0.12s ease !important;
                }
                .sidebar-nav .nav-link.keyboard-spatial-focus,
                .sidebar-nav .nav-link:focus {
                    background: rgba(16, 185, 129, 0.14) !important;
                    color: #065f46 !important;
                    outline: 2px solid #059669 !important;
                    outline-offset: -2px !important;
                    font-weight: 600 !important;
                }
                .sidebar-nav .nav-link.keyboard-spatial-focus i,
                .sidebar-nav .nav-link:focus i {
                    color: #059669 !important;
                }
                tr.cart-row.keyboard-spatial-focus {
                    background-color: #ecfdf5 !important;
                    outline: 2px solid #059669 !important;
                    outline-offset: -2px !important;
                }
            `;
            document.head.appendChild(style);
        },

        _bindEvents() {
            window.addEventListener('keydown', (e) => {
                const key = e.key;
                if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(key)) {
                    return;
                }

                // If user is holding Ctrl, Alt, or Meta, let browser/OS shortcuts execute
                if (e.altKey || e.metaKey || (e.ctrlKey && key !== 'ArrowDown' && key !== 'ArrowUp')) {
                    return;
                }

                // Do not intercept if Command Palette or Shortcuts Help is open
                if (document.getElementById('commandPaletteModal')?.classList.contains('show') ||
                    document.getElementById('shortcutsHelpModal')?.classList.contains('show')) {
                    return;
                }

                const activeEl = document.activeElement;

                // Check if custom suggestions dropdown is open (Medicine suggestions or Patient suggestions)
                const openSuggestions = document.querySelector('#medicineSuggestionsList:not(.d-none), #counterPatientSuggestionsList:not(.d-none), #patientSuggestionsList:not(.d-none), .searchable-select-menu.show');
                if (openSuggestions && (key === 'ArrowUp' || key === 'ArrowDown')) {
                    // Let suggestions dropdown list handle Up and Down
                    return;
                }

                // Guard for active typing in text inputs / textareas
                if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) {
                    const inputType = (activeEl.type || '').toLowerCase();
                    const isTextual = ['text', 'search', 'email', 'tel', 'url', 'password', ''].includes(inputType) || activeEl.tagName === 'TEXTAREA';
                    
                    if (isTextual && !activeEl.readOnly) {
                        const val = activeEl.value || '';
                        const len = val.length;
                        const start = activeEl.selectionStart;
                        const end = activeEl.selectionEnd;

                        if (len > 0) {
                            if (key === 'ArrowLeft' && !(start === 0 && end === 0)) {
                                return; // Normal text cursor movement to the left
                            }
                            if (key === 'ArrowRight' && !(end === len)) {
                                return; // Normal text cursor movement to the right
                            }
                            if (activeEl.tagName === 'TEXTAREA') {
                                // Multiline textarea: let Up/Down move lines
                                return;
                            }
                        }
                    }

                    // Number input: if user is on a cart quantity input or chargeQtyInput,
                    // ArrowLeft / ArrowRight should navigate spatially to adjacent controls
                    if (inputType === 'number') {
                        if (activeEl.classList.contains('cart-qty-input') && (key === 'ArrowDown' || key === 'ArrowUp')) {
                            // If user presses Up/Down on cart qty input, navigate to prev/next row's qty input
                            const row = activeEl.closest('tr.cart-row');
                            if (row) {
                                const targetRow = key === 'ArrowDown' ? row.nextElementSibling : row.previousElementSibling;
                                if (targetRow && targetRow.classList.contains('cart-row')) {
                                    const nextQty = targetRow.querySelector('.cart-qty-input') || targetRow;
                                    e.preventDefault();
                                    this.focus(nextQty);
                                    return;
                                }
                            }
                        }
                    }
                }

                // Execute spatial move
                const handled = this.move(key.replace('Arrow', '').toLowerCase(), e);
                if (handled) {
                    e.preventDefault();
                }
            }, false);

            // Keep track of last focused element in main content
            document.addEventListener('focusin', (e) => {
                const target = e.target;
                if (target && !target.closest('.sidebar') && !target.closest('.modal')) {
                    this._lastMainFocusedElement = target;
                }
            });
        },

        move(direction, e) {
            const activeEl = document.activeElement;
            const isSidebar = activeEl && !!activeEl.closest('.sidebar');
            const openModal = document.querySelector('.modal.show');

            // 1. If currently inside the left sidebar:
            if (isSidebar && !openModal) {
                const sidebarNav = document.querySelector('.sidebar-nav');
                const sidebarLinks = Array.from(sidebarNav ? sidebarNav.querySelectorAll('a.nav-link:not([disabled])') : []);
                const currentLink = activeEl.closest('.nav-link');
                const currentIndex = currentLink ? sidebarLinks.indexOf(currentLink) : -1;

                if (direction === 'down') {
                    if (sidebarLinks.length > 0) {
                        const nextIdx = (currentIndex + 1) % sidebarLinks.length;
                        this.focus(sidebarLinks[nextIdx]);
                        return true;
                    }
                } else if (direction === 'up') {
                    if (sidebarLinks.length > 0) {
                        const prevIdx = currentIndex <= 0 ? sidebarLinks.length - 1 : currentIndex - 1;
                        this.focus(sidebarLinks[prevIdx]);
                        return true;
                    }
                } else if (direction === 'right') {
                    // JUMP OUT OF SIDEBAR INTO MAIN CONTENT
                    return this._jumpOutOfSidebar();
                } else if (direction === 'left') {
                    // Already at the leftmost edge
                    return true;
                }
                return false;
            }

            // 2. If in main content (or inside an open modal)
            const mainScope = openModal ? openModal : (document.querySelector('.main-container') || document.body);
            const candidates = this.getCandidates(mainScope).filter(el => !el.closest('.sidebar'));

            if (candidates.length === 0) return false;

            // If no active element or body is focused, focus the primary or first candidate
            if (!activeEl || activeEl === document.body || !mainScope.contains(activeEl)) {
                const primary = document.getElementById('chargeMedicineInput') ||
                                document.getElementById('counterPatientSearchInput') ||
                                document.getElementById('ipdPatientSearchInput') ||
                                candidates[0];
                this.focus(primary);
                return true;
            }

            const currentRect = activeEl.getBoundingClientRect();
            const currentCenter = {
                x: currentRect.left + currentRect.width / 2,
                y: currentRect.top + currentRect.height / 2
            };

            // Filter candidates in the chosen direction
            const dirCandidates = [];

            for (const cand of candidates) {
                if (cand === activeEl || cand.contains(activeEl)) continue;

                const r = cand.getBoundingClientRect();
                const center = {
                    x: r.left + r.width / 2,
                    y: r.top + r.height / 2
                };

                let isMatch = false;
                let primaryDist = 0;
                let orthoDist = 0;
                let overlapBonus = 0;

                if (direction === 'right') {
                    // Candidate is to the right
                    if (r.left >= currentRect.left + 3 || center.x > currentCenter.x + 5) {
                        isMatch = true;
                        primaryDist = center.x - currentCenter.x;
                        orthoDist = Math.abs(center.y - currentCenter.y);
                        // Vertical overlap check
                        if (!(r.bottom <= currentRect.top || r.top >= currentRect.bottom)) {
                            overlapBonus = 60;
                        }
                    }
                } else if (direction === 'left') {
                    // Candidate is to the left
                    if (r.right <= currentRect.right - 3 || center.x < currentCenter.x - 5) {
                        isMatch = true;
                        primaryDist = currentCenter.x - center.x;
                        orthoDist = Math.abs(center.y - currentCenter.y);
                        // Vertical overlap check
                        if (!(r.bottom <= currentRect.top || r.top >= currentRect.bottom)) {
                            overlapBonus = 60;
                        }
                    }
                } else if (direction === 'down') {
                    // Candidate is below
                    if (r.top >= currentRect.top + 3 || center.y > currentCenter.y + 5) {
                        isMatch = true;
                        primaryDist = center.y - currentCenter.y;
                        orthoDist = Math.abs(center.x - currentCenter.x);
                        // Horizontal overlap check
                        if (!(r.right <= currentRect.left || r.left >= currentRect.right)) {
                            overlapBonus = 60;
                        }
                    }
                } else if (direction === 'up') {
                    // Candidate is above
                    if (r.bottom <= currentRect.bottom - 3 || center.y < currentCenter.y - 5) {
                        isMatch = true;
                        primaryDist = currentCenter.y - center.y;
                        orthoDist = Math.abs(center.x - currentCenter.x);
                        // Horizontal overlap check
                        if (!(r.right <= currentRect.left || r.left >= currentRect.right)) {
                            overlapBonus = 60;
                        }
                    }
                }

                if (isMatch && primaryDist > 0) {
                    const weight = (direction === 'left' || direction === 'right') ? 2.5 : 2.0;
                    const score = primaryDist + (orthoDist * weight) - overlapBonus;
                    dirCandidates.push({ element: cand, score });
                }
            }

            // If candidates exist in this direction, pick the best one
            if (dirCandidates.length > 0) {
                dirCandidates.sort((a, b) => a.score - b.score);
                const winner = dirCandidates[0].element;
                this.focus(winner);
                return true;
            }

            // 3. IF MOVING LEFT AND NO MORE CANDIDATES TO THE LEFT IN MAIN CONTENT:
            // JUMP TO LEFT SIDEBAR!
            if (direction === 'left' && !openModal) {
                return this._jumpToSidebar(currentCenter.y);
            }

            return false;
        },

        _jumpToSidebar(refY) {
            const sidebar = document.querySelector('.sidebar');
            const sidebarNav = document.querySelector('.sidebar-nav');
            if (!sidebar || !sidebarNav) return false;

            // Only jump if sidebar is visible (not collapsed offscreen on small screens)
            if (sidebar.offsetWidth < 50 && !sidebar.classList.contains('show')) {
                return false;
            }

            const links = Array.from(sidebarNav.querySelectorAll('a.nav-link:not([disabled])'));
            if (links.length === 0) return false;

            // Pick the link closest vertically to refY
            let bestLink = links[0];
            let minDy = Infinity;

            links.forEach(link => {
                const r = link.getBoundingClientRect();
                const linkY = r.top + r.height / 2;
                const dy = Math.abs(linkY - refY);
                if (dy < minDy) {
                    minDy = dy;
                    bestLink = link;
                }
            });

            this.focus(bestLink);
            return true;
        },

        _jumpOutOfSidebar() {
            // Check if we have a preserved last main focused element
            if (this._lastMainFocusedElement && document.body.contains(this._lastMainFocusedElement) && this._lastMainFocusedElement.offsetWidth > 0) {
                this.focus(this._lastMainFocusedElement);
                return true;
            }

            // Otherwise, focus the primary search input or first main candidate
            const primary = document.getElementById('chargeMedicineInput') ||
                            document.getElementById('counterPatientSearchInput') ||
                            document.getElementById('ipdPatientSearchInput');
            if (primary) {
                this.focus(primary);
                return true;
            }

            const mainScope = document.querySelector('.main-container') || document.body;
            const candidates = this.getCandidates(mainScope).filter(el => !el.closest('.sidebar'));
            if (candidates.length > 0) {
                this.focus(candidates[0]);
                return true;
            }
            return false;
        },

        getCandidates(container) {
            if (!container) container = document.body;
            const selector = [
                'button:not([disabled]):not([tabindex="-1"])',
                'a[href]:not([disabled]):not([tabindex="-1"])',
                'input:not([type="hidden"]):not([disabled]):not([tabindex="-1"])',
                'select:not([disabled]):not([tabindex="-1"])',
                'textarea:not([disabled]):not([tabindex="-1"])',
                'tr.cart-row[tabindex="0"]',
                '[tabindex="0"]:not([disabled])',
                '.badge-batch-btn',
                '.expiry-picker-btn'
            ].join(', ');

            const nodes = Array.from(container.querySelectorAll(selector));
            return nodes.filter(el => {
                // Must not be hidden by d-none or hidden attribute
                if (el.closest('.d-none, [hidden], .modal:not(.show)')) return false;
                // Check if hidden by style
                if (el.offsetParent === null && el.tagName !== 'BODY') {
                    const rects = el.getClientRects();
                    if (!rects || rects.length === 0) return false;
                }
                const r = el.getBoundingClientRect();
                return r.width > 0 && r.height > 0;
            });
        },

        focus(element) {
            if (!element || typeof element.focus !== 'function') return;

            // Clear previous highlight
            document.querySelectorAll('.keyboard-spatial-focus').forEach(el => {
                el.classList.remove('keyboard-spatial-focus');
            });

            try {
                element.focus();
                element.classList.add('keyboard-spatial-focus');

                // If input, auto-select content for rapid editing
                if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                    const type = (element.type || '').toLowerCase();
                    if (!['checkbox', 'radio', 'button', 'submit'].includes(type)) {
                        element.select();
                    }
                }

                // Remove spatial class on blur
                const onBlur = () => {
                    element.classList.remove('keyboard-spatial-focus');
                    element.removeEventListener('blur', onBlur);
                };
                element.addEventListener('blur', onBlur);

            // Smooth scroll into view (only for main content elements, keeping sidebar steady)
                if (!element.closest('.sidebar')) {
                    element.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
                }
            } catch (e) {
                // Ignore focus errors
            }
        }
    };

    // ──────────────────────────────────────────────────────────────────────────
    // 11. CENTRAL KEYBOARD MANAGER (Root Orchestrator)
    // ──────────────────────────────────────────────────────────────────────────
    const KeyboardManager = {
        init() {
            this._registerStandardShortcuts();
            FormNavigator.init();
            ModalNavigator.init();
            CommandPalette.init();
            ShortcutsHelp.init();
            PosWorkflowHelper.init();
            SpatialNavigator.init();

            // Main Global Key Listener
            window.addEventListener('keydown', (e) => {
                // 1. Check Barcode scanner buffer first
                if (BarcodeScannerHandler.handleKeyPress(e)) {
                    return;
                }

                // 2. Delegate to ShortcutManager
                if (ShortcutManager.handle(e)) {
                    return;
                }

                // 3. Hierarchical Escape handling
                if (e.key === 'Escape') {
                    this._handleGlobalEscape(e);
                }
            }, false);
        },

        _registerStandardShortcuts() {
            // F2: Focus Primary Medicine Search (POS Counter or IPD)
            ShortcutManager.register({
                key: 'F2',
                scope: 'all',
                description: 'Focus Primary Medicine Search',
                handler: () => {
                    const posSearch = document.getElementById('chargeMedicineInput');
                    const globalSearch = document.getElementById('globalSearchInput');
                    const target = posSearch || globalSearch;
                    if (target) {
                        FocusManager.highlight(target, true);
                    }
                }
            });

            // F4: Focus Patient / Customer Selector
            ShortcutManager.register({
                key: 'F4',
                scope: 'all',
                description: 'Focus Patient Selector',
                handler: () => {
                    const patientSelect = document.getElementById('patientSelect') || document.getElementById('ipdPatientSelect') || document.getElementById('patientSearchInput');
                    if (patientSelect) {
                        FocusManager.highlight(patientSelect, true);
                    }
                }
            });

            // F8: Proceed to Payment Preview Modal
            ShortcutManager.register({
                key: 'F8',
                scope: 'pos',
                description: 'Open Billing Preview & Payment Modal',
                handler: () => {
                    if (typeof window.openBillingPreviewModal === 'function') {
                        window.openBillingPreviewModal();
                    } else {
                        const btn = document.getElementById('btnProceedPreview');
                        if (btn) btn.click();
                    }
                }
            });

            // F9: Confirm & Complete Sale
            ShortcutManager.register({
                key: 'F9',
                scope: 'pos',
                description: 'Confirm & Complete Sale',
                handler: () => {
                    const modal = document.getElementById('billingPreviewModal');
                    const isModalOpen = modal && modal.classList.contains('show');

                    if (isModalOpen) {
                        if (typeof window.submitFinalSale === 'function') {
                            window.submitFinalSale();
                        }
                    } else {
                        // Open preview first to let cashier verify
                        if (typeof window.openBillingPreviewModal === 'function') {
                            window.openBillingPreviewModal();
                        }
                    }
                }
            });

            // Ctrl + K: Global Command Palette
            ShortcutManager.register({
                key: 'k',
                ctrl: true,
                scope: 'all',
                description: 'Open Global Command Palette',
                handler: () => {
                    CommandPalette.open();
                }
            });

            // Ctrl + S: Save Form or Draft
            ShortcutManager.register({
                key: 's',
                ctrl: true,
                scope: 'all',
                description: 'Save / Submit Current Form or Draft',
                handler: (e) => {
                    const activeEl = document.activeElement;
                    const form = activeEl ? activeEl.closest('form') : null;
                    if (form && form.id !== 'posSaleForm') {
                        const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
                        if (submitBtn) {
                            submitBtn.click();
                            return;
                        }
                    }
                    if (typeof window.saveDraft === 'function') {
                        window.saveDraft();
                    }
                }
            });

            // '?' or Shift + '/': Open Keyboard Shortcuts Help
            ShortcutManager.register({
                key: '?',
                shift: true,
                scope: 'all',
                description: 'Open Keyboard Shortcuts Help Modal',
                handler: () => {
                    const activeEl = document.activeElement;
                    const isTyping = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA') && !activeEl.readOnly;
                    // Only open if not inside an active typing field
                    if (!isTyping) {
                        ShortcutsHelp.open();
                    }
                }
            });

            // Alt + S: Focus Sidebar Navigation & Scroll into View
            ShortcutManager.register({
                key: 's',
                alt: true,
                scope: 'all',
                description: 'Focus Sidebar Navigation',
                handler: () => {
                    const nav = document.querySelector('.sidebar-nav');
                    if (nav) {
                        const target = nav.querySelector('.nav-link.active') || nav.querySelector('.nav-link');
                        if (target) {
                            target.focus({ preventScroll: true });
                        }
                    }
                }
            });
        },

        _handleGlobalEscape(e) {
            // 1. If Command Palette is open, close it
            const palette = document.getElementById('commandPaletteModal');
            if (palette && palette.classList.contains('show')) {
                e.preventDefault();
                CommandPalette.close();
                return;
            }

            // 2. If Shortcuts Help is open, close it
            const help = document.getElementById('shortcutsHelpModal');
            if (help && help.classList.contains('show')) {
                e.preventDefault();
                const bsHelp = bootstrap.Modal.getInstance(help);
                if (bsHelp) bsHelp.hide();
                return;
            }

            // 3. If Medicine Autocomplete suggestions are open, close them
            const suggestions = document.getElementById('medicineSuggestionsList');
            if (suggestions && !suggestions.classList.contains('d-none')) {
                e.preventDefault();
                suggestions.classList.add('d-none');
                return;
            }

            // 4. If a Bootstrap Modal is open, close it
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                e.preventDefault();
                const bsModal = bootstrap.Modal.getInstance(openModal);
                if (bsModal) bsModal.hide();
                return;
            }

            // 5. If on POS page, refocus primary search
            const posSearch = document.getElementById('chargeMedicineInput');
            if (posSearch && document.activeElement !== posSearch) {
                e.preventDefault();
                FocusManager.highlight(posSearch, true);
            }
        }
    };

    // Expose API globally
    window.PharmacyKeyboard = {
        KeyboardManager,
        FocusManager,
        ShortcutManager,
        FormNavigator,
        DropdownNavigator,
        ModalNavigator,
        CommandPalette,
        ShortcutsHelp,
        BarcodeScannerHandler,
        PosWorkflowHelper,
        SpatialNavigator
    };

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => KeyboardManager.init());
    } else {
        KeyboardManager.init();
    }

})(window, document);
