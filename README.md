# phabricator-feed
Phabricator Feed Importer Wordpress plugin creates Wordpress content [shortcodes](https://en.support.wordpress.com/shortcodes/) from task feeds imported from [Phabricator](https://en.wikipedia.org/wiki/Phabricator) through its [Conduit API](https://secure.phabricator.com/book/phabricator/article/conduit/). The shortcodes are formatted like `[phabricator_feed_` + `project_slug]` (for example `[phabricator_feed_myproject]`). The shortcodes will be created for all the project slugs as well as column titles found under specified source project and listed under settings.

The intended use of the plugin is displaying set of simpler Phabricator tasks on Wordpress in order to be outsourced to volunteers.

## Settings
* Source project where to import task feed from (with subprojects)
* Update interval in minutes (this is used to see if data needs to be updated during actuall call to shortcode)
* Last updated (timestamp, used mostly for testing)
* Conduit URI (for example `https://phabricator.wikimedia.org/api/`)
* Conduit token (for example `api-z4ekoy6xefnego7i7xezxao23lj2`)

## Install

Download the pugin into your Wordpress [plugins directory](https://codex.wordpress.org/WordPress_Files#wp-content.2Fplugins):

```
cd /usr/share/wordpress/wp-content/plugins
git clone https://github.com/infoaed/phabricator-feed.git
```

Download the [Phacility utilities PHP library](https://github.com/phacility/libphutil) to `phabricator-feed` directory:

```
cd phabricator-feed
git clone https://github.com/phacility/libphutil.git
```

Activate the plugin in Wordpress, edit Conduit API settings and start using the shortcodes.

Also make sure your installation PHP has [cURL modules](https://www.php.net/manual/en/curl.installation.php) installed. 
