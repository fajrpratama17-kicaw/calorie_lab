<?php if (!empty($sudah_login)): ?>
  </main>
  </div>
<?php else: ?>
  </main>
<?php endif; ?>

<footer>
  <p>&copy; <?= date('Y') ?> Calorie Lab &middot; Informasi bersifat edukatif, bukan pengganti saran medis.</p>
</footer>

<script>
// ── Sidebar collapsible: simpan & pulihkan state dari localStorage ──
function toggleSidebarSection(btn, listId) {
  var list = document.getElementById(listId);
  var expanded = btn.getAttribute('aria-expanded') === 'true';
  btn.setAttribute('aria-expanded', !expanded);
  list.classList.toggle('ditutup', expanded);
  try { localStorage.setItem('sidebar_' + listId, expanded ? 'tutup' : 'buka'); } catch(e) {}
}

// Pulihkan state saat halaman dimuat
(function() {
  var listId = 'main-menu-list';
  var btn = document.getElementById('btn-main-menu');
  var list = document.getElementById(listId);
  if (!btn || !list) return;
  try {
    var state = localStorage.getItem('sidebar_' + listId);
    if (state === 'tutup') {
      btn.setAttribute('aria-expanded', 'false');
      list.classList.add('ditutup');
    }
  } catch(e) {}
})();
</script>

</body>
</html>
