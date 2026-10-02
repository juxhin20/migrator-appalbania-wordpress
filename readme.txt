=== Migration by AppAlbania ===
Contributors: appalbania
Tags: backup, migration, restore, clone, move
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup, restore and move any WordPress site with one .xhin file. No size limits, compressed, verified and resumable. Free, by AppAlbania.

== Description ==

Xhin Migration packs the whole site (database, uploads, plugins, themes, must-use plugins, other wp-content and optionally WordPress core) into a single `.xhin` archive and restores it on any WordPress site — same domain or a new one.

* **No size limit.** 64-bit sizes everywhere, files streamed in 2 MB verified chunks. Tested with multi-GB single files.
* **Never stops.** Work runs in short time slices that resume from a saved cursor. Requests killed by the host, a proxy timeout, a closed tab or a crashed PHP worker lose seconds, not the job. Tested with hundreds of random SIGKILLs during backup and restore.
* **Keeps going with the tab closed.** Loopback background runner + a WP-Cron watchdog.
* **Runner isolation.** A tiny must-use loader boots Xhin's own requests without any other plugin or theme, so a broken or half-restored plugin cannot kill a migration.
* **Safe restore.** The database is imported into temporary tables while the site keeps running, URLs are rewritten, then everything switches in one atomic `RENAME TABLE`. If anything fails before that, the live site is untouched. `wp-config.php` is never overwritten; core and mu-plugin files are swapped in last.
* **Correct URL replacement.** Plain, JSON-escaped (`https:\/\/`), URL-encoded and protocol-relative URLs plus server paths. Serialized data is rewritten by a grammar walker (no `unserialize()`), so objects of classes that don't exist on the new site, enums and double-serialized strings stay valid. Table prefix changes are handled (`wp_user_roles`, `wp_capabilities`…).
* **Server-to-server migration.** "Copy migration link" on the old site gives a signed, expiring, Range-capable URL; the new site pulls the archive directly and resumes broken downloads.
* **Unlimited uploads.** Browser uploads go in chunks under `post_max_size`; a dropped connection or page reload resumes where it stopped.
* **Compressed, lossless.** Text, PHP, CSS, JS and the SQL dump are deflated (or zstd when the server has php-zstd: ~50% smaller SQL at ~6x the speed). Images, video and zips are stored byte-for-byte — never recompressed, zero quality loss.
* **Verified.** Every 2 MB block carries a CRC32; each backup is read back and checked after it is made (optional), and every block is checked again on restore.
* **Choose what to restore.** Database, media, plugins, themes, must-use plugins, other content, WordPress core — any combination.
* **Real-time progress.** Live percentage, speed, time left and the file being processed.
* **Works on every common server.** Tested on nginx, Apache and OpenLiteSpeed; PHP 7.4 to 8.5; WordPress 5.9 to 7.1; MySQL 5.7 & 8.4, MariaDB 10.6, 10.11 & 11.4 — in every direction, including WordPress and PHP downgrades and MySQL ⇄ MariaDB collation differences.
* **Plays well with other plugins.** Every class, constant and script name is prefixed (AppAlbania_Xhin_), it declares no global functions, and backup/restore requests run with other plugins and the theme switched off, so a broken plugin cannot stop a migration. Other backup plugins' archive folders are skipped automatically.
* **Self-healing.** Constant memory use whatever the row sizes, adapts to the host's request limits, resumes after any crash, updates the WordPress database when versions differ, and swaps WordPress core folders in one step so the site is never half-updated.
* **Private on every server.** Backups get random names and job data lives in a folder with an unguessable name, so nothing is reachable from the web even where .htaccess is ignored (nginx).
* **Encryption.** Optional AES-256-GCM per chunk (PBKDF2-SHA256, 200k rounds).
* **Scheduled backups** with retention, **WP-CLI**, protected storage folder.

== WP-CLI ==

    wp xhin export [--without=media,plugins] [--core] [--password=<p>] [--exclude=<patterns>]
    wp xhin import <file|name|url> [--password=<p>] [--keep-dropins] --yes
    wp xhin verify <file> [--password=<p>]
    wp xhin list
    wp xhin status [--resume]
    wp xhin cancel

For very large sites the CLI is the fastest path (no HTTP time limits).

== The .xhin format (v1) ==

All integers big-endian.

    HEADER  64 B   "XHIN\x1A\r\n\0" | version u16 | flags u16 (bit0 encrypted, bit1 zstd) | created u64
                   | salt[16] | keycheck[16] | reserved[12]
    ENTRY          "XE" | type u8 (1 file, 2 dir) | scope u8 (0 meta, 1 wp-content, 2 site root)
                   | path_len u16 | path | size u64 | mtime u64 | mode u32 | CHUNK*… | terminator
    CHUNK          raw_len u32 | stored_len u32 | crc32(raw) u32 | flags u8 (bit0 deflate, bit1 AES-GCM, bit2 zstd)
                   | data   (encrypted: iv[12] tag[16] ciphertext)
    TERMINATOR     13 zero bytes
    TRAILER 26 B   "XZ" | entries u64 | total_raw_bytes u64 | "XHINEND!"

The first entry is `manifest.json` (site URLs, paths, prefix, versions), then `database.sql`
(prefix-neutral: tables are written as `XHIN_PFX_posts`, one statement per line), then files,
then `summary.json`.

== Frequently Asked Questions ==

= Where are backups stored? =
`wp-content/xhin-backups/` — protected by .htaccess/web.config, and every archive name contains a 16-character random part for servers that ignore .htaccess (nginx).

= My host kills requests after 30 seconds. =
Lower "Slice time" in Settings (default 20 s; it is capped automatically at 60 % of max_execution_time). Progress is committed every ~1.5 s anyway.

= Do I need the plugin on the new site? =
Yes: install WordPress + Xhin Migration on the new site, then restore. After the restore, log in with the old site's username and password.

= Which compression should I pick? =
"Standard" restores on every PHP server. "Maximum" (zstd) is smaller and faster, but the server you restore on also needs the php-zstd extension — Xhin checks this before touching anything.

= Multisite? =
Not in v1.

== Screenshots ==

1. One simple screen: back up your whole site, restore it, or move it to another server. Every backup is listed with its date and size.
2. Real-time progress while a backup runs: percentage, speed, time left and the file being packed.
3. Choose exactly what to restore — database, media, plugins, themes, must-use plugins, other content, WordPress core.
4. Restoring: files and database are replaced step by step; the site keeps working until the very last step.
5. Restore complete: URLs and paths are already updated for the new address.
6. Settings and the Server check, which shows how the plugin adapts to your host's limits.

== Changelog ==

= 1.3.0 =
* Ready for the WordPress.org directory: passes the official Plugin Check.
* Downloads from a migration link now use the WordPress HTTP API, in resumable 16 MB pieces.
* WordPress core folders (wp-admin, wp-includes) are swapped in with two folder renames, so the site is never left with mixed core files.
* All messages escaped; small hardening of request handling.

= 1.2.0 =
* New name: Migration by AppAlbania. All code names prefixed AppAlbania_Xhin_ — no clashes with any other plugin.
* Tested in a 7-server lab (nginx / Apache / OpenLiteSpeed, PHP 7.4–8.5, WordPress 5.9–7.1, MySQL 5.7/8.4, MariaDB 10.6/10.11/11.4) and with 35 popular plugins active.
* Fix: database export and URL replacement now stream rows — no "memory exhausted" on tables with large rows.
* Fix: batched updates respect the server's max_allowed_packet (1 MB hosts).
* Fix: jobs started from WP-CLI as root no longer block the web server; restored files keep the site owner.
* New: restoring a database from a newer WordPress restores the matching core files too (or stops before changing anything).
* New: automatic WordPress database update after restoring into a different WordPress version.
* Robust against other plugins printing PHP notices into AJAX responses; PHP 8.5 deprecations removed.
* Skips Duplicator, BackWPup, WP Staging and similar backup folders.

= 1.1.1 =
* Security: job state and temporary database dumps now live in a folder with an unguessable name, so they stay private on nginx and other servers that ignore .htaccess. Upgrading moves the old folder automatically.

= 1.1.0 =
* New simple design with real-time progress (live %, speed, time left), dates & times everywhere.
* Choose what to restore; read-back verification; zstd compression option; uploads in up to 32 MB pieces.
* Hardened resume: mid-step checkpoints, size-aware URL replacement, crash-safe database switch, orphaned temp tables cleaned up.

= 1.0.0 =
* First release.
