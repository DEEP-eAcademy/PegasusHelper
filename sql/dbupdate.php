<#1>
<?php
// No-op since PegasusHelper 7.0.0: this step used to configure the ILIAS REST
// plugin's "ilias_pegasus" API client. PegasusHelper is now self-contained; see
// steps #12-#16 below for its own OAuth client/token setup and migration.
?>
<#2>
<?php
// No-op since PegasusHelper 7.0.0 (used to set the REST plugin's access_token_ttl).
?>
<#3>
<?php
// No-op since PegasusHelper 7.0.0 (used to set the REST plugin's refresh_token_ttl).
?>
<#4>
<?php
// No-op since PegasusHelper 7.0.0 (used to whitelist GET /v1/files/:id on the REST plugin's client).
?>
<#5>
<?php
$error_if_not_existing = false;
$ilDB->dropTable("ui_uihk_pegasus_theme", $error_if_not_existing);

$fields = array(
    'id' => array(
        'type' => 'integer',
        'length' => 4,
        'notnull' => true
    ),
    'primary_color' => array(
        'type' => 'text',
        'length' => 10,
        'fixed' => true,
        'notnull' => true
    ),
    'contrast_color' => array(
        'type' => 'integer',
        'length' => 4,
        'notnull' => true
    )
);
$ilDB->createTable('ui_uihk_pegasus_theme', $fields, true);

$ilDB->addPrimaryKey('ui_uihk_pegasus_theme', array('id'));
$ilDB->manipulate('ALTER TABLE ui_uihk_pegasus_theme CHANGE id id INT NOT NULL AUTO_INCREMENT');

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #5: Created ui_uihk_pegasus_theme.');
?>
<#6>
<?php
$ilDB->insert('ui_uihk_pegasus_theme', array(
    'primary_color' => array('text', '4a668b'),
    'contrast_color' => array('integer', 1)
));

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #6: Filled ui_uihk_pegasus_theme.');
?>
<#7>
<?php
global $ilDB;
$error_if_not_existing = false;
$ilDB->dropTable("ui_uihk_pegasus_theme", $error_if_not_existing);

$fields = array(
    'id' => array(
        'type' => 'integer',
        'length' => 4,
        'notnull' => true
    ),
    'primary_color' => array(
        'type' => 'text',
        'length' => 10,
        'fixed' => true,
        'notnull' => true
    ),
    'contrast_color' => array(
        'type' => 'integer',
        'length' => 4,
        'notnull' => true
    )
);
$ilDB->createTable('ui_uihk_pegasus_theme', $fields, true);

$ilDB->addPrimaryKey('ui_uihk_pegasus_theme', array('id'));
$ilDB->manipulate('ALTER TABLE ui_uihk_pegasus_theme CHANGE id id INT NOT NULL AUTO_INCREMENT');

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #7: Created ui_uihk_pegasus_theme.');
?>
<#8>
<?php
global $ilDB;
$ilDB->insert('ui_uihk_pegasus_theme', array(
    'primary_color' => array('text', '4a668b'),
    'contrast_color' => array('integer', 1)
));

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #8: Filled ui_uihk_pegasus_theme.');
?>
<#9>
<?php
global $ilDB;
$ilDB->addTableColumn(
    "ui_uihk_pegasus_theme",
    "timestamp",
    array(
        "type" => "integer",
        "length" => 8,
        "notnull" => true
    )
);
?>
<#10>
<?php
global $ilDB;
$values = array(
    "timestamp" => array("integer", time())
);
$where = array(
    "id" => array("integer", 1)
);

$ilDB->update("ui_uihk_pegasus_theme", $values, $where);
?>
<#11>
<?php
ilPegasusHelperConfigGUI::copyDefaultIcons();
?>
<#12>
<?php
// No-op. This step originally created a table named "ui_uihk_pegasus_config"
// (22 bytes), which was right at ILIAS's maximum table-identifier length and
// made the very next step (which needed "ui_uihk_pegasus_refresh", 23 bytes)
// fail with "Invalid table name ... Maximum table identifer length is 22
// bytes". Both tables are created (under shorter names) in step #13 instead,
// which also cleans up the now-orphaned table for any install that got this
// far before the fix. See step #13.
?>
<#13>
<?php
// Settings for the plugin's own OAuth2 implementation (replacing the ILIAS
// REST plugin's "ui_uihk_rest_config" table; see step #15 for the migration),
// and tracking for issued refresh tokens (replacing "ui_uihk_rest_refresh";
// see step #16). Access tokens are stateless and are not tracked in a table.
//
// Table names are kept well under ILIAS's 22-byte identifier limit (see #12).
global $ilDB;

// Clean up the orphaned table from the previous (too-long-named) attempt at
// this step, on any install that reached step #12 before this fix.
$ilDB->dropTable('ui_uihk_pegasus_config', false);

if (!$ilDB->tableExists('ui_uihk_peg_config')) {
    $fields = array(
        'setting_name' => array(
            'type' => 'text',
            'length' => 128,
            'notnull' => true
        ),
        'setting_value' => array(
            'type' => 'text',
            'length' => 512,
            'notnull' => false
        )
    );
    $ilDB->createTable('ui_uihk_peg_config', $fields);
    $ilDB->addPrimaryKey('ui_uihk_peg_config', array('setting_name'));
}

if (!$ilDB->tableExists('ui_uihk_peg_refresh')) {
    $fields = array(
        'token_hash' => array(
            'type' => 'text',
            'length' => 64,
            'fixed' => true,
            'notnull' => true
        ),
        'user_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'created' => array(
            'type' => 'timestamp',
            'notnull' => true
        ),
        'last_refresh' => array(
            'type' => 'timestamp',
            'notnull' => true
        ),
        'refreshes' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true,
            'default' => 0
        )
    );
    $ilDB->createTable('ui_uihk_peg_refresh', $fields);
    $ilDB->addPrimaryKey('ui_uihk_peg_refresh', array('token_hash'));
    $ilDB->addIndex('ui_uihk_peg_refresh', array('created'), 'i1');
}

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #13: Created ui_uihk_peg_config and ui_uihk_peg_refresh.');
?>
<#14>
<?php
// Short-lived, one-time SSO auth-tokens for opening ILIAS pages/resources from
// the app, replacing the REST plugin's "ui_uihk_rest_token" (and the plugin's
// own removed entity\UserToken ActiveRecord, which used the same table name).
global $ilDB;
$fields = array(
    'token' => array(
        'type' => 'text',
        'length' => 128,
        'notnull' => true
    ),
    'user_id' => array(
        'type' => 'integer',
        'length' => 4,
        'notnull' => true
    ),
    'expires' => array(
        'type' => 'timestamp',
        'notnull' => true
    )
);
$ilDB->createTable('ui_uihk_peg_token', $fields);
$ilDB->addPrimaryKey('ui_uihk_peg_token', array('token'));
$ilDB->addIndex('ui_uihk_peg_token', array('user_id'), 'i1');

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #14: Created ui_uihk_peg_token.');
?>
<#15>
<?php
// Copies the "ilias_pegasus" client's secret, the token signing salt and the
// token TTLs from the ILIAS REST plugin, if it is still installed; otherwise
// generates fresh values. Must run before #16 (which needs the copied salt to
// recognize migrated refresh tokens).
global $ilDB;
(new SRAG\PegasusHelper\migration\RestPluginMigration($ilDB))->migrateSettings();
?>
<#16>
<?php
// Migrates the REST plugin's live refresh tokens, so app installations that
// are already logged in stay logged in after the switch.
global $ilDB;
(new SRAG\PegasusHelper\migration\RestPluginMigration($ilDB))->migrateRefreshTokens();
?>
<#17>
<?php
// Per-user (and global, under the reserved user_id 0) token revocation cutoffs.
// Access tokens are otherwise stateless and cannot be individually killed, so
// this is what backs the "Revoke" actions on the General configuration tab --
// see SRAG\PegasusHelper\oauth\RevocationRepository.
global $ilDB;
$fields = array(
    'user_id' => array(
        'type' => 'integer',
        'length' => 4,
        'notnull' => true
    ),
    'revoked_before' => array(
        'type' => 'integer',
        'length' => 4,
        'notnull' => true
    )
);
$ilDB->createTable('ui_uihk_peg_revoke', $fields);
$ilDB->addPrimaryKey('ui_uihk_peg_revoke', array('user_id'));

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #17: Created ui_uihk_peg_revoke.');
?>