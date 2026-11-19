    </main>
<?php if (current_user()): ?>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php // cache-busting: ?v=<mtime> supaya perubahan assets/app.js langsung terpakai ?>
<script src="<?= url('assets/app.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/app.js') ?: '1' ?>"></script>
</body>
</html>
