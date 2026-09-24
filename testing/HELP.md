# Reference for Test-Suite

This file contains instructions for troubleshooting the installation of the PegasusHelper plugin, based on the results of the test-suite

## General Tests

### Location where script is run (General)

The test-script was not run from the correct working-directory

> 0. Follow the [instructions for running the tests in CLI](../README.md/#testing)

### Location of PegasusHelper-plugin (General)

This test fails, if the folder that should contain the PegasusHelper-plugin does not exist. The Plugin MUST be located at [YOUR_ILIAS]/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/

> 0. Follow the [installation instructions](../README.md/#installation)

## ILIAS Tests

### Version (ILIAS)

Your ILIAS-installation is not compatible with the PegasusHelper-plugin, in which case it is not possible to setup the Pegasus-App

> 0. Install a version of ILIAS that is compatible with the PegasusHelper-plugin

### Https redirects (ILIAS)

TODO

## PegasusHelper-plugin Tests

### Version (PegasusHelper)

TODO

### Compatible ILIAS-version (PegasusHelper)

TODO

### Entry in ilias-database (PegasusHelper)

TODO

### Plugin-updates in ilias (PegasusHelper)

TODO

### Ilias-database version (PegasusHelper)

TODO

### Active (PegasusHelper)

TODO

## PegasusHelper API Tests

### api.php rejects a request without a token

`GET .../PegasusHelper/api.php/v2/ilias-app/desktop` did not return HTTP 401 for
a request without an `Authorization` header. This usually means the request
never reached `api.php` at all (a wrong path, a missing rewrite rule, or a web
server blocking `PATH_INFO`).

> 0. Confirm the plugin is installed at
>    `[YOUR_ILIAS]/public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper`
> 0. Confirm your web server passes `PATH_INFO` through to PHP for `api.php`
>    (see the nginx example in the [README](../README.md))
> 0. If you have a rewrite rule from the old `.../REST/api.php/...` path, make
>    sure it points at `.../PegasusHelper/api.php/...`

### Authorization header reaches PHP

A request with a bogus (but present) `Authorization: Bearer ...` header did not
get an "invalid token" response. This usually means the web server or a proxy
in front of it strips the `Authorization` header before PHP sees it.

> 0. On Apache, confirm `mod_setenvif` is enabled (ILIAS's own `public/.htaccess`
>    already configures it: `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`)
> 0. On nginx / PHP-FPM, confirm `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`
>    (or an equivalent) is set for the `PegasusHelper/api.php` location
> 0. If ILIAS sits behind a reverse proxy, confirm it forwards the `Authorization` header

### Legacy REST plugin no longer active

The ILIAS REST plugin is still installed and active. PegasusHelper no longer
needs it (since version 7.0.0) -- see the [README](../README.md) for the
recommended rollout order.

> 0. Confirm PegasusHelper has already been updated and the migration ran successfully
>    (check the "General" tab in the plugin configuration for the API secret,
>    and the "Statistics" tab for token counts)
> 0. Add a rewrite rule from the old `.../REST/api.php/...` path to
>    `.../PegasusHelper/api.php/...`, so app builds still on the old URL keep working
> 0. Uninstall the REST plugin and remove its directory
