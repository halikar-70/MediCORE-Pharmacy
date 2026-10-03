(function() {
    'use strict';

    var FETCH_COOLDOWN = 2000; // 2 seconds throttle per select element
    var isFetching = false;

    function getBaseUrl() {
        if (typeof window.BASE_URL !== 'undefined' && window.BASE_URL) {
            return window.BASE_URL;
        }
        var path = window.location.pathname;
        var idx = path.indexOf('/modules/');
        if (idx !== -1) {
            return path.substring(0, idx + 1);
        }
        return '/MediPro/';
    }

    function formatDoctorLabel(doc) {
        var label = doc.name || 'Dr. Unknown';
        if (doc.specialization) {
            label += ' (' + doc.specialization + ')';
        } else if (doc.department_name) {
            label += ' (' + doc.department_name + ')';
        }
        return label;
    }

    function syncDoctorSelectOptions(select, activeDoctors) {
        var currentVal = select.value ? String(select.value) : '';
        var activeIds = new Set(activeDoctors.map(function(d) { return String(d.doctor_id); }));

        // Collect existing options to preserve placeholders or historical selection
        var preservedOptions = [];
        Array.from(select.options).forEach(function(opt) {
            var val = String(opt.value);
            // Preserve placeholder (empty value) or currently selected option if inactive
            if (val === '' || (val === currentVal && !activeIds.has(val))) {
                preservedOptions.push({
                    value: opt.value,
                    text: opt.text,
                    selected: opt.selected,
                    disabled: opt.disabled
                });
            }
        });

        // Rebuild options list
        select.innerHTML = '';

        // Re-add preserved options first (e.g. placeholder)
        preservedOptions.forEach(function(p) {
            var opt = document.createElement('option');
            opt.value = p.value;
            opt.textContent = p.text;
            if (p.disabled) opt.disabled = true;
            if (p.selected) opt.selected = true;
            select.appendChild(opt);
        });

        // Add latest active doctors from database
        activeDoctors.forEach(function(doc) {
            var docIdStr = String(doc.doctor_id);
            // Don't duplicate if already in preserved (e.g., placeholder)
            if (preservedOptions.some(function(p) { return String(p.value) === docIdStr; })) {
                return;
            }
            var opt = document.createElement('option');
            opt.value = doc.doctor_id;
            opt.textContent = formatDoctorLabel(doc);
            if (docIdStr === currentVal) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });

        if (currentVal) {
            select.value = currentVal;
        }

        // Trigger change event to notify any legacy change handlers
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function refreshDoctorSelect(select) {
        var now = Date.now();
        var lastFetch = parseInt(select.dataset.lastDoctorFetch || '0', 10);

        if (now - lastFetch < FETCH_COOLDOWN) {
            return; // Throttle frequent clicks within cooldown period
        }

        select.dataset.lastDoctorFetch = now;

        var apiUrl = getBaseUrl() + 'modules/doctors/get_active_doctors.php';

        fetch(apiUrl, { credentials: 'same-origin' })
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP error ' + res.status);
                return res.json();
            })
            .then(function(data) {
                if (data && data.success && Array.isArray(data.doctors)) {
                    syncDoctorSelectOptions(select, data.doctors);
                } else if (data && data.error) {
                    showDoctorLoadError(data.error);
                }
            })
            .catch(function(err) {
                console.warn('[LiveDoctorDropdown] Error refreshing active doctors:', err);
                showDoctorLoadError('Unable to load doctors. Please try again.');
            });
    }

    function showDoctorLoadError(msg) {
        if (typeof window.flash === 'function') {
            window.flash('error', msg);
        } else if (typeof window.showToast === 'function') {
            window.showToast(msg, 'error');
        } else {
            console.error('[LiveDoctorDropdown] ' + msg);
        }
    }

    function attachLiveDoctorListeners() {
        var selects = document.querySelectorAll('select[name="doctor_id"], select#doctor_id, select#doctor_id_select, select.live-doctor-select');

        selects.forEach(function(select) {
            if (select.dataset.liveDoctorBound) return;
            select.dataset.liveDoctorBound = "true";

            // Attach listeners to trigger refresh on focus/click
            select.addEventListener('focus', function() { refreshDoctorSelect(select); });
            select.addEventListener('click', function() { refreshDoctorSelect(select); });

            // Also attach to custom container if searchable-select widget is wrapping it
            var container = select.closest('.custom-select-container') || (select.nextElementSibling && select.nextElementSibling.classList.contains('custom-select-container') ? select.nextElementSibling : null);
            if (container) {
                container.addEventListener('mousedown', function() { refreshDoctorSelect(select); }, { capture: true });
                container.addEventListener('focusin', function() { refreshDoctorSelect(select); }, { capture: true });
            }
        });
    }

    // Initialize on DOM Ready and observe dynamic form insertions
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachLiveDoctorListeners);
    } else {
        attachLiveDoctorListeners();
    }

    // MutationObserver to bind new doctor selects loaded dynamically via AJAX
    var observer = new MutationObserver(function() {
        attachLiveDoctorListeners();
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // Expose global helper if manual trigger needed
    window.refreshActiveDoctors = function(selectElem) {
        if (selectElem) {
            delete selectElem.dataset.lastDoctorFetch;
            refreshDoctorSelect(selectElem);
        } else {
            document.querySelectorAll('select[name="doctor_id"]').forEach(function(s) {
                delete s.dataset.lastDoctorFetch;
                refreshDoctorSelect(s);
            });
        }
    };
})();
