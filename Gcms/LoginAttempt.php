<?php
/**
 * @filesource Gcms/LoginAttempt.php
 *
 * Login Attempt / Brute-force protection helper (Phase 5)
 * Uses gcms_login_attempt table
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms;

class LoginAttempt extends \Kotchasan\KBase
{
    /**
     * Odds of sweeping aged-out attempts, per failed attempt recorded (1 in N).
     *
     * cleanup() had no caller anywhere in the codebase, so this table only ever
     * grew: purgeStale() removes rows for the username/IP being checked right
     * then, but a bot that tries one password and never returns leaves its row
     * behind for good. Sweeping here ties the cleanup rate to the thing that
     * creates the rows — the busier the table gets, the more often it is swept —
     * and needs no cron, which many shared hosts do not provide.
     *
     * Set to 0 to disable and drive cleanup() from a scheduled task instead.
     */
    const GC_DIVISOR = 200;

    /**
     * Normalize a username for attempt-counting so trivial case/whitespace
     * variants ("User", "user ", " USER") cannot each get their own counter
     * and thereby bypass the per-username lockout.
     *
     * @param string $username
     * @return string
     */
    private static function normalizeUsername($username)
    {
        return mb_strtolower(trim((string) $username));
    }

    /**
     * Normalize loopback addresses so browser (::1) and server (127.0.0.1)
     * share the same brute-force counter on local/dev environments.
     *
     * @param string $ip
     *
     * @return string
     */
    private static function normalizeIp($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '::1' || $ip === '127.0.0.1' || $ip === 'localhost') {
            return '127.0.0.1';
        }

        return $ip;
    }

    /**
     * Sliding-window cutoff for lockout counting.
     *
     * @return string
     */
    private static function lockoutSince()
    {
        return date('Y-m-d H:i:s', time() - ((int) self::$cfg->lockout_duration * 60));
    }

    /**
     * Lockout duration in seconds.
     *
     * @return int
     */
    private static function lockoutSeconds()
    {
        return (int) self::$cfg->lockout_duration * 60;
    }

    /**
     * Remove failed-attempt rows that have aged out of the active lockout window.
     * Called lazily on login checks so expired history does not accumulate and
     * cannot contribute to a future lockout once the window has passed.
     *
     * @param string $username
     * @param string $ip
     *
     * @return void
     */
    private static function purgeStale($username, $ip)
    {
        try {
            $since = self::lockoutSince();
            $db = \Kotchasan\DB::create();

            $normalized = self::normalizeUsername($username);
            if ($normalized !== '') {
                $db->delete('login_attempt', [
                    ['username', $normalized],
                    ['attempted_at', '<', $since]
                ], 0);
            }

            $ip = self::normalizeIp($ip);
            if ($ip !== '') {
                $db->delete('login_attempt', [
                    ['ip_address', $ip],
                    ['attempted_at', '<', $since]
                ], 0);
            }
        } catch (\Exception $e) {
            error_log('LoginAttempt::purgeStale failed: '.$e->getMessage());
        }
    }

    /**
     * Count recent failed attempts for a single scope (IP or username).
     *
     * @param string $field   Column name: ip_address or username
     * @param string $value   Value to match
     *
     * @return int
     */
    private static function countRecentAttempts($field, $value)
    {
        if ($value === '') {
            return 0;
        }

        $row = \Kotchasan\Model::createQuery()
            ->selectCount()
            ->from('login_attempt')
            ->where([
                [$field, $value],
                ['attempted_at', '>=', self::lockoutSince()]
            ])
            ->first();

        return $row ? (int) $row->count : 0;
    }

    /**
     * Remaining lockout seconds for one scope using sliding-window math.
     * Returns 0 when the scope is below the attempt threshold.
     *
     * @param string $field
     * @param string $value
     *
     * @return int
     */
    private static function remainingForScope($field, $value)
    {
        if ($value === '') {
            return 0;
        }

        $max = (int) self::$cfg->max_login_attempts;
        $count = self::countRecentAttempts($field, $value);
        if ($count < $max) {
            return 0;
        }

        // The oldest attempt that must leave the window before count drops below max.
        $critical = \Kotchasan\Model::createQuery()
            ->select('attempted_at')
            ->from('login_attempt')
            ->where([
                [$field, $value],
                ['attempted_at', '>=', self::lockoutSince()]
            ])
            ->orderBy('attempted_at', 'ASC')
            ->limit(1, $count - $max)
            ->first();

        if (!$critical) {
            return 0;
        }

        $unlockAt = strtotime($critical->attempted_at) + self::lockoutSeconds();

        return max(0, $unlockAt - time());
    }

    /**
     * Drop in-window attempt rows once the computed lockout has expired so the
     * next login check does not stay blocked with remaining=0.
     *
     * @param string $username
     * @param string $ip
     *
     * @return void
     */
    private static function releaseExpiredLockout($username, $ip)
    {
        try {
            $db = \Kotchasan\DB::create();
            $max = (int) self::$cfg->max_login_attempts;
            $ip = self::normalizeIp($ip);
            $normalized = self::normalizeUsername($username);

            if ($normalized !== '' && self::countRecentAttempts('username', $normalized) >= $max) {
                $db->delete('login_attempt', [['username', $normalized]], 0);
            }

            if ($ip !== '' && self::countRecentAttempts('ip_address', $ip) >= $max) {
                $db->delete('login_attempt', [['ip_address', $ip]], 0);
            }
        } catch (\Exception $e) {
            error_log('LoginAttempt::releaseExpiredLockout failed: '.$e->getMessage());
        }
    }

    /**
     * Record a failed login attempt
     *
     * @param string $username  Email or username attempted
     * @param string $ip        Client IP address
     * @param string $userAgent Browser user agent
     *
     * @return void
     */
    public static function record($username, $ip, $userAgent = '')
    {
        try {
            $ip = self::normalizeIp($ip);
            if ($ip === '' && self::normalizeUsername($username) === '') {
                return;
            }

            self::purgeStale($username, $ip);

            $db = \Kotchasan\DB::create();
            $db->insert('login_attempt', [
                'username' => self::normalizeUsername($username),
                'ip_address' => $ip,
                'user_agent' => mb_substr($userAgent, 0, 500),
                'attempted_at' => date('Y-m-d H:i:s')
            ]);

            self::collectStaleAttempts();
        } catch (\Exception $e) {
            // Don't break the login flow, but make the failure visible so a
            // broken table can't silently disable brute-force protection.
            error_log('LoginAttempt::record failed: '.$e->getMessage());
        }
    }

    /**
     * Check if IP or username is locked out
     *
     * @param string $username  Email or username
     * @param string $ip        Client IP address
     *
     * @return bool True if locked out
     */
    public static function isLocked($username, $ip)
    {
        try {
            $ip = self::normalizeIp($ip);
            self::purgeStale($username, $ip);

            $remaining = self::getRemainingLockTime($username, $ip);
            if ($remaining > 0) {
                return true;
            }

            self::releaseExpiredLockout($username, $ip);

            return false;
        } catch (\Exception $e) {
            // Fail CLOSED: if the attempt store is unavailable we cannot rule out
            // an ongoing brute-force, so treat the account/IP as locked. This does
            // not add a DoS surface — authenticate() needs the same database to
            // verify credentials, so a DB outage already blocks all logins.
            error_log('LoginAttempt::isLocked failed (failing closed): '.$e->getMessage());
            return true;
        }
    }

    /**
     * Clear login attempts after successful login
     *
     * @param string $username Email or username
     * @param string $ip       Client IP address
     *
     * @return void
     */
    public static function clear($username, $ip)
    {
        try {
            $db = \Kotchasan\DB::create();
            $ip = self::normalizeIp($ip);

            $normalized = self::normalizeUsername($username);
            if ($normalized !== '') {
                // limit 0 = delete ALL matching rows. The DB::delete() default
                // limit is 1, which would leave stale failed attempts behind and
                // keep a just-authenticated user near the lockout threshold.
                $db->delete('login_attempt', [['username', $normalized]], 0);
            }

            // Successful auth from this IP should reset the IP counter too;
            // otherwise a user who eventually enters the right password remains
            // blocked until the sliding window expires.
            if ($ip !== '') {
                $db->delete('login_attempt', [['ip_address', $ip]], 0);
            }

            self::purgeStale($username, $ip);
        } catch (\Exception $e) {
            error_log('LoginAttempt::clear failed: '.$e->getMessage());
        }
    }

    /**
     * Get remaining lockout time in seconds
     *
     * @param string $username
     * @param string $ip
     *
     * @return int Seconds remaining (0 if not locked)
     */
    public static function getRemainingLockTime($username, $ip)
    {
        try {
            $ip = self::normalizeIp($ip);
            self::purgeStale($username, $ip);

            $normalized = self::normalizeUsername($username);
            $ipRemaining = self::remainingForScope('ip_address', $ip);
            $userRemaining = $normalized !== '' ? self::remainingForScope('username', $normalized) : 0;

            return max($ipRemaining, $userRemaining);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Sweep aged-out attempts at random, standing in for a cron job.
     *
     * Housekeeping only: a failure here must never affect the brute-force
     * counters or the login flow, and a missed round is picked up by a later
     * one anyway.
     *
     * @return void
     */
    private static function collectStaleAttempts()
    {
        if (self::GC_DIVISOR < 1) {
            return;
        }

        try {
            if (random_int(1, self::GC_DIVISOR) === 1) {
                self::cleanup();
            }
        } catch (\Throwable $e) {
            error_log('LoginAttempt::collectStaleAttempts failed: '.$e->getMessage());
        }
    }

    /**
     * Clean up old login attempts (called periodically)
     *
     * @param int $days Number of days to keep (default 30)
     *
     * @return void
     */
    public static function cleanup($days = 30)
    {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
            // Use the DB helper with limit 0 (delete ALL matching) — consistent
            // with clear(); the query-builder delete() signature differs.
            \Kotchasan\DB::create()->delete('login_attempt', [['attempted_at', '<', $cutoff]], 0);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
