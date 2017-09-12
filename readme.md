# Web Deploy - Automatic website deployment with GitHub and PHP

Web Deploy will deploy files from a GitHub public repository to a web server, with PHP as the only dependancy.

## How it works

GitHub can be configured to automatically perform actions when certain events occur, such as pushing to your repository or publishing a software release. When `deploy.php` recieves one of these *webhook* requests, it compares the repository URL and branch to its configuration file. If the configuration matches the webhook, the repository files are retrieved and their changes are written to the files on the web server.

This system works without requiring a Git executable or SSH access. Multiple repositories or branches can be configured to be deployed to different locations on the same server, allowing multi-site hosting or live and staging sites to be deployed from one-location.

## Installation and setup

**Warning**: This script will create, modify and delete files on your web server. Although it *should* only do this according to changes made in your GitHub repository, there may be untested edge cases where things can go wrong. Please use this software with caution and at your own risk.

### Requirements

This script should work on any server running PHP version 5.x and above, although it has been tested using PHP 5.5 and Apache.

### Basic setup

The script can be installed in any location on your server that is available publicly from the web.

1. Copy `deploy.php` to a dedicated folder on your server, for example `/deploy`.
2. Create the configuration file `config.json` in the same folder using the following example:
```json
[
	{
		"repository": "https://github.com/username/deployment-test",
		"destination": "/var/www/html",
		"mode": "update",
	}
]
```
3. Set the mandatory options `repository`, `destination` and `mode` in the config file (see below for details).
4. Add a webhook in your GitHub repository settings, pointing the payload URL at the deployment script.
5. Push to your GitHub repository to deploy. 

### Config options

**repository** (required): The URL of the GitHub repository to be deployed. This will be matched against the webhook payload.

**destination** (required): The absolute path on the webserver to which the repository should be deployed.

**mode** (required): The mode the deployment should be performed in. Valid options are:  
        `update`: Only the file changes specified in the payload are deployed.  
        `replace`: All files are deployed, irrespective of whether they have changed.  
        `dry-run`: As with `update`, but no files are actually changed. Changes are still recorded in the log file.

**branch**: The branch of the repository to be deployed. This will be matched against the webhook payload.

**ignore**: An array of file names that should not be extracted from the repository, similar to .gitignore rules.

**log-level**: The amount of information that should be written to the log file `deploy.log`. Valid options are:  
        `none`: Logging is disabled  
        `basic` (default): Each deployment is logged.  
        `verbose`: Each file change is logged.

### Advanced configuration



## Credits

This script was inspired by Easy Git Code Deploy (available for a fee from [codecanyon.net](https://codecanyon.net/item/easy-git-code-deploy/8586366)), and expands upon its functionality with more options and flexibility.
