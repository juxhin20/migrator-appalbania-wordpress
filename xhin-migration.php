<?php
/**
 * Plugin Name:       Migration by AppAlbania
 * Description:       Backup, restore & migrate any WordPress site with one .xhin file. No size limits, compressed, verified, resumable — built for 150 GB+ sites. Free, by AppAlbania.
 * Version:           1.3.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            AppAlbania
 * Author URI:        https://appalbania.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package XhinMigration
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'APPALBANIA_XHIN_VERSION' ) || defined( 'XHIN_VERSION' ) ) {
	return; // already loaded (by the isolation guard, or another copy of this plugin is active).
}

define( 'APPALBANIA_XHIN_VERSION', '1.3.0' );
define( 'APPALBANIA_XHIN_FILE', __FILE__ );
define( 'APPALBANIA_XHIN_DIR', __DIR__ . '/' );
define( 'APPALBANIA_XHIN_BASENAME', plugin_basename( __FILE__ ) );

require_once APPALBANIA_XHIN_DIR . 'includes/class-xhin-archive.php';
require_once APPALBANIA_XHIN_DIR . 'includes/class-xhin-replace.php';
require_once APPALBANIA_XHIN_DIR . 'includes/class-xhin-db.php';
require_once APPALBANIA_XHIN_DIR . 'includes/class-xhin-storage.php';
require_once APPALBANIA_XHIN_DIR . 'includes/class-xhin-job.php';
require_once APPALBANIA_XHIN_DIR . 'includes/class-xhin-admin.php';
require_once APPALBANIA_XHIN_DIR . 'includes/class-xhin-plugin.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once APPALBANIA_XHIN_DIR . 'includes/class-xhin-cli.php';
}

AppAlbania_Xhin_Plugin::boot();
