<?php
/**
 * @filesource Gcms/Database/BackupImportApi.php
 *
 * The upload and import actions of a database backup API, for a controller that also uses
 * BackupExportApi.
 *
 * ⚠️ Only for people who may already change anything on this installation: the central console, or the
 * owner of a site that runs alone. An import writes any row into any table, past every check of the
 * application, and the application trusts its tables: a row can make it delete a file outside the site,
 * or make a site's admin its owner. Checking the SQL cannot see that, so a customer of a shared
 * installation must not have this. See modules/index/controllers/database.php of gcms.in.th.
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Database;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * @mixin \Kotchasan\ApiController
 * @mixin BackupExportApi
 */
trait BackupImportApi
{
    /**
     * Take a backup in pieces, each small enough for upload_max_filesize, post_max_size and the web
     * server, whatever the size of the file.
     *
     * Without upload_id: start, and answer with the id and the size of a piece. This asks for the CSRF
     * token. With upload_id: one more piece. These do not: the token allows a limited number of uses,
     * and only someone who has already passed this check knows the id.
     *
     * @param Request $request
     * @return Response
     */
    public function upload(Request $request)
    {
        try {
            static::validateMethod($request, 'POST');
            if (!$this->backupRequestFits()) {
                return $this->errorResponse('This request is larger than post_max_size allows', 413);
            }
            $id = $request->post('upload_id')->filter('0-9a-f');
            if ($id === '') {
                $this->validateCsrfToken($request);
            }
            $actor = $this->backupActor($request);
            if ($actor instanceof Response) {
                return $actor;
            }

            $service = new ImportExportService();
            if ($id === '') {
                $this->removeStaleUploads();
                $id = bin2hex(random_bytes(16));
                $path = $this->backupUploadPath($service, $actor, $id);
                if (@file_put_contents($path, '') === false) {
                    return $this->errorResponse('Unable to store the uploaded file', 500);
                }
                @chmod($path, 0600);

                return $this->successResponse(['upload_id' => $id, 'chunk_size' => $this->backupChunkSize(), 'received' => 0], 'Ready');
            }

            $path = $this->backupUploadPath($service, $actor, $id);
            if (!is_file($path)) {
                return $this->errorResponse('Upload not found. Please choose the file again', 404);
            }

            return $this->successResponse(['upload_id' => $id, 'received' => $this->appendUploadPiece($request, $path)], 'Received');
        } catch (\Exception $e) {
            return $this->backupFailure($e);
        }
    }

    /**
     * Once the import takes more than a few seconds this starts answering, with spaces, which JSON
     * ignores, so that no proxy gives up on a silent connection. The status is 200 from then on and
     * the JSON at the end says how it went.
     *
     * @param Request $request
     * @return Response
     */
    public function import(Request $request)
    {
        $uploaded = '';
        $holding = null;
        try {
            static::validateMethod($request, 'POST');
            if (!$this->backupRequestFits()) {
                return $this->errorResponse('This request is larger than post_max_size allows. Please use the page, which sends a file in pieces', 413);
            }
            $this->validateCsrfToken($request);
            $actor = $this->backupActor($request);
            if ($actor instanceof Response) {
                return $actor;
            }

            $overwrite = $request->post('overwrite')->toBoolean();
            if ($overwrite && !$request->post('confirm_overwrite')->toBoolean()) {
                return $this->errorResponse('Please confirm overwrite operation before importing', 422);
            }

            $service = new ImportExportService();
            $tables = $this->backupRequestedTables($request, $service);
            $tableModes = $service->sanitizeTableModes($this->backupJsonField($request, 'table_modes'), $tables);
            if (empty($tableModes)) {
                return $this->errorResponse('Please select structure or data for at least one table', 422);
            }

            [$filePath, $uploaded] = $this->backupSource($request, $service, $actor);
            // The same file twice, from a double click or a retry: the second must not remove it.
            if ($uploaded !== '') {
                $holding = fopen($uploaded, 'rb');
                if (!$holding || !flock($holding, LOCK_EX | LOCK_NB)) {
                    return $this->errorResponse('This file is already being imported', 409);
                }
            }
            $format = $request->post('format')->filter('a-z') === 'json' ? 'json' : 'sql';
        } catch (\Exception $e) {
            return $this->backupFailure($e);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $answering = false;
        $service->setHeartbeat(function () use (&$answering) {
            if (!$answering) {
                $answering = true;
                $this->openBackupStream(['Content-Type: application/json; charset=utf-8']);
            }
            echo ' ';
            flush();
        });

        try {
            $result = $format === 'sql'
                ? $service->importSql($filePath, $tableModes, $tables, $overwrite)
                : $service->importJson($filePath, $tableModes, $tables, $overwrite);
            $this->logBackup('Import DB backup ('.$format.')', $actor);
            $response = $this->successResponse(['stats' => $result['stats'] ?? []], 'Import completed successfully');
        } catch (\Exception $e) {
            $response = $this->backupFailure($e);
        } finally {
            if ($uploaded !== '') {
                unlink($uploaded);
                flock($holding, LOCK_UN);
                fclose($holding);
            }
        }

        if ($answering) {
            echo $response->getContent();
            exit;
        }

        return $response;
    }

    /**
     * @return bool false if PHP threw the body away, which otherwise looks like a form left empty
     */
    private function backupRequestFits(): bool
    {
        return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) <= ImportExportService::iniBytes((string) ini_get('post_max_size'));
    }

    /**
     * @param Request $request
     * @param ImportExportService $service
     * @param int $actor
     * @return array the file to read, and the same again if it is ours to remove afterwards
     */
    private function backupSource(Request $request, ImportExportService $service, int $actor): array
    {
        $id = $request->post('upload_id')->filter('0-9a-f');
        if ($id !== '') {
            $path = $this->backupUploadPath($service, $actor, $id);
            if (!is_file($path)) {
                throw new \RuntimeException('Upload not found. Please choose the file again', 404);
            }

            return [$path, $path];
        }

        $file = $request->getUploadedFiles()['backup_file'] ?? null;
        if (!is_object($file) || (!$file->hasError() && !$file->hasUploadFile())) {
            throw new \RuntimeException('Backup file is required', 422);
        }
        if ($file->hasError()) {
            throw new \RuntimeException($file->getErrorMessage(), 422);
        }

        return [$file->getTempFileName(), ''];
    }

    /**
     * @param Request $request
     * @param string $path
     * @return int the bytes held now. An offset that does not match adds nothing and tells the sender
     * where to go on, so a piece sent twice after a lost answer does no harm.
     */
    private function appendUploadPiece(Request $request, string $path): int
    {
        $file = $request->getUploadedFiles()['chunk'] ?? null;
        if (!is_object($file) || !$file->hasUploadFile()) {
            throw new \RuntimeException(is_object($file) && $file->hasError() ? $file->getErrorMessage() : 'Backup file is required', 422);
        }

        $out = fopen($path, 'ab');
        if (!$out || !flock($out, LOCK_EX)) {
            throw new \RuntimeException('Unable to store the uploaded file', 500);
        }
        try {
            clearstatcache(true, $path);
            $held = (int) filesize($path);
            if ($request->post('offset')->toInt() !== $held) {
                return $held;
            }
            $free = @disk_free_space(dirname($path));
            if ($free !== false && $free < $file->getSize() * 2) {
                throw new \RuntimeException('Not enough disk space to store the uploaded file', 507);
            }
            $in = fopen($file->getTempFileName(), 'rb');
            $copied = $in ? stream_copy_to_stream($in, $out) : false;
            if ($in) {
                fclose($in);
            }
            if ($copied === false || $copied !== $file->getSize()) {
                ftruncate($out, $held);
                throw new \RuntimeException('Unable to store the uploaded file', 500);
            }

            return $held + $copied;
        } finally {
            flock($out, LOCK_UN);
            fclose($out);
        }
    }

    /**
     * @param ImportExportService $service
     * @param int $actor
     * @param string $id
     * @return string the name holds who uploaded it, so nobody else's id leads anywhere
     */
    private function backupUploadPath(ImportExportService $service, int $actor, string $id): string
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            throw new \RuntimeException('Upload not found. Please choose the file again', 404);
        }

        return sys_get_temp_dir().'/gcms-import-'.md5($service->getPrefix().'|'.$actor).'-'.$id.'.part';
    }

    /**
     * @return int bytes. The sender halves it if the web server still says it is too large.
     */
    private function backupChunkSize(): int
    {
        $limit = min(
            ImportExportService::iniBytes((string) ini_get('upload_max_filesize')),
            ImportExportService::iniBytes((string) ini_get('post_max_size')) - 65536,
            8388608
        );

        return max(65536, $limit);
    }

    /**
     * An upload that was abandoned must not stay: a backup holds password hashes.
     */
    private function removeStaleUploads(): void
    {
        foreach (glob(sys_get_temp_dir().'/gcms-import-*.part') ?: [] as $file) {
            if (filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }
}
