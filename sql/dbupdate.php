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
<#18>
<?php
// Seeds a `log_components` row for this plugin's own audit-log channel
// (SRAG\PegasusHelper\audit\AuditLog::CHANNEL, "sragpegasushelper") at level
// 200 (ilLogLevel::INFO), so audit entries are written even on installs whose
// global log level default is higher. An admin can still raise or lower this
// afterwards from Administration > System Settings and Maintenance > Logging
// (it is listed there as "Unknown (sragpegasushelper)", since ILIAS's
// component repository doesn't know plugin ids) -- inserted only if the row
// doesn't already exist, so that later choice is never overwritten by a
// re-run of this step.
global $ilDB;

if ($ilDB->tableExists('log_components')) {
    $set = $ilDB->queryF(
        'SELECT component_id FROM log_components WHERE component_id = %s',
        array('text'),
        array('sragpegasushelper')
    );
    if ($ilDB->fetchAssoc($set) === null) {
        $ilDB->manipulateF(
            'INSERT INTO log_components (component_id, log_level) VALUES (%s, %s)',
            array('text', 'integer'),
            array('sragpegasushelper', 200)
        );
    }
}

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #18: Seeded log_components row for the audit log channel.');
?>
<#19>
<?php
// Repairs a missing or empty signing salt, API key or API secret. Since
// 7.3.0, SRAG\PegasusHelper\oauth\ApiSettings::requireSalt()/requireApiKey()/
// requireApiSecret() make the plugin fail closed rather than validate tokens
// against an empty key (SEC-01) -- an install that somehow ended up with one
// of these empty (e.g. a manual DB edit, or an old bug) would otherwise be
// locked out of login/refresh entirely after this update. A non-empty value
// already set by steps #15/#16 (or an admin, via the General tab) is left
// untouched; regenerating any of these has the same effect as an admin
// rotating them via the General tab -- every previously issued token stops
// working and a fresh login is required.
global $ilDB;

$settings = new SRAG\PegasusHelper\oauth\ApiSettings($ilDB);

if ($settings->getSalt() === '') {
    $settings->set(SRAG\PegasusHelper\oauth\ApiSettings::KEY_SALT, bin2hex(random_bytes(32)));
}
if ($settings->getApiKey() === '') {
    $settings->set(SRAG\PegasusHelper\oauth\ApiSettings::KEY_API_KEY, 'ilias_pegasus');
}
if ($settings->getApiSecret() === '') {
    $alphabet = '123456789abcdefghijklmnopqrstuvwxyz';
    $chunk = static function (int $length) use ($alphabet): string {
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $result;
    };
    $settings->set(SRAG\PegasusHelper\oauth\ApiSettings::KEY_API_SECRET, $chunk(4) . '.' . $chunk(4) . '-' . $chunk(2));
}

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #19: Repaired any missing/empty OAuth signing salt, API key or API secret.');
?>
<#20>
<?php
// Refresh-token rotation, login-family tracking and replay detection
// (SEC-03/SEC-06) -- see SRAG\PegasusHelper\oauth\RefreshTokenRepository,
// GrantFamilyRepository and TokenService::refresh(). The three new columns on
// ui_uihk_peg_refresh are nullable/defaulted, so a row minted by pre-7.3.0
// code is simply treated as "no family yet" and adopted into a fresh one the
// next time it is used to refresh (see TokenService::adoptLegacyFamily()).
global $ilDB;

if (!$ilDB->tableColumnExists('ui_uihk_peg_refresh', 'family_id')) {
    $ilDB->addTableColumn('ui_uihk_peg_refresh', 'family_id', [
        'type' => 'text',
        'length' => 32,
        'notnull' => false,
    ]);
}
if (!$ilDB->tableColumnExists('ui_uihk_peg_refresh', 'expires')) {
    $ilDB->addTableColumn('ui_uihk_peg_refresh', 'expires', [
        'type' => 'integer',
        'length' => 8,
        'notnull' => true,
        'default' => 0,
    ]);
}
if (!$ilDB->tableColumnExists('ui_uihk_peg_refresh', 'rotated_at')) {
    $ilDB->addTableColumn('ui_uihk_peg_refresh', 'rotated_at', [
        'type' => 'integer',
        'length' => 8,
        'notnull' => true,
        'default' => 0,
    ]);
}
if (!$ilDB->indexExistsByFields('ui_uihk_peg_refresh', ['family_id'])) {
    $ilDB->addIndex('ui_uihk_peg_refresh', ['family_id'], 'i2');
}
if (!$ilDB->indexExistsByFields('ui_uihk_peg_refresh', ['expires'])) {
    $ilDB->addIndex('ui_uihk_peg_refresh', ['expires'], 'i3');
}
if (!$ilDB->indexExistsByFields('ui_uihk_peg_refresh', ['user_id'])) {
    $ilDB->addIndex('ui_uihk_peg_refresh', ['user_id'], 'i4');
}

if (!$ilDB->tableExists('ui_uihk_peg_family')) {
    $fields = [
        'family_id' => [
            'type' => 'text',
            'length' => 32,
            'fixed' => true,
            'notnull' => true,
        ],
        'user_id' => [
            'type' => 'integer',
            'length' => 4,
            'notnull' => true,
        ],
        'auth_time' => [
            'type' => 'integer',
            'length' => 8,
            'notnull' => true,
        ],
        'created' => [
            'type' => 'integer',
            'length' => 8,
            'notnull' => true,
        ],
        'last_used' => [
            'type' => 'integer',
            'length' => 8,
            'notnull' => true,
        ],
        'revoked' => [
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0,
        ],
    ];
    $ilDB->createTable('ui_uihk_peg_family', $fields);
    $ilDB->addPrimaryKey('ui_uihk_peg_family', ['family_id']);
    $ilDB->addIndex('ui_uihk_peg_family', ['user_id'], 'i1');
}

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #20: Added refresh-token rotation columns and created ui_uihk_peg_family.');
?>
<#21>
<?php
// SSO auth-tokens now carry the login (grant) they were minted from, so a
// redeemed token can be rejected if its login was revoked after issuance but
// before redemption (SEC-02), and the token itself is stored only as a hash,
// never raw (SEC-05) -- see SRAG\PegasusHelper\authentication\AuthTokenRepository.
// Existing rows predate both changes and live at most 60 seconds in the first
// place, so they are simply cleared rather than migrated.
global $ilDB;

if (!$ilDB->tableColumnExists('ui_uihk_peg_token', 'auth_time')) {
    $ilDB->addTableColumn('ui_uihk_peg_token', 'auth_time', [
        'type' => 'integer',
        'length' => 8,
        'notnull' => true,
        'default' => 0,
    ]);
}
if (!$ilDB->tableColumnExists('ui_uihk_peg_token', 'family_id')) {
    $ilDB->addTableColumn('ui_uihk_peg_token', 'family_id', [
        'type' => 'text',
        'length' => 32,
        'notnull' => false,
    ]);
}
if (!$ilDB->indexExistsByFields('ui_uihk_peg_token', ['expires'])) {
    $ilDB->addIndex('ui_uihk_peg_token', ['expires'], 'i2');
}

$ilDB->manipulate('DELETE FROM ui_uihk_peg_token');

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #21: Added grant columns to ui_uihk_peg_token; cleared outstanding pre-7.3.0 SSO tokens (max 60s old).');
?>
<#22>
<?php
// Widens revoked_before from a 32-bit to a 64-bit integer: the previous width
// would silently overflow (wrapping into the past, making the cutoff
// ineffective) in January 2038.
global $ilDB;

$ilDB->modifyTableColumn('ui_uihk_peg_revoke', 'revoked_before', [
    'type' => 'integer',
    'length' => 8,
    'notnull' => true,
]);

global $ilLog;
$ilLog->write('Plugin PegasusHelper -> DB-Update #22: Widened ui_uihk_peg_revoke.revoked_before to a 64-bit integer.');
?>
