# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

PegasusHelper is an ILIAS UserInterfaceHook plugin that integrates ILIAS with the Pegasus mobile application (developed by DEEP-eAcademy). It extends ILIAS with custom login flows, file download capabilities, and REST API integration for mobile clients.

**Target ILIAS versions:** 10.x  
**PHP version:** 8.2+  
**Current version:** 6.0.0

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
- **Lifecycle hooks:** beforeUpdate() and beforeUninstall() in the main plugin class enforce prerequisites (REST plugin must be installed)

### Chain of Responsibility Pattern

The plugin uses the **Chain of Responsibility** design pattern to handle incoming API requests. This replaces large switch statements with modular handler chains:

- **BaseHandler** (`classes/handler/BaseHandler.php`) — abstract base providing chain linking via `add()` and `next()` methods
- **ChainRequestHandler** interface — marks chain participants
- **Handler implementations:** Located under `classes/handler/*/`. Each handler interface has exactly one concrete implementation now, registered in `Ilias6RequestHandlerProvider`:
  - `OAuthManager` → `OAuthManager/v52/OauthManagerImpl`
  - `ResourceLinkHandler` → `ResourceLinkHandler/v53/ResourceLinkHandlerImpl`
  - `RefLinkRedirectHandler` → `RefLinkRedirectHandler/v54/RefLinkRedirectHandlerImpl`
  - `NewsLinkRedirectHandler` → `NewsLinkRedirectHandler/v6/NewsLinkRedirectHandlerImpl`
  - `LoginPageManager` → `LoginPageManager/v52/LoginPageManagerImpl`
  - `ExcludedHandler` → `ExcludedHandler/v52/ExcludedHandlerImpl`

  The `v52`/`v53`/`v54`/`v6` folder names are historical leftovers from when the plugin supported ILIAS 5.2 through 6 side by side and picked an implementation per version at runtime. Since the plugin only targets ILIAS 10 now, they're just labels on the last surviving implementation per handler — don't read them as an ILIAS-10 compatibility claim, and don't add new version-suffixed folders for future ILIAS bumps unless you actually need two implementations to coexist again.

Each handler decides: "Can I handle this?" If yes, process. If no, call `next()` to pass to the next handler in the chain.

### Dependency Injection Container

The plugin uses a custom DI container for service provisioning:

- **PegasusHelperContainer** (`classes/container/PegasusHelperContainer.php`) — bootstrapped in `bootstrap.php` during plugin initialization
- **Service Providers:**
  - `AuthenticationProvider` — registers authentication services (token management)
  - `Ilias6RequestHandlerProvider` — registers the handler chain (the only provider still wired up; the historical `Ilias53RequestHandlerProvider`/`Ilias54RequestHandlerProvider` were dead code and have been removed)

The container validates ILIAS version >= 9.0 at bootstrap time and throws `DependencyResolutionException` if requirements aren't met. (The plugin manifest itself now restricts installation to ILIAS 10.x via `$ilias_min_version`/`$ilias_max_version` in `plugin.php`.)

### REST Integration

- **RestSetup** (`classes/rest/RestSetup.php`) — configures the REST Plugin during migration/installation:
  - Auto-creates an "ilias_pegasus" API client with OAuth credentials if missing
  - Removes the client on uninstall
  - **Caveat:** HTTP→HTTPS redirects convert POST to GET, breaking REST client setup. See section below.

### Entity & Authentication

- **UserToken** (`classes/entity/UserToken.php`) — represents user authentication tokens
- **UserTokenAuthenticator** interface — token validation strategy
- **DefaultUserTokenAuthenticator** — standard ILIAS user authentication

## Important Caveats & Configuration

### HTTP Path Redirect Issue

**Problem:** If ILIAS is configured with `http://` in `ilias.ini.php` but requests are redirected to `https://`, plugin migration fails. The PegasusHelper configures the REST plugin via local HTTP POST requests, which the redirect converts to GET—breaking REST client setup.

**Solution:** Ensure `ilias.ini.php` uses the correct protocol (typically `https://`):
```ini
[server]
http_path = "https://your.ilias-installation.org"
```

### REST Plugin Dependency

- PegasusHelper **requires** the ILIAS REST Plugin to be installed and active
- The `beforeUpdate()` hook prevents plugin activation if REST Plugin is missing
- On uninstall, the plugin cleans up its REST client configuration

## Testing

The plugin includes a testing suite for validating local ILIAS configuration:

```bash
cd testing
php run.php
```

This script:
- Verifies REST Plugin is installed
- Checks database connectivity and schema
- Validates REST client configuration
- Writes detailed results to `testing/results.log`

Use this when installation fails to diagnose configuration issues.

## Key Concepts for Code Changes

1. **No hardcoded core-class paths:** Never add `require_once`/`include_once` for a core ILIAS class using a `./Services/...` or `./Modules/...` path — those directories don't exist under ILIAS 10's `public/` web root. Core classes autoload via the classmap; only plugin-local files (e.g. `class.ilPegasusTesting.php`) need explicit includes.

2. **Handler chain ordering:** When adding new handlers, consider the order they're chained in the provider. Earlier handlers match first; provide more specific handlers before generic ones.

3. **Service resolution:** Use `PegasusHelperContainer::resolve(ClassName::class)` to retrieve registered services, not direct instantiation.

4. **Configuration scope:** Plugin configuration persists in the database; defaults are set via `setupClient()` in RestSetup during migration.

5. **Autoloading:** PSR-4 autoloading is configured for the `SRAG\PegasusHelper\` namespace pointing to `classes/`. Class map entries exist for legacy ILIAS plugin classes (ilPegasusHelperConfigGUI, etc.).
