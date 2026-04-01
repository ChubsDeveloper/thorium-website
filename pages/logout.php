<?php
/**
 * pages/logout.php
 * Page template - renders the logout page
 */

// pages/logout.php
// Page template - renders the logout page

require_once __DIR__ . '/../app/auth.php';
auth_logout();
redirect(base_url(''));
