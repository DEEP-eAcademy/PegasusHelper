<?php

namespace SRAG\PegasusHelper\api\controller;

use ilObject;
use SRAG\PegasusHelper\api\ApiException;
use SRAG\PegasusHelper\api\ApiInitialisation;
use SRAG\PegasusHelper\api\JsonResponse;
use SRAG\PegasusHelper\api\LearningModuleZipBuilder;
use SRAG\PegasusHelper\api\Request;
use SRAG\PegasusHelper\audit\AuditLog;
use SRAG\PegasusHelper\authentication\AuthTokenRepository;

/**
 * Class LearningModuleController
 *
 * Serves `GET /v1/learning-module/{refId}` (metadata) and the new
 * `GET /v1/learning-module/{refId}/zip` (the actual download). The zip route is
 * not a Bearer route: the app fetches it the same way it fetches any other
 * resource link, via the one-time `user`+`token` query parameters minted by
 * `GET /v2/ilias-app/auth-token` (see {@see \SRAG\PegasusHelper\api\Router::AUTH_NONE}).
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class LearningModuleController
{
    /**
     * @var LearningModuleZipBuilder
     */
    private $zipBuilder;

    /**
     * @var AuthTokenRepository
     */
    private $authTokens;

    /**
     * @var AuditLog
     */
    private $audit;

    public function __construct(LearningModuleZipBuilder $zipBuilder, AuthTokenRepository $authTokens, AuditLog $audit)
    {
        $this->zipBuilder = $zipBuilder;
        $this->authTokens = $authTokens;
        $this->audit = $audit;
    }

    /**
     * `GET /v1/learning-module/{refId}` (Bearer route)
     */
    public function metadata(Request $request, array $params): JsonResponse
    {
        $refId = (int) $params['refId'];
        global $DIC;
        $access = $DIC->access();

        if (!($access->checkAccess('visible', '', $refId) && $access->checkAccess('read', '', $refId))) {
            throw ApiException::forbidden();
        }

        [$objId, $type] = $this->resolveLearningModule($refId);
        $meta = $this->zipBuilder->describe($objId, $type);

        return new JsonResponse([
            'startFile' => $meta['startFile'],
            'zipFile' => 'Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/api.php/v1/learning-module/' . $refId . '/zip',
            'zipDirName' => $meta['zipDirName'],
            'timestamp' => $meta['timestamp'],
        ]);
    }

    /**
     * `GET /v1/learning-module/{refId}/zip` (no Bearer auth -- see class docblock)
     */
    public function zip(Request $request, array $params): void
    {
        $refId = (int) $params['refId'];
        $userId = (int) ($request->query('user') ?? '0');
        $token = (string) ($request->query('token') ?? '');

        if ($userId <= 0 || $token === '') {
            throw ApiException::unauthorized('Invalid token')->withReason('missing_sso_token');
        }

        $status = $this->authTokens->consume($userId, $token);
        if ($status !== AuthTokenRepository::STATUS_CONSUMED) {
            $reason = $status === AuthTokenRepository::STATUS_EXPIRED ? 'sso_token_expired' : 'sso_token_unknown';
            throw ApiException::unauthorized('Invalid token')->withReason($reason);
        }

        ApiInitialisation::loadUser($userId);
        $this->audit->setActor($userId);

        global $DIC;
        if (!$DIC->access()->checkAccess('read', '', $refId)) {
            throw ApiException::forbidden();
        }

        [$objId, $type] = $this->resolveLearningModule($refId);

        $this->audit->log(AuditLog::EVENT_LM_DOWNLOAD, AuditLog::LEVEL_INFO, [
            'ref_id' => $refId,
            'obj_id' => $objId,
            'type' => $type,
        ]);

        $this->zipBuilder->streamZip($objId, $type);
    }

    /**
     * @param int $refId
     * @return array{0:int, 1:string} [objId, type]
     */
    private function resolveLearningModule(int $refId): array
    {
        $objId = ilObject::_lookupObjId($refId);
        $type = $objId !== 0 ? ilObject::_lookupType($objId) : '';

        if ($objId === 0 || !in_array($type, ['htlm', 'sahs'], true)) {
            throw ApiException::forbidden();
        }

        return [$objId, $type];
    }
}
