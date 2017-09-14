# Web Deploy - Automatic website deployment with GitHub and PHP

Deploy files from a GitHub public repository to a web server, with PHP as the only dependancy.

Multiple repositories or branches can be configured to be deployed to different locations on the same server, allowing multi-site hosting or live and staging sites to be deployed from a single installation.

## Installation and setup

**Warning**: This script will create, modify and delete files on your web server. It is highly recommended that the `dry-run` mode option is used to test the setup before using on a production environment. Use this software at your own risk.

### Requirements

- Any server running PHP version 5.2 or above.
- An installation directory which is publicly available from the web.

### Basic setup

1. Copy `deploy.php` to a dedicated folder on your server, for example `/deploy`.
2. Create the configuration file `config.json` in the same folder using the following example:
```json
[
	{
		"repository": "https://github.com/username/deployment-test",
		"destinations": ["/var/www/html"],
		"mode": "update"
	}
]
```
3. Set the mandatory options `repository`, `destinations` and `mode` in the config file (see below for details).
4. Add a webhook in your GitHub repository settings, pointing the payload URL at the deployment script.
5. Push to your GitHub repository to deploy. 

## Config options

The configuration file `config.json` can contain multiple configs, each defined as a JSON object, and surrounded by curly braces. Web Deploy will pick the first matching config, based on the values of the `repository`, `branch` and `secret` options. This allows complex configurations where branches can be deployed to different destinations, or ignored completely.

**repository** (required): The URL of the GitHub repository to be deployed. This will be matched against the webhook payload.

**destinations** (required): An array of one or more absolute paths on the webserver to which the repository should be deployed.

**mode** (required): The mode the deployment should be performed in. Valid options are:  
        `update`: Only the file changes specified in the payload are deployed.  
        `replace`: All files are deployed, irrespective of whether they have changed.  
        `dry-run`: As with `update`, but no files are actually changed. Changes are still recorded in the log file.

**branch**: The branch of the repository to be deployed. This will be matched against the webhook payload.

**secret**: The secret signature of your deployment webhook. This can be used to expllicitly select a particular config.

**ignore**: An array of file names that should not be extracted from the repository, similar to .gitignore rules.

**log-level**: The amount of information that should be written to the log file `deploy.log`. Valid options are:  
        `none`: Logging is disabled  
        `basic` (default): Each deployment is logged  
        `verbose`: Each file change is logged

### Example config

```json
[
	{
		"repository": "https://github.com/username/deployment-test",
		"destinations": [
			"/var/www/html",
			"/var/www/html/example.com/lib"
		],
		"mode": "update"
	},
	{
		"repository": "https://github.com/username/deployment-test",
		"destinations": ["/var/www/html/staging"],
		"mode": "update",
		"branch": "develop"
	},
	{
		"repository": "https://github.com/username/magic-deploy",
		"destinations": ["/var/www/html/magic.example.com"],
		"mode": "replace",
		"branch": "master",
		"ignore": [".gitignore", "user-content", "readme.md"],
		"log-level": "verbose"
	}
]
```


## Credits

This script was inspired by *Easy Git Code Deploy*, which is available from [codecanyon.net](https://codecanyon.net/item/easy-git-code-deploy/8586366).
