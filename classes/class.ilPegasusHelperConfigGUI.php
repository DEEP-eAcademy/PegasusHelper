<?php

require_once __DIR__ . '/../bootstrap.php';

/**
 * @ilCtrl_IsCalledBy ilPegasusHelperConfigGUI: ilObjComponentSettingsGUI
 *
 * Implements ilCtrlSecurityInterface so every state-changing command below is
 * CSRF-protected and (via {@see requireMutationAllowed()}) rejected outright
 * over GET: ilObjComponentSettingsGUI::executeCommand() only ever checks
 * 'read' access before forwarding here, and ILIAS's ilCtrl treats a
 * cmd=<name> given directly in the URL as "safe" (no token required, no HTTP
 * method enforced) for any class that does NOT implement this interface --
 * see getUnsafeGetCommands()/getSafePostCommands() below (SEC-04). After
 * deploying this change, rebuild ILIAS's ctrl-structure artifacts
 * (`php cli/setup.php build`) so the new security lists take effect; the
 * runtime method/permission checks in requireMutationAllowed() protect these
 * commands regardless.
 */
final class ilPegasusHelperConfigGUI extends ilPluginConfigGUI implements ilCtrlSecurityInterface
{

    /**
     * @var \ilPegasusHelperPlugin
     */
    public $pl;

    public static $ICON_CATEGORIES = [
        "course",
        "folder",
        "group",
        "file",
        "learningplace",
        "learningmodule",
        "link"
    ];
    public static $ICON_WEB_DIR = "pegasushelper/theme/icons/";

    /**
     * Every command that changes state. Read by ILIAS's ctrl-structure
     * tooling via reflection with the object constructed *without* its
     * constructor ever running (see ilCtrlSecurityArtifactObjective), so this
     * must stay a pure constant -- never touch $this or any instance state.
     */
    private const MUTATING_COMMANDS = [
        "theme_save_colors",
        "theme_reset_colors",
        "theme_save_icons",
        "theme_reset_icons",
        "general_save_secret",
        "general_revoke_all",
        "general_revoke_user",
        "general_rotate_salt",
    ];

    /**
     * @inheritDoc
     */
    public function getUnsafeGetCommands(): array
    {
        return self::MUTATING_COMMANDS;
    }

    /**
     * @inheritDoc
     */
    public function getSafePostCommands(): array
    {
        return [];
    }

    /**
     * copies the default icons to the directory for the synchronization with the app
     */
    public static function copyDefaultIcons()
    {
        global $DIC;

        $customizingSourceDir = "global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/templates/icons/";
        foreach (ilPegasusHelperConfigGUI::$ICON_CATEGORIES as $category) {
            $fileName = "icon_$category.svg";
            $fsWeb = $DIC->filesystem()->web();
            $fsCustomizing = $DIC->filesystem()->customizing();
            if ($fsWeb->has(ilPegasusHelperConfigGUI::$ICON_WEB_DIR . $fileName)) {
                $fsWeb->delete(ilPegasusHelperConfigGUI::$ICON_WEB_DIR . $fileName);
            }
            $fileStream = $fsCustomizing->readStream($customizingSourceDir . $fileName);
            $fsWeb->writeStream(ilPegasusHelperConfigGUI::$ICON_WEB_DIR . $fileName, $fileStream);
        }
    }

    /**
     * @return \SRAG\PegasusHelper\audit\AuditLog
     */
    private function audit(): \SRAG\PegasusHelper\audit\AuditLog
    {
        return \SRAG\PegasusHelper\container\PegasusHelperContainer::resolve(\SRAG\PegasusHelper\audit\AuditLog::class);
    }

    /**
     * @return \SRAG\PegasusHelper\oauth\TokenService
     */
    private function tokenService(): \SRAG\PegasusHelper\oauth\TokenService
    {
        return \SRAG\PegasusHelper\container\PegasusHelperContainer::resolve(\SRAG\PegasusHelper\oauth\TokenService::class);
    }

    /**
     * invoked by parent
     * @param $cmd string
     */
    public function performCommand($cmd): void
    {
        global $ilTabs, $ilCtrl, $tpl;
        $this->pl = $this->getPluginObject();

        $ilTabs->addSubTab(
            "id_general",
            $this->pl->txt("tab_general"),
            $ilCtrl->getLinkTarget($this, "general")
        );
        $ilTabs->addSubTab(
            "id_theme",
            $this->pl->txt("tab_app_theme"),
            $ilCtrl->getLinkTarget($this, "theme")
        );
        $ilTabs->addSubTab(
            "id_statistics",
            $this->pl->txt("tab_statistics"),
            $ilCtrl->getLinkTarget($this, "statistics")
        );
        $ilTabs->addSubTab(
            "id_testing",
            $this->pl->txt("tab_testing"),
            $ilCtrl->getLinkTarget($this, "testing")
        );

        // ilObjComponentSettingsGUI::executeCommand() only checks 'read'
        // access before forwarding to this class -- enforce 'write' ourselves
        // for every state-changing command, and refuse it outright unless it
        // arrived as an actual POST (SEC-04). This runs regardless of whether
        // ILIAS's own ctrl-structure artifacts have been rebuilt yet.
        if (in_array($cmd, self::MUTATING_COMMANDS, true) && !$this->requireMutationAllowed($cmd)) {
            return;
        }

        switch ($cmd) {
            case "testing":
                $ilTabs->setSubTabActive("id_testing");
                $tpl->setContent($this->getTestsTableHtml());
                break;
            case "theme":
                $ilTabs->setSubTabActive("id_theme");
                $tpl->setContent($this->getColorFormHtml() . $this->getIconsForm()->getHTML());
                break;
            case "statistics":
                $ilTabs->setSubTabActive("id_statistics");
                $tpl->setContent($this->getTokenStatisticsHtml());
                break;
            case "theme_save_colors":
                $this->saveColors();
                break;
            case "theme_reset_colors":
                $this->resetColors();
                break;
            case "theme_save_icons":
                $this->saveIcons();
                break;
            case "theme_reset_icons":
                $this->resetIcons();
                break;
            case "general_save_secret":
                $this->saveApiSecret();
                break;
            case "general_revoke_all":
                $this->revokeAllTokens();
                break;
            case "general_revoke_user":
                $this->revokeUserTokens();
                break;
            case "general_rotate_salt":
                $this->rotateSigningSalt();
                break;
            case "general":
            default:
                $ilTabs->setSubTabActive("id_general");
                $tpl->setContent(
                    $this->getApiSecretFormHtml()
                    . $this->getRevokeUserFormHtml()
                    . $this->getRevokeAllFormHtml()
                    . $this->getRotateSaltFormHtml()
                    . $this->getAuditStatusFormHtml()
                );
                break;
        }
    }

    /**
     * Guards every command in {@see MUTATING_COMMANDS}: it must be an actual
     * POST request, and the acting user must have 'write' access on the
     * plugins administration node (see class docblock, SEC-04). On failure,
     * sends the given HTTP status, audits the rejection, and renders the
     * current tab's normal (unchanged) content in its place.
     *
     * @return bool true if the command may proceed
     */
    private function requireMutationAllowed(string $cmd): bool
    {
        if (!$this->isPostRequest()) {
            $this->rejectMutation($cmd, 405, 'method_not_allowed', $this->pl->txt("msg_method_not_allowed"));

            return false;
        }
        if (!$this->hasWriteAccess()) {
            $this->rejectMutation($cmd, 403, 'forbidden', $this->pl->txt("msg_permission_denied"));

            return false;
        }

        return true;
    }

    private function isPostRequest(): bool
    {
        global $DIC;
        if (isset($DIC) && is_object($DIC) && $DIC->offsetExists('http')) {
            return strtoupper($DIC->http()->request()->getMethod()) === 'POST';
        }

        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * ilObjComponentSettingsGUI itself only requires 'read' access before
     * forwarding to any plugin's config GUI (including this one), so a role
     * with only view access to the Plugins administration screen could
     * otherwise still reach and execute a mutating command here.
     */
    private function hasWriteAccess(): bool
    {
        global $DIC;

        try {
            // _getObjectsByType() returns rows keyed by obj_id (not a plain
            // 0-indexed list) -- array_key_first() is the obj_id itself.
            $objIds = \ilObject::_getObjectsByType('cmps');
            if ($objIds === []) {
                return false;
            }
            $objId = array_key_first($objIds);

            $refIds = \ilObject::_getAllReferences((int) $objId);
            if ($refIds === []) {
                return false;
            }
            $refId = (int) reset($refIds);

            return (bool) $DIC->rbac()->system()->checkAccess('write', $refId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function rejectMutation(string $cmd, int $status, string $reason, string $message): void
    {
        global $ilTabs, $tpl;

        http_response_code($status);

        $this->audit()->log(
            \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_REQUEST_REJECTED,
            \SRAG\PegasusHelper\audit\AuditLog::LEVEL_WARNING,
            ['cmd' => $cmd, 'reason' => $reason]
        );

        $tpl->setOnScreenMessage('failure', $message, false);

        if (strpos($cmd, 'theme_') === 0) {
            $ilTabs->setSubTabActive("id_theme");
            $tpl->setContent($this->getColorFormHtml() . $this->getIconsForm()->getHTML());

            return;
        }

        $ilTabs->setSubTabActive("id_general");
        $tpl->setContent(
            $this->getApiSecretFormHtml()
            . $this->getRevokeUserFormHtml()
            . $this->getRevokeAllFormHtml()
            . $this->getRotateSaltFormHtml()
            . $this->getAuditStatusFormHtml()
        );
    }

    /**
     * Reads a trimmed string field from the POST body via ILIAS's HTTP
     * wrapper/refinery, rather than raw `$_POST` (which is neither validated
     * nor guaranteed to be a string, e.g. for a null or array value). Falls
     * back to $default on any transformation failure too (not just a missing
     * key), so a malformed value degrades to the same "not saved" validation
     * message the caller already shows for an empty/out-of-range one, instead
     * of an uncaught refinery exception.
     */
    private function postString(string $key, string $default = ''): string
    {
        global $DIC;
        $wrapper = $DIC->http()->wrapper()->post();
        if (!$wrapper->has($key)) {
            return $default;
        }
        try {
            return trim($wrapper->retrieve($key, $DIC->refinery()->kindlyTo()->string()));
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function postInt(string $key, int $default = 0): int
    {
        global $DIC;
        $wrapper = $DIC->http()->wrapper()->post();
        if (!$wrapper->has($key)) {
            return $default;
        }
        try {
            return $wrapper->retrieve($key, $DIC->refinery()->kindlyTo()->int());
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * A checkbox submits as a non-empty string when checked and is simply
     * absent from the body when unchecked.
     */
    private function postBool(string $key): bool
    {
        global $DIC;
        $wrapper = $DIC->http()->wrapper()->post();
        if (!$wrapper->has($key)) {
            return false;
        }
        try {
            $value = $wrapper->retrieve($key, $DIC->refinery()->kindlyTo()->string());
        } catch (\Throwable $e) {
            return false;
        }

        return $value !== '' && $value !== '0';
    }

    /**
     * html of form for the API endpoint and the ilias_pegasus API secret
     * @return string
     */
    protected function getApiSecretFormHtml()
    {
        global $ilDB, $ilCtrl;

        $settings = new \SRAG\PegasusHelper\oauth\ApiSettings($ilDB);

        $form = new ilPropertyFormGUI();
        $form->setTitle($this->pl->txt("form_api_secret"));
        $form->setFormAction($ilCtrl->getFormAction($this));

        $endpoint = new ilNonEditableValueGUI($this->pl->txt("txt_api_endpoint"));
        $endpoint->setValue(
            ILIAS_HTTP_PATH . "/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/api.php"
        );
        $form->addItem($endpoint);

        $apiKey = new ilNonEditableValueGUI($this->pl->txt("txt_api_key"));
        $apiKey->setValue($settings->getApiKey());
        $form->addItem($apiKey);

        $apiSecret = new ilTextInputGUI($this->pl->txt("txt_api_secret"), "api_secret");
        $apiSecret->setInfo($this->pl->txt("txt_info_api_secret"));
        $apiSecret->setValue($settings->getApiSecret());
        $apiSecret->setRequired(true);
        $form->addItem($apiSecret);

        $accessTtl = new ilNumberInputGUI($this->pl->txt("txt_access_token_ttl"), "access_token_ttl");
        $accessTtl->setInfo($this->pl->txt("txt_info_access_token_ttl"));
        $accessTtl->setValue((string) $settings->getAccessTokenTtlMinutes());
        $accessTtl->setSize(10);
        $accessTtl->setMinValue(1);
        $accessTtl->setRequired(true);
        $form->addItem($accessTtl);

        $refreshTtl = new ilNumberInputGUI($this->pl->txt("txt_refresh_token_ttl"), "refresh_token_ttl");
        $refreshTtl->setInfo($this->pl->txt("txt_info_refresh_token_ttl"));
        $refreshTtl->setValue((string) $settings->getRefreshTokenTtlMinutes());
        $refreshTtl->setSize(10);
        $refreshTtl->setMinValue(1);
        $refreshTtl->setRequired(true);
        $form->addItem($refreshTtl);

        $maxLoginAge = new ilNumberInputGUI($this->pl->txt("txt_max_login_age_days"), "max_login_age_days");
        $maxLoginAge->setInfo($this->pl->txt("txt_info_max_login_age_days"));
        $maxLoginAge->setValue((string) $settings->getMaxLoginAgeDays());
        $maxLoginAge->setSize(10);
        $maxLoginAge->setMinValue(0);
        $maxLoginAge->setRequired(true);
        $form->addItem($maxLoginAge);

        if ($settings->hasWeakSalt()) {
            $warning = new ilNonEditableValueGUI("", "", true);
            $warning->setValue($this->pl->txt("txt_weak_salt_warning"));
            $form->addItem($warning);
        }

        $form->addCommandButton("general_save_secret", $this->pl->txt("button_save"));

        return $form->getHTML();
    }

    /**
     * saves the (admin-editable) ilias_pegasus API secret and the token TTLs
     */
    protected function saveApiSecret()
    {
        global $ilDB, $ilCtrl, $tpl;

        $secret = $this->postString("api_secret");
        $accessTtl = $this->postInt("access_token_ttl");
        $refreshTtl = $this->postInt("refresh_token_ttl");
        $maxLoginAgeDays = $this->postInt("max_login_age_days", -1);

        if ($secret === "" || $accessTtl < 1 || $refreshTtl < 1 || $maxLoginAgeDays < 0) {
            $tpl->setOnScreenMessage('failure', $this->pl->txt("msg_api_secret_not_saved"), true);
            $ilCtrl->redirect($this, "general");
            return;
        }

        $settings = new \SRAG\PegasusHelper\oauth\ApiSettings($ilDB);
        $oldSecret = $settings->getApiSecret();
        $oldAccessTtl = $settings->getAccessTokenTtlMinutes();
        $oldRefreshTtl = $settings->getRefreshTokenTtlMinutes();
        $oldMaxLoginAgeDays = $settings->getMaxLoginAgeDays();

        $settings->set(\SRAG\PegasusHelper\oauth\ApiSettings::KEY_API_SECRET, $secret);
        $settings->set(\SRAG\PegasusHelper\oauth\ApiSettings::KEY_ACCESS_TOKEN_TTL, (string) $accessTtl);
        $settings->set(\SRAG\PegasusHelper\oauth\ApiSettings::KEY_REFRESH_TOKEN_TTL, (string) $refreshTtl);
        $settings->set(\SRAG\PegasusHelper\oauth\ApiSettings::KEY_MAX_LOGIN_AGE_DAYS, (string) $maxLoginAgeDays);

        // Never log the secret itself, only whether it changed; TTLs are not
        // secret, so their old/new values are logged as a diff.
        $auditFields = ['api_secret_changed' => !hash_equals($oldSecret, $secret)];
        if ($accessTtl !== $oldAccessTtl) {
            $auditFields['access_token_ttl'] = [$oldAccessTtl, $accessTtl];
        }
        if ($refreshTtl !== $oldRefreshTtl) {
            $auditFields['refresh_token_ttl'] = [$oldRefreshTtl, $refreshTtl];
        }
        if ($maxLoginAgeDays !== $oldMaxLoginAgeDays) {
            $auditFields['max_login_age_days'] = [$oldMaxLoginAgeDays, $maxLoginAgeDays];
        }
        $this->audit()->log(
            \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_API_SETTINGS_CHANGED,
            \SRAG\PegasusHelper\audit\AuditLog::LEVEL_NOTICE,
            $auditFields
        );

        $tpl->setOnScreenMessage('success', $this->pl->txt("msg_api_secret_saved"), true);
        $ilCtrl->redirect($this, "general");
    }

    /**
     * html of the form to revoke all tokens issued to one specific user
     * @return string
     */
    protected function getRevokeUserFormHtml()
    {
        global $ilCtrl;

        $form = new ilPropertyFormGUI();
        $form->setTitle($this->pl->txt("form_revoke_user"));

        $info = new ilNonEditableValueGUI("", "", true);
        $info->setValue($this->pl->txt("txt_info_revoke_user"));
        $form->addItem($info);

        $login = new ilTextInputGUI($this->pl->txt("txt_username"), "revoke_login");
        $form->addItem($login);

        $terminateSessions = new ilCheckboxInputGUI($this->pl->txt("txt_terminate_sessions"), "terminate_sessions");
        $terminateSessions->setInfo($this->pl->txt("txt_info_terminate_sessions"));
        $terminateSessions->setChecked(true);
        $form->addItem($terminateSessions);

        $form->setFormAction($ilCtrl->getFormAction($this));
        $form->addCommandButton("general_revoke_user", $this->pl->txt("button_revoke"));

        return $form->getHTML();
    }

    /**
     * revokes every app token (access + refresh), every SSO auth-token and
     * every login family already issued to one user, by login; optionally
     * also terminates that user's ILIAS web sessions (SEC-02).
     */
    protected function revokeUserTokens()
    {
        global $ilCtrl, $tpl;

        $login = $this->postString("revoke_login");
        $terminateSessions = $this->postBool("terminate_sessions");
        $userId = $login !== "" ? \ilObjUser::_lookupId($login) : 0;

        if (!$userId) {
            $this->audit()->log(
                \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_TOKENS_REVOKED,
                \SRAG\PegasusHelper\audit\AuditLog::LEVEL_WARNING,
                ['scope' => 'user', 'target_login' => $login, 'outcome' => 'user_not_found']
            );
            $tpl->setOnScreenMessage('failure', $this->pl->txt("msg_revoke_user_not_found"), true);
            $ilCtrl->redirect($this, "general");
            return;
        }

        $this->tokenService()->revokeUser((int) $userId);

        if ($terminateSessions) {
            \ilSession::_destroyByUserId((int) $userId);
        }

        $this->audit()->log(
            \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_TOKENS_REVOKED,
            \SRAG\PegasusHelper\audit\AuditLog::LEVEL_NOTICE,
            [
                'scope' => 'user',
                'target_user_id' => (int) $userId,
                'target_login' => $login,
                'sessions_terminated' => $terminateSessions,
            ]
        );

        $tpl->setOnScreenMessage('success', $this->pl->txt("msg_revoke_user_done"), true);
        $ilCtrl->redirect($this, "general");
    }

    /**
     * html of the form to revoke every app token for every user
     * @return string
     */
    protected function getRevokeAllFormHtml()
    {
        global $ilCtrl;

        $form = new ilPropertyFormGUI();
        $form->setTitle($this->pl->txt("form_revoke_all"));

        $info = new ilNonEditableValueGUI("", "", true);
        $info->setValue($this->pl->txt("txt_info_revoke_all"));
        $form->addItem($info);

        $confirm = new ilTextInputGUI($this->pl->txt("txt_type_to_confirm_pre") . " REVOKE " . $this->pl->txt("txt_type_to_confirm_post"), "confirm_revoke_all");
        $form->addItem($confirm);

        $form->setFormAction($ilCtrl->getFormAction($this));
        $form->addCommandButton("general_revoke_all", $this->pl->txt("button_revoke_all"));

        return $form->getHTML();
    }

    /**
     * revokes every app token, SSO auth-token and login family already issued
     * to every user
     */
    protected function revokeAllTokens()
    {
        global $ilCtrl, $tpl;

        if ($this->postString("confirm_revoke_all") !== "REVOKE") {
            $tpl->setOnScreenMessage('failure', $this->pl->txt("msg_confirm_mismatch"), true);
            $ilCtrl->redirect($this, "general");
            return;
        }

        $this->tokenService()->revokeAll();

        $this->audit()->log(
            \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_TOKENS_REVOKED,
            \SRAG\PegasusHelper\audit\AuditLog::LEVEL_NOTICE,
            ['scope' => 'all']
        );

        $tpl->setOnScreenMessage('success', $this->pl->txt("msg_revoke_all_done"), true);
        $ilCtrl->redirect($this, "general");
    }

    /**
     * html of the form to rotate the token signing salt
     * @return string
     */
    protected function getRotateSaltFormHtml()
    {
        global $ilCtrl;

        $form = new ilPropertyFormGUI();
        $form->setTitle($this->pl->txt("form_rotate_salt"));

        $info = new ilNonEditableValueGUI("", "", true);
        $info->setValue($this->pl->txt("txt_info_rotate_salt"));
        $form->addItem($info);

        $confirm = new ilTextInputGUI($this->pl->txt("txt_type_to_confirm_pre") . " ROTATE " . $this->pl->txt("txt_type_to_confirm_post"), "confirm_rotate_salt");
        $form->addItem($confirm);

        $form->setFormAction($ilCtrl->getFormAction($this));
        $form->addCommandButton("general_rotate_salt", $this->pl->txt("button_rotate_salt"));

        return $form->getHTML();
    }

    /**
     * rotates the token signing salt, which instantly invalidates every
     * already-issued token (its signature no longer matches), and -- since
     * this is meant as the key-compromise response -- also revokes every
     * outstanding SSO auth-token and login family, exactly like "Revoke all"
     * (SEC-02).
     */
    protected function rotateSigningSalt()
    {
        global $ilDB, $ilCtrl, $tpl;

        if ($this->postString("confirm_rotate_salt") !== "ROTATE") {
            $tpl->setOnScreenMessage('failure', $this->pl->txt("msg_confirm_mismatch"), true);
            $ilCtrl->redirect($this, "general");
            return;
        }

        $settings = new \SRAG\PegasusHelper\oauth\ApiSettings($ilDB);
        $settings->set(\SRAG\PegasusHelper\oauth\ApiSettings::KEY_SALT, bin2hex(random_bytes(32)));

        $this->tokenService()->revokeAll();

        $this->audit()->log(
            \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_SALT_ROTATED,
            \SRAG\PegasusHelper\audit\AuditLog::LEVEL_NOTICE
        );

        $tpl->setOnScreenMessage('success', $this->pl->txt("msg_rotate_salt_done"), true);
        $ilCtrl->redirect($this, "general");
    }

    /**
     * html of a read-only status block showing whether (and how much of) the
     * audit trail is currently being written -- see \SRAG\PegasusHelper\audit\AuditLog
     * @return string
     */
    protected function getAuditStatusFormHtml()
    {
        $status = $this->audit()->status();

        $form = new ilPropertyFormGUI();
        $form->setTitle($this->pl->txt("form_audit_logging"));

        $channel = new ilNonEditableValueGUI($this->pl->txt("txt_audit_channel"));
        $channel->setInfo($this->pl->txt("txt_info_audit_channel"));
        $channel->setValue(\SRAG\PegasusHelper\audit\AuditLog::CHANNEL);
        $form->addItem($channel);

        $state = new ilNonEditableValueGUI($this->pl->txt("txt_audit_state"));
        if (!($status['available'] ?? false)) {
            $state->setValue($this->pl->txt("txt_audit_state_unavailable"));
        } elseif (($status['logging_enabled'] ?? false) === false) {
            $state->setValue($this->pl->txt("txt_audit_state_disabled"));
        } else {
            $tierKeys = [
                'INFO' => "txt_audit_state_all",
                'NOTICE' => "txt_audit_state_notice",
                'WARNING' => "txt_audit_state_warning",
                'ERROR' => "txt_audit_state_error",
            ];
            $tierKey = $tierKeys[$status['lowest_level_written'] ?? ''] ?? "txt_audit_state_unavailable";
            $state->setValue($this->pl->txt($tierKey));
        }
        $form->addItem($state);

        if (!empty($status['log_dir']) || !empty($status['log_file'])) {
            $path = new ilNonEditableValueGUI($this->pl->txt("txt_audit_log_file"));
            $path->setValue(
                rtrim((string) ($status['log_dir'] ?? ''), '/') . '/' . ltrim((string) ($status['log_file'] ?? ''), '/')
            );
            $form->addItem($path);
        }

        if (!empty($status['cache_enabled'])) {
            $warning = new ilNonEditableValueGUI("", "", true);
            $warning->setValue($this->pl->txt("txt_audit_cache_warning"));
            $form->addItem($warning);
        }

        return $form->getHTML();
    }

    /**
     * html with token statistics
     * @return string
     */
    protected function getTokenStatisticsHtml()
    {
        global $ilDB;
        $refreshTokens = new \SRAG\PegasusHelper\oauth\RefreshTokenRepository($ilDB);

        $formTokensStatistics = new ilPropertyFormGUI();
        $formTokensStatistics->setTitle($this->pl->txt("form_token_statistics"));

        // get number of accesses for different durations
        $differences = [
            $this->pl->txt("txt_month") => 30,
            $this->pl->txt("txt_quarter") => 90,
            $this->pl->txt("txt_semester") => 180
        ];

        foreach ($differences as $label => $dd) {
            $gui = new ilNonEditableValueGUI($label);
            $gui->setValue($refreshTokens->countCreatedWithinDays($dd));
            $formTokensStatistics->addItem($gui);
        }

        return $formTokensStatistics->getHTML();
    }

    /**
     * form for dynamic coloring
     * @return string
     */
    protected function getColorFormHtml()
    {
        global $ilDB, $ilCtrl;

        $form = new ilPropertyFormGUI();
        $form->setTitle($this->pl->txt("form_coloring"));
        $form->setFormAction($ilCtrl->getFormAction($this));
        $form->addCommandButton("theme_reset_colors", $this->pl->txt("button_reset"));
        $form->addCommandButton("theme_save_colors", $this->pl->txt("button_save"));

        $primaryColor = "04427e";
        $contrastColor = 1;
        $sql = "SELECT * FROM ui_uihk_pegasus_theme";
        $set = $ilDB->query($sql);
        if ($set !== false) {
            while ($rec = $ilDB->fetchAssoc($set)) {
                $primaryColor = $rec["primary_color"];
                $contrastColor = $rec["contrast_color"];
            }
        }

        // preview
        $thisDir = "public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/classes";
        $tpl = new ilTemplate("tpl.theme_example.html", true, true, $thisDir);
        $tpl->setVariable('EX_TEXT', "&nbsp;ILIAS Pegasus&nbsp;");
        $tpl->setVariable('COLOR_PRIMARY', $primaryColor);
        $tpl->setVariable('COLOR_CONTRAST', $contrastColor ? "ffffff" : "000000");
        $themeExample = new ilNonEditableValueGUI($this->pl->txt("txt_preview"), "", true);
        $themeExample->setInfo($this->pl->txt("txt_info_preview"));
        $themeExample->setValue($tpl->get());
        $form->addItem($themeExample);

        // primary color
        $primaryInput = new ilColorPickerInputGUI($this->pl->txt("txt_primary_color"), "primary_color");
        $primaryInput->setInfo($this->pl->txt("txt_info_primary_color"));
        $primaryInput->setValue($primaryColor);
        $form->addItem($primaryInput);

        // contrast color
        $contrastInput = new ilRadioGroupInputGUI($this->pl->txt("txt_contrast"), "contrast_color");
        $contrastInput->setInfo($this->pl->txt("txt_info_contrast"));
        $contrastInput->addOption(new ilRadioOption($this->pl->txt("button_white"), 1));
        $contrastInput->addOption(new ilRadioOption($this->pl->txt("button_black"), 0));
        $contrastInput->setValue($contrastColor);
        $form->addItem($contrastInput);

        return $form->getHTML();
    }

    /**
     * form for icons
     * @return ilPropertyFormGUI
     * @throws ilTemplateException
     */
    public function getIconsForm()
    {
        global $ilCtrl;

        $form = new ilPropertyFormGUI();
        $form->setTitle($this->pl->txt("form_icon"));
        $form->setFormAction($ilCtrl->getFormAction($this));
        $form->addCommandButton("theme_reset_icons", $this->pl->txt("button_reset"));
        $form->addCommandButton("theme_save_icons", $this->pl->txt("button_save"));

        foreach (ilPegasusHelperConfigGUI::$ICON_CATEGORIES as $category) {
            // current item
            $thisDir = "public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/classes";
            $clientName = CLIENT_ID;
            $webDir = "data/$clientName/";
            $tpl = new ilTemplate("tpl.icon.html", true, true, $thisDir);
            $iconsDir = $webDir . ilPegasusHelperConfigGUI::$ICON_WEB_DIR;
            $tpl->setVariable("SRC", $iconsDir . "icon_$category.svg");
            $tpl->setVariable("SIZE", 45);
            $icon = new ilNonEditableValueGUI(ucfirst($category), "", true);
            $icon->setValue($tpl->get());
            $form->addItem($icon);
            // upload form
            $fileUpload = new ilFileInputGUI("", "post_icon_$category");
            $fileUpload->setSuffixes(["svg"]);
            $form->addItem($fileUpload);
        }

        return $form;
    }

    /**
     * html of legend and results for the plugin's internal self-test suite
     * @return string
     */
    protected function getTestsTableHtml()
    {
        include_once __DIR__ . "/class.ilPegasusTestingTableGUI.php";
        $tableLegend = new ilPegasusTestingTableGUI($this, "Status");
        $tableLegend->setTitle($this->pl->txt("tests_txt_legend"));
        require_once __DIR__ . "/class.ilPegasusTestingStatus.php";
        $dataLegend = [
            [
                "status" => ilPegasusTestingStatus::T_STATUS_OK,
                "test" => $this->pl->txt("tests_txt_passed"),
                "info" => ""
            ],
            [
                "status" => ilPegasusTestingStatus::T_STATUS_FAIL,
                "test" => $this->pl->txt("tests_txt_failed"),
                "info" => $this->pl->txt("tests_txt_failed_info")
            ],
            [
                "status" => ilPegasusTestingStatus::T_STATUS_WARN,
                "test" => $this->pl->txt("tests_txt_warning"),
                "info" => $this->pl->txt("tests_txt_warning_info")
            ],
            [
                "status" => ilPegasusTestingStatus::T_STATUS_INCOMPLETE,
                "test" => $this->pl->txt("tests_txt_aborted"),
                "info" => ""
            ]
        ];
        $tableLegend->setData($dataLegend);

        // table with internal tests
        $tableInt = new ilPegasusTestingTableGUI($this, $this->pl->txt("tests_txt_name"));
        $tableInt->setTitle($this->pl->txt("tests_txt_tests"));
        require_once __DIR__ . "/class.ilPegasusTesting.php";
        $tableInt->setData((new ilPegasusHelperTesting())->run());

        return $tableLegend->getHTML() . $tableInt->getHTML();
    }

    /**
     * save input from the colors form
     */
    protected function saveColors()
    {
        global $ilDB, $ilCtrl, $tpl;
        $primaryColor = $this->postString("primary_color");
        $contrastColor = $this->postInt("contrast_color");

        if (!preg_match("/^[0-9a-fA-F]{6}$/", $primaryColor)) {
            $tpl->setOnScreenMessage( 'failure', $this->pl->txt("msg_coloring_not_saved"), true);
            $ilCtrl->redirect($this, "theme");
            return;
        }

        $values = array(
            "primary_color" => array("text", $primaryColor),
            "contrast_color" => array("integer", $contrastColor)
        );
        $where = array(
            "id" => array("integer", 1)
        );
        $ilDB->update("ui_uihk_pegasus_theme", $values, $where);

        $this->updateTimestamp();

        $this->audit()->log(
            \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_THEME_CHANGED,
            \SRAG\PegasusHelper\audit\AuditLog::LEVEL_INFO,
            ['action' => 'save_colors', 'primary_color' => $primaryColor, 'contrast_color' => (int) $contrastColor]
        );

        $tpl->setOnScreenMessage( 'success', $this->pl->txt("msg_coloring_saved"), true);
        $ilCtrl->redirect($this, "theme");
    }

    /**
     * reset colors to default values
     */
    protected function resetColors()
    {
        global $ilDB, $ilCtrl, $tpl;

        $values = array(
            "primary_color" => array("text", "4a668b"),
            "contrast_color" => array("integer", 1)
        );
        $where = array(
            "id" => array("integer", 1)
        );
        $ilDB->update("ui_uihk_pegasus_theme", $values, $where);

        $this->updateTimestamp();

        $this->audit()->log(
            \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_THEME_CHANGED,
            \SRAG\PegasusHelper\audit\AuditLog::LEVEL_INFO,
            ['action' => 'reset_colors']
        );

        $tpl->setOnScreenMessage( 'success', $this->pl->txt("msg_coloring_reset"), true);
        $ilCtrl->redirect($this, "theme");
    }

    protected function saveIcons()
    {
        global $ilCtrl, $DIC, $tpl;

        $form = $this->getIconsForm();
        if ($form->checkInput()) {
            // manage upload
            if ($DIC->upload()->hasBeenProcessed() !== true) {
                if (PATH_TO_GHOSTSCRIPT !== "") {
                    $DIC->upload()->register(new ilCountPDFPagesPreProcessors());
                }
            }
            $DIC->upload()->process();

            // for each category, set the new icon
            $msgSuccess = "";
            $msgFail = "";
            $savedCategories = [];
            foreach (ilPegasusHelperConfigGUI::$ICON_CATEGORIES as $category) {
                $key = "post_icon_$category";
                $file = $form->getInput($key);

                foreach ($DIC->upload()->getResults() as $result) {
                    if ($result->getStatus()->getCode() === \ILIAS\FileUpload\DTO\ProcessingStatus::OK) {
                        $resultMatchesCategory = $file["tmp_name"] === $result->getPath();
                        if ($resultMatchesCategory) {
                            $fileName = "icon_$category.svg";
                            if ($DIC->filesystem()->web()->has(ilPegasusHelperConfigGUI::$ICON_WEB_DIR . $fileName)) {
                                $DIC->filesystem()->web()->delete(ilPegasusHelperConfigGUI::$ICON_WEB_DIR . $fileName);
                            }
                            $DIC->upload()->moveOneFileTo($result, ilPegasusHelperConfigGUI::$ICON_WEB_DIR, \ILIAS\FileUpload\Location::WEB, $fileName);
                            $msgSuccess .= $this->pl->txt("msg_icon_saved_pre") . $category . $this->pl->txt("msg_icon_saved_post");
                            $savedCategories[] = $category;
                        }
                    } else {
                        if ($result->getName()) {
                            $msgFail = $this->pl->txt("msg_icon_not_uploaded");
                        }
                    }
                }
            }
            $this->updateTimestamp();

            if ($savedCategories !== []) {
                $this->audit()->log(
                    \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_THEME_CHANGED,
                    \SRAG\PegasusHelper\audit\AuditLog::LEVEL_INFO,
                    ['action' => 'save_icons', 'categories' => $savedCategories]
                );
            }
            // user feedback
            if ($msgSuccess) {
                $tpl->setOnScreenMessage( 'success', $msgSuccess, true);
            }
            if ($msgFail) {
                $tpl->setOnScreenMessage( 'failure', $msgFail, true);
            }
        } else {
            $tpl->setOnScreenMessage( 'failure', $this->pl->txt("msg_icons_not_saved"), true);
        }

        $ilCtrl->redirect($this, "theme");
    }

    /**
     * reset icons to default values
     */
    protected function resetIcons()
    {
        global $ilCtrl, $tpl;

        ilPegasusHelperConfigGUI::copyDefaultIcons();

        $this->updateTimestamp();

        $this->audit()->log(
            \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_THEME_CHANGED,
            \SRAG\PegasusHelper\audit\AuditLog::LEVEL_INFO,
            ['action' => 'reset_icons']
        );

        $tpl->setOnScreenMessage( 'success', $this->pl->txt("msg_icons_reset"), true);
        $ilCtrl->redirect($this, "theme");
    }

    /**
     * sets the timestamp for the settings to the current time
     */
    private function updateTimestamp()
    {
        global $ilDB;

        $values = array(
            "timestamp" => array("integer", time())
        );
        $where = array(
            "id" => array("integer", 1)
        );
        $ilDB->update("ui_uihk_pegasus_theme", $values, $where);
    }
}
