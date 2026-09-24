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

## Requirements
* Version: ILIAS 10
* PHP 8.2

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
     RewriteRule ^Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST/api\.php(/.*)?$ \
       /Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/api.php$1 [L]
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
