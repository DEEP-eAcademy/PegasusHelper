<?php

namespace SRAG\PegasusHelper\api\controller;

use ilParticipants;
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
