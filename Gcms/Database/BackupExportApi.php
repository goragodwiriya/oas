<?php
/**
 * @filesource Gcms/Database/BackupExportApi.php
 *
 * The tables and export actions of a database backup API. The controller that uses this says who may
 * call them and where the log goes.
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Database;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * @mixin \Kotchasan\ApiController
 */
trait BackupExportApi
{
    /**
     * @param Request $request
     * @return int|Response the id of the person acting, or the response that refuses them
     */
    abstract protected function backupActor(Request $request);

    /**
     * @param string $topic
     * @param int $actor
     */
    abstract protected function logBackup(string $topic, int $actor): void;

    /**
     * @param Request $request
     * @return Response
     */
    public function tables(Request $request)
    {
        try {
            static::validateMethod($request, 'GET');
            $actor = $this->backupActor($request);
            if ($actor instanceof Response) {
                return $actor;
            }

            $service = new ImportExportService();
            $recovered = $service->recoverInterruptedImport();
            if (!empty($recovered)) {
                $this->logBackup('Put back after an interrupted import: '.implode(', ', $recovered), $actor);
            }

            return $this->successResponse([
                'prefix' => $service->getPrefix(),
                'table_prefix' => $service->getTablePrefixWithUnderscore(),
                'tables' => $service->detectTables(),
                'recovered' => $recovered
            ], 'Database tables loaded');
        } catch (\Exception $e) {
            return $this->backupFailure($e);
        }
    }

    /**
     * The file goes out as it is produced: no temporary file with password hashes in it, no limit
     * from memory_limit, and no proxy gives up on a connection that keeps moving. So a failure cannot
     * change the status any more. It shows as a file without its last line, which the page reports
     * and the import refuses.
     *
     * @param Request $request
     * @return Response
     */
    public function export(Request $request)
    {
        try {
            static::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $actor = $this->backupActor($request);
            if ($actor instanceof Response) {
                return $actor;
            }

            $service = new ImportExportService();
            $tableModes = $service->sanitizeTableModes($this->backupJsonField($request, 'table_modes'), $this->backupRequestedTables($request, $service));
            if (empty($tableModes)) {
                return $this->errorResponse('No table selected for export', 422);
            }
            $batchSize = max(100, min(5000, $request->post('batch_size', 500)->toInt()));
        } catch (\Exception $e) {
            return $this->backupFailure($e);
        }

        $this->openBackupStream([
            'Content-Type: application/sql; charset=utf-8',
            'Content-Disposition: attachment; filename="database-backup-'.date('Y-m-d-His').'.sql"'
        ]);
        try {
            $service->exportSqlByTableModes('php://output', $tableModes, $batchSize);
            $this->logBackup('Export DB backup', $actor);
        } catch (\Exception $e) {
            $this->backupFailure($e);
            echo "\n-- The export failed here\n";
        }
        exit;
    }

    /**
     * @param Request $request
     * @param string $field
     * @return array the JSON field decoded, empty if it is anything but a JSON array or object
     */
    private function backupJsonField(Request $request, string $field): array
    {
        $decoded = json_decode($request->post($field)->toString(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param Request $request
     * @param ImportExportService $service
     * @return array the tables the request is about: those it names that exist, or all of them
     */
    private function backupRequestedTables(Request $request, ImportExportService $service): array
    {
        if ($request->post('scope')->filter('a-z') === 'selected') {
            return $service->sanitizeSelectedTables(array_filter($this->backupJsonField($request, 'tables'), 'is_string'));
        }

        return array_column($service->detectTables(), 'name');
    }

    /**
     * @param \Exception $e
     * @return Response
     */
    private function backupFailure(\Exception $e)
    {
        $code = (int) $e->getCode();

        return $this->errorResponse($e->getMessage(), $code >= 400 && $code < 600 ? $code : 500, $e);
    }

    /**
     * Start answering now rather than when the work is done. The session is released too: while it is
     * open, every other request of this person waits for it.
     *
     * @param array $headers
     */
    private function openBackupStream(array $headers): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        foreach ($headers as $header) {
            header($header);
        }
        header('Cache-Control: no-store');
        // Otherwise nginx holds the output back until it has enough of it.
        header('X-Accel-Buffering: no');
    }
}
