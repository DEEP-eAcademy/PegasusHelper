<?php

namespace SRAG\PegasusHelper\api\controller;

use ilChangeEvent;
use ilLPStatusWrapper;
use ilObject;
use ilObjFile;
use SplFileInfo;
use SRAG\PegasusHelper\api\ApiException;
use SRAG\PegasusHelper\api\JsonResponse;
use SRAG\PegasusHelper\api\Request;
use SRAG\PegasusHelper\audit\AuditLog;

/**
 * Class FileController
 *
 * Serves file metadata, the file download, and the "mark as done" learning
 * progress update. Ports the ILIAS REST plugin's `files_v1` and `ilias_app_v3`
 * file routes.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class FileController
{
    /**
     * @var AuditLog
     */
    private $audit;

    public function __construct(AuditLog $audit)
    {
        $this->audit = $audit;
    }

    /**
     * `GET /v3/ilias-app/files/{refId}`
     */
    public function metadata(Request $request, array $params): JsonResponse
    {
        $refId = (int) $params['refId'];
        global $DIC;
        $access = $DIC->access();

        if (!$access->checkAccess('visible', '', $refId)) {
            throw ApiException::forbidden();
        }

        $file = $this->loadFile($refId);
        $userId = (int) $DIC->user()->getId();

        $ilDB = $DIC->database();
        $set = $ilDB->queryF(
            'SELECT status FROM ut_lp_marks WHERE obj_id = %s AND usr_id = %s',
            ['integer', 'integer'],
            [$file->getId(), $userId]
        );
        $row = $ilDB->fetchAssoc($set);
        $learningProgress = $row !== null && (bool) $row['status'];

        return new JsonResponse([
            'fileExtension' => $this->fileExtension($file),
            'fileName' => $this->sanitizeFileName($file->getFileName()),
            'fileSize' => (string) $file->getFileSize(),
            'fileType' => $file->getFileType(),
            'fileVersion' => (string) $file->getVersion(),
            'fileVersionDate' => $file->getLastUpdateDate(),
            'fileLearningProgress' => $learningProgress,
        ]);
    }

    /**
     * `POST /v3/ilias-app/files/{refId}/learning-progress-to-done`
     */
    public function markLearningProgressDone(Request $request, array $params): JsonResponse
    {
        $refId = (int) $params['refId'];
        global $DIC;
        $access = $DIC->access();

        if (!($access->checkAccess('visible', '', $refId) && $access->checkAccess('read', '', $refId))) {
            throw ApiException::forbidden();
        }

        $file = $this->loadFile($refId);
        $userId = (int) $DIC->user()->getId();

        ilChangeEvent::_recordReadEvent($file->getType(), $file->getRefId(), $file->getId(), $userId);
        ilLPStatusWrapper::_updateStatus($file->getId(), $userId);

        return new JsonResponse(['message' => 'Learning progress was successfully set to done']);
    }

    /**
     * `GET /v1/files/{refId}` -- streams the file and exits (never returns to the caller).
     */
    public function download(Request $request, array $params): void
    {
        $refId = (int) $params['refId'];
        global $DIC;

        if (!$DIC->access()->checkAccess('read', '', $refId)) {
            throw ApiException::forbidden();
        }

        $file = $this->loadFile($refId);

        $this->audit->log(AuditLog::EVENT_FILE_DOWNLOAD, AuditLog::LEVEL_INFO, [
            'ref_id' => $refId,
            'obj_id' => $file->getId(),
            'file_name' => $file->getFileName(),
            'file_version' => $file->getVersion(),
            'file_size' => $file->getFileSize(),
        ]);

        $file->sendFile();
    }

    private function loadFile(int $refId): ilObjFile
    {
        $objId = ilObject::_lookupObjId($refId);
        if ($objId === 0 || ilObject::_lookupType($objId) !== 'file') {
            throw ApiException::forbidden();
        }

        return new ilObjFile($refId);
    }

    private function sanitizeFileName(string $fileName): string
    {
        $fileName = mb_strtolower($fileName);

        return (string) preg_replace('/[^a-z0-9\-_.]+/', '', $fileName);
    }

    private function fileExtension(ilObjFile $file): string
    {
        try {
            $info = new SplFileInfo($file->getTitle());

            return $info->getExtension();
        } catch (\Exception $e) {
            return '';
        }
    }
}
