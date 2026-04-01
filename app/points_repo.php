<?php
/**
 * app/points_repo.php
 * Data repository - manages points data operations
 * Updated to support auth database vp/dp columns
 */
declare(strict_types=1);

/* -------------------- Legacy Points Functions -------------------- */

    /** Process and return array data. */
function points_get(?PDO $legacyPdo, array $config, int $accountId): array {
  if (!$legacyPdo || empty($config['points']['enabled'])) return ['vote'=>0, 'donation'=>0];
  $sql = $config['points']['get_sql'] ?? '';
  if (!$sql) return ['vote'=>0, 'donation'=>0];
  $st = $legacyPdo->prepare($sql);
  $st->execute([':id' => $accountId]);
  $row = $st->fetch();
  return [
    'vote'     => isset($row['vote'])     ? (int)$row['vote']     : 0,
    'donation' => isset($row['donation']) ? (int)$row['donation'] : 0,
  ];
}

/* -------------------- Auth Database Points (Primary) -------------------- */

/** Get points from auth.account table (primary source) */
    /** Process and return array data. */
function points_get_auth(PDO $authPdo, int $accountId): array {
  try {
    $st = $authPdo->prepare("SELECT vp, dp FROM account WHERE id = :id LIMIT 1");
    $st->execute([':id' => $accountId]);
    $row = $st->fetch();
    return [
      'vote' => (int)($row['vp'] ?? 0), 
      'donation' => (int)($row['dp'] ?? 0)
    ];
  } catch (Exception $e) {
    return ['vote' => 0, 'donation' => 0];
  }
}

/** Set points in auth.account table */
    /** Perform operation without return value. */
function points_set_auth(PDO $authPdo, int $accountId, int $vp, int $dp): bool {
  try {
    $st = $authPdo->prepare("UPDATE account SET vp = :vp, dp = :dp WHERE id = :id");
    return $st->execute([':vp' => $vp, ':dp' => $dp, ':id' => $accountId]);
  } catch (Exception $e) {
    return false;
  }
}

/** Increment points in auth.account table */
    /** Perform operation without return value. */
function points_inc_auth(PDO $authPdo, int $accountId, int $vpDelta, int $dpDelta): bool {
  try {
    $st = $authPdo->prepare("UPDATE account SET vp = vp + :v, dp = dp + :d WHERE id = :id");
    return $st->execute([':v' => $vpDelta, ':d' => $dpDelta, ':id' => $accountId]);
  } catch (Exception $e) {
    return false;
  }
}

/** Add vote points to auth.account */
    /** Check condition and return boolean result. */
function points_add_vote_auth(PDO $authPdo, int $accountId, int $points): bool {
  if ($points <= 0) return false;
  return points_inc_auth($authPdo, $accountId, $points, 0);
}

/** Add donation points to auth.account */
    /** Check condition and return boolean result. */
function points_add_donation_auth(PDO $authPdo, int $accountId, int $points): bool {
  if ($points <= 0) return false;
  return points_inc_auth($authPdo, $accountId, 0, $points);
}

/** Spend vote points from auth.account (with balance check) */
    /** Check condition and return boolean result. */
function points_spend_vote_auth(PDO $authPdo, int $accountId, int $points): bool {
  if ($points <= 0) return false;
  
  $authPdo->beginTransaction();
  try {
    // Check current balance
    $current = points_get_auth($authPdo, $accountId);
    if ($current['vote'] < $points) {
      $authPdo->rollBack();
      return false; // Insufficient points
    }
    
    // Deduct points
    $st = $authPdo->prepare("UPDATE account SET vp = vp - :points WHERE id = :id");
    $success = $st->execute([':points' => $points, ':id' => $accountId]);
    
    $authPdo->commit();
    return $success;
  } catch (Exception $e) {
    $authPdo->rollBack();
    return false;
  }
}

/** Spend donation points from auth.account (with balance check) */
    /** Check condition and return boolean result. */
function points_spend_donation_auth(PDO $authPdo, int $accountId, int $points): bool {
  if ($points <= 0) return false;
  
  $authPdo->beginTransaction();
  try {
    // Check current balance
    $current = points_get_auth($authPdo, $accountId);
    if ($current['donation'] < $points) {
      $authPdo->rollBack();
      return false; // Insufficient points
    }
    
    // Deduct points
    $st = $authPdo->prepare("UPDATE account SET dp = dp - :points WHERE id = :id");
    $success = $st->execute([':points' => $points, ':id' => $accountId]);
    
    $authPdo->commit();
    return $success;
  } catch (Exception $e) {
    $authPdo->rollBack();
    return false;
  }
}

/* -------------------- Site Database Points (Legacy/Fallback) -------------------- */

/** Get points from thorium_website.accounts table (fallback) */
    /** Process and return array data. */
function points_get_site(PDO $sitePdo, int $accountId): array {
  try {
    $st = $sitePdo->prepare("SELECT vp, dp FROM accounts WHERE id = :id LIMIT 1");
    $st->execute([':id' => $accountId]);
    $row = $st->fetch();
    return ['vote' => (int)($row['vp'] ?? 0), 'donation' => (int)($row['dp'] ?? 0)];
  } catch (Exception $e) {
    return ['vote' => 0, 'donation' => 0];
  }
}

/** Set points in thorium_website.accounts table (legacy) */
    /** Perform operation without return value. */
function points_set_site(PDO $sitePdo, int $accountId, int $vp, int $dp): void {
  $st = $sitePdo->prepare("UPDATE accounts SET vp = :vp, dp = :dp WHERE id = :id");
  $st->execute([':vp'=>$vp, ':dp'=>$dp, ':id'=>$accountId]);
}

/** Increment points in thorium_website.accounts table (legacy) */
    /** Perform operation without return value. */
function points_inc_site(PDO $sitePdo, int $accountId, int $vpDelta, int $dpDelta): void {
  $st = $sitePdo->prepare("UPDATE accounts SET vp = vp + :v, dp = dp + :d WHERE id = :id");
  $st->execute([':v'=>$vpDelta, ':d'=>$dpDelta, ':id'=>$accountId]);
}

/* -------------------- Smart Points Functions (Auto-detect source) -------------------- */

/** Get points from best available source (auth first, then site fallback) */
    /** Process and return array data. */
function points_get_smart(?PDO $authPdo, ?PDO $sitePdo, int $accountId): array {
  // Try auth database first (preferred)
  if ($authPdo) {
    $authPoints = points_get_auth($authPdo, $accountId);
    // If auth has points or no site fallback, use auth
    if ($authPoints['vote'] > 0 || $authPoints['donation'] > 0 || !$sitePdo) {
      return $authPoints;
    }
  }
  
  // Fallback to site database
  if ($sitePdo) {
    return points_get_site($sitePdo, $accountId);
  }
  
  return ['vote' => 0, 'donation' => 0];
}

/** Add vote points to best available database */
    /** Check condition and return boolean result. */
function points_add_vote_smart(?PDO $authPdo, ?PDO $sitePdo, int $accountId, int $points): bool {
  if ($authPdo) {
    return points_add_vote_auth($authPdo, $accountId, $points);
  } elseif ($sitePdo) {
    try {
      points_inc_site($sitePdo, $accountId, $points, 0);
      return true;
    } catch (Exception $e) {
      return false;
    }
  }
  return false;
}

/** Add donation points to best available database */
    /** Check condition and return boolean result. */
function points_add_donation_smart(?PDO $authPdo, ?PDO $sitePdo, int $accountId, int $points): bool {
  if ($authPdo) {
    return points_add_donation_auth($authPdo, $accountId, $points);
  } elseif ($sitePdo) {
    try {
      points_inc_site($sitePdo, $accountId, 0, $points);
      return true;
    } catch (Exception $e) {
      return false;
    }
  }
  return false;
}

/* -------------------- Migration & Sync Functions -------------------- */

/** Sync points from site database to auth database */
    /** Check condition and return boolean result. */
function points_sync_site_to_auth(PDO $sitePdo, PDO $authPdo, int $accountId): bool {
  try {
    $sitePoints = points_get_site($sitePdo, $accountId);
    $authPoints = points_get_auth($authPdo, $accountId);
    
    // Add site points to auth points (don't overwrite)
    $newVp = $authPoints['vote'] + $sitePoints['vote'];
    $newDp = $authPoints['donation'] + $sitePoints['donation'];
    
    return points_set_auth($authPdo, $accountId, $newVp, $newDp);
  } catch (Exception $e) {
    return false;
  }
}

/** Check if auth database has vp/dp columns */
    /** Check condition and return boolean result. */
function points_auth_has_columns(PDO $authPdo): bool {
  try {
    $authPdo->query("SELECT vp, dp FROM account LIMIT 1");
    return true;
  } catch (Exception $e) {
    return false;
  }
}

/** Check if site database has vp/dp columns */
    /** Check condition and return boolean result. */
function points_site_has_columns(PDO $sitePdo): bool {
  try {
    $sitePdo->query("SELECT vp, dp FROM accounts LIMIT 1");
    return true;
  } catch (Exception $e) {
    return false;
  }
}

/* -------------------- Wrapper Functions for Backward Compatibility -------------------- */

/** 
 * Main points getter - automatically uses auth database if available
 * This function maintains backward compatibility while preferring auth database
 */
    /** Process and return array data. */
function points_get_main(int $accountId): array {
  global $authPdo, $pdo;
  
  // Prefer auth database if available and has vp/dp columns
  if (isset($authPdo) && $authPdo && points_auth_has_columns($authPdo)) {
    return points_get_auth($authPdo, $accountId);
  }
  
  // Fallback to site database
  if (isset($pdo) && $pdo && points_site_has_columns($pdo)) {
    return points_get_site($pdo, $accountId);
  }
  
  return ['vote' => 0, 'donation' => 0];
}

/**
 * For use in templates - automatically detects best database
 * Updates global usage like: $balances = points_get_for_user($u['id']);
 */
    /** Process and return array data. */
function points_get_for_user(int $accountId): array {
  return points_get_main($accountId);
}

/* -------------------- Admin Functions -------------------- */

/** Transfer vote points between accounts (admin only) */
    /** Check condition and return boolean result. */
function points_transfer_vote(PDO $authPdo, int $fromAccountId, int $toAccountId, int $points): bool {
  if ($points <= 0) return false;
  
  $authPdo->beginTransaction();
  try {
    // Check sender balance
    $senderPoints = points_get_auth($authPdo, $fromAccountId);
    if ($senderPoints['vote'] < $points) {
      $authPdo->rollBack();
      return false;
    }
    
    // Deduct from sender
    $st = $authPdo->prepare("UPDATE account SET vp = vp - :points WHERE id = :id");
    $st->execute([':points' => $points, ':id' => $fromAccountId]);
    
    // Add to receiver
    $st = $authPdo->prepare("UPDATE account SET vp = vp + :points WHERE id = :id");
    $st->execute([':points' => $points, ':id' => $toAccountId]);
    
    $authPdo->commit();
    return true;
  } catch (Exception $e) {
    $authPdo->rollBack();
    return false;
  }
}

/** Get top vote point holders */
    /** Process and return array data. */
function points_get_top_voters(PDO $authPdo, int $limit = 10): array {
  try {
    $st = $authPdo->prepare("SELECT username, vp FROM account WHERE vp > 0 ORDER BY vp DESC LIMIT :limit");
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
  } catch (Exception $e) {
    return [];
  }
}

/** Get top donation point holders */
    /** Process and return array data. */
function points_get_top_donors(PDO $authPdo, int $limit = 10): array {
  try {
    $st = $authPdo->prepare("SELECT username, dp FROM account WHERE dp > 0 ORDER BY dp DESC LIMIT :limit");
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
  } catch (Exception $e) {
    return [];
  }
}