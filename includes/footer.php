<?php require_once __DIR__ . '/../functions.php'; ?>
    <footer class="footer">
        <div class="flex-between">
            <div>&copy; <?= e(date('Y')) ?> <?= e(app_config('app')['name'] ?? 'Finance Dashboard') ?>. All rights reserved.</div>
        </div>
    </footer>
</main><!-- /.main -->
</div><!-- /.app -->
<script src="<?= e(base_url('/ui/js/app.js')) ?>"></script>
</body>
</html>
