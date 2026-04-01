
// Test theme layout - basic HTML structure for testing purposes
<!doctype html>
<html lang="en">
<head>
  <?php theme_include('partials/head'); ?>
</head>
<body>
  <?php theme_include('partials/header'); ?>

  <main>
    <?= $content ?? '' ?>
  </main>

  <?php theme_include('partials/footer'); ?>
</body>
</html>
