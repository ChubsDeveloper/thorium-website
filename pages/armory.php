<?php
/**
 * pages/armory.php
 * Page template - renders the armory page
 */
declare(strict_types=1);

// pages/armory.php
declare(strict_types=1);

require_once __DIR__ . '/../app/modules_view.php';

if (!module_is_enabled('armory')) {
  http_response_code(404);
  require themed_page_path('404');
  return;
}

module_render('armory');
