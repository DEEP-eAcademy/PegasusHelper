# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

PegasusHelper is an ILIAS UserInterfaceHook plugin that integrates ILIAS with the Pegasus mobile application (developed by DEEP-eAcademy). It extends ILIAS with custom login flows, file download capabilities, and a self-contained JSON API for the mobile client.

Since version 7.0.0, PegasusHelper is fully self-contained: it no longer depends on, configures, or calls the third-party ILIAS REST plugin (`Ilias.RESTPlugin`). Everything the app needs is served from this plugin's own `api.php`. See "The `api.php` JSON API" below.

**Target ILIAS versions:** 10.x  
**PHP version:** 8.2+  
**Current version:** 7.0.0

## Quick Commands

```bash
# Install/update dependencies
composer install --no-dev

# Run plugin tests (provides useful feedback for ILIAS configuration)
cd testing
php run.php
# Outputs: results.log with detailed diagnostics

# Access ILIAS admin panel to configure plugin
# Navigate to: Administration → System Configuration and Maintenance → Plugins
```

## Architecture Overview

### ILIAS 10 directory layout

ILIAS 10 moved the application code (`Services/`, `Modules/`) that used to live at the ILIAS installation root into `components/ILIAS/<Component>/` and made `public/` the actual web root. Concretely, on a real ILIAS 10 install:

- `ilias.ini.php` and `ilias_version.php` live at the installation root, **one level above** `public/`.
- This plugin's own directory (`public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper`) is unaffected — the `Customizing/` plugin path convention did not change.
- Core ILIAS classes (`ilPropertyFormGUI`, `ilTable2GUI`, `ilWebAccessCheckerDelivery`, etc.) are all resolved via a global composer classmap covering `./components`, so plugin code should **never** hardcode a `require_once`/`include_once` path like `./Services/Form/classes/class.ilXyz.php` — those paths no longer exist and the classes autoload anyway. If you see a fatal "failed to open stream" for a core ILIAS class, look for a stale hardcoded require path first.
- The testing suite (see below) has to resolve `ilias.ini.php`/`ilias_version.php` one directory level higher than the plugin/web root — see `getRootIliasConfig()` in `testing/auxiliaries/auxiliaries.php`.
- `ilTemplate`'s module-relative path resolution (`ilTemplate::getTemplatePath()` in ILIAS core) resolves the `$in_module` directory argument against the **installation root** (one level above `public/`), not the web root. A module path like `Customizing/global/plugins/.../PegasusHelper/classes` must be written as `public/Customizing/global/plugins/.../PegasusHelper/classes` — otherwise `ilTemplate`/`ilTable2GUI::setRowTemplate()` throws `ilTemplateException: Template '...' was not found` (or, for row templates, a confusing downstream `HTML_Template_IT` "Cannot find this block" error, since the wrong/incomplete template got loaded). This bit `ilPegasusHelperConfigGUI` and `ilPegasusTestingTableGUI`; check any future `new ilTemplate(...)` or `setRowTemplate(...)` call for the same missing `public/` prefix.
- Any plugin class file that ILIAS's ctrl-structure build tooling might `require_once` for reflection (i.e. any GUI class, since it scans the whole classmap for `@ilCtrl_IsCalledBy` docblocks) must not have side effects that require a live `$DIC` at file-scope. `bootstrap.php` is pulled in at the top of several plugin class files outside of any class body; `PegasusHelperContainer::bootstrap()` deliberately no-ops instead of throwing when `$DIC` isn't set, precisely so that this reflection pass — which runs with no request context — doesn't crash the whole installation's `ctrl_structure` artifact build. Don't reintroduce a throw there.

### Plugin Structure

The plugin follows ILIAS 10 plugin conventions as a `UserInterfaceHook` plugin:

- **Plugin entry point:** `class.ilPegasusHelperPlugin.php` — singleton that extends `ilUserInterfaceHookPlugin`
- **Configuration GUI:** `class.ilPegasusHelperConfigGUI.php` — admin configuration interface
- **UI Hook GUI:** `class.ilPegasusHelperUIHookGUI.php` — handles UI hook integration points
- **Lifecycle hooks:** `beforeUninstall()` in the main plugin class drops the plugin's own tables (`ui_uihk_pegasus_theme`/`config`/`refresh`/`token`). There is no `beforeUpdate()` prerequisite check since 7.0.0 (it used to require the ILIAS REST plugin).

### Chain of Responsibility Pattern

The plugin uses the **Chain of Responsibility** design pattern to handle incoming API requests. This replaces large switch statements with modular handler chains:

- **BaseHandler** (`classes/handler/BaseHandler.php`) — abstract base providing chain linking via `add()` and `next()` methods
- **ChainRequestHandler** interface — marks chain participants
- **Handler implementations:** Located under `classes/handler/*/`. Each handler interface has exactly one concrete implementation now, registered in `Ilias6RequestHandlerProvider`:
  - `OAuthManager` → `OAuthManager/v52/OauthManagerImpl` (mints the login token pair itself via `oauth\TokenService`, since 7.0.0)
  - `ResourceLinkHandler` → `ResourceLinkHandler/v53/ResourceLinkHandlerImpl`
  - `RefLinkRedirectHandler` → `RefLinkRedirectHandler/v54/RefLinkRedirectHandlerImpl`
  - `NewsLinkRedirectHandler` → `NewsLinkRedirectHandler/v6/NewsLinkRedirectHandlerImpl`
  - `LoginPageManager` → `LoginPageManager/v52/LoginPageManagerImpl`
  - `ExcludedHandler` → `ExcludedHandler/v52/ExcludedHandlerImpl`

  The `v52`/`v53`/`v54`/`v6` folder names are historical leftovers from when the plugin supported ILIAS 5.2 through 6 side by side and picked an implementation per version at runtime. Since the plugin only targets ILIAS 10 now, they're just labels on the last surviving implementation per handler — don't read them as an ILIAS-10 compatibility claim, and don't add new version-suffixed folders for future ILIAS bumps unless you actually need two implementations to coexist again.

Each handler decides: "Can I handle this?" If yes, process. If no, call `next()` to pass to the next handler in the chain.

### Dependency Injection Container

The plugin uses a custom DI container for service provisioning:

- **PegasusHelperContainer** (`classes/container/PegasusHelperContainer.php`) — bootstrapped in `bootstrap.php` during plugin initialization, and again (idempotently) by `api.php` right after it boots ILIAS itself
- **Service Providers:**
  - `AuthenticationProvider` — registers authentication services (`authentication\AuthTokenRepository`, the SSO one-time-token repository; `UserTokenAuthenticator`)
  - `Ilias6RequestHandlerProvider` — registers the handler chain (the only provider still wired up; the historical `Ilias53RequestHandlerProvider`/`Ilias54RequestHandlerProvider` were dead code and have been removed)
  - `ApiProvider` — registers the OAuth services (`oauth\TokenCodec`/`TokenService`/`ApiSettings`/`RefreshTokenRepository`), the request mapping helpers, and the `api\Router` route table (see below)

The container validates ILIAS version >= 9.0 at bootstrap time and throws `DependencyResolutionException` if requirements aren't met. (The plugin manifest itself now restricts installation to ILIAS 10.x via `$ilias_min_version`/`$ilias_max_version` in `plugin.php`.)

### The `api.php` JSON API

`api.php` (plugin root) is the entry point the ILIAS-Pegasus app calls, replacing the routes it used to call on the ILIAS REST plugin. It's a plain script, not a class file, specifically so ILIAS's ctrl-structure reflection pass (see above) never loads it.

- **`api\ApiKernel::run()`** — sends CORS headers, handles `OPTIONS` preflights before touching ILIAS at all, then boots ILIAS, bootstraps the container, routes the request, and turns `api\ApiException`/any other `Throwable` into a JSON error response.
- **`api\ApiInitialisation`** (`extends \ilInitialisation`, to reach its protected statics) — boots a bare ILIAS instance under `CONTEXT_REST` (no session, no web template) and loads the token's ILIAS user with `loadUser()`, without ever creating a web session. This is a port of the ILIAS REST plugin's own (production-proven) `RESTilias::initILIAS()`/`loadIlUser()`.
- **`api\Router`** — a ~60-line regex router (method + `{param}` pattern → handler), replacing the Slim framework the REST plugin used. Each route also declares `Router::AUTH_BEARER` (the default: `ApiKernel` validates the token and loads the user before the handler runs) or `Router::AUTH_NONE` (the handler authenticates itself — used by the token-refresh route and by the learning-module zip download, which authenticates via its own one-time token instead of a Bearer header).
- **`api\controller\*`** — one controller per route group (`Token`, `AuthToken`, `Object`, `File`, `Theme`, `News`, `LearningModule`), each a close port of the corresponding ILIAS REST plugin route/model.
- **`api\ObjectDataMapper`** — builds the repository-object JSON shape (`DesktopData`/`IliasTreeItem`) from reference ids.
- **`api\LearningModuleZipBuilder`** — builds/caches the offline zip for htlm/SCORM learning modules outside the public web root, served only through the RBAC-checked zip route.

### OAuth (`classes/oauth/`)

- **`TokenCodec`** — encodes/decodes the OAuth2 access/refresh token wire format **byte-compatibly** with the (now removed) ILIAS REST plugin's `core/oauth2_v2` tokens, so tokens issued before the 7.0.0 migration keep working. Don't change the wire format (field order, hash construction) without a very good reason — see its docblock.
- **`TokenService`** — issues/validates the token pair; used by both `OauthManagerImpl` (initial login) and `api\controller\TokenController` (refresh).
- **`ApiSettings`** — repository over `ui_uihk_pegasus_config` (API key/secret, signing salt, token TTLs).
- **`RefreshTokenRepository`** — repository over `ui_uihk_pegasus_refresh`; backs both refresh-token validation and the Statistics tab.

### Authentication (`classes/authentication/`)

- **`AuthTokenRepository`** — the short-lived (60s), one-time SSO auth-tokens used to open ILIAS pages/resources from the app (`ui_uihk_pegasus_token`), replacing the REST plugin's `ui_uihk_rest_token` table.
- **`UserTokenAuthenticator`** interface — token validation strategy.
- **`DefaultUserTokenAuthenticator`** — logs the user into a real ILIAS web session; used by the `goto.php`/resource-link handlers, which run inside a normal ILIAS request that already has a session. **Never** use this from inside `api.php` — that request has no session and must stay stateless; the learning-module zip route instead calls `AuthTokenRepository::consume()` directly, followed by `ApiInitialisation::loadUser()`.

### Migration (`classes/migration/RestPluginMigration.php`)

Run once from `sql/dbupdate.php` (steps #15/#16) when a site updates from a pre-7.0.0 version. If the REST plugin's tables are still present, it copies the `ilias_pegasus` client's secret, the token salt, the TTLs and any live refresh tokens; otherwise it generates fresh values, matching what the removed `classes/rest/RestSetup.php` used to do for a brand-new install.

## Testing

The plugin includes a testing suite for validating local ILIAS configuration:

```bash
cd testing
php run.php
```

This script:
- Checks database connectivity and schema
- Confirms `api.php` is reachable and rejects requests without a valid token
- Confirms the `Authorization` header actually reaches PHP (some server/proxy configs strip it)
- Warns if the (no longer required) ILIAS REST plugin is still active
- Writes detailed results to `testing/results.log`

Use this when installation fails to diagnose configuration issues.

## Key Concepts for Code Changes

1. **No hardcoded core-class paths:** Never add `require_once`/`include_once` for a core ILIAS class using a `./Services/...` or `./Modules/...` path — those directories don't exist under ILIAS 10's `public/` web root. Core classes autoload via the classmap; only plugin-local files (e.g. `class.ilPegasusTesting.php`) need explicit includes.

2. **Handler chain ordering:** When adding new handlers, consider the order they're chained in the provider. Earlier handlers match first; provide more specific handlers before generic ones.

3. **Service resolution:** Use `PegasusHelperContainer::resolve(ClassName::class)` to retrieve registered services, not direct instantiation.

4. **Configuration scope:** Plugin configuration (API key/secret, token salt/TTLs) persists in `ui_uihk_pegasus_config`, managed by `oauth\ApiSettings`; defaults are set (or migrated from the REST plugin) by `migration\RestPluginMigration` during the database update.

5. **Autoloading:** PSR-4 autoloading is configured for the `SRAG\PegasusHelper\` namespace pointing to `classes/`. Class map entries exist for legacy ILIAS plugin classes (ilPegasusHelperConfigGUI, etc.).
