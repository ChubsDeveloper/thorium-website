<?php
/**
 * themes/thorium-emeraldforest/layouts/base.php
 * Pairs with the “old” head.php that prints <!doctype html> and opens <body>.
 */

$title = $fullTitle ?? ($title ?? 'Thorium WoW');

// This prints <!doctype html>, <html>, <head>…</head>, and opens <body …>
theme_include('partials/head', compact('title', 'config'));

// Page chrome
theme_include('partials/header');
?>
<main>
  <?= $content ?? '' ?>
</main>
<?php theme_include('partials/footer'); ?>

</body>
</html>
