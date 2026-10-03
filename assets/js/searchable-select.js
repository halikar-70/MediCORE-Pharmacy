(function() {
    'use strict';

    // ───────────────────────────────────────────────────────────────────────
    // Constants
    // ───────────────────────────────────────────────────────────────────────
    var ITEM_HEIGHT = 32;       // approximate px per dropdown item
    var MAX_VISIBLE = 5;        // max visible items before scrolling
    var MENU_PADDING = 8;       // vertical padding inside the menu
    var MAX_MENU_HEIGHT = (ITEM_HEIGHT * MAX_VISIBLE) + MENU_PADDING;
    var Z_INDEX = 9999;

    // Track the currently open dropdown so we can close it when another opens
    var currentlyOpen = null;

    // ───────────────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────────────

    /** Inject or normalise placeholder option on the native <select> */
    function setupSelectPlaceholder(select) {
        var hasPlaceholder = false;
        var placeholderOpt = null;
        for (var i = 0; i < select.options.length; i++) {
            var val = select.options[i].value;
            var txt = (select.options[i].text || '').toLowerCase().trim();
            if (val === "" || val === null || String(val).toUpperCase() === "ALL" || (val === "0" && (txt.includes('all ') || txt.includes('-- all'))) || txt.startsWith('all ') || txt === 'all') {
                hasPlaceholder = true;
                placeholderOpt = select.options[i];
                break;
            }
        }

        var isRequired = select.required || select.dataset.wasRequired === "true";
        var hasExplicitSelected = select.querySelector('option[selected]') !== null;

        if (!hasPlaceholder) {
            placeholderOpt = document.createElement('option');
            placeholderOpt.value = "";
            placeholderOpt.text = isRequired ? "-- Select --" : "-- No Selection --";
            placeholderOpt.disabled = true;
            if (!hasExplicitSelected) {
                placeholderOpt.selected = true;
            }
            select.insertBefore(placeholderOpt, select.firstChild);
            if (!hasExplicitSelected) {
                select.value = "";
                select.selectedIndex = 0;
            }
        } else {
            var currentText = placeholderOpt.text.toLowerCase().trim();
            if (currentText === "" || currentText === "select" || currentText === "search / select...") {
                placeholderOpt.text = isRequired ? "-- Select --" : "-- No Selection --";
                placeholderOpt.disabled = true;
            }
            if (placeholderOpt.value === "") {
                placeholderOpt.disabled = true;
            }
            if (!hasExplicitSelected && select.value === placeholderOpt.value) {
                placeholderOpt.selected = true;
                select.value = placeholderOpt.value;
                select.selectedIndex = Array.from(select.options).indexOf(placeholderOpt);
            }
        }
        return placeholderOpt;
    }

    /** Standard constraints for native selects that opt out of the custom widget */
    function applyStandardSelectConstraints(select) {
        if (select.dataset.standardConstraintsInit) return;
        select.dataset.standardConstraintsInit = "true";

        if (select.required) {
            select.dataset.wasRequired = "true";
        }

        setupSelectPlaceholder(select);

        if (select.required || select.dataset.wasRequired === "true") {
            select.required = true;
            var setCustomMsg = function() {
                if (select.value === "") {
                    select.setCustomValidity("Please select a value.");
                } else {
                    select.setCustomValidity("");
                }
            };
            select.addEventListener('invalid', setCustomMsg);
            select.addEventListener('change', setCustomMsg);
            setCustomMsg();
        }

        var observer = new MutationObserver(function() {
            observer.disconnect();
            setupSelectPlaceholder(select);
            observer.observe(select, { childList: true, attributes: true, subtree: true });
        });
        observer.observe(select, { childList: true, attributes: true, subtree: true });
    }

    // ───────────────────────────────────────────────────────────────────────
    // Position the menu using position:fixed so it escapes all containers
    // ───────────────────────────────────────────────────────────────────────
    function positionMenu(menu, anchor) {
        var rect = anchor.getBoundingClientRect();
        var menuHeight = menu.scrollHeight || MAX_MENU_HEIGHT;
        if (menuHeight > MAX_MENU_HEIGHT) menuHeight = MAX_MENU_HEIGHT;

        var spaceBelow = window.innerHeight - rect.bottom;
        var spaceAbove = rect.top;
        var openUpward = false;

        // Decide direction: prefer below, but go up if not enough space below and more above
        if (spaceBelow < menuHeight + 4 && spaceAbove > spaceBelow) {
            openUpward = true;
        }

        menu.style.position = 'fixed';
        menu.style.width = rect.width + 'px';
        menu.style.left = rect.left + 'px';
        menu.style.zIndex = Z_INDEX;
        menu.style.maxHeight = MAX_MENU_HEIGHT + 'px';
        menu.style.overflowY = 'auto';

        if (openUpward) {
            menu.style.bottom = (window.innerHeight - rect.top + 2) + 'px';
            menu.style.top = 'auto';
            menu.classList.add('dropdown-menu-up');
            menu.classList.remove('dropdown-menu-down');
        } else {
            menu.style.top = (rect.bottom + 2) + 'px';
            menu.style.bottom = 'auto';
            menu.classList.add('dropdown-menu-down');
            menu.classList.remove('dropdown-menu-up');
        }
    }

    // ───────────────────────────────────────────────────────────────────────
    // Main init for a single <select>
    // ───────────────────────────────────────────────────────────────────────
    function initSelect(select) {
        if (select.dataset.searchableInit) return;
        select.dataset.searchableInit = "true";

        if (select.required) {
            select.dataset.wasRequired = "true";
        }

        var placeholderOpt = setupSelectPlaceholder(select);

        // Determine if this select should have a search input
        var isSearchable = true;

        // Remove required from original select to avoid hidden-element focus issues
        if (select.required) {
            select.required = false;
        }

        // Hide original select
        select.style.display = 'none';

        // ── Build Container ──
        var container = document.createElement('div');
        container.className = 'custom-select-container';
        container.style.position = 'relative';
        container.style.width = select.style.width || '';
        container.style.minWidth = select.style.minWidth || '';
        if (select.className.includes('w-100')) {
            container.classList.add('w-100');
        }
        container.style.display = 'block';

        // ── Build Trigger (input for searchable, button-like div for simple) ──
        var input;
        if (isSearchable) {
            input = document.createElement('input');
            input.type = 'text';
            var inputClasses = 'form-control';
            if (select.classList.contains('form-select-sm') || select.classList.contains('form-control-sm')) {
                inputClasses += ' form-control-sm';
            }
            input.className = inputClasses + ' searchable-select-input';
            input.setAttribute('autocomplete', 'new-password');
            input.setAttribute('autocorrect', 'off');
            input.setAttribute('autocapitalize', 'off');
            input.setAttribute('spellcheck', 'false');
        } else {
            // Non-searchable: use a readonly input that looks like a form-select
            input = document.createElement('input');
            input.type = 'text';
            input.readOnly = true;
            var inputClasses2 = 'form-control';
            if (select.classList.contains('form-select-sm') || select.classList.contains('form-control-sm')) {
                inputClasses2 += ' form-control-sm';
            }
            input.className = inputClasses2 + ' searchable-select-input searchable-select-readonly';
            input.style.cursor = 'pointer';
            input.style.caretColor = 'transparent';
            input.setAttribute('autocomplete', 'off');
        }

        // Set placeholder text
        var placeholderText = placeholderOpt ? placeholderOpt.text : '-- Select --';
        input.setAttribute('placeholder', placeholderText);
        input.required = (select.dataset.wasRequired === "true");
        input.disabled = select.disabled;
        if (select.tabIndex) input.tabIndex = select.tabIndex;
        if (select.style.pointerEvents) input.style.pointerEvents = select.style.pointerEvents;
        if (select.style.backgroundColor) input.style.backgroundColor = select.style.backgroundColor;

        var menuId = 'searchable-menu-' + Math.random().toString(36).substr(2, 9);
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', menuId);

        container.appendChild(input);

        // ── Dropdown Arrow Indicator ──
        var arrow = document.createElement('span');
        arrow.className = 'searchable-select-arrow';
        arrow.style.cssText = 'position:absolute; right:10px; top:50%; transform:translateY(-50%); pointer-events:none; display:flex; align-items:center; line-height:0; z-index:3;';
        arrow.innerHTML = '<svg width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        container.appendChild(arrow);

        // ── Build Dropdown Menu ──
        var menu = document.createElement('ul');
        menu.id = menuId;
        menu.setAttribute('role', 'listbox');
        menu.className = 'dropdown-menu searchable-select-menu';
        menu.style.maxHeight = MAX_MENU_HEIGHT + 'px';
        menu.style.overflowY = 'auto';
        menu.style.zIndex = Z_INDEX;
        menu.style.margin = '0';
        menu.style.padding = '0.25rem 0';

        // Append menu to <body> so it escapes all overflow:hidden containers
        document.body.appendChild(menu);

        // Insert container after original select
        select.parentNode.insertBefore(container, select.nextSibling);

        var activeIndex = -1;
        var isFetching = false;
        var debounceTimer = null;
        var isOpen = false;

        // ── Sync input text from select value ──
        function updateInputFromSelect() {
            var selectedOpt = select.options[select.selectedIndex];
            if (selectedOpt && selectedOpt.value !== "") {
                input.value = selectedOpt.text;
                input.setCustomValidity("");
            } else {
                input.value = '';
                if (select.dataset.wasRequired === "true") {
                    input.setCustomValidity("Please select a value.");
                } else {
                    input.setCustomValidity("");
                }
            }
        }

        updateInputFromSelect();

        // Custom validity events
        input.addEventListener('invalid', function() {
            if (input.required && (select.value === "" || select.value === null)) {
                input.setCustomValidity("Please select a value.");
            } else {
                input.setCustomValidity("");
            }
        });

        if (isSearchable) {
            input.addEventListener('input', function() {
                if (select.value === "") {
                    input.setCustomValidity("Please select a value.");
                } else {
                    input.setCustomValidity("");
                }
            });
        }

        // ── Render local items (non-AJAX) ──
        function renderLocalItems(filterText) {
            filterText = filterText || '';
            menu.innerHTML = '';
            var query = filterText.toLowerCase().trim();
            var matches = 0;

            if (query === '') {
                Array.from(select.children).forEach(function(child) {
                    if (child.tagName === 'OPTGROUP') {
                        var headerLi = document.createElement('li');
                        headerLi.className = 'dropdown-header text-muted fw-bold ps-3 py-1 bg-light small';
                        headerLi.style.fontSize = '11px';
                        headerLi.style.pointerEvents = 'none';
                        headerLi.style.borderBottom = '1px solid #eee';
                        headerLi.style.borderTop = '1px solid #eee';
                        headerLi.textContent = child.label;
                        menu.appendChild(headerLi);

                        Array.from(child.children).forEach(function(opt) {
                            if (opt.value === "" && opt.disabled) return;
                            renderOptionItem(opt, true);
                        });
                    } else if (child.tagName === 'OPTION') {
                        if (child.value === "" && child.disabled) return;
                        renderOptionItem(child, false);
                    }
                });
            } else {
                Array.from(select.options).forEach(function(opt) {
                    if (opt.value === "" && opt.disabled) return;
                    if (opt.value === "" && query !== '') return;

                    var text = opt.text;
                    var parent = opt.parentNode;
                    var displayText = text;
                    if (parent && parent.tagName === 'OPTGROUP') {
                        displayText = parent.label + ' → ' + text;
                    }

                    if (displayText.toLowerCase().indexOf(query) !== -1) {
                        renderOptionItem(opt, false, displayText);
                    }
                });
            }

            function renderOptionItem(opt, isIndented, customText) {
                var idx = Array.from(select.options).indexOf(opt);
                var text = customText || opt.text;
                var li = document.createElement('li');
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dropdown-item py-1 text-start border-0 w-100 bg-transparent';
                btn.style.fontSize = '12.5px';
                btn.style.minHeight = ITEM_HEIGHT + 'px';
                btn.style.lineHeight = '1.4';
                btn.textContent = text;
                btn.dataset.index = idx;

                btn.id = menuId + '-opt-' + idx;
                btn.setAttribute('role', 'option');
                btn.setAttribute('aria-selected', select.selectedIndex === idx ? 'true' : 'false');

                if (isIndented) {
                    btn.classList.add('ps-4');
                } else {
                    btn.classList.add('ps-3');
                }

                if (select.selectedIndex === idx) {
                    btn.classList.add('active', 'bg-light', 'text-dark');
                }

                btn.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    select.selectedIndex = idx;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    updateInputFromSelect();
                    closeDropdown();
                });

                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    select.selectedIndex = idx;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    updateInputFromSelect();
                    closeDropdown();
                });

                li.appendChild(btn);
                menu.appendChild(li);
                matches++;
            }

            if (select.dataset.allowCustom === "true" && query !== '') {
                var li2 = document.createElement('li');
                var btn2 = document.createElement('button');
                btn2.type = 'button';
                btn2.className = 'dropdown-item py-1 px-3 text-start border-0 w-100 text-primary fw-bold bg-transparent';
                btn2.style.fontSize = '12.5px';
                btn2.style.minHeight = ITEM_HEIGHT + 'px';
                btn2.innerHTML = '<i class="bi bi-plus-circle me-1"></i> Use custom: "' + filterText + '"';
                btn2.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var opt = Array.from(select.options).find(function(o) { return o.text === filterText; });
                    if (!opt) {
                        opt = document.createElement('option');
                        opt.value = filterText;
                        opt.textContent = filterText;
                        select.appendChild(opt);
                    }
                    select.value = opt.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    updateInputFromSelect();
                    closeDropdown();
                });
                li2.appendChild(btn2);
                menu.appendChild(li2);
                matches++;
            }

            if (matches === 0) {
                var liEmpty = document.createElement('li');
                liEmpty.className = 'px-3 py-2 text-muted small text-center';
                var isPatient = select.dataset.ajaxType === 'patient' || (select.name && select.name.toLowerCase().includes('patient')) || (select.id && select.id.toLowerCase().includes('patient'));
                liEmpty.textContent = isPatient ? 'No patients found.' : 'No matching records found.';
                menu.appendChild(liEmpty);
            }
            activeIndex = -1;
        }

        // ── AJAX search ──
        function fetchAjaxItems(query) {
            query = query || '';
            if (debounceTimer) clearTimeout(debounceTimer);

            menu.innerHTML = '<li class="px-3 py-2 text-muted small text-center"><span class="spinner-border spinner-border-sm me-2"></span>Searching...</li>';

            debounceTimer = setTimeout(function() {
                var ajaxType = select.dataset.ajaxType;
                var baseUrl = window.BASE_URL || '';
                var url = baseUrl + 'modules/common/search_api.php?type=' + encodeURIComponent(ajaxType) + '&q=' + encodeURIComponent(query);

                isFetching = true;
                fetch(url)
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        isFetching = false;
                        menu.innerHTML = '';

                        var currentVal = select.value;
                        var currentTextVal = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';

                        select.innerHTML = '';

                        var phOpt = document.createElement('option');
                        phOpt.value = "";
                        phOpt.text = placeholderText;
                        select.appendChild(phOpt);

                        var exists = false;
                        data.forEach(function(item) {
                            var opt = document.createElement('option');
                            opt.value = item.value;
                            opt.text = item.text;
                            if (String(item.value) === String(currentVal)) {
                                opt.selected = true;
                                exists = true;
                            }
                            select.appendChild(opt);
                        });

                        if (currentVal && currentVal !== "" && !exists && currentTextVal) {
                            var keepOpt = document.createElement('option');
                            keepOpt.value = currentVal;
                            keepOpt.text = currentTextVal;
                            keepOpt.selected = true;
                            select.appendChild(keepOpt);
                        }

                        var matchCount = 0;
                        Array.from(select.options).forEach(function(opt, idx) {
                            if (opt.value === "") return;

                            var li = document.createElement('li');
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'dropdown-item py-1 px-3 text-start border-0 w-100 bg-transparent';
                            btn.style.fontSize = '12.5px';
                            btn.style.minHeight = ITEM_HEIGHT + 'px';
                            btn.textContent = opt.text;
                            btn.dataset.index = idx;

                            if (select.selectedIndex === idx) {
                                btn.classList.add('active', 'bg-light', 'text-dark');
                            }

                            btn.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                select.selectedIndex = idx;
                                select.dispatchEvent(new Event('change', { bubbles: true }));
                                updateInputFromSelect();
                                closeDropdown();
                            });

                            btn.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                select.selectedIndex = idx;
                                select.dispatchEvent(new Event('change', { bubbles: true }));
                                updateInputFromSelect();
                                closeDropdown();
                            });

                            li.appendChild(btn);
                            menu.appendChild(li);
                            matchCount++;
                        });

                        if (matchCount === 0) {
                            var liEmpty = document.createElement('li');
                            liEmpty.className = 'px-3 py-2 text-muted small text-center';
                            var isPatient2 = select.dataset.ajaxType === 'patient' || (select.name && select.name.toLowerCase().includes('patient')) || (select.id && select.id.toLowerCase().includes('patient'));
                            liEmpty.textContent = isPatient2 ? 'No patients found.' : 'No matching records found.';
                            menu.appendChild(liEmpty);
                        }
                        activeIndex = -1;

                        // Reposition after content loads
                        if (isOpen) {
                            positionMenu(menu, input);
                        }
                    })
                    .catch(function() {
                        isFetching = false;
                        menu.innerHTML = '<li class="px-3 py-2 text-danger small text-center">Error searching records.</li>';
                    });
            }, 300);
        }

        // ── Open / Close ──
        function openDropdown() {
            // Close any other currently open dropdown first
            if (currentlyOpen && currentlyOpen !== closeDropdown) {
                currentlyOpen();
            }

            isOpen = true;
            currentlyOpen = closeDropdown;

            if (select.dataset.ajaxType) {
                var selectedText = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
                var q = input.value === selectedText ? '' : input.value;
                fetchAjaxItems(q);
            } else {
                renderLocalItems(isSearchable ? input.value : '');
            }

            menu.classList.add('show');
            menu.style.display = 'block';
            input.setAttribute('aria-expanded', 'true');
            positionMenu(menu, input);

            container.classList.add('open');
            arrow.classList.add('open');

            // Attach close-on-outside-click
            setTimeout(function() {
                document.addEventListener('mousedown', onDocMousedown);
            }, 0);

            // Reposition on scroll or resize
            window.addEventListener('scroll', onScrollResize, true);
            window.addEventListener('resize', onScrollResize);
        }

        function closeDropdown() {
            if (!isOpen) return;
            isOpen = false;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');

            if (currentlyOpen === closeDropdown) {
                currentlyOpen = null;
            }

            menu.classList.remove('show');
            menu.style.display = 'none';
            container.classList.remove('open');
            arrow.classList.remove('open');

            document.removeEventListener('mousedown', onDocMousedown);
            window.removeEventListener('scroll', onScrollResize, true);
            window.removeEventListener('resize', onScrollResize);

            if (select.dataset.allowCustom === "true") {
                var typedVal = input.value.trim();
                if (typedVal !== "") {
                    var opt = Array.from(select.options).find(function(o) { return o.text === typedVal; });
                    if (!opt) {
                        opt = document.createElement('option');
                        opt.value = typedVal;
                        opt.textContent = typedVal;
                        select.appendChild(opt);
                    }
                    if (select.value !== opt.value) {
                        select.value = opt.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } else {
                    if (select.value !== "") {
                        select.value = "";
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            } else {
                var typedVal2 = input.value.trim().toLowerCase();
                if (typedVal2 !== "") {
                    var matchedOpt = Array.from(select.options).find(function(o) {
                        return o.text.trim().toLowerCase() === typedVal2;
                    });
                    if (matchedOpt && select.value !== matchedOpt.value) {
                        select.value = matchedOpt.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            }

            // Defer text sync so the value is settled after any item click
            setTimeout(function() {
                updateInputFromSelect();
            }, 50);
        }

        function onDocMousedown(e) {
            if (!container.contains(e.target) && !menu.contains(e.target)) {
                closeDropdown();
            }
        }

        function onScrollResize() {
            if (isOpen) {
                positionMenu(menu, input);
            }
        }

        // ── Event bindings ──

        if (isSearchable) {
            // Searchable: open on focus, filter on input
            input.addEventListener('focus', function() {
                openDropdown();
            });

            input.addEventListener('input', function() {
                if (!isOpen) {
                    openDropdown();
                } else {
                    if (select.dataset.ajaxType) {
                        fetchAjaxItems(input.value);
                    } else {
                        renderLocalItems(input.value);
                        positionMenu(menu, input);
                    }
                }
            });
        } else {
            // Non-searchable: toggle on click
            input.addEventListener('mousedown', function(e) {
                e.preventDefault();
                if (isOpen) {
                    closeDropdown();
                } else {
                    openDropdown();
                }
            });

            // Also handle focus (e.g. tab navigation)
            input.addEventListener('focus', function() {
                if (!isOpen) {
                    openDropdown();
                }
            });
        }

        // Keyboard navigation
        input.addEventListener('keydown', function(e) {
            var items = menu.querySelectorAll('.dropdown-item');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) {
                    openDropdown();
                    return;
                }
                if (items.length > 0) {
                    activeIndex = (activeIndex + 1) % items.length;
                    highlightItem(items);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen) {
                    openDropdown();
                    return;
                }
                if (items.length > 0) {
                    activeIndex = (activeIndex - 1 + items.length) % items.length;
                    highlightItem(items);
                }
            } else if (e.key === 'Home') {
                if (isOpen && items.length > 0) {
                    e.preventDefault();
                    activeIndex = 0;
                    highlightItem(items);
                }
            } else if (e.key === 'End') {
                if (isOpen && items.length > 0) {
                    e.preventDefault();
                    activeIndex = items.length - 1;
                    highlightItem(items);
                }
            } else if (e.key === 'Enter') {
                if (isOpen) {
                    e.preventDefault();
                    var targetItem = (activeIndex >= 0 && activeIndex < items.length) ? items[activeIndex] : (items.length > 0 ? items[0] : null);
                    if (targetItem) {
                        targetItem.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                    } else {
                        closeDropdown();
                    }
                }
            } else if (e.key === 'Escape') {
                if (isOpen) {
                    e.preventDefault();
                    closeDropdown();
                }
            } else if (e.key === 'Tab') {
                closeDropdown();
            }
        });

        function highlightItem(items) {
            items.forEach(function(item, idx) {
                if (idx === activeIndex) {
                    item.classList.add('bg-primary', 'text-white');
                    item.setAttribute('aria-selected', 'true');
                    input.setAttribute('aria-activedescendant', item.id || '');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('bg-primary', 'text-white');
                    item.setAttribute('aria-selected', 'false');
                }
            });
        }

        // ── Observe changes on the original <select> ──
        var selectObserver = new MutationObserver(function() {
            selectObserver.disconnect();
            setupSelectPlaceholder(select);
            input.disabled = select.disabled;
            var updatedPlaceholder = placeholderOpt ? placeholderOpt.text : '-- Select --';
            input.setAttribute('placeholder', updatedPlaceholder);
            updateInputFromSelect();
            if (isOpen && !select.dataset.ajaxType) {
                renderLocalItems(isSearchable ? input.value : '');
                positionMenu(menu, input);
            }
            selectObserver.observe(select, { childList: true, attributes: true, subtree: true });
        });
        selectObserver.observe(select, { childList: true, attributes: true, subtree: true });

        // ── Cleanup if container is removed from DOM ──
        var containerObserver = new MutationObserver(function(mutations) {
            if (!document.body.contains(container)) {
                closeDropdown();
                if (menu.parentNode) menu.parentNode.removeChild(menu);
                containerObserver.disconnect();
                selectObserver.disconnect();
            }
        });
        containerObserver.observe(document.body, { childList: true, subtree: true });
    }

    // ───────────────────────────────────────────────────────────────────────
    // Public API
    // ───────────────────────────────────────────────────────────────────────
    window.initializeSearchableSelects = function(root) {
        root = root || document.body;
        root.querySelectorAll('select').forEach(function(select) {
            if (select.id === 'catalogStockFilterSelect') {
                return;
            }
            if (select.dataset.noSearch === "true" || select.classList.contains('no-search') ||
                select.id === 'patientSelect' || select.id === 'chargeBatchSelect' ||
                select.id === 'modalPaymentMode' || select.id === 'ipdPatientSelect' ||
                select.id === 'billTypeSelect' || select.name === 'sale_type' || select.name === 'payment_status') {
                applyStandardSelectConstraints(select);
                return;
            }
            initSelect(select);
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        window.initializeSearchableSelects();
    });
})();
