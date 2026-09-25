# PegasusHelper

The pegasus helper is a small helper plugin for ILIAS which is required to
operate the IACUBUS mobile application.

This is fork of an OpenSource project created by fluxlabs ag, CH-Burgdorf (https://fluxlabs.ch)

Features:

- Custom app login flow
- A self-contained JSON API for the app (login, repository objects, files, news,
  theme, learning modules) -- no external REST plugin required
- Download files from ILIAS except FileObjects
- Directly open ILIAS pages with SSO tokens
- Open personal news of the user
- Configure dynamic theme of the ILIAS-Pegasus community app
- Basic user token statistic
- Basic plugin setup tests which verify your local ILIAS configuration
- Audit logging of logins, token issuance/refresal/rejection, downloads and
  admin configuration changes, integrated with ILIAS's own logging system

## Requirements

- Version: ILIAS 10
- PHP 8.2

## Installation

Start at your ILIAS root directory

```bash
cd Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/
git clone https://github.com/DEEP-eAcademy/PegasusHelper.git
```

Update and activate the plugin in the ILIAS Plugin Administration.

```
cd <ILIAS_ROOT_PATH>
composer-install --no-dev
```

The plugin generates its own `ilias_pegasus` API key and secret on first
install. Go to the ILIAS Plugin Administration, choose the Action 'Configure'
of the PegasusHelper plugin, and open the 'General' tab to see the API
endpoint URL, the API key and the (editable) API secret. The secret must match
the one configured in the ILIAS-Pegasus app build you use.

## Testing

If the installation as described above fails, the test-script in the directory 'testing' may provide useful information

Start at your ILIAS root directory

```bash
cd Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/testing/
php run.php
```

The script prints out feedback from the tests and writes a log-file 'results.log' in 'PegasusHelper/testing/'

## Update

Start at your ILIAS root directory

```bash
cd Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper
git pull
```

Update / activate the plugin in the ILIAS Plugin Administration.

```
cd <ILIAS_ROOT_PATH>
composer-install --no-dev
```

### Upgrading from a version older than 7.0.0 (removing the REST plugin dependency)

Versions before 7.0.0 required the ILIAS REST plugin (`Ilias.RESTPlugin`) to be
installed, and used it to authenticate the app and serve its API. From 7.0.0
on, PegasusHelper serves that API itself, under
`.../UserInterfaceHook/PegasusHelper/api.php`, and no longer needs the REST
plugin at all. Follow this order so that app installations that are already
logged in stay logged in:

1. **Update PegasusHelper to 7.0.0 or later while the REST plugin is still
   installed and active.** Its database migration copies the `ilias_pegasus`
   client's secret, the token signing salt, the token lifetimes and any live
   refresh tokens out of the REST plugin's tables.
2. Check the 'General' and 'Statistics' tabs in the PegasusHelper configuration:
   the API secret shown there should match the one the REST plugin's client
   used to have, and the token counts should look familiar.
3. **Add a rewrite rule** from the old API path to the new one, so app builds
   that still call the old URL keep working during the rollout:
   - Apache (add to your ILIAS vhost or an `.htaccess`):
     ```apacheconf
     RewriteRule ^Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST/api\.php(/.*)?$ Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/api.php$1 [L]
     ```
   - nginx:
     ```nginx
     location ~ ^/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST/api\.php(/.*)?$ {
         rewrite ^ /Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/api.php$1 last;
     }
     ```
4. Once you are ready to drop the old URL entirely, update the ILIAS-Pegasus
   app to call the new URL, uninstall the REST plugin in the ILIAS Plugin
   Administration, and remove its directory.

### `PATH_INFO` and the `Authorization` header (nginx)

`api.php` reads its route from `PATH_INFO` and reads the Bearer token from the
`Authorization` request header, the same way ILIAS's own webservices do.
Apache already passes both through by default (ILIAS's `public/.htaccess`
configures `SetEnvIf Authorization`). On nginx / PHP-FPM, make sure your
`PegasusHelper/api.php` location has:

```nginx
fastcgi_split_path_info ^(.+?\.php)(/.*)$;
fastcgi_param PATH_INFO $fastcgi_path_info;
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

### Keep `testing/` out of production

`testing/` is a CLI diagnostic tool, not part of the app-facing API, and must
not be reachable over HTTP: it can write logs containing configuration
details, and `testing/external/run.php` (meant to be deployed on a _separate_
host to check reachability from outside) makes outbound requests to a
caller-supplied host, so exposing it is effectively an open SSRF endpoint.
Apache is covered by the included `testing/.htaccess` (`Require all denied`).
For nginx, add:

```nginx
location ~ ^/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/testing/ {
    deny all;
    return 403;
}
```

If you do deploy `testing/external/run.php` on a separate host, copy
`secret.php.dist` to `secret.php` there and set a random secret first --
the script refuses every request until that file exists.

## Audit logging

Every security- or audit-relevant event -- app/SSO logins, token issuance,
refresh and rejection (with a reason), file and learning-module downloads, and
admin changes to the API secret, token TTLs, revocations, the signing salt and
the app theme -- is written as a single-line, structured entry to ILIAS's own
logging system, on a dedicated channel:

```
PEGASUS_AUDIT {"event":"auth.app_login","ts":"2025-01-01T12:00:00+00:00","request_id":"a1b2c3d4e5f6a7b8","client":"default","user_id":6,"login":"jdoe","ip":"203.0.113.7","forwarded_for":null,"user_agent":"...","refresh_fp":"9f2c...","access_expires_in":3600}
```

- **Where it goes:** the normal ILIAS log file (`ilias.log`, see the `[log]`
  section of `ilias.ini.php`), channel `sragpegasushelper`. Grep and parse it:
  ```bash
  grep PEGASUS_AUDIT ilias.log | sed 's/.*PEGASUS_AUDIT //' | jq .
  ```
- **Level:** the update step that installs/updates this plugin seeds a
  `log_components` row for the channel at INFO, so entries are written even if
  the site's global log level default is higher. Adjust it per-channel under
  **Administration > System Settings and Maintenance > Logging** (listed as
  "Unknown (sragpegasushelper)"): raising it to NOTICE suppresses routine
  activity and keeps only logins, downloads, 403s and admin changes; WARNING
  keeps only suspicious/failed authentication; ERROR keeps only server errors.
  The plugin's 'General' configuration tab shows the current effective state.
- **Caching caveat:** if this ILIAS installation has log caching enabled
  (`Administration > Logging`), entries below the *cache's* level can be
  silently discarded even though the channel itself is set to write them. The
  'General' tab warns about this when it detects caching is on.
- **Never logged:** raw access/refresh/SSO tokens, the API secret, or the
  signing salt. Tokens appear only as a short, non-reversible fingerprint
  (`*_fp` fields); for refresh tokens this is a prefix of the same hash stored
  in the `ui_uihk_peg_refresh` table, so a log line can be correlated with its
  DB row without either revealing the token itself.
- **Privacy note:** entries include the client IP and User-Agent, which are
  personal data. There is no plugin-side retention/purge -- entries live as
  long as your server's own rotation policy for `ilias.log` keeps them. If you
  need indefinite retention or a searchable audit trail, configure `logrotate`
  accordingly or ship `ilias.log` to a SIEM/log aggregator.

## Versioning

We use SemVer for versioning. For the versions available, see the tags on this repository.

## License

This project is licensed under the GNU GPLv3 License - see the LICENSE.md file for details.

## Acknowledgments

[composer](https://getcomposer.org/)

## Contributing :purple_heart:

Please create pull requests :fire:

## Adjustment suggestions / bug reporting :feet:

Please [read and create issues](https://github.com/DEEP-eAcademy/PegasusHelper/issues) :kissing_heart:

