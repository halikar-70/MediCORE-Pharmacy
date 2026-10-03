// Global automatic capitalization of the first letter for applicable text fields (attached immediately)
(function () {
  function handleCapitalization(e) {
    const target = e.target;
    if (!target || !(target.tagName === "INPUT" || target.tagName === "TEXTAREA")) {
      return;
    }

    // Skip non-text inputs or inputs explicitly excluded/readonly/disabled
    const inputType = (target.getAttribute("type") || "text").toLowerCase();
    if (target.readOnly || target.disabled) return;

    // Allowed input types
    const allowedTypes = ["text", "search"];
    if (target.tagName === "INPUT" && !allowedTypes.includes(inputType)) {
      return;
    }

    const name = (target.name || "").toLowerCase();
    const id = (target.id || "").toLowerCase();

    // Keywords to exclude from auto-capitalization
    const excludeKeywords = [
      "email", "mail",
      "user", "username", "login",
      "password", "pass", "pwd",
      "phone", "mobile", "contact", "tel",
      "otp", "pin",
      "aadhaar", "aadhar", "pan",
      "receipt", "bill", "invoice", "uhid", "reg", "registration",
      "url", "website", "link",
      "file", "filename", "path",
      "code", "sku", "hsn",
      "amount", "price", "fee", "cost", "charge", "qty", "quantity", "total", "subtotal", "tax", "discount", "balance", "paid"
    ];

    const isExcluded = excludeKeywords.some(kw => name.includes(kw) || id.includes(kw));
    if (isExcluded) return;

    const val = target.value;
    if (!val) return;

    // Check if the first character is lowercase letter
    const firstChar = val.charAt(0);
    const upperFirstChar = firstChar.toUpperCase();

    if (firstChar !== upperFirstChar && firstChar.toLowerCase() !== firstChar.toUpperCase()) {
      const start = target.selectionStart;
      const end = target.selectionEnd;

      target.value = upperFirstChar + val.slice(1);

      if (start !== null && end !== null) {
        target.setSelectionRange(start, end);
      }
    }
  }

  document.addEventListener("input", handleCapitalization, true);
})();

document.addEventListener("DOMContentLoaded", function () {
  const toggle = document.getElementById("sidebarToggle");
  const sidebar = document.querySelector(".sidebar");
  if (toggle && sidebar) {
    toggle.addEventListener("click", () => sidebar.classList.toggle("show"));
  }

  if (sidebar) {
    document.addEventListener("click", function (event) {
      const clickedInsideSidebar = sidebar.contains(event.target);
      const clickedToggle = toggle && toggle.contains(event.target);
      if (
        window.innerWidth <= 768 &&
        !clickedInsideSidebar &&
        !clickedToggle &&
        sidebar.classList.contains("show")
      ) {
        sidebar.classList.remove("show");
      }
    });
  }

  // Standardized Toast Notification System
  window.PharmacyToast = {
    show: function(options) {
      const container = document.getElementById('pharmacyToastContainer');
      if (!container) return;

      const type = options.type || 'info'; // 'success' | 'error' | 'warning' | 'info'
      const title = options.title || (type === 'success' ? 'Success' : (type === 'error' ? 'Error' : 'Notification'));
      const message = options.message || '';
      const duration = options.duration || 4500;

      let iconClass = 'ti ti-info-circle text-info';
      let borderClass = 'toast-info';
      if (type === 'success') {
        iconClass = 'ti ti-circle-check text-emerald';
        borderClass = 'toast-success';
      } else if (type === 'error' || type === 'danger') {
        iconClass = 'ti ti-alert-circle text-danger';
        borderClass = 'toast-danger';
      } else if (type === 'warning') {
        iconClass = 'ti ti-alert-triangle text-warning';
        borderClass = 'toast-warning';
      }

      const toastId = 'toast_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4);
      const toastEl = document.createElement('div');
      toastEl.id = toastId;
      toastEl.className = `toast pharmacy-toast ${borderClass} show mb-2`;
      toastEl.setAttribute('role', 'alert');
      toastEl.setAttribute('aria-live', 'assertive');
      toastEl.setAttribute('aria-atomic', 'true');

      toastEl.innerHTML = `
        <div class="toast-header">
          <div class="d-flex align-items-center gap-1.5">
            <i class="${iconClass} fs-5"></i>
            <strong class="me-auto">${title}</strong>
          </div>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
          ${message}
        </div>
      `;

      container.appendChild(toastEl);

      const bsToast = new bootstrap.Toast(toastEl, { delay: duration });
      bsToast.show();

      toastEl.addEventListener('hidden.bs.toast', () => {
        toastEl.remove();
      });
    },
    success: function(message, title = 'Success') {
      this.show({ type: 'success', title: title, message: message });
    },
    error: function(message, title = 'Action Failed') {
      this.show({ type: 'error', title: title, message: message || 'Unable to complete the action. Please try again.' });
    },
    warning: function(message, title = 'Warning') {
      this.show({ type: 'warning', title: title, message: message });
    },
    info: function(message, title = 'Notice') {
      this.show({ type: 'info', title: title, message: message });
    }
  };

  // Standardized Clinical Confirmation Modal System
  window.PharmacyConfirm = function(options) {
    const modalEl = document.getElementById('pharmacyConfirmModal');
    if (!modalEl) {
      if (confirm(options.message || 'Are you sure?')) {
        if (typeof options.onConfirm === 'function') options.onConfirm();
      }
      return;
    }

    const titleEl = document.getElementById('confirmModalTitle');
    const msgEl = document.getElementById('confirmModalMessage');
    const actionBtn = document.getElementById('confirmModalActionBtn');
    const cancelBtn = document.getElementById('confirmModalCancelBtn');
    const headerTitle = document.getElementById('pharmacyConfirmModalLabel');
    const iconEl = document.getElementById('confirmModalIcon');
    const iconWrap = document.getElementById('confirmModalIconWrap');

    if (titleEl) titleEl.textContent = options.title || 'Confirm Action';
    if (msgEl) msgEl.textContent = options.message || 'This action cannot be undone.';
    if (headerTitle) headerTitle.textContent = options.header || 'Confirmation Required';

    const isDanger = (options.type === 'danger' || options.danger !== false);
    if (actionBtn) {
      actionBtn.textContent = options.confirmText || (isDanger ? 'Delete' : 'Confirm');
      actionBtn.className = `btn btn-sm rounded px-3 fw-semibold ${isDanger ? 'btn-danger' : 'btn-primary'}`;
    }

    if (iconWrap && iconEl) {
      if (isDanger) {
        iconWrap.className = 'd-inline-flex align-items-center justify-content-center rounded bg-danger-subtle text-danger';
        iconEl.className = 'ti ti-alert-triangle fs-5';
      } else {
        iconWrap.className = 'd-inline-flex align-items-center justify-content-center rounded bg-primary-subtle text-primary';
        iconEl.className = 'ti ti-help fs-5';
      }
    }

    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

    // One-time click handler on confirm button
    const handleConfirm = function() {
      bsModal.hide();
      actionBtn.removeEventListener('click', handleConfirm);
      if (typeof options.onConfirm === 'function') {
        options.onConfirm();
      }
    };

    actionBtn.onclick = handleConfirm;
    bsModal.show();
  };

  // Attach Clinical Confirmation Modal to all [data-confirm] elements
  document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-confirm]');
    if (!target) return;

    e.preventDefault();
    const prompt = target.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
    const title = target.getAttribute('data-confirm-title') || 'Confirm Action';
    const btnText = target.getAttribute('data-confirm-btn') || 'Confirm';

    window.PharmacyConfirm({
      title: title,
      message: prompt,
      confirmText: btnText,
      type: target.classList.contains('btn-danger') || target.classList.contains('text-danger') ? 'danger' : 'primary',
      onConfirm: function() {
        if (target.tagName === 'A' && target.href) {
          window.location.href = target.href;
        } else if (target.tagName === 'BUTTON' && target.type === 'submit' && target.form) {
          target.form.submit();
        } else if (typeof target.onclick === 'function') {
          target.onclick();
        }
      }
    });
  });

  // Standardized auto-dismissible alerts with fade
  document.querySelectorAll(".alert-dismissible").forEach(function (el) {
    setTimeout(() => {
      try {
        const alert = bootstrap.Alert.getOrCreateInstance(el);
        alert.close();
      } catch (err) {}
    }, 5000);
  });

  // Global: Prevent mouse wheel from accidentally incrementing / decrementing number inputs
  document.addEventListener("wheel", function (e) {
    if (e.target && e.target.tagName === "INPUT" && e.target.type === "number") {
      e.target.blur();
    }
    if (document.activeElement && document.activeElement.tagName === "INPUT" && document.activeElement.type === "number") {
      document.activeElement.blur();
    }
  }, { passive: true });

  document.addEventListener("focusin", function (e) {
    if (e.target && e.target.tagName === "INPUT" && e.target.type === "number") {
      if (!e.target._hasWheelDisabled) {
        e.target._hasWheelDisabled = true;
        e.target.addEventListener("wheel", function () {
          this.blur();
        }, { passive: true });
      }
    }
  });

  // Dynamically resolve the BASE_URL relative path for AJAX requests
  let basePath = "";
  const scripts = document.getElementsByTagName("script");
  for (let i = 0; i < scripts.length; i++) {
    const src = scripts[i].src;
    if (src && src.includes("assets/js/app.js")) {
      const idx = src.indexOf("assets/js/app.js");
      if (idx !== -1) {
        basePath = src.substring(0, idx);
      }
      break;
    }
  }

  // 1. Sidebar Search Menu Filter
  const sidebarSearch = document.querySelector(".sidebar-search input");
  if (sidebarSearch && sidebar) {
    sidebarSearch.addEventListener("input", function () {
      const query = this.value.toLowerCase().trim();
      const navItems = sidebar.querySelectorAll(".sidebar-nav .nav-item");
      const groupTitles = sidebar.querySelectorAll(".sidebar-nav .sidebar-group-title");
      const dividers = sidebar.querySelectorAll(".sidebar-nav .sidebar-divider");
      const sectionTitles = sidebar.querySelectorAll(".sidebar-section-title");

      navItems.forEach((item) => {
        const text = item.textContent.toLowerCase();
        if (text.includes(query)) {
          item.style.setProperty("display", "", "important");
        } else {
          item.style.setProperty("display", "none", "important");
        }
      });

      if (query !== "") {
        groupTitles.forEach(el => el.style.setProperty("display", "none", "important"));
        dividers.forEach(el => el.style.setProperty("display", "none", "important"));
        sectionTitles.forEach(el => el.style.setProperty("display", "none", "important"));
      } else {
        groupTitles.forEach(el => el.style.setProperty("display", "", "important"));
        dividers.forEach(el => el.style.setProperty("display", "", "important"));
        sectionTitles.forEach(el => el.style.setProperty("display", "", "important"));
      }
    });
  }

  // 2. Global Search Autocomplete
  const globalSearchInput = document.getElementById("globalSearchInput");
  const autocompleteResults = document.getElementById("search-autocomplete-results");
  
  if (globalSearchInput && autocompleteResults) {
    let debounceTimer;
    globalSearchInput.addEventListener("input", function () {
      clearTimeout(debounceTimer);
      const query = this.value.trim();
      
      if (query.length < 2) {
        autocompleteResults.classList.add("d-none");
        autocompleteResults.innerHTML = "";
        return;
      }
      
      debounceTimer = setTimeout(() => {
        fetch(`${basePath}api/patient_api.php?search=${encodeURIComponent(query)}`)
          .then(res => res.json())
          .then(res => {
            if (res.success && res.count > 0) {
              let html = '<div class="list-group list-group-flush shadow-sm" style="border-radius: 8px; overflow: hidden;">';
              res.data.forEach(patient => {
                html += `
                  <a href="${basePath}modules/patients/profile.php?id=${patient.patient_id}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3 border-0 border-bottom">
                    <div>
                      <div class="fw-semibold text-dark" style="font-size:0.9rem;">${patient.full_name_formatted || patient.patient_name || (patient.patient_prefix ? patient.patient_prefix + ' ' : '') + patient.first_name + ' ' + patient.last_name}</div>
                      <div class="text-muted small" style="font-size:0.75rem;">UHID: ${patient.patient_code} &bull; Phone: ${patient.phone}</div>
                    </div>
                    <span class="badge bg-primary-light text-primary rounded-pill small" style="font-size: 0.7rem; padding: 0.35em 0.65em;">Profile</span>
                  </a>
                `;
              });
              html += '</div>';
              autocompleteResults.innerHTML = html;
              autocompleteResults.classList.remove("d-none");
            } else {
              autocompleteResults.innerHTML = '<div class="p-3 text-muted small text-center bg-white rounded border">No patient records found.</div>';
              autocompleteResults.classList.remove("d-none");
            }
          })
          .catch(err => {
            console.error("Autocomplete search error:", err);
          });
      }, 300);
    });

    // Close autocomplete when clicking outside
    document.addEventListener("click", function (e) {
      if (!globalSearchInput.contains(e.target) && !autocompleteResults.contains(e.target)) {
        autocompleteResults.classList.add("d-none");
      }
    });
  }

  // 3. Notification Interactions (Mark all read)
  const markAllReadBtn = document.getElementById("mark-all-read-btn");
  if (markAllReadBtn) {
    markAllReadBtn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();

      fetch(`${basePath}api/notifications_api.php?action=mark_all_read`, {
        method: "POST"
      })
      .then(res => res.json())
      .then(res => {
        if (res.success) {
          const badge = document.getElementById("notif-badge-count");
          if (badge) badge.remove();
          
          document.querySelectorAll(".notification-item").forEach(item => {
            item.style.borderLeft = "";
            item.style.fontWeight = "";
            item.style.backgroundColor = "";
            const title = item.querySelector(".fw-semibold");
            if (title) {
              title.classList.remove("text-dark");
              title.classList.add("text-secondary");
            }
          });
          
          markAllReadBtn.remove();
        }
      })
      .catch(err => console.error("Error marking all read:", err));
    });
  }

  // 4. Click individual notification item to mark as read
  document.querySelectorAll(".notification-item a").forEach(link => {
    link.addEventListener("click", function (e) {
      const id = this.getAttribute("data-id");
      const href = this.getAttribute("href");
      if (!id || id === "0" || href === "#") return;

      e.preventDefault();

      const formData = new FormData();
      formData.append("action", "mark_read");
      formData.append("id", id);

      fetch(`${basePath}api/notifications_api.php`, {
        method: "POST",
        body: formData
      })
      .then(res => res.json())
      .then(() => {
        window.location.href = href;
      })
      .catch(err => {
        console.error("Error marking notification read:", err);
        window.location.href = href;
      });
    });
  });
});

// Global Scroll Position Preservation System
(function () {
  const currentPath = window.location.pathname + window.location.search;
  const storageKey = 'sys_scroll_pos_' + currentPath;

  window.addEventListener('beforeunload', function () {
    try {
      sessionStorage.setItem(storageKey, JSON.stringify({
        x: window.scrollX || window.pageXOffset || 0,
        y: window.scrollY || window.pageYOffset || 0
      }));
    } catch (e) {}
  });

  document.addEventListener('DOMContentLoaded', function () {
    try {
      const saved = sessionStorage.getItem(storageKey);
      if (saved) {
        const pos = JSON.parse(saved);
        if (pos && typeof pos.y === 'number') {
          window.scrollTo(pos.x || 0, pos.y || 0);
        }
        sessionStorage.removeItem(storageKey);
      }
    } catch (e) {}
  });

  // Global helper for safe DOM updates with scroll & cursor preservation
  window.updateContainerWithScrollPreservation = function (container, newHtml) {
    if (!container) return;

    const activeEl = document.activeElement;
    const isEditing = activeEl && container.contains(activeEl) &&
      (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT');

    if (container.innerHTML.trim() === newHtml.trim()) return;

    const winX = window.scrollX || window.pageXOffset || 0;
    const winY = window.scrollY || window.pageYOffset || 0;
    const containerX = container.scrollLeft;
    const containerY = container.scrollTop;

    let activeId = null, start = null, end = null;
    if (isEditing && activeEl.id) {
      activeId = activeEl.id;
      try {
        start = activeEl.selectionStart;
        end = activeEl.selectionEnd;
      } catch (e) {}
    }

    container.innerHTML = newHtml;

    container.scrollLeft = containerX;
    container.scrollTop = containerY;
    window.scrollTo(winX, winY);

    if (activeId) {
      const restored = document.getElementById(activeId);
      if (restored) {
        restored.focus();
        try {
          if (start !== null && end !== null) {
            restored.setSelectionRange(start, end);
          }
        } catch (e) {}
      }
    }
  };
})();

window.showOutstandingDetails = function(patientId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    // Check if modal already exists in DOM
    let modalEl = document.getElementById('globalOutstandingModal');
    if (!modalEl) {
        modalEl = document.createElement('div');
        modalEl.id = 'globalOutstandingModal';
        modalEl.className = 'modal fade';
        modalEl.setAttribute('tabindex', '-1');
        modalEl.innerHTML = `
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content" style="border-radius: 12px; overflow: hidden; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                    <div class="modal-header bg-warning text-dark py-3">
                        <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Outstanding Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div id="outstanding_modal_loading" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="text-muted mt-3 mb-0">Loading outstanding bills...</p>
                        </div>
                        <div id="outstanding_modal_content" style="display: none;">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Receipt No.</th>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th class="text-end">Total (₹)</th>
                                            <th class="text-end">Paid (₹)</th>
                                            <th class="text-end">Outstanding (₹)</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="outstanding_modal_table_body"></tbody>
                                    <tfoot>
                                        <tr class="table-danger fw-bold fs-6">
                                            <td colspan="5" class="text-end py-3">Total Outstanding:</td>
                                            <td class="text-end text-danger py-3" id="outstanding_modal_total">₹0.00</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer py-3">
                        <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal" style="border-radius: 8px;">Close</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modalEl);
    }
    
    // Show modal loading state
    const loadingEl = document.getElementById('outstanding_modal_loading');
    const contentEl = document.getElementById('outstanding_modal_content');
    loadingEl.style.display = 'block';
    contentEl.style.display = 'none';
    
    const myModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    myModal.show();
    
    // Fetch details
    fetch((window.BASE_URL || '') + 'api/billing_api.php?patient_id=' + patientId + '&outstanding_only=1')
        .then(res => res.json())
        .then(res => {
            loadingEl.style.display = 'none';
            contentEl.style.display = 'block';
            
            const tbody = document.getElementById('outstanding_modal_table_body');
            tbody.innerHTML = '';
            
            if (!res.success || !res.data || res.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-check-circle-fill text-success fs-3 mb-2 d-block"></i>No outstanding balance. All bills cleared.</td></tr>';
                document.getElementById('outstanding_modal_total').textContent = '₹0.00';
                return;
            }
            
            let totalOutstanding = 0;
            res.data.forEach(b => {
                const total = parseFloat(b.total_amount) || 0;
                const paid = parseFloat(b.paid_amount) || 0;
                const balance = parseFloat(b.balance_amount) || 0;
                totalOutstanding += balance;
                
                const tr = document.createElement('tr');
                const formattedDate = new Date(b.bill_date).toLocaleDateString('en-IN', {
                    day: '2-digit', month: 'short', year: 'numeric'
                });
                
                tr.innerHTML = `
                    <td class="fw-bold font-monospace text-primary">${b.receipt_no || ('#' + b.bill_id)}</td>
                    <td>${formattedDate}</td>
                    <td><span class="badge bg-light text-dark border px-2 py-1">${b.bill_type || 'General'}</span></td>
                    <td class="text-end fw-semibold">₹${total.toFixed(2)}</td>
                    <td class="text-end text-success">₹${paid.toFixed(2)}</td>
                    <td class="text-end text-danger fw-bold">₹${balance.toFixed(2)}</td>
                    <td class="text-center">
                        <a href="${(window.BASE_URL || '')}modules/billing/collect_payment.php?bill_id=${b.bill_id}" class="btn btn-xs btn-success py-1 px-2 fw-bold me-1">
                            <i class="bi bi-cash-coin me-1"></i>Collect
                        </a>
                        <a href="${(window.BASE_URL || '')}modules/opd/print_opd_bill.php?id=${b.bill_id}" target="_blank" class="btn btn-xs btn-outline-primary py-1 px-2 font-semibold">
                            <i class="bi bi-printer me-1"></i>Print
                        </a>
                    </td>
                `;
                tbody.appendChild(tr);
            });
            
            document.getElementById('outstanding_modal_total').textContent = '₹' + totalOutstanding.toLocaleString('en-IN', {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            });
        })
        .catch(err => {
            console.error(err);
            loadingEl.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Error loading outstanding bill details. Please retry.</div>';
        });
};

window.showBillPaymentHistory = function(billId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    if (!billId) return;

    let modalEl = document.getElementById('globalPaymentHistoryModal');
    if (!modalEl) {
        modalEl = document.createElement('div');
        modalEl.id = 'globalPaymentHistoryModal';
        modalEl.className = 'modal fade';
        modalEl.setAttribute('tabindex', '-1');
        modalEl.innerHTML = `
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content" style="border-radius:12px; border:none; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                    <div class="modal-header bg-light" style="border-bottom:1px solid #eee; border-top-left-radius:12px; border-top-right-radius:12px;">
                        <h5 class="modal-title fw-bold text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Invoice Lifecycle & Payment Ledger</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <!-- Loading State -->
                        <div id="history_modal_loading" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2 mb-0">Retrieving payment ledger...</p>
                        </div>
                        
                        <!-- Main Content -->
                        <div id="history_modal_content" style="display:none;">
                            <div class="row g-4">
                                <!-- LEFT COLUMN: FINANCIAL TIMELINE -->
                                <div class="col-lg-5 border-end">
                                    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-bar-chart-steps me-2 text-primary"></i>Financial Timeline</h6>
                                    <div id="history_modal_timeline" style="max-height: 420px; overflow-y: auto; padding-right: 5px;">
                                        <!-- Dynamic timeline goes here -->
                                    </div>
                                </div>
                                
                                <!-- RIGHT COLUMN: SUMMARY & LEDGER -->
                                <div class="col-lg-7">
                                    <!-- Summary info -->
                                    <div class="card bg-light border-0 p-3 mb-3" style="border-radius:8px;">
                                        <div class="row g-2 text-center">
                                            <div class="col-4">
                                                <small class="text-muted d-block uppercase font-semibold" style="font-size:0.7rem;">BILL AMOUNT</small>
                                                <span class="fw-bold text-dark fs-6" id="hist_bill_amount">₹0.00</span>
                                            </div>
                                            <div class="col-4 border-start border-end">
                                                <small class="text-muted d-block uppercase font-semibold" style="font-size:0.7rem;">DISCOUNT</small>
                                                <span class="fw-bold text-danger fs-6" id="hist_discount">₹0.00</span>
                                            </div>
                                            <div class="col-4">
                                                <small class="text-muted d-block uppercase font-semibold" style="font-size:0.7rem;">NET BILL</small>
                                                <span class="fw-bold text-primary fs-6" id="hist_net_bill">₹0.00</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Payment List -->
                                    <h6 class="fw-bold text-dark mb-2"><i class="bi bi-journal-text me-1 text-secondary"></i>Collected Receipts</h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-3" style="font-size:12.5px;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Receipt No</th>
                                                    <th>Mode</th>
                                                    <th class="text-end">Collected</th>
                                                    <th class="text-end">Remaining Due</th>
                                                    <th>Collected By</th>
                                                </tr>
                                            </thead>
                                            <tbody id="history_modal_table_body">
                                                <!-- Dynamic payment rows go here -->
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <!-- Final Outstanding badge -->
                                    <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-3">
                                        <span class="text-muted small">* All amounts shown in INR (₹). Read-only.</span>
                                        <h5 class="fw-bold text-danger mb-0">Outstanding: <span id="history_modal_outstanding">₹0.00</span></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modalEl);
    }

    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    const loadingEl = document.getElementById('history_modal_loading');
    const contentEl = document.getElementById('history_modal_content');
    
    loadingEl.style.display = 'block';
    contentEl.style.display = 'none';

    fetch((window.BASE_URL || '') + 'api/billing_api.php?bill_id=' + billId)
        .then(res => res.json())
        .then(res => {
            if (!res.success || !res.bill) {
                loadingEl.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Invoice details could not be loaded.</div>';
                return;
            }
            
            loadingEl.style.display = 'none';
            contentEl.style.display = 'block';

            // Set Header and top card info
            const discount = parseFloat(res.bill.discount_amount) || 0;
            const tax = parseFloat(res.bill.tax_amount) || 0;
            const net = parseFloat(res.bill.total_amount) || 0;
            const gross = net + discount - tax;
            
            document.getElementById('hist_bill_amount').textContent = '₹' + gross.toLocaleString('en-IN', { minimumFractionDigits: 2 });
            document.getElementById('hist_discount').textContent = '₹' + discount.toLocaleString('en-IN', { minimumFractionDigits: 2 });
            document.getElementById('hist_net_bill').textContent = '₹' + net.toLocaleString('en-IN', { minimumFractionDigits: 2 });

            const tbody = document.getElementById('history_modal_table_body');
            tbody.innerHTML = '';

            // Group payments by date within 2 seconds of each other
            const grouped = [];
            const rawPayments = res.payments || [];
            rawPayments.forEach(p => {
                const time = new Date(p.payment_date).getTime();
                let match = grouped.find(g => Math.abs(new Date(g.payment_date).getTime() - time) <= 2000);
                if (match) {
                    match.amount = parseFloat(match.amount) + parseFloat(p.amount);
                    match.modes.push(`${p.payment_mode} (₹${parseFloat(p.amount).toLocaleString('en-IN')})`);
                } else {
                    grouped.push({
                        payment_id: p.payment_id,
                        payment_date: p.payment_date,
                        amount: parseFloat(p.amount),
                        modes: [p.payment_mode],
                        received_by_name: p.received_by_name || 'Cashier',
                        receipt_no: 'REC-' + String(p.payment_id).padStart(6, '0')
                    });
                }
            });

            // 1. Initial Bill Generation event in Financial Timeline
            const formattedBillDate = new Date(res.bill.bill_date).toLocaleDateString('en-IN', {
                day: '2-digit', month: 'short', year: 'numeric'
            });
            const formattedBillTime = new Date(res.bill.bill_date).toLocaleTimeString('en-IN', {
                hour: '2-digit', minute: '2-digit'
            });
            
            let timelineHtml = `
                <div class="timeline-container ps-3" style="position: relative; border-left: 2px dashed #0d6efd; margin-left: 15px; padding-bottom: 5px;">
                    <!-- Step 1: Bill Generated -->
                    <div class="timeline-item mb-4" style="position: relative; padding-left: 15px;">
                        <div class="timeline-marker bg-primary" style="position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; box-shadow: 0 0 0 3px rgba(13,110,253,0.25);"></div>
                        <div class="fw-bold text-dark" style="font-size:0.85rem;">Bill Generated</div>
                        <div class="text-muted" style="font-size:0.75rem; line-height: 1.5; margin-top: 3px;">
                            <div><i class="bi bi-calendar-event me-1 text-primary"></i><strong>Date:</strong> ${formattedBillDate} ${formattedBillTime}</div>
                            <div><i class="bi bi-receipt me-1 text-primary"></i><strong>Receipt No:</strong> ${res.bill.receipt_no || ('#' + res.bill.bill_id)}</div>
                            <div><i class="bi bi-wallet2 me-1 text-primary"></i><strong>Net Bill:</strong> ₹${net.toLocaleString('en-IN', { minimumFractionDigits: 2 })}</div>
                            <div><i class="bi bi-info-circle me-1 text-primary"></i><strong>Payment Mode:</strong> ${res.bill.payment_mode || '—'}</div>
                        </div>
                    </div>
            `;

            let running_balance = net;
            if (grouped.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No payments recorded for this invoice yet.</td></tr>';
            } else {
                grouped.forEach((g, index) => {
                    running_balance = Math.max(0, running_balance - g.amount);
                    const tr = document.createElement('tr');
                    const formattedDate = new Date(g.payment_date).toLocaleDateString('en-IN', {
                        day: '2-digit', month: 'short', year: 'numeric'
                    });
                    const formattedPayTime = new Date(g.payment_date).toLocaleTimeString('en-IN', {
                        hour: '2-digit', minute: '2-digit'
                    });
                    
                    const printUrl = (window.BASE_URL || '') + 'modules/billing/print_receipt.php?id=' + g.payment_id;
                    const modeDisplay = g.modes.length > 1 ? `Split (${g.modes.join(' | ')})` : g.modes[0];

                    tr.innerHTML = `
                        <td>${formattedDate}</td>
                        <td class="font-monospace fw-semibold"><a href="${printUrl}" target="_blank" class="text-decoration-underline" title="View receipt slip">${g.receipt_no}</a></td>
                        <td><span class="badge bg-light text-dark border px-2 py-0.5">${modeDisplay}</span></td>
                        <td class="text-end fw-semibold">₹${g.amount.toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                        <td class="text-end text-danger fw-semibold">₹${running_balance.toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                        <td>${g.received_by_name}</td>
                    `;
                    tbody.appendChild(tr);

                    // Add Intermediate Collection step in Financial Timeline
                    let stepTitle = "Outstanding Collection";
                    if (index === 0) {
                        const billDateStr = new Date(res.bill.bill_date).toDateString();
                        const payDateStr = new Date(g.payment_date).toDateString();
                        if (billDateStr === payDateStr) {
                            stepTitle = `${modeDisplay} Received`;
                        } else {
                            stepTitle = `Outstanding Collection (${modeDisplay})`;
                        }
                    } else {
                        stepTitle = `Outstanding Collection (${modeDisplay})`;
                    }

                    timelineHtml += `
                        <div class="text-muted my-2" style="margin-left: -19px;"><i class="bi bi-arrow-down-short fs-6"></i></div>
                        <div class="timeline-item mb-4" style="position: relative; padding-left: 15px;">
                            <div class="timeline-marker bg-warning" style="position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; box-shadow: 0 0 0 3px rgba(255,193,7,0.25);"></div>
                            <div class="fw-bold text-dark" style="font-size:0.85rem;">${stepTitle}</div>
                            <div class="text-muted" style="font-size:0.75rem; line-height: 1.5; margin-top: 3px;">
                                <div><i class="bi bi-calendar-event me-1 text-warning"></i><strong>Date:</strong> ${formattedDate} ${formattedPayTime}</div>
                                <div><i class="bi bi-receipt me-1 text-warning"></i><strong>Receipt:</strong> ${g.receipt_no}</div>
                                <div><i class="bi bi-cash-coin me-1 text-warning"></i><strong>Collected Amount:</strong> ₹${g.amount.toLocaleString('en-IN', { minimumFractionDigits: 2 })}</div>
                                <div><i class="bi bi-exclamation-circle me-1 text-warning"></i><strong>Remaining Outstanding:</strong> ₹${running_balance.toLocaleString('en-IN', { minimumFractionDigits: 2 })}</div>
                            </div>
                        </div>
                    `;
                });
            }

            // 3. Final Bill Closed event in Financial Timeline (if outstanding balance is settled)
            if (running_balance <= 0.01) {
                const finalPayDate = grouped.length > 0 ? new Date(grouped[grouped.length - 1].payment_date).toLocaleDateString('en-IN', {
                    day: '2-digit', month: 'short', year: 'numeric'
                }) : formattedBillDate;
                
                timelineHtml += `
                    <div class="text-muted my-2" style="margin-left: -19px;"><i class="bi bi-arrow-down-short fs-6"></i></div>
                    <div class="timeline-item" style="position: relative; padding-left: 15px;">
                        <div class="timeline-marker bg-success" style="position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; box-shadow: 0 0 0 3px rgba(25,135,84,0.25);"></div>
                        <div class="fw-bold text-success" style="font-size:0.85rem;"><i class="bi bi-check-circle-fill me-1"></i>Bill Closed</div>
                        <div class="text-muted" style="font-size:0.75rem; margin-top: 3px;">
                            All dues settled on ${finalPayDate}.<br>Outstanding: <strong>₹0.00</strong>
                        </div>
                    </div>
                `;
            }
            
            timelineHtml += `</div>`;
            document.getElementById('history_modal_timeline').innerHTML = timelineHtml;

            document.getElementById('history_modal_outstanding').textContent = '₹' + (parseFloat(res.bill.balance_amount) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
        })
        .catch(err => {
            console.error(err);
            loadingEl.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Network error loading payment details.</div>';
        });
};
