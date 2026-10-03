<?php // includes/footer.php - Pharmacy System Layout Footer ?>
</main>
<footer class="bg-white border-top py-2.5 px-4 text-center text-muted small d-flex justify-content-center align-items-center flex-wrap gap-2" style="font-size: 0.76rem;">
    <div>
        <span>Developed &amp; Maintained by <a href="https://www.wtsindia.co.in/" target="_blank" rel="noopener noreferrer" class="text-decoration-none fw-semibold" style="color: var(--info);">WTS</a> &copy; 2026 All rights reserved.</span>
    </div>
</footer>
</div><!-- /.main-container -->
</div><!-- /.app-wrapper -->

<!-- Global Clinical Toast Notification Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;" id="pharmacyToastContainer"></div>

<!-- Standardized Clinical Confirmation Modal -->
<div class="modal fade" id="pharmacyConfirmModal" tabindex="-1" aria-labelledby="pharmacyConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom px-3 py-2.5 bg-light-subtle">
                <div class="d-flex align-items-center gap-2">
                    <div id="confirmModalIconWrap" class="d-inline-flex align-items-center justify-content-center rounded bg-danger-subtle text-danger" style="width: 32px; height: 32px;">
                        <i id="confirmModalIcon" class="ti ti-alert-triangle fs-5"></i>
                    </div>
                    <h6 class="modal-title fw-bold text-dark mb-0" id="pharmacyConfirmModalLabel">Confirm Action</h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-3 py-3">
                <div class="fw-semibold text-dark mb-1" id="confirmModalTitle">Are you sure?</div>
                <div class="text-muted small" id="confirmModalMessage">This action cannot be undone.</div>
            </div>
            <div class="modal-footer border-top px-3 py-2 bg-light-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-sm btn-secondary rounded px-3" data-bs-dismiss="modal" id="confirmModalCancelBtn">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger rounded px-3 fw-semibold" id="confirmModalActionBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/app.js"></script>
<script src="<?= BASE_URL ?>assets/js/searchable-select.js"></script>
<script src="<?= BASE_URL ?>assets/js/keyboard-navigation.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Sidebar Group Accordion Toggle
    const groupHeaders = document.querySelectorAll('.sidebar-group-header');
    groupHeaders.forEach(header => {
        header.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = header.getAttribute('data-group');
            if (!targetId) return;
            const submenu = document.getElementById(targetId);
            if (!submenu) return;

            const isExpanded = header.getAttribute('aria-expanded') === 'true';
            
            if (isExpanded) {
                header.setAttribute('aria-expanded', 'false');
                header.classList.remove('open');
                submenu.classList.remove('show');
            } else {
                header.setAttribute('aria-expanded', 'true');
                header.classList.add('open');
                submenu.classList.add('show');
            }
        });
    });

    // 2. Mobile & Tablet Drawer Toggling
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const closeBtn = document.getElementById('sidebarCloseBtn');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('show');
        if (backdrop) backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('show');
        if (backdrop) backdrop.classList.remove('show');
        document.body.style.overflow = '';
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    // Auto-close drawer when clicking a link on mobile screens (< 992px)
    if (sidebar) {
        sidebar.querySelectorAll('.sidebar-submenu .nav-link, a.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 992) {
                    closeSidebar();
                }
            });
        });
    }
});
</script>
</body>
</html>
