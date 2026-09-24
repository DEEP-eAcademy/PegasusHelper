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
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class LearningModuleZipBuilder
{
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

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="lm_' . $objId . '.zip"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
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

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            throw ApiException::serverError('Could not prepare learning module archive');
        }
        $this->purgeOtherZips($cacheDir, $target);

        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ApiException::serverError('Could not create learning module archive');
        }

        if ($type === 'htlm') {
            $rid = $this->fileBasedLmResourceId($objId);
            if ($rid !== null && $rid !== '' && $rid !== '-') {
                $this->addIrssContainerEntries($zip, $rid, $meta['zipDirName']);
            } else {
                $this->addDirectoryEntries($zip, $this->legacyDir($objId), $meta['zipDirName']);
            }
        } else {
            $this->addDirectoryEntries($zip, $this->legacyDir($objId), $meta['zipDirName']);
        }

        $zip->close();

        return $target;
    }

    /**
     * Repacks the container's raw zip (read directly off disk via the IRSS stream
     * metadata) under the `$zipDirName/` prefix the app requires.
     */
    private function addIrssContainerEntries(ZipArchive $zip, string $rid, string $zipDirName): void
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

        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);
            if ($name === false || substr($name, -1) === '/') {
                continue;
            }
            $contents = $source->getFromIndex($i);
            if ($contents === false) {
                continue;
            }
            $zip->addFromString($zipDirName . '/' . $name, $contents);
        }
        $source->close();
    }

    /**
     * Adds every file under $sourceDir to the zip, under the `$zipDirName/` prefix,
     * skipping nested `.zip` files (matches the REST plugin's own exclusion, which
     * avoided re-shipping a SCORM package's internal backup zip of itself).
     */
    private function addDirectoryEntries(ZipArchive $zip, string $sourceDir, string $zipDirName): void
    {
        $sourceDir = rtrim($sourceDir, '/');
        if (!is_dir($sourceDir)) {
            throw ApiException::serverError('Learning module content is unavailable');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) === 'zip') {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($sourceDir)), '/');
            $zip->addFile($file->getPathname(), $zipDirName . '/' . $relative);
        }
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
