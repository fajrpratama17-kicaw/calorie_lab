<?php if (!empty($sudah_login)): ?>
  </main>
  </div>
<?php else: ?>
  </main>
<?php endif; ?>

<footer>
  <p>© <?= date('Y') ?> Calorie Lab · Informasi bersifat edukatif, bukan pengganti saran medis.</p>
</footer>

<script>
// Nonaktifkan pengubahan angka akibat scroll wheel mouse pada seluruh input number di aplikasi
document.addEventListener('wheel', function(e) {
  if (document.activeElement && document.activeElement.type === 'number') {
    document.activeElement.blur();
  }
}, { passive: true });
</script>

</body>
</html>
