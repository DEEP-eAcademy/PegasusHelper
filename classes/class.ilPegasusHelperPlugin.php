<?php

use ILIAS\DI\Container;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Class ilPegasusHelperPlugin
 *
 * @author Stefan Wanzenried <sw@studer-raimann.ch>
 * @author Martin Studer <ms@studer-raimann.ch>
 */
final class ilPegasusHelperPlugin extends ilUserInterfaceHookPlugin
{

    /**
     * @var ilPegasusHelperPlugin
     */
    private static $instance;

    /**
     * @return ilPegasusHelperPlugin
     */
    public static function getInstance(ilDBInterface $db, ilComponentRepositoryWrite $component_repository, string $id): ilPegasusHelperPlugin
    {
        if (!isset(ilPegasusHelperPlugin::$instance)) {
            ilPegasusHelperPlugin::$instance = new self($db, $component_repository, $id);
        }

        return ilPegasusHelperPlugin::$instance;
    }

    public function __construct(ilDBInterface $db, ilComponentRepositoryWrite $component_repository, string $id)
    {
        parent::__construct($db, $component_repository, $id);

        /**
         * @var Container $DIC
         */
        global $DIC;
    }

    /**
     * @return string
     */
    public function getPluginName(): string
    {
        return 'PegasusHelper';
    }

    /**
     * Before uninstall processing
     *
     * PegasusHelper is self-contained since version 7.0.0: it no longer depends
     * on (or configures) the ILIAS REST plugin, so this only drops the plugin's
     * own tables.
     */
    protected function beforeUninstall(): bool
    {
        try {
            global $ilDB, $tpl;
            $error_if_not_existing = false;
            $ilDB->dropTable("ui_uihk_pegasus_theme", $error_if_not_existing);
            $ilDB->dropTable("ui_uihk_pegasus_config", $error_if_not_existing);
            $ilDB->dropTable("ui_uihk_pegasus_refresh", $error_if_not_existing);
            $ilDB->dropTable("ui_uihk_pegasus_token", $error_if_not_existing);

            return true;
        } catch (Exception $e) {
            $tpl->setOnScreenMessage( 'failure', "There was a problem when uninstalling the PegasuHelper plugin", true);
            return false;
        }
    }
}
