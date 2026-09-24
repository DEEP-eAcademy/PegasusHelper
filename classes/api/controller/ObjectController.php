<?php

namespace SRAG\PegasusHelper\api\controller;

use ilParticipants;
use SRAG\PegasusHelper\api\ApiException;
use SRAG\PegasusHelper\api\JsonResponse;
use SRAG\PegasusHelper\api\ObjectDataMapper;
use SRAG\PegasusHelper\api\Request;

/**
 * Class ObjectController
 *
 * Serves the desktop (course/group memberships), the repository object list and
 * a single repository object. Ports the ILIAS REST plugin's `ilias_app_v2`
 * (`/desktop`, `/objects/{refId}`) and `ilias_app_v3` (`/object/{refId}`) routes.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class ObjectController
{
    /**
     * `?recursive=1` walks the whole subtree and runs a per-node checkAccess()
     * call (see ObjectDataMapper::mapRefIds()); cap it so a request against a
     * huge or near-root subtree can't be used to load the server.
     */
    private const MAX_RECURSIVE_NODES = 5000;

    /**
     * @var ObjectDataMapper
     */
    private $mapper;

    public function __construct(ObjectDataMapper $mapper)
    {
        $this->mapper = $mapper;
    }

    /**
     * `GET /v2/ilias-app/desktop`
     */
    public function desktop(Request $request, array $params): JsonResponse
    {
        global $DIC;
        $userId = (int) $DIC->user()->getId();
        $objIds = ilParticipants::_getMembershipByType($userId, ['crs', 'grp']);

        return new JsonResponse($this->mapper->mapObjIds($objIds));
    }

    /**
     * `GET /v2/ilias-app/objects/{refId}[?recursive=1]`
     */
    public function children(Request $request, array $params): JsonResponse
    {
        global $DIC;
        $refId = (int) $params['refId'];
        $tree = $DIC->repositoryTree();

        $refIds = $request->queryBool('recursive') ? $tree->getSubTreeIds($refId) : $tree->getChildIds($refId);

        if (count($refIds) > self::MAX_RECURSIVE_NODES) {
            throw ApiException::badRequest('Subtree is too large to return in one request (' . count($refIds) . ' nodes)');
        }

        return new JsonResponse($this->mapper->mapRefIds($refIds));
    }

    /**
     * `GET /v3/ilias-app/object/{refId}`
     */
    public function object(Request $request, array $params): JsonResponse
    {
        return new JsonResponse($this->mapper->requireReadable((int) $params['refId']));
    }
}
