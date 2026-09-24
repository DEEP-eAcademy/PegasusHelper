<?php

namespace SRAG\PegasusHelper\api\controller;

use ilDBInterface;
use SRAG\PegasusHelper\api\JsonResponse;
use SRAG\PegasusHelper\api\Request;

/**
 * Class ThemeController
 *
 * Serves `GET /v3/ilias-app/theme`. Reads the `ui_uihk_pegasus_theme` table and
 * icon directory that {@see \ilPegasusHelperConfigGUI} already manages -- this
 * plugin has always owned that table; only the delivery route moves here from
 * the REST plugin.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class ThemeController
{
    private const ICON_KEYS = ['course', 'file', 'folder', 'group', 'learningplace', 'learningmodule', 'link'];

    /**
     * @var ilDBInterface
     */
    private $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, array $params): JsonResponse
    {
        $clientTimestamp = (int) ($request->query('timestamp') ?? '0');

        $set = $this->db->query('SELECT * FROM ui_uihk_pegasus_theme WHERE id = 1');
        $row = $this->db->fetchAssoc($set);
        if ($row === null) {
            $row = ['primary_color' => null, 'contrast_color' => null, 'timestamp' => 0];
        }

        $iconsDir = 'data/' . CLIENT_ID . '/pegasushelper/theme/icons/';
        $resources = [];
        if ($clientTimestamp < (int) $row['timestamp']) {
            foreach (self::ICON_KEYS as $key) {
                $resources[] = ['key' => $key, 'path' => $iconsDir . "icon_$key.svg"];
            }
        }

        return new JsonResponse([
            'themePrimaryColor' => $row['primary_color'],
            'themeContrastColor' => (bool) $row['contrast_color'],
            'themeTimestamp' => (int) $row['timestamp'],
            'themeIconResources' => $resources,
        ]);
    }
}
