// Hospital Management ERP - Main JS

document.addEventListener('DOMContentLoaded', function () {
    // Sidebar toggle
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('mobile-open');
            } else {
                sidebar.classList.toggle('collapsed');
            }
        });
    }

    // Initialize DataTables
    document.querySelectorAll('.data-table').forEach(function (table) {
        new DataTable(table, {
            pageLength: 10,
            responsive: true,
            language: {
                search: "",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_"
            }
        });
    });

    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });

    // PWA: Mobile swipe gestures (simple left/right swipe on cards)
    if (window.innerWidth <= 768) {
        let touchStartX = 0;
        let touchEndX = 0;
        const swipeThreshold = 100;

        document.addEventListener('touchstart', e => {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });

        document.addEventListener('touchend', e => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });

        function handleSwipe() {
            const diff = touchStartX - touchEndX;
            if (Math.abs(diff) > swipeThreshold) {
                // Swipe left or right detected
                // Could trigger navigation or refresh - currently passive
            }
        }
    }

    // PWA: Install prompt
    let deferredPrompt;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        // Could show custom install UI here
    });
});

// Confirm delete
function confirmDelete(message) {
    return confirm(message || 'Are you sure you want to delete this record?');
}

// Format currency input
function formatCurrencyInput(input) {
    let value = parseFloat(input.value);
    if (!isNaN(value)) {
        input.value = value.toFixed(2);
    }
}
