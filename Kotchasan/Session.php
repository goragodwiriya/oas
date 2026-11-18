<?php
namespace Kotchasan;

use Kotchasan\Database;

/**
 * Kotchasan Session Class
 *
 * This class handles session management using a database table.
 * It provides methods to open, close, read, write, destroy sessions,
 * and perform garbage collection on old sessions.
 *
 * Like PHP's file storage, a session is locked from _read() to _close(), so
 * requests of one visitor that run at the same time (several AJAX calls) take
 * turns instead of overwriting what the other one wrote: GET_LOCK on MySQL,
 * an advisory lock on PostgreSQL, sp_getapplock on SQL Server and a file lock
 * on SQLite (one machine only).
 *
 * @package Kotchasan
 */
class Session extends \Kotchasan\Model
{
    /**
     * Seconds _read() waits for the lock of a session another request holds.
     * A request that waits longer goes on without the lock.
     *
     * @var int
     */
    public static $lockTimeout = 30;

    /**
     * @var Database Database connection
     */
    private $database;

    /**
     * @var string Table name for sessions
     */
    private $table = 'sessions';

    /**
     * Session id whose lock this request holds
     *
     * @var string|null
     */
    private $lockedId = null;

    /**
     * Lock file of SQLite
     *
     * @var resource|null
     */
    private $lockFile = null;

    /**
     * Open the session.
     *
     * @return bool Always returns true
     */
    public function _open()
    {
        $this->connection();
        return true;
    }

    /**
     * Get the database connection, opening it on first use.
     *
     * @return Database
     */
    private function connection()
    {
        if ($this->database === null) {
            $this->database = Database::create();
        }
        return $this->database;
    }

    /**
     * Close the session: release its lock.
     *
     * @return bool Always returns true
     */
    public function _close()
    {
        $this->unlock();
        return true;
    }

    /**
     * Read session data, after taking the lock of the session.
     *
     * @param string $sess_id Session ID
     * @return string Session data or an empty string
     */
    public function _read($sess_id)
    {
        $this->lock((string) $sess_id);
        $row = $this->connection()->select('data')
            ->from($this->table)
            ->where(['sess_id', $sess_id])
            ->first();
        return $row ? (string) $row->data : '';
    }

    /**
     * Write session data.
     *
     * Every request writes its session, so the writes keep the query cache: a
     * cached SELECT never reads this table, and emptying the cache on every
     * request (as any other INSERT or UPDATE does) left DB_CACHE with nothing.
     *
     * @param string $sess_id Session ID
     * @param string $data Session data
     * @return bool Always returns true
     */
    public function _write($sess_id, $data)
    {
        $row = $this->connection()->select('sess_id')
            ->from($this->table)
            ->where(['sess_id', $sess_id])
            ->first();
        if ($row) {
            $this->connection()->update($this->table)
                ->set([
                    'access' => time(),
                    'data' => $data
                ])
                ->where(['sess_id', $sess_id])
                ->keepQueryCache()
                ->execute();
        } else {
            $this->connection()->insert($this->table)
                ->values([
                    'sess_id' => $sess_id,
                    'access' => time(),
                    'data' => $data,
                    'created_at' => date('Y-m-d H:i:s')
                ])
                ->keepQueryCache()
                ->execute();
        }
        return true;
    }

    /**
     * Destroy a session.
     *
     * @param string $sess_id Session ID
     * @return bool Always returns true
     */
    public function _destroy($sess_id)
    {
        $this->connection()->delete($this->table)
            ->where(['sess_id', $sess_id])
            ->keepQueryCache()
            ->execute();
        return true;
    }

    /**
     * Garbage collection: remove sessions idle longer than $max seconds.
     *
     * @param int $max Maximum lifetime in seconds
     * @return bool Always returns true
     */
    public function _gc($max)
    {
        $old = time() - $max;
        $this->connection()->delete($this->table)
            ->where(['access', '<', $old])
            ->keepQueryCache()
            ->execute();
        return true;
    }

    /**
     * Create the sessions table (with the prefix of the connection) in the SQL of
     * the connection's database: MySQL, PostgreSQL, SQL Server or SQLite.
     *
     * @return bool True on success, false on failure
     */
    public function createTable()
    {
        $database = $this->connection();
        $table = $database->getTableName($this->table);
        switch ($database->getDriverName()) {
        case 'pgsql':
            $sqls = [
                'CREATE TABLE IF NOT EXISTS "'.$table.'" ("sess_id" varchar(128) NOT NULL PRIMARY KEY, "data" text, "access" integer NOT NULL, "created_at" timestamp NOT NULL)',
                'CREATE INDEX IF NOT EXISTS "'.$table.'_access" ON "'.$table.'" ("access")'
            ];
            break;
        case 'sqlite':
            $sqls = [
                'CREATE TABLE IF NOT EXISTS "'.$table.'" ("sess_id" TEXT NOT NULL PRIMARY KEY, "data" TEXT, "access" INTEGER NOT NULL, "created_at" TEXT NOT NULL)',
                'CREATE INDEX IF NOT EXISTS "'.$table.'_access" ON "'.$table.'" ("access")'
            ];
            break;
        case 'sqlsrv':
            $sqls = [
                "IF OBJECT_ID(N'".$table."', N'U') IS NULL CREATE TABLE [".$table.'] ([sess_id] nvarchar(128) NOT NULL PRIMARY KEY, [data] nvarchar(max), [access] int NOT NULL, [created_at] datetime2 NOT NULL)',
                "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'".$table."_access') CREATE INDEX [".$table.'_access] ON ['.$table.'] ([access])'
            ];
            break;
        default:
            $sqls = ["CREATE TABLE IF NOT EXISTS `{$table}` (
            `sess_id` varchar(128) NOT NULL,
            `data` longtext,
            `access` int(11) NOT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`sess_id`),
            KEY `access` (`access`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"];
        }

        try {
            foreach ($sqls as $sql) {
                $database->raw($sql);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Take the lock of a session, waiting up to $lockTimeout seconds.
     *
     * @param string $sessId
     * @return bool true when this request holds the lock
     */
    private function lock($sessId)
    {
        $this->unlock();
        $name = 'kotchasan_sess_'.md5($sessId);
        $timeout = max(0, (int) static::$lockTimeout);
        $database = $this->connection();
        try {
            switch ($database->getDriverName()) {
            case 'mysql':
                $locked = (int) $this->lockQuery('SELECT GET_LOCK(:name, :wait) AS l', [':name' => $name, ':wait' => $timeout]) === 1;
                break;
            case 'pgsql':
                // pg_advisory_lock() waits for ever: try until the timeout instead
                $until = microtime(true) + $timeout;
                do {
                    $locked = (bool) $this->lockQuery('SELECT pg_try_advisory_lock(hashtext(:name)) AS l', [':name' => $name]);
                } while (!$locked && microtime(true) < $until && usleep(50000) === null);
                break;
            case 'sqlsrv':
                $locked = (int) $this->lockQuery("SET NOCOUNT ON; DECLARE @r int; EXEC @r = sp_getapplock @Resource = :name, @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = :wait; SELECT @r AS l", [':name' => $name, ':wait' => $timeout * 1000]) >= 0;
                break;
            case 'sqlite':
                $this->lockFile = @fopen(rtrim(sys_get_temp_dir(), '/').'/'.$name.'.lock', 'c');
                $locked = false;
                if ($this->lockFile) {
                    $until = microtime(true) + $timeout;
                    do {
                        $locked = flock($this->lockFile, LOCK_EX | LOCK_NB);
                    } while (!$locked && microtime(true) < $until && usleep(50000) === null);
                }
                break;
            default:
                $locked = false;
            }
        } catch (\Exception $e) {
            $locked = false;
        }
        $this->lockedId = $locked ? $sessId : null;

        return $locked;
    }

    /**
     * Release the lock this request holds, if any.
     *
     * @return void
     */
    private function unlock()
    {
        if ($this->lockedId === null) {
            return;
        }
        $name = 'kotchasan_sess_'.md5($this->lockedId);
        $this->lockedId = null;
        try {
            switch ($this->connection()->getDriverName()) {
            case 'mysql':
                $this->lockQuery('SELECT RELEASE_LOCK(:name) AS l', [':name' => $name]);
                break;
            case 'pgsql':
                $this->lockQuery('SELECT pg_advisory_unlock(hashtext(:name)) AS l', [':name' => $name]);
                break;
            case 'sqlsrv':
                $this->lockQuery("SET NOCOUNT ON; DECLARE @r int; EXEC @r = sp_releaseapplock @Resource = :name, @LockOwner = 'Session'; SELECT @r AS l", [':name' => $name]);
                break;
            case 'sqlite':
                if ($this->lockFile) {
                    flock($this->lockFile, LOCK_UN);
                    fclose($this->lockFile);
                    $this->lockFile = null;
                }
                break;
            }
        } catch (\Exception $e) {
            // The lock goes with the connection anyway
        }
    }

    /**
     * Run a lock statement on the driver itself (not through raw(), which would
     * empty the query cache for a statement that is not a SELECT) and return the
     * first column.
     *
     * @param string $sql
     * @param array $params
     * @return mixed
     */
    private function lockQuery($sql, array $params)
    {
        return $this->connection()->getConnection()->getDriver()->prepare($sql)->execute($params)->fetchColumn();
    }

    /**
     * Get a value from session.
     *
     * @param string $name Session key
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public function session(string $name, $default = null)
    {
        return isset($_SESSION[$name]) ? $_SESSION[$name] : $default;
    }

    /**
     * Set a value in session.
     *
     * @param string $name Session key
     * @param mixed $value Value to set
     * @return self
     */
    public function setSession(string $name, $value): self
    {
        $_SESSION[$name] = $value;
        return $this;
    }
}
