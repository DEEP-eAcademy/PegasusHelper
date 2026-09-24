<?php

namespace SRAG\PegasusHelper\api;

use ilAccessHandler;
use ilContainerReference;
use ilDBInterface;
use ilLink;
use ilObject;
use ilSessionAppointment;

/**
 * Class ObjectDataMapper
 *
 * Builds the `DesktopData`/`IliasTreeItem` JSON shape the app expects for repository
 * objects, given a set of reference ids. Ports the ILIAS REST plugin's
 * `ilias_app_v2`/`ilias_app_v3` `fetchObjectData()`/`getObjectByRefId()` SQL, with
 * all identifiers bound as query parameters instead of interpolated.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class ObjectDataMapper
{
    /**
     * Reference types whose displayed title is the title of the object they refer to.
     */
    private const REFERENCE_TYPES = ['grpr', 'catr', 'crsr'];

    /**
     * @var ilDBInterface
     */
    private $db;

    /**
     * @var ilAccessHandler
     */
    private $access;

    public function __construct(ilDBInterface $db, ilAccessHandler $access)
    {
        $this->db = $db;
        $this->access = $access;
    }

    /**
     * Maps a set of reference ids to their `DesktopData` rows, silently dropping
     * any reference the current user cannot even see.
     *
     * @param int[] $refIds
     * @return array<int, array>
     */
    public function mapRefIds(array $refIds): array
    {
        $refIds = array_values(array_unique(array_map('intval', $refIds)));
        if (count($refIds) === 0) {
            return [];
        }

        $result = [];
        foreach ($this->queryByRefIds($refIds) as $row) {
            $refId = (int) $row['ref_id'];
            if (!$this->isVisible($refId)) {
                continue;
            }
            $result[] = $this->buildItem($row);
        }

        return $result;
    }

    /**
     * Maps object ids (e.g. course/group memberships, which come as obj ids) to
     * their `DesktopData` rows, by resolving each to one of its references first.
     *
     * @param int[] $objIds
     * @return array<int, array>
     */
    public function mapObjIds(array $objIds): array
    {
        $refIds = [];
        foreach (array_unique(array_map('intval', $objIds)) as $objId) {
            $references = ilObject::_getAllReferences($objId);
            if (count($references) > 0) {
                $refIds[] = (int) reset($references);
            }
        }

        return $this->mapRefIds($refIds);
    }

    /**
     * Returns the single `IliasTreeItem` for a reference id, requiring visible AND
     * read permission (used by `GET /v3/ilias-app/object/{refId}`).
     *
     * @param int $refId
     * @return array
     *
     * @throws ApiException 403 if the object is not visible+readable, 404 if it does not exist
     */
    public function requireReadable(int $refId): array
    {
        $rows = $this->queryByRefIds([$refId]);
        $row = $rows[0] ?? null;
        if ($row === null) {
            throw ApiException::notFound();
        }
        if (!($this->isVisible($refId) && $this->isRead($refId))) {
            throw ApiException::forbidden();
        }

        return $this->buildItem($row);
    }

    /**
     * @param int[] $refIds
     * @return array<int, array> raw DB rows
     */
    private function queryByRefIds(array $refIds): array
    {
        $inClause = $this->db->in('object_reference.ref_id', $refIds, false, 'integer');

        $sql = 'SELECT object_data.*, tree.child AS ref_id, tree.parent AS parent_ref_id, '
            . 'page_object.parent_id AS page_layout, cs.value AS timeline '
            . 'FROM object_data '
            . 'INNER JOIN object_reference ON (object_reference.obj_id = object_data.obj_id AND object_reference.deleted IS NULL) '
            . 'INNER JOIN tree ON (tree.child = object_reference.ref_id) '
            . 'LEFT JOIN page_object ON page_object.parent_id = object_data.obj_id '
            . "LEFT JOIN container_settings AS cs ON cs.id = object_data.obj_id AND cs.keyword = 'news_timeline' "
            . "WHERE $inClause AND object_data.type NOT IN ('rolf', 'itgr')";

        $set = $this->db->query($sql);
        $rows = [];
        while ($row = $this->db->fetchAssoc($set)) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function buildItem(array $row): array
    {
        $refId = (int) $row['ref_id'];
        $objId = (int) $row['obj_id'];
        $type = (string) $row['type'];

        $item = [
            'objId' => (string) $objId,
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'hasPageLayout' => $row['page_layout'] !== null,
            'hasTimeline' => ((int) $row['timeline']) === 1,
            'permissionType' => $this->isRead($refId) ? 'read' : 'visible',
            'refId' => (string) $refId,
            'parentRefId' => (string) $row['parent_ref_id'],
            'type' => $type,
            'link' => (string) ilLink::_getStaticLink($refId, $type),
            'repoPath' => $this->repoPath($refId),
        ];

        $item['title'] = $this->fixSessionTitle($item['title'], $type, $objId);
        $item['title'] = $this->fixReferenceTitle($item['title'], $type, $objId);

        return $item;
    }

    private function fixSessionTitle(string $title, string $type, int $objId): string
    {
        if ($type !== 'sess') {
            return $title;
        }

        $appointment = ilSessionAppointment::_lookupAppointment($objId);
        $suffix = strlen($title) > 0 ? (': ' . $title) : '';

        return ilSessionAppointment::_appointmentToString(
            (int) $appointment['start'],
            (int) $appointment['end'],
            (bool) $appointment['fullday']
        ) . $suffix;
    }

    private function fixReferenceTitle(string $title, string $type, int $objId): string
    {
        if (!in_array($type, self::REFERENCE_TYPES, true)) {
            return $title;
        }

        return (string) ilContainerReference::_lookupTitle($objId);
    }

    /**
     * @param int $refId
     * @return string[]
     */
    private function repoPath(int $refId): array
    {
        global $DIC;
        $path = [];
        foreach ($DIC->repositoryTree()->getPathFull($refId) as $node) {
            $path[] = (string) $node['title'];
        }

        return $path;
    }

    private function isVisible(int $refId): bool
    {
        return $this->access->checkAccess('visible', '', $refId);
    }

    private function isRead(int $refId): bool
    {
        return $this->access->checkAccess('read', '', $refId);
    }
}
