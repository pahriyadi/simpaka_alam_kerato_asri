<?php
declare(strict_types=1);
?>
<div class="aka-subbar no-print">
  <a href="<?= e(base_url('index.php')) ?>" class="btn btn-sm btn-aka-outline rounded-pill px-3">
    <i class="bi bi-arrow-left me-1"></i> Beranda
  </a>
  <span class="small fw-bold text-success text-truncate"><?= e($pageTitle ?? 'Admin') ?></span>
</div>
