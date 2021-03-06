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
        "destination": "/var/www/html",
        "mode": "update"
    }
]
```
3. Set the mandatory options `repository`, `destination` and `mode` in the config file (see below for details).
4. Add a webhook in your GitHub repository settings, pointing the payload URL at the deployment script.
5. Push to your GitHub repository to deploy. 

## Config options

The configuration file `config.json` can contain multiple config rules, each defined as a JSON object, and surrounded by curly braces. Web Deploy will use all matching config rules for deployment, based on the values of options `repository`, `branches`, `events`, and `pre-releases`. This allows complex configurations where multiple branches can be deployed to different destinations, or ignored completely.

**repository** (required): The URL of the GitHub repository to be deployed. This will be matched against the webhook payload.

**destination** (required): The absolute path on the webserver to which the repository should be deployed.

**mode** (required): The mode the deployment should be performed in. Valid options are:  
        `update`: Only the file changes specified in the payload are deployed. Overridden by `git push --force`.  
        `replace`: All files are deployed, irrespective of whether they have changed.  
        `deploy`: Use mode `replace` if the destination is empty (apart from ignored files) and `update` otherwise.  
        `dry-run`: As with `deploy`, but no files are actually changed. Changes are still recorded in the log file.

**branches**: An array of branch names (or name prefixes) to be deployed. This will be matched against the webhook payload.

**events**: An array of webhook event names to enable deployment for. Valid options include `push`, `release`, `commit` etc.

**pre-releases**: A boolean value to enable the deployment of pre-releases with the `release` event. Defaults to `false`.

**ignore**: An array of file names that should not be created (or modified if they exist) during deployment.

**log-level**: The amount of information that should be written to the log file `deploy.log`. Valid options are:  
        `none`: Logging is disabled  
        `basic` (default): Each deployment is logged  
        `verbose`: Each file change is logged

### Example config

```json
[
    {
        "repository": "https://github.com/username/deployment-test",
        "destination": "/var/www/html",
        "mode": "update",
        "events": ["release"],
        "pre-releases": true
    },
    {
        "repository": "https://github.com/username/deployment-test",
        "destination": "/var/www/html/staging",
        "mode": "replace",
        "branches": ["develop", "release-"]
    },
    {
        "repository": "https://github.com/username/magic-deploy",
        "destination": "/var/www/html/magic.example.com",
        "mode": "deploy",
        "branches": ["master"],
        "ignore": [".gitignore", "user-content", "readme.md"],
        "log-level": "verbose"
    }
]
```

## Unit testing

Testing is provided by the [PHPUnit](https://phpunit.de) framework. To run the tests, install PHPUnit in the project directory (or a location accessible from `PATH`), then issue the following command:

```
phpunit ./tests
```

## License

This software is distributed under the GNU General Public License v3. Copyright 2017-2018 Jonathan Holvey.

## Credits

This project was inspired by *Easy Git Code Deploy*, which is available from [codecanyon.net](https://codecanyon.net/item/easy-git-code-deploy/8586366).
