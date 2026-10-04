</main>

<footer class="bg-light border-top py-3 mt-4 d-none d-md-block">
    <div class="container text-center text-muted small">
        Krishi Sathi Research System &bull; Phase 1.8 &bull; Mobile-First Field Research
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- ═══ Responsive Table Helper (adds data-label + card view on mobile) ═══ -->
<script>
(function() {
    'use strict';
    if (window.innerWidth >= 768) return;

    var wrappers = document.querySelectorAll('.table-responsive');
    wrappers.forEach(function(w) {
        w.classList.add('table-card-view');
    });

    var tables = document.querySelectorAll('.table-responsive table');
    tables.forEach(function(table) {
        var thead = table.querySelector('thead');
        var tbody = table.querySelector('tbody');
        if (!thead || !tbody) return;

        var headers = [];
        thead.querySelectorAll('th').forEach(function(th) {
            var label = th.textContent.trim();
            if (label) headers.push(label);
            else headers.push('');
        });

        if (headers.length === 0) return;

        tbody.querySelectorAll('tr').forEach(function(row) {
            row.querySelectorAll('td').forEach(function(td, index) {
                if (headers[index]) {
                    td.setAttribute('data-label', headers[index]);
                }
            });
        });
    });
})();
</script>
</body>
</html>
