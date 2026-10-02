<?php
/**
 * Removes settings and the runner guard. Backup files in wp-content/xhin-backups
 * are kept on purpose — delete that folder yourself if you no longer need them.
 *
 * @package XhinMigration
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'xhin_settings' );
delete_option( 'xhin_secret' );
delete_option( 'xhin_guard_v' );
wp_clear_scheduled_hook( 'xhin_watchdog' );
wp_clear_scheduled_hook( 'xhin_scheduled_backup' );
wp_delete_file( WPMU_PLUGIN_DIR . '/0-xhin-guard.php' );
