# PegasusHelper
The pegasus helper is a small helper plugin for ILIAS which is required to
operate the IACUBUS mobile application.

This is fork of an OpenSource project created by fluxlabs ag, CH-Burgdorf (https://fluxlabs.ch)

Features:
- Custom app login flow
- Download files from ILIAS except FileObjects
- Directly open ILIAS pages with SSO tokens
- Open personal news of the user
- Configure required REST plugin routes and client
- Configure dynamic theme of the ILIAS-Pegasus community app
- Basic user token statistic
- Basic plugin setup tests which verify your local ILIAS configuration

## Requirements
* Version: ILIAS 8
* PHP 7.0 - 7.4

## Installation

### 1. Install RESTPlugin
Start at your ILIAS root directory 
```bash
mkdir -p Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/  
cd Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/  
git clone https://github.com/DEEP-eAcademy/Ilias.RESTPlugin REST
```  
Update and activate the plugin in the ILIAS Plugin Administration.
```
cd <ILIAS_ROOT_PATH>
composer-install --no-dev
```

### 2. Install PegausHelper
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

### 3. Get the API Secret  
- Go to the ILIAS Plugin Administration  
- Choose the Action 'Configure' of the REST Plugin  
- Click the Button 'Start Administration Panel'  
- Click the Button 'Manage API-Keys and Authorization Schemes'  
- Click the Button 'Modify' at the row 'ilias_pegasus'  

## Testing
If the installation of the REST- or PegasusHelper-plugin as described above fails, the test-script in the directory 'testing' may provide useful information

Start at your ILIAS root directory
```bash
cd Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/testing/
php run.php
```
The script prints out feedback from the tests and writes a log-file 'results.log' in 'PegasusHelper/testing/'

Also read through the paragraph 'Caveats' below

## Update

### 1. Update RESTPlugin
Start at your ILIAS root directory
```bash
cd Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST
git pull
```
Update / activate the plugin in the ILIAS Plugin Administration.
```
cd <ILIAS_ROOT_PATH>
composer-install --no-dev
```

### 2. Update PegasusHelper
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

## Caveats
### ILIAS http path

#### Description
If the host address of ILIAS is configured with http but requests to ILIAS are
redirected to https, the plugin migration of the PegasusHelper will fail.

The PegasusHelper configures the REST plugin while updating to ensure that all
routes are as expected by the Pegasus mobile application. In order to configure the REST
plugin the PegasusHelper adds all routes to the REST plugin with local http POST requests.

The redirect will transform the POST request to a GET request which is not understood by
the REST plugin which leads to the migration error of the PegasusHelper.

#### Solution
To ensure that no https redirects are done, the configuration in the ilias.ini.php has to
be adjusted as shown in the example below.

The ilias.ini.php is located in the root directory of the ILIAS installation.
```text
[server]
http_path = "https://your.ilias-installation.org"
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
