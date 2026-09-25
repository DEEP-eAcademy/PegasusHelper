<?php

namespace SRAG\PegasusHelper\api;

use FilesystemIterator;
use ilDBInterface;
use ilObjFileBasedLMAccess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Class LearningModuleZipBuilder
 *
 * Builds (and caches) a downloadable zip of an HTML learning module (htlm) or
 * SCORM package (sahs) for offline use, in the shape the Pegasus app expects:
 * a single top-level folder named `zipDirName`, containing `startFile`.
 *
 * This replaces the ILIAS REST plugin's `learning_module_v1` extension, which
 * shells out to `zip`/`unzip` against a `data/<client>/lm_data/lm_<id>` folder --
 * a layout ILIAS 10 no longer uses for htlm content (it moved into the resource
 * storage service, IRSS) and which is not safe to expose under the public web
 * root anyway. Zips built here are cached outside `public/` and only ever
 * served through the RBAC-checked `/v1/learning-module/{refId}/zip` route.
 *
 * A build is (a) serialized per module via an `flock()` on a lock file in the
 * module's own cache directory, so two concurrent first-time downloads build
 * once rather than racing each other or the cache-purge step; (b) written to a
 * temporary file and `rename()`d into place only once complete, so a reader
 * can never observe (or a purge never removes) a half-written archive; and
 * (c) bounded by an {@see ArchiveBudget} while repacking, so a maliciously (or
 * just very badly) sized source archive can't exhaust memory or disk (SEC-10).
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class LearningModuleZipBuilder
{
    private const MAX_ARCHIVE_ENTRIES = 50000;
    private const MAX_ENTRY_BYTES = 512 * 1024 * 1024;      // 512 MiB
    private const MAX_TOTAL_BYTES = 2 * 1024 * 1024 * 1024; // 2 GiB
    private const COMPRESSION_RATIO_CAP = 200;
    private const RATIO_CHECK_THRESHOLD_BYTES = 10 * 1024 * 1024; // 10 MiB

    /**
     * @var ilDBInterface
     */
    private $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @param int    $objId
     * @param string $type  'htlm' or 'sahs'
     * @return array{startFile:string, zipDirName:string, timestamp:int}
     *
     * @throws ApiException 404/500 if the content cannot be located
     */
    public function describe(int $objId, string $type): array
    {
        $zipDirName = 'lm_' . $objId;

        if ($type === 'htlm') {
            return $this->describeHtlm($objId, $zipDirName);
        }

        return [
            'startFile' => 'imsmanifest.xml',
            'zipDirName' => $zipDirName,
            'timestamp' => $this->maxMtime($this->legacyDir($objId)),
        ];
    }

    /**
     * Builds (if not already cached) and streams the zip to the client, then exits.
     *
     * @param int    $objId
     * @param string $type 'htlm' or 'sahs'
     */
    public function streamZip(int $objId, string $type): void
    {
        $path = $this->buildCachedZip($objId, $type);

        // Open the file *before* sending any header, and stream from this one
        // handle to completion: on POSIX, an already-open file descriptor
        // keeps serving its original inode's content even if a concurrent
        // cache purge (see purgeOtherZips()) unlinks that path in the
        // meantime, rather than this request risking a read against a file
        // that got replaced or removed mid-transfer.
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ApiException::serverError('Could not read learning module archive');
        }
        $size = filesize($path);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="lm_' . $objId . '.zip"');
        if ($size !== false) {
            header('Content-Length: ' . (string) $size);
        }

        fpassthru($handle);
        fclose($handle);
        exit;
    }

    private function describeHtlm(int $objId, string $zipDirName): array
    {
        $rid = $this->fileBasedLmResourceId($objId);

        if ($rid === '-') {
            throw ApiException::serverError('Learning module content is unavailable');
        }

        if ($rid !== null && $rid !== '') {
            // Migrated to the resource storage service (IRSS): _determineStartUrl()
            // already returns a path relative to the container, no stripping needed.
            $startFile = ilObjFileBasedLMAccess::_determineStartUrl($objId);
            if (strlen($startFile) === 0) {
                throw ApiException::notFound('No entry point found for HTLM object');
            }

            global $DIC;
            $uri = $DIC->resourceStorage()->consume()->stream($rid)->getStream()->getMetadata('uri');
            $timestamp = is_string($uri) && is_file($uri) ? (int) filemtime($uri) : time();

            return ['startFile' => $startFile, 'zipDirName' => $zipDirName, 'timestamp' => $timestamp];
        }

        // Not (yet) migrated: content still sits in the legacy web data directory.
        $startPath = ilObjFileBasedLMAccess::_determineStartUrl($objId);
        if (strlen($startPath) === 0) {
            throw ApiException::notFound('No entry point found for HTLM object');
        }
        $prefix = '/data/' . CLIENT_ID . '/lm_data/lm_' . $objId . '/';
        $startFile = strpos($startPath, $prefix) === 0
            ? substr($startPath, strlen($prefix))
            : ltrim($startPath, '/');

        return [
            'startFile' => $startFile,
            'zipDirName' => $zipDirName,
            'timestamp' => $this->maxMtime($this->legacyDir($objId)),
        ];
    }

    private function buildCachedZip(int $objId, string $type): string
    {
        $meta = $this->describe($objId, $type);
        $cacheDir = $this->cacheDir($objId);
        $target = $cacheDir . '/' . $meta['timestamp'] . '.zip';

        if (is_file($target)) {
            return $target;
        }

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0750, true) && !is_dir($cacheDir)) {
            throw ApiException::serverError('Could not prepare learning module archive');
        }

        // Serialize every build for this module through one lock file, so two
        // concurrent first-time downloads (or a download racing a purge)
        // build exactly once rather than corrupting/duplicating work.
        $lockHandle = fopen($cacheDir . '/.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
            throw ApiException::serverError('Could not prepare learning module archive');
        }

        try {
            // Re-check now that the lock is held: another request may have
            // just finished building it while this one was waiting.
            if (is_file($target)) {
                return $target;
            }

            $this->purgeOtherZips($cacheDir, $target);

            $tmpPath = $cacheDir . '/.tmp-' . bin2hex(random_bytes(8)) . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw ApiException::serverError('Could not create learning module archive');
            }

            try {
                $budget = new ArchiveBudget(
                    self::MAX_ARCHIVE_ENTRIES,
                    self::MAX_ENTRY_BYTES,
                    self::MAX_TOTAL_BYTES,
                    self::COMPRESSION_RATIO_CAP,
                    self::RATIO_CHECK_THRESHOLD_BYTES
                );

                if ($type === 'htlm') {
                    $rid = $this->fileBasedLmResourceId($objId);
                    if ($rid !== null && $rid !== '' && $rid !== '-') {
                        $this->addIrssContainerEntries($zip, $rid, $meta['zipDirName'], $budget);
                    } else {
                        $this->addDirectoryEntries($zip, $this->legacyDir($objId), $meta['zipDirName'], $budget);
                    }
                } else {
                    $this->addDirectoryEntries($zip, $this->legacyDir($objId), $meta['zipDirName'], $budget);
                }
            } catch (ApiException $e) {
                $zip->close();
                @unlink($tmpPath);

                throw $e;
            }

            if (!$zip->close()) {
                @unlink($tmpPath);

                throw ApiException::serverError('Could not finalize learning module archive');
            }

            chmod($tmpPath, 0640);

            if (!rename($tmpPath, $target)) {
                @unlink($tmpPath);

                throw ApiException::serverError('Could not finalize learning module archive');
            }

            return $target;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Repacks the container's raw zip (read directly off disk via the IRSS stream
     * metadata) under the `$zipDirName/` prefix the app requires.
     */
    private function addIrssContainerEntries(ZipArchive $zip, string $rid, string $zipDirName, ArchiveBudget $budget): void
    {
        global $DIC;
        $uri = $DIC->resourceStorage()->consume()->stream($rid)->getStream()->getMetadata('uri');
        if (!is_string($uri) || !is_file($uri)) {
            throw ApiException::serverError('Learning module content is unavailable');
        }

        $source = new ZipArchive();
        if ($source->open($uri) !== true) {
            throw ApiException::serverError('Learning module archive could not be read');
        }

        try {
            for ($i = 0; $i < $source->numFiles; $i++) {
                $name = $source->getNameIndex($i);
                if ($name === false || substr($name, -1) === '/') {
                    continue;
                }
                if (!self::isSafeEntryName($name)) {
                    continue;
                }

                $stat = $source->statIndex($i);
                $uncompressed = $stat !== false ? (int) $stat['size'] : 0;
                $compressed = $stat !== false ? (int) $stat['comp_size'] : 0;
                // Checked -- and may throw -- before the entry is ever
                // decompressed into memory below (SEC-10).
                $budget->account($uncompressed, $compressed);

                $contents = $source->getFromIndex($i);
                if ($contents === false) {
                    continue;
                }
                if (!$zip->addFromString($zipDirName . '/' . $name, $contents)) {
                    throw ApiException::serverError('Could not add an entry to the learning module archive');
                }
            }
        } finally {
            $source->close();
        }
    }

    /**
     * Adds every file under $sourceDir to the zip, under the `$zipDirName/` prefix,
     * skipping nested `.zip` files (matches the REST plugin's own exclusion, which
     * avoided re-shipping a SCORM package's internal backup zip of itself).
     */
    private function addDirectoryEntries(ZipArchive $zip, string $sourceDir, string $zipDirName, ArchiveBudget $budget): void
    {
        $sourceDir = rtrim($sourceDir, '/');
        if (!is_dir($sourceDir)) {
            throw ApiException::serverError('Learning module content is unavailable');
        }
        $realSourceDir = realpath($sourceDir);
        if ($realSourceDir === false) {
            throw ApiException::serverError('Learning module content is unavailable');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink()) {
                // Never follow a symlinked file out of (or within) the source
                // tree; a symlinked *directory* is already not descended into
                // by RecursiveDirectoryIterator without FOLLOW_SYMLINKS.
                continue;
            }
            if (!$file->isFile() || strtolower($file->getExtension()) === 'zip') {
                continue;
            }

            $realPath = realpath($file->getPathname());
            if ($realPath === false || strpos($realPath, $realSourceDir . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($sourceDir)), '/');
            if (!self::isSafeEntryName($relative)) {
                continue;
            }

            $size = (int) $file->getSize();
            // A plain filesystem file has no separate compressed size; pass
            // the same value twice so the ratio check is always a no-op here.
            $budget->account($size, $size);

            if (!$zip->addFile($file->getPathname(), $zipDirName . '/' . $relative)) {
                throw ApiException::serverError('Could not add an entry to the learning module archive');
            }
        }
    }

    /**
     * @param string $name a zip entry name, or a path relative to a source directory
     * @return bool false if the name could escape the `$zipDirName/` prefix it
     *              is about to be added under, or contains characters no
     *              legitimate learning-module asset needs
     */
    private static function isSafeEntryName(string $name): bool
    {
        if ($name === '' || strpos($name, "\0") !== false || strpos($name, '\\') !== false) {
            return false;
        }
        if ($name[0] === '/') {
            return false;
        }
        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function maxMtime(string $path): int
    {
        if (!file_exists($path)) {
            return time();
        }

        $max = (int) filemtime($path);
        if (!is_dir($path)) {
            return $max;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isLink()) {
                continue;
            }
            $mtime = (int) $entry->getMTime();
            if ($mtime > $max) {
                $max = $mtime;
            }
        }

        return $max;
    }

    private function fileBasedLmResourceId(int $objId): ?string
    {
        $set = $this->db->queryF('SELECT rid FROM file_based_lm WHERE id = %s', ['integer'], [$objId]);
        $row = $this->db->fetchAssoc($set);

        if ($row === null || !array_key_exists('rid', $row) || $row['rid'] === null) {
            return null;
        }

        return (string) $row['rid'];
    }

    private function legacyDir(int $objId): string
    {
        $base = defined('CLIENT_WEB_DIR') ? CLIENT_WEB_DIR : ('./data/' . CLIENT_ID);

        return rtrim($base, '/') . '/lm_data/lm_' . $objId;
    }

    private function cacheDir(int $objId): string
    {
        $root = defined('ILIAS_ABSOLUTE_PATH') ? ILIAS_ABSOLUTE_PATH : sys_get_temp_dir();

        return rtrim($root, '/') . '/data/pegasushelper/lm_zip/' . CLIENT_ID . '/lm_' . $objId;
    }

    private function purgeOtherZips(string $cacheDir, string $keep): void
    {
        foreach (glob($cacheDir . '/*.zip') ?: [] as $file) {
            if (basename($file) !== basename($keep)) {
                @unlink($file);
            }
        }
    }
}
