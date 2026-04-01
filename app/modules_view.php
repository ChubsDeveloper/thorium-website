<?php
/**
 * app/modules_view.php
 * Module rendering system - handles module display and theme overrides
 */
declare(strict_types=1);

// app/modules_view.php - FIXED to use new clean system
declare(strict_types=1);

if (!defined('APP_ROOT')) define('APP_ROOT', __DIR__);

// require_once APP_ROOT . '/modules_repo.php'; // REMOVED

if (!function_exists('module_enabled')) {
    /** Manage module functionality. */
  function module_enabled(string $name): bool {
    // Try to use new clean system if available
    if (function_exists('clean_module_enabled')) {
      return clean_module_enabled($name);
    }
    
    // Fallback: direct database query (last resort)
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
      try {
        $name = preg_replace('/[^a-z0-9\-_]/i', '', $name);
        if (empty($name)) return false;
        
        $stmt = $GLOBALS['pdo']->prepare("SELECT enabled FROM modules WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $enabled = $stmt->fetchColumn();
        return (bool)$enabled;
      } catch (Exception $e) {
        return false;
      }
    }
    
    return false;
  }
}


/** 
 * Check if module is enabled (using new system)
 */
    /** Manage module functionality. */
function module_is_enabled(string $name): bool {
  return module_enabled($name);
}

/**
 * Render module view with fallback system
 */
    /** Manage module functionality. */
function module_render(string $name, array $vars = []): void {
  if (!module_is_enabled($name)) {
    return;
  }

  // Try to use new theme manager if available
  if (function_exists('get_clean_theme_manager')) {
    try {
      $theme_manager = get_clean_theme_manager();
      $theme_path = $theme_manager->getThemePath("partials/modules/{$name}.php");
      
      if ($vars) extract($vars, EXTR_SKIP);
      
      if (is_file($theme_path)) {
        require $theme_path;
        return;
      }
    } catch (Exception $e) {
      // Fall through to legacy method
    }
  }

  // Legacy method using existing themed_partial_path function
  if (function_exists('themed_partial_path')) {
    $theme_path = themed_partial_path('modules/' . $name);
    $app_path   = APP_ROOT . '/partials/modules/' . $name . '.php';

    if ($vars) extract($vars, EXTR_SKIP);

    if (is_file($theme_path)) { 
      require $theme_path; 
      return; 
    }
    if (is_file($app_path)) { 
      require $app_path; 
      return; 
    }
  }

  // Final fallback - direct file check
  $app_path = APP_ROOT . '/partials/modules/' . $name . '.php';
  if ($vars) extract($vars, EXTR_SKIP);
  
  if (is_file($app_path)) {
    require $app_path;
    return;
  }

  if (defined('DEBUG') && DEBUG) {
    echo '<!-- module ' . e($name) . ' not found -->';
  }
}
