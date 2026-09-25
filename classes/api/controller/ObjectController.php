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
     * huge or near-root subtree can't be used to load the server. ilTree
     * exposes no public API to bound the fetch at the data source (no count
     * or limit parameter on getSubTreeIds()/getChildIds()), so this is
     * enforced on the result rather than the query -- still bounded, just
     * after the ids for an oversized subtree have already been fetched once.
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
        $access = $DIC->access();

        // Without this, an authenticated app user could enumerate the ref ids
        // (and, via ?recursive=1, the whole subtree shape) of a repository
        // node they cannot themselves see, before ObjectDataMapper's own
        // per-node checkAccess() calls ever filter the *contents* (SEC-10).
        if (!$access->checkAccess('visible', '', $refId)) {
            throw ApiException::forbidden();
        }

        $tree = $DIC->repositoryTree();

        // ilTree has no public API to cap a subtree fetch at the data source
        // (see class docblock on MAX_RECURSIVE_NODES); the check below still
        // rejects an oversized result before it is mapped or returned, which
        // is the same bound this route has always enforced.
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
