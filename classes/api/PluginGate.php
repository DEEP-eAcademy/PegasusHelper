<?php

namespace SRAG\PegasusHelper\api;

use Throwable;

/**
 * Class PluginGate
 *
 * Checked once per request by {@see ApiKernel}, right after ILIAS has booted
 * and before the request is routed: deactivating PegasusHelper from
 * Administration > Plugins is meant to be a usable emergency stop, but
 * `api.php` is a directly reachable script that does not otherwise consult
 * ILIAS's plugin activation state at all (unlike the UI hook, which ILIAS only
 * invokes for an active plugin). Without this, existing credentials would keep
 * working through `api.php` even while the plugin shows as deactivated (SEC-09).
 *
 * Uses the same ILIAS 10 component-repository API this plugin's own
 * `beforeUninstall()`/history already relies on
 * (`$DIC['component.repository']->hasActivatedPlugin()`/`getPluginById()`),
 * rather than re-implementing plugin-state logic here.
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class PluginGate
{
    /**
     * The plugin's own id, as declared in `plugin.php`. Not read from that
     * file at runtime to avoid yet another filesystem dependency in the
     * request's hot path; it is effectively part of this plugin's identity.
     */
    private const PLUGIN_ID = 'sragpegasushelper';

    /**
     * @throws ApiException 503 if the plugin's own active state can't be
     *         confirmed, for any reason: inactive, not installed, incompatible
     *         with the running ILIAS version, a pending update not yet run, or
     *         the component-repository service being unavailable at all. Fails
     *         closed in every case -- a component-repository lookup that
     *         throws is treated exactly like "inactive", never like "active".
     */
    public function assertActive(): void
    {
        global $DIC;

        try {
            if (!isset($DIC) || !is_object($DIC) || !$DIC->offsetExists('component.repository')) {
                throw ApiException::serviceUnavailable()->withReason('plugin_inactive');
            }

            $plugin = $DIC['component.repository']->getPluginById(self::PLUGIN_ID);
            if ($plugin === null || !$plugin->isActive()) {
                throw ApiException::serviceUnavailable()->withReason('plugin_inactive');
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ApiException::serviceUnavailable()->withReason('plugin_inactive');
        }
    }
}
