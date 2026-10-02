<?php
/**
 * Admin screen.
 *
 * @package XhinMigration
 */

defined( 'ABSPATH' ) || exit;

final class AppAlbania_Xhin_Admin {

	const SLUG = 'xhin-migration';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . APPALBANIA_XHIN_BASENAME, array( __CLASS__, 'links' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Backup &amp; Migrate</a>' );
		return $links;
	}

	/** Keeps the guard current after plugin updates. */
	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3 3h3.2l3.8 5.2L13.8 3H17l-5.4 7L17 17h-3.2L10 11.8 6.2 17H3l5.4-7z"/></svg>' );
		add_menu_page( 'Migration by AppAlbania', 'Migration', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), $icon, 80 );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'xhin-migration', plugins_url( 'assets/admin.css', APPALBANIA_XHIN_FILE ), array(), APPALBANIA_XHIN_VERSION );
		wp_enqueue_script( 'xhin-migration', plugins_url( 'assets/admin.js', APPALBANIA_XHIN_FILE ), array(), APPALBANIA_XHIN_VERSION, true );

		$job   = AppAlbania_Xhin_Job::current();
		$state = null;
		if ( $job ) {
			$state          = $job->public_state();
			$state['token'] = $job->s['token'];
		}
		wp_localize_script(
			'xhin-migration',
			'AppAlbaniaXhin',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'wp'       => get_bloginfo( 'version' ),
				'nonce'    => wp_create_nonce( 'xhin' ),
				'job'      => $state,
				'last'     => $job ? null : AppAlbania_Xhin_Job::last(),
				'archives' => AppAlbania_Xhin_Plugin::archives_for_ui(),
				'login'    => wp_login_url(),
				'home'     => home_url( '/' ),
				'chunk'    => AppAlbania_Xhin_Plugin::chunk_upload_size(),
			)
		);
	}

	private static function env() {
		$free = AppAlbania_Xhin_Storage::free_space();
		$max  = (int) ini_get( 'max_execution_time' );
		$mem  = (string) ini_get( 'memory_limit' );
		return array(
			array( 'PHP', PHP_VERSION . ( PHP_INT_SIZE >= 8 ? ' (64-bit)' : ' (32-bit — 2 GB limit)' ), PHP_INT_SIZE >= 8 ),
			array( 'Memory', '-1' === $mem ? 'unlimited' : $mem, true ),
			array( 'Time per request', $max ? $max . ' s → works in ' . AppAlbania_Xhin_Plugin::budget() . ' s steps' : 'unlimited', true ),
			array( 'Upload size', 'unlimited (sent in ' . size_format( AppAlbania_Xhin_Plugin::chunk_upload_size() ) . ' pieces)', true ),
			array( 'Free disk', null === $free ? 'unknown' : AppAlbania_Xhin_Storage::human( $free ), null === $free || $free > 1073741824 ),
			array( 'Crash protection', AppAlbania_Xhin_Plugin::guard_active() ? 'on' : 'off (mu-plugins folder not writable)', AppAlbania_Xhin_Plugin::guard_active() ),
			array( 'zstd compression', AppAlbania_Xhin_Archive::zstd_available() ? 'available' : 'not installed (Standard is used)', true ),
		);
	}

	private static function icon( $name ) {
		$p = array(
			'backup'  => '<path d="M12 3v12m0 0-4-4m4 4 4-4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
			'restore' => '<path d="M12 21V9m0 0-4 4m4-4 4 4"/><path d="M4 9V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3"/>',
			'gear'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
			'file'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
		);
		return '<svg class="xh-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[ $name ] . '</svg>';
	}

	public static function render() {
		$s     = AppAlbania_Xhin_Plugin::settings();
		$codec = isset( $s['codec'] ) ? $s['codec'] : 'deflate';
		$host  = preg_replace( '#^https?://#', '', home_url() );
		$parts = array(
			'db'        => array( 'Database', true ),
			'media'     => array( 'Media', true ),
			'plugins'   => array( 'Plugins', true ),
			'themes'    => array( 'Themes', true ),
			'muplugins' => array( 'Must-use plugins', true ),
			'other'     => array( 'Other wp-content', true ),
			'core'      => array( 'WordPress core', false ),
		);
		$store = str_replace( untrailingslashit( ABSPATH ), '', AppAlbania_Xhin_Storage::dir() );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup.
		?>
		<div class="wrap xh" id="xh-app">
			<h1 class="screen-reader-text">Migration by AppAlbania</h1>

			<header class="xh-head">
				<div class="xh-brand">
					<span class="xh-mark" aria-hidden="true"><svg viewBox="0 0 40 40"><rect width="40" height="40" rx="11"/><path d="M11 10h5l4 5.6 4-5.6h5l-6.5 9.5L29 30h-5l-4-5.8L16 30h-5l6.5-10.5z"/></svg></span>
					<div>
						<div class="xh-title">Migration</div>
						<div class="xh-by">by <a href="https://appalbania.com" target="_blank" rel="noopener">AppAlbania</a></div>
					</div>
				</div>
				<button type="button" class="xh-iconbtn" id="xh-open-settings" aria-expanded="false" aria-controls="xh-settings"><?php echo self::icon( 'gear' ); ?><span>Settings</span></button>
			</header>

			<!-- Settings drawer -->
			<form class="xh-settings" id="xh-settings" hidden>
				<h2>Settings</h2>
				<div class="xh-grid2">
					<label class="xh-field"><span>Compression</span>
						<select name="codec">
							<option value="deflate" <?php selected( $codec, 'deflate' ); ?>>Standard — restores on every server</option>
							<option value="zstd" <?php selected( $codec, 'zstd' ); ?> <?php disabled( ! AppAlbania_Xhin_Archive::zstd_available() ); ?>>Maximum (zstd) — smaller &amp; faster<?php echo AppAlbania_Xhin_Archive::zstd_available() ? '' : ' · not installed'; ?></option>
							<option value="none" <?php selected( $codec, 'none' ); ?>>Off</option>
						</select>
					</label>
					<label class="xh-field"><span>Seconds per step <em>lower it if your host times out</em></span><input type="number" name="slice" min="5" max="120" value="<?php echo (int) $s['slice']; ?>"></label>
					<label class="xh-field"><span>Automatic backups</span>
						<select name="schedule">
							<?php foreach ( array( 'off' => 'Off', 'daily' => 'Every day', 'weekly' => 'Every week' ) as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['schedule'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="xh-field"><span>Keep last <em>automatic backups</em></span><input type="number" name="keep" min="1" max="100" value="<?php echo (int) $s['keep']; ?>"></label>
				</div>
				<label class="xh-toggle"><input type="checkbox" name="verify" <?php checked( ! empty( $s['verify'] ) ); ?>><span>Verify every backup after creating it (recommended)</span></label>
				<label class="xh-toggle"><input type="checkbox" name="background" <?php checked( $s['background'] ); ?>><span>Keep working when I close this page</span></label>
				<label class="xh-toggle"><input type="checkbox" name="sched_core" <?php checked( $s['sched_core'] ); ?>><span>Include WordPress core in automatic backups</span></label>
				<label class="xh-field"><span>Always skip <em>one per line</em></span><textarea name="excludes" rows="2"><?php echo esc_textarea( $s['excludes'] ); ?></textarea></label>
				<input type="hidden" name="chunk_mb" value="<?php echo (int) $s['chunk_mb']; ?>">
				<div class="xh-settings-foot">
					<button type="submit" class="xh-btn">Save</button>
					<span class="xh-saved" id="xh-saved" hidden>Saved ✓</span>
				</div>
				<details class="xh-more">
					<summary>Server check</summary>
					<ul class="xh-env">
						<?php foreach ( self::env() as $e ) : ?>
							<li class="<?php echo $e[2] ? 'ok' : 'warn'; ?>"><span><?php echo esc_html( $e[0] ); ?></span><b><?php echo esc_html( $e[1] ); ?></b></li>
						<?php endforeach; ?>
					</ul>
					<p class="xh-muted">Very large sites: <code>wp xhin export</code> and <code>wp xhin import file.xhin --yes</code> run without any time limit.</p>
				</details>
			</form>

			<!-- Live progress -->
			<section class="xh-live" id="xh-live" hidden aria-live="polite">
				<div class="xh-live-top">
					<div class="xh-live-text">
						<div class="xh-live-kind" id="xh-kind">Backup</div>
						<div class="xh-live-msg" id="xh-msg">Starting…</div>
						<div class="xh-live-now" id="xh-now"></div>
					</div>
					<div class="xh-pct" aria-hidden="true"><span id="xh-pct">0</span><i>%</i></div>
				</div>
				<div class="xh-track" role="progressbar" aria-label="Progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="xh-track"><div class="xh-fill" id="xh-fill"></div></div>
				<div class="xh-live-meta" id="xh-meta"></div>
				<ol class="xh-steps" id="xh-steps"></ol>
				<div class="xh-note" id="xh-note" hidden></div>
				<div class="xh-live-actions">
					<a class="xh-btn" id="xh-login" hidden href="#">Log in to the restored site</a>
					<button type="button" class="xh-btn" id="xh-retry" hidden>Continue from here</button>
					<button type="button" class="xh-btn xh-btn-quiet" id="xh-close" hidden>Done</button>
					<button type="button" class="xh-btn xh-btn-quiet" id="xh-cancel">Cancel</button>
					<button type="button" class="xh-link" id="xh-logbtn">Details</button>
				</div>
				<pre class="xh-log" id="xh-log" hidden></pre>
			</section>

			<div id="xh-main">
				<div class="xh-cards">
					<!-- Backup -->
					<section class="xh-card">
						<div class="xh-card-icon"><?php echo self::icon( 'backup' ); ?></div>
						<h2>Backup</h2>
						<p class="xh-lead">Your whole site in one <b>.xhin</b> file — compressed, verified, any size. Choose what to include:</p>
						<div class="xh-chips" id="xh-parts" role="group" aria-label="What to include">
							<?php foreach ( $parts as $k => $p ) : ?>
								<label class="xh-chip"><input type="checkbox" name="<?php echo esc_attr( $k ); ?>" <?php checked( $p[1] ); ?>><span><?php echo esc_html( $p[0] ); ?></span></label>
							<?php endforeach; ?>
						</div>
						<div class="xh-last none" id="xh-lastbk">No backup yet</div>
						<button type="button" class="xh-btn xh-btn-big" id="xh-start-export">Create backup</button>
						<details class="xh-more">
							<summary>Password &amp; exclusions</summary>
							<label class="xh-field"><span>Password <em>optional · AES-256</em></span><input type="password" id="xh-pass" autocomplete="new-password" placeholder="No password"></label>
							<label class="xh-field"><span>Skip these <em>one per line · wildcards ok</em></span><textarea id="xh-excl" rows="2" placeholder="wp-content/uploads/2019&#10;*.log"></textarea></label>
						</details>
					</section>

					<!-- Restore -->
					<section class="xh-card">
						<div class="xh-card-icon"><?php echo self::icon( 'restore' ); ?></div>
						<h2>Restore or move</h2>
						<p class="xh-lead">Upload a file, paste a link from the old site, or pick a backup below. Links and paths are fixed for <b><?php echo esc_html( $host ); ?></b>.</p>
						<label class="xh-drop" id="xh-drop">
							<input type="file" id="xh-file" accept=".xhin">
							<?php echo self::icon( 'file' ); ?>
							<span class="xh-drop-t" id="xh-drop-t">Drop a .xhin file or <u>choose</u> — any size</span>
						</label>
						<div class="xh-or"><span>or</span></div>
						<input type="url" id="xh-url" placeholder="Paste a migration link from the old site" aria-label="Migration link">
						<details class="xh-more">
							<summary>Options</summary>
							<label class="xh-field"><span>Archive password <em>only if it has one</em></span><input type="password" id="xh-ipass" autocomplete="off"></label>
							<label class="xh-toggle"><input type="checkbox" id="xh-dropins" checked><span>Skip cache drop-ins (recommended when changing hosting)</span></label>
						</details>
						<button type="button" class="xh-btn xh-btn-big" id="xh-start-import" disabled>Restore</button>
					</section>
				</div>

				<!-- Backups -->
				<section class="xh-list-card">
					<div class="xh-list-head">
						<h2>Backups <span class="xh-count" id="xh-count">0</span></h2>
						<span class="xh-muted"><?php echo esc_html( $store ); ?></span>
					</div>
					<ul class="xh-list" id="xh-list"></ul>
					<button type="button" class="xh-more-list" id="xh-showall" hidden></button>
					<p class="xh-empty" id="xh-empty" hidden>No backups yet — create your first one above.</p>
				</section>
			</div>

			<dialog class="xh-dialog" id="xh-confirm" aria-labelledby="xh-dlg-title">
				<form method="dialog">
					<h3 id="xh-dlg-title">Restore into this site</h3>
					<div class="xh-dlg-file" id="xh-dlg-file"></div>
					<div class="xh-field"><span>What to restore</span></div>
					<div class="xh-chips" id="xh-rparts" role="group" aria-label="What to restore">
						<?php foreach ( $parts as $k => $p ) : ?>
							<label class="xh-chip"><input type="checkbox" value="<?php echo esc_attr( $k ); ?>" checked><span><?php echo esc_html( $p[0] ); ?></span></label>
						<?php endforeach; ?>
					</div>
					<p class="xh-dlg-warn">The selected parts of <b><?php echo esc_html( $host ); ?></b> will be replaced. Your site keeps working until the very last step — if anything fails, nothing changes. If you restore the database, log in afterwards with the backup's username &amp; password.</p>
					<div class="xh-dialog-actions">
						<button value="no" class="xh-btn xh-btn-quiet">Cancel</button>
						<button value="yes" class="xh-btn" id="xh-confirm-yes">Restore now</button>
					</div>
				</form>
			</dialog>

			<footer class="xh-foot">Migration by AppAlbania <?php echo esc_html( APPALBANIA_XHIN_VERSION ); ?> · free forever · made in Tirana · <a href="https://appalbania.com" target="_blank" rel="noopener">appalbania.com</a></footer>
			<div class="xh-toast" id="xh-toast" role="status" aria-live="polite"></div>
		</div>
		<?php
		// phpcs:enable
	}
}
