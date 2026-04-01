<?php
/**
 * views/layouts/base.php
 * View template - renders UI components
 */

// Base HTML wrapper — head + body. Themes can override this file.
$title = $fullTitle ?? ($title ?? 'Thorium WoW');
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <?php partial('head', compact('title')) ?>
  <!-- Theme CSS goes last so it can override -->
  <link rel="stylesheet" href="<?= asset_url('theme.css') ?>">
  <script defer src="<?= asset_url('theme.js') ?>"></script>
</head>
<body class="min-h-dvh text-neutral-100 bg-app">
  <?php partial('nav') ?>
  <main><?= $content ?></main>
  <?php partial('footer') ?>
</body>
</html>
