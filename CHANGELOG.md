# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [7.2.0]
### Added
- Audit logging, integrated with ILIAS's own logging system
  (`classes/audit/AuditLog.php`), on a dedicated `sragpegasushelper` channel:
  one structured `PEGASUS_AUDIT {json}` entry per `api.php` request, plus
  richer entries for app/SSO logins, token issuance/refresh/rejection (with a
  reason -- missing, malformed, expired, revoked, wrong client, ...), file and
  learning-module downloads, and every admin change on the 'General' and
  'App Theme' tabs (API secret/TTL changes, token revocations, signing-salt
  rotation, theme/icon changes, running the external tests). Entries carry the
  client IP, User-Agent and a short, non-reversible token fingerprint, and
  never the token/secret/salt itself. The database update step seeds the
  channel's log level at INFO so entries are written regardless of the site's
  global default; the plugin's 'General' tab shows the channel's current
  effective state
### Changed
- `AuthTokenRepository::consume()` now returns which of three outcomes
  occurred (consumed / unknown / expired) instead of a bare bool, so a failed
  SSO login can be audited with a reason instead of a generic failure
### Added
- Token revocation: an admin can invalidate every access/refresh token already
  issued to one user, or to everyone at once, from the plugin's 'General' tab
  (`oauth\RevocationRepository`) -- access tokens were previously stateless and
  had no way to be individually killed before they expired
- A "rotate signing salt" admin action, which instantly and permanently
  invalidates every token ever issued, for when the salt itself may have leaked
- Editable access/refresh token TTLs on the 'General' tab, so an admin can
  shorten them without direct database access
### Changed
- Much shorter default token TTLs for brand-new installs (1 hour / 90 days,
  down from the REST plugin's inherited ~6.8 / ~8.6 years) in
  `migration\RestPluginMigration`; installs migrated from the REST plugin keep
  its actual TTL values unless changed via the new 'General' tab fields
- `testing/external/run.php` now requires a shared secret (`secret.php`, not
  committed; see `secret.php.dist`) and rejects targets that resolve to a
  private/loopback/reserved address; previously any caller could make the
  server issue arbitrary outbound GET requests (SSRF) via `?host=`
- `Access-Control-Allow-Origin` (in `api.php` and the resource-link handler) is
  now restricted to the app's actual WebView origins instead of `*`
### Added (deployment)
- `testing/.htaccess` denies all HTTP access to the `testing/` tree (a CLI
  diagnostic tool that should never be web-reachable); see the README for the
  nginx equivalent
### Fixed
- **`TokenService::issuePair()` hashed the raw, still-urlencoded refresh token
  for storage, while `refresh()` hashed the normalised (decoded) form for
  lookup.** Serializing a token urlencodes its base64 output, which routinely
  contains `+`, `/` or `=` and thus gets percent-escaped; whenever that
  happened, the very first refresh attempt for a brand-new login would fail
  unpredictably with "Refresh token has been revoked". Found while adding the
  revocation tests above; not related to the REST-plugin merge itself, but a
  real, silent, order-of-magnitude-more-likely-than-not bug in the code this
  release also modifies.
- `$_GET['target']` is now normalised to a string before any handler runs
  (`ExcludedHandlerImpl`); a request like `?target[]=x` previously threw an
  uncaught `TypeError` deep inside whichever redirect handler ran next
- `GET /v2/ilias-app/objects/{refId}?recursive=1` now caps the returned subtree
  at 5000 nodes instead of walking (and `checkAccess()`-ing) an unbounded one

## [7.0.0]
### Added
- Self-contained JSON API at `api.php`, serving every route the ILIAS-Pegasus
  app needs (login token issuing/refresh, repository objects, files, news,
  theme, learning modules) -- the plugin no longer depends on, configures, or
  calls the third-party ILIAS REST plugin
- Database migration that copies the `ilias_pegasus` API secret, token signing
  salt, token lifetimes and live refresh tokens out of the REST plugin's
  tables on update, so app installations that are already logged in stay
  logged in
- Editable API secret and API endpoint URL shown on the plugin's 'General'
  configuration tab
### Changed
- The app now calls `.../UserInterfaceHook/PegasusHelper/api.php` instead of
  `.../UserInterfaceHook/REST/api.php`; see the README for the recommended
  upgrade order and a rewrite-rule example to keep older app builds working
- `GET /v2/ilias-app/auth-token` now returns a proper JSON object (`{"token":
  "..."}`) instead of a JSON-encoded string containing JSON
### Removed
- `beforeUpdate()` no longer requires the ILIAS REST plugin to be installed
- `classes/rest/*` (`RestSetup`, `TokenParam`, `RouteParam`, `TokenType`) and
  the `entity\UserToken` ActiveRecord model, replaced by
  `oauth\TokenService`/`TokenCodec` and `authentication\AuthTokenRepository`
### Fixed
- SSO auth-tokens (used to open ILIAS pages/resources from the app) are now
  drawn from a CSPRNG and several can be valid per user at once, instead of the
  REST plugin's predictable, single-token-per-user scheme
- Refresh tokens, the token signing salt and the API secret are now compared
  with `hash_equals()`

## [6.0.0]
### Added
- ILIAS 10 support
### Removed
- Compatibility code for ILIAS versions older than 10 (dead ILIAS 5.3/5.4-era provider and handler classes that were no longer wired up)
### Fixed
- Removed hardcoded `require_once`/`include_once` paths to core ILIAS classes (`ilColorPickerInputGUI`, `ilRadioGroupInputGUI`, `ilRadioOption`, `ilTable2GUI`, `ilWebAccessCheckerDelivery`) that broke under ILIAS 10's reorganized directory structure (`Services`/`Modules` moved into `components/ILIAS/*`); these classes are autoloaded already
- Testing suite now resolves `ilias.ini.php` and the ILIAS version file from the installation root, which in ILIAS 10 sits one level above the `public/` web root
- Missing `ilUtil` import in the reference-link timeline redirect handler
- `PegasusHelperContainer::bootstrap()` no longer throws when `$DIC` isn't available; ILIAS 10's ctrl-structure build tooling `require_once`s plugin GUI class files outside of a real request, which crashed on the old unconditional bootstrap and silently broke the ctrl_structure artifact for the whole installation
- Template/row-template module paths in `ilPegasusHelperConfigGUI` and `ilPegasusTestingTableGUI` now include the `public/` prefix; ILIAS 10's `ilTemplate::getTemplatePath()` resolves module-relative template dirs against the installation root (above `public/`), not the web root

## [5.0.0]
### Added
- ILIAS 9 support
- PHP 8.2 requirement
### Removed
- Compatibility code for ILIAS versions older than 9
- PHP 7 support
### Fixed
- Replaced deprecated input sanitizing to avoid PHP 8.2 warnings
- Testing suite no longer calls empty URLs when no external backend is configured

## [4.0.0]
### Added
- ILIAS 8 Support
### Removed
- ILIAS 7 and 6 Support

## [3.0.0]
### Added
- ILIAS 7 Support

## [2.0.0]
### Fixed
- News link redirects are now working correctly on ILIAS 6
- Timeline links no longer result in a 404 error on ILIAS 6

### Deprecated
- PHP 5.x support
- ILIAS 5.3 support

### Removed
- ILIAS 5.2 support

## [1.1.5]
### Fixed
- Plugin max ILIAS version constraint

## [1.1.4]
### Added
- added token statistics (number of logins for past 30 90 180 days)
- configuration of REST client without requests to API

## [1.1.3]
### Added
- app theme icons

## [1.1.2]
### Fixed
- addition of table 'ui_uihk_pegasus_theme' 

## [1.1.1]
### Added
- external testing
### Changed
- modified feedback to user for failed tests (even if test fails, setup may still be ok)
### Fixed
- checking for presence of mysqli implementation
- installing and uninstalling the plugin

## [1.1.0]
### Added
- app theme coloring

## [1.0.2]
### Added
- testing for REST- and PegasusHelper-Plugins
- support for ILIAS 5.4

## [1.0.1]
### Fixed
- White page while opening ILIAS website with an auth token 

## [1.0.0]
### Added
- Changelog
- Download of documents with authentication token.
- Open personal news feed with authentication token.
### Changed
- Internal refactoring to speed up future releases.
### Fixed
- Fixed a bug which could lead to an access denied error with a valid authentication token.

## [0.0.11]
### Added
- Login
- Open links in ILIAS
- Open time line of a course in ILIAS


## [Unreleased]
### Added
### Changed
### Deprecated
### Removed
### Fixed
### Security
