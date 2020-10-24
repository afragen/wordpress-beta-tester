# WordPress Beta Tester
* Tags: beta, advanced, testing
* Contributors: [westi](https://github.com/westi), [mlteal](https://github.com/mlteal), [afragen](https://github.com/afragen), [pbiron](https://github.com/pbiron)
* Requires at least: 3.1
* Stable Tag: master
* License: GPLv2
* License URI: https://www.opensource.org/licenses/GPL-2.0
* Requires PHP: 5.2.4

Allows you to easily upgrade for testing the next versions of WordPress.

## Description
This plugin provides an easy way to get involved with beta testing WordPress.

Once installed it will enable you to upgrade your website to the latest Nightly, Beta, or Release Candidate at the click of a button using the built in upgrader.

By default once enabled it switches your website onto the point release development track.

For the more adventurous there is the option to switch to the bleeding edge (trunk) of development.

Don't forget to backup before you start!

### Extra Settings

There is a setting to **Skip successful autoupdate emails**. It functions to disable sending emails to the admin user for successful autoupdates. Only emails indicating failures of the autoupdate process are sent.

The **Extra Settings** tab may contain choices for testing new features in trunk that require constants to be set. A checked feature will add a constant to the user's `wp-config.php` file in the format as follows:

`define( 'WP_BETA_TESTER_{$feature}', true );`

Unchecking the feature will remove the constant.

This plugin resets the constants in `wp-config.php` on plugin activation and removes them on plugin deactivation. Use the filter `wp_beta_tester_config_path` to return a non-standard `wp-config.php` file path.

If no settings are present there is no testing to be done that requires this feature.

PRs are welcome on [GitHub](https://github.com/afragen/wordpress-beta-tester).
