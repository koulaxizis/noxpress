<?php
/**
 * Noxpress_Updater — updates for the suite from GitHub releases, without
 * WordPress.org (Bible §16).
 *
 * Flow:
 *  1. The release workflow publishes https://noxpress.tech/updates.json:
 *     per plugin, the newest "stable" and "beta" release with zip URL,
 *     sha256 and an Ed25519 signature.
 *  2. Whenever WordPress saves its plugin update transient, the suite
 *     plugins get an entry from the chosen channel (Stable by default).
 *     Updates, the update button, the changelog popup and the auto-update
 *     toggle are then WordPress's own.
 *  3. Before WordPress unpacks a suite package, upgrader_pre_download
 *     downloads it here and checks the sha256 and the signature. A failed
 *     check stops the install. A package older than the installed version
 *     is refused.
 *
 * Signed list (Core 1.3.0, security hardening NXS-21): updates.json has a
 * "serial" (unix time of the build) and an "expires" (serial + 14 days; the
 * release workflow re-signs it every week). Every release carries "sig2",
 * an Ed25519 signature of
 *   noxpress2|<slug>|<channel>|<version>|<sha256>|<serial>|<expires>
 * so nobody can serve an old list (rollback / freeze), present a beta as
 * stable, or keep a site on an expired list. A list older than the newest
 * serial this site has seen, an expired one or one without valid "sig2"
 * signatures is ignored, and the hub says why. The v1 "signature"
 * (noxpress|<slug>|<version>|<sha256>) stays in the file for sites with
 * older Core copies; this Core no longer reads it.
 *
 * Whole-list signature (Core 1.4.0): "sig3" signs the compact JSON copy
 * of the list in "signed" (noxpress3|<signed>); only that copy is read,
 * so no release can be hidden and no requirement or changelog changed.
 *
 * New plugins (Core 1.4.0): every validly signed plugin of the list is
 * read, also one this Core copy's catalog does not list yet, so the hub
 * can install it. Its name and description come from the list; it counts
 * as installed only with our Update URI in its header.
 *
 * Requests: wp_safe_remote_get only, 10 s timeout, cached for 12 hours
 * (1 hour after a failure), never on visitor requests: update checks run
 * in the admin and in WP-Cron only.
 */

defined( 'ABSPATH' ) || exit;

final class Noxpress_Updater {

	const MANIFEST_URL = 'https://noxpress.tech/updates.json';

	/** Only packages under this prefix are accepted. */
	const PACKAGE_PREFIX = 'https://github.com/koulaxizis/noxpress/releases/download/nox-';

	/** Ed25519 public key (base64) matching the NOXPRESS_SIGNING_KEY secret of the repo. */
	const PUBLIC_KEY = 'xtCTPe6qjQzMxplW3cNmUqELvAuNMVvit/P0IOaSgd0=';

	/** Most plugins read from one list. */
	const MAX_PLUGINS = 200;

	/** Site transient holding the decoded manifest (or an empty array after a failure). */
	const CACHE = 'noxpress_manifest';

	/** Site option: 'stable' | 'beta'. */
	const OPT_CHANNEL = 'noxpress_channel';

	/** Site option: unix time of the last manifest fetch attempt. */
	const OPT_CHECKED = 'noxpress_checked';

	/** Site option: array( 'serial' => newest serial seen, 'problem' => '' | 'expired' | 'rollback' | 'signature' ). */
	const OPT_TRUST = 'noxpress_trust';

	/** Longest validity a list may claim (expires - serial), in seconds. */
	const MAX_VALID = 31 * DAY_IN_SECONDS;

	/** User meta: slug => version of the other channel's release this user hid in the hub. */
	const META_HIDDEN = 'noxpress_hidden';

	/** Query argument of the hub's "Install this beta" links. */
	const PICK = 'noxpress_pick';

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
		// Other plugins' plugins_api filters run after ours and some of them
		// replace any result (e.g. with WordPress.org's "Plugin not found").
		// The suite's own details win at the very end of both filters.
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api_last' ), PHP_INT_MAX, 3 );
		add_filter( 'plugins_api_result', array( __CLASS__, 'plugins_api_last' ), PHP_INT_MAX, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 3 );
		// Hub "Install this beta": one plugin, one request, the channel stays.
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'pick_transient' ) );
	}

	public static function channel(): string {
		return 'beta' === get_site_option( self::OPT_CHANNEL, 'stable' ) ? 'beta' : 'stable';
	}

	public static function set_channel( string $channel ): void {
		update_site_option( self::OPT_CHANNEL, 'beta' === $channel ? 'beta' : 'stable' );
	}

	/** Unix time of the last fetch attempt, 0 if never. */
	public static function last_checked(): int {
		return (int) get_site_option( self::OPT_CHECKED, 0 );
	}

	/**
	 * Validated manifest: slug => array( 'stable' => release|null,
	 * 'beta' => release|null ). Empty when it cannot be fetched.
	 */
	public static function manifest( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$data     = array();
		$problem  = 'unavailable';
		$response = wp_safe_remote_get(
			self::MANIFEST_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data    = self::parse( (string) wp_remote_retrieve_body( $response ), $problem );
		}
		self::remember( $data, $problem );

		update_site_option( self::OPT_CHECKED, time() );
		set_site_transient( self::CACHE, $data, $data ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Strict parse of updates.json. Since 1.4.0 only the "signed" copy of
	 * the list counts, and only with a valid "sig3" over it: every release,
	 * requirement and changelog is then exactly as the release workflow
	 * built it, and no plugin can be left out. Malformed or unsigned
	 * releases inside it are still dropped. The whole list is refused when
	 * sig3 fails, it has expired, is older than the newest list this site
	 * has seen, or has no valid release at all.
	 *
	 * @param string $problem Set to '' | 'expired' | 'rollback' | 'signature'.
	 */
	public static function parse( string $json, &$problem = '' ): array {
		$problem = 'signature';
		$outer   = json_decode( $json, true );
		if ( ! is_array( $outer ) || ! isset( $outer['signed'], $outer['sig3'] ) || ! is_string( $outer['signed'] ) || ! is_string( $outer['sig3'] )
			|| ! preg_match( '#^[A-Za-z0-9+/]{86}==$#', $outer['sig3'] )
			|| ! self::signed( 'noxpress3|' . $outer['signed'], $outer['sig3'] ) ) {
			return array();
		}
		$raw = json_decode( $outer['signed'], true );
		if ( ! is_array( $raw ) || ! isset( $raw['plugins'] ) || ! is_array( $raw['plugins'] ) ) {
			return array();
		}
		$serial  = isset( $raw['serial'] ) && is_int( $raw['serial'] ) ? $raw['serial'] : 0;
		$expires = isset( $raw['expires'] ) && is_int( $raw['expires'] ) ? $raw['expires'] : 0;
		if ( $serial <= 0 || $expires <= $serial || $expires - $serial > self::MAX_VALID ) {
			return array();
		}
		if ( $expires < time() ) {
			$problem = 'expired';
			return array();
		}
		if ( $serial < self::trusted_serial() ) {
			$problem = 'rollback';
			return array();
		}
		$out     = array();
		$catalog = Noxpress_Core::catalog();
		// Every validly signed plugin of the list counts, also one this Core
		// copy does not list yet (1.4.0): the signature binds the slug and the
		// zip must be that slug's own release asset.
		foreach ( array_slice( array_keys( $raw['plugins'] ), 0, self::MAX_PLUGINS ) as $slug ) {
			$slug = (string) $slug;
			if ( ! preg_match( Noxpress_Core::SLUG_RE, $slug ) || ! is_array( $raw['plugins'][ $slug ] ) ) {
				continue;
			}
			$entry = array();
			foreach ( array( 'stable', 'beta' ) as $channel ) {
				$rel               = isset( $raw['plugins'][ $slug ][ $channel ] ) ? self::release( $slug, $channel, $raw['plugins'][ $slug ][ $channel ], $serial, $expires ) : null;
				$entry[ $channel ] = $rel;
			}
			if ( ! $entry['stable'] && ! $entry['beta'] ) {
				continue;
			}
			if ( ! isset( $catalog[ $slug ] ) ) {
				$entry['info'] = self::info_of( $raw['plugins'][ $slug ] );
			}
			$out[ $slug ] = $entry;
		}
		$problem = $out ? '' : 'signature';
		return $out;
	}

	/**
	 * Display data of a plugin this Core does not list: name, Greek and
	 * English description, admin page. Unsigned, so plain text with limits.
	 */
	private static function info_of( array $p ): array {
		$text = static function ( $key, $max ) use ( $p ): string {
			return isset( $p[ $key ] ) && is_scalar( $p[ $key ] ) ? mb_substr( sanitize_text_field( (string) $p[ $key ] ), 0, $max ) : '';
		};
		$page = isset( $p['page'] ) && is_string( $p['page'] ) ? sanitize_key( $p['page'] ) : '';
		return array(
			'name'    => $text( 'name', 60 ),
			'desc_el' => $text( 'desc_el', 400 ),
			'desc_en' => $text( 'desc_en', 400 ),
			'page'    => substr( $page, 0, 40 ),
		);
	}

	/** Newest list serial this site has accepted (0 = none yet). */
	public static function trusted_serial(): int {
		$t = get_site_option( self::OPT_TRUST, array() );
		return is_array( $t ) && isset( $t['serial'] ) ? (int) $t['serial'] : 0;
	}

	/** Why the last list was refused: '' | 'expired' | 'rollback' | 'signature' | 'unavailable'. */
	public static function problem(): string {
		$t = get_site_option( self::OPT_TRUST, array() );
		return is_array( $t ) && isset( $t['problem'] ) ? (string) $t['problem'] : '';
	}

	/** Stores the newest accepted serial and the reason of a refusal. */
	private static function remember( array $data, string $problem ): void {
		$serial = self::trusted_serial();
		foreach ( $data as $entry ) {
			foreach ( array( 'stable', 'beta' ) as $channel ) {
				if ( ! empty( $entry[ $channel ]['serial'] ) ) {
					$serial = max( $serial, (int) $entry[ $channel ]['serial'] );
				}
			}
		}
		update_site_option( self::OPT_TRUST, array( 'serial' => $serial, 'problem' => $data ? '' : $problem ) );
	}

	/** Ed25519 check of a message against PUBLIC_KEY. */
	private static function signed( string $message, string $signature ): bool {
		try {
			return function_exists( 'sodium_crypto_sign_verify_detached' )
				&& sodium_crypto_sign_verify_detached(
					(string) base64_decode( $signature, true ),
					$message,
					(string) base64_decode( self::PUBLIC_KEY, true )
				);
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** The signed message of a release (sig2). */
	public static function message( string $slug, array $rel ): string {
		return 'noxpress2|' . $slug . '|' . $rel['channel'] . '|' . $rel['version'] . '|' . $rel['sha256'] . '|' . $rel['serial'] . '|' . $rel['expires'];
	}

	/** One validated release with a valid sig2, or null. */
	private static function release( string $slug, string $channel, $r, int $serial, int $expires ): ?array {
		if ( ! is_array( $r ) ) {
			return null;
		}
		$version = isset( $r['version'] ) ? (string) $r['version'] : '';
		$zip     = isset( $r['zip'] ) ? (string) $r['zip'] : '';
		$sha     = isset( $r['sha256'] ) ? strtolower( (string) $r['sha256'] ) : '';
		$sig     = isset( $r['sig2'] ) ? (string) $r['sig2'] : '';

		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version )
			|| 0 !== strpos( $zip, self::PACKAGE_PREFIX )
			|| ! preg_match( '/^[0-9a-f]{64}$/', $sha )
			|| ! preg_match( '#^[A-Za-z0-9+/]{86}==$#', $sig ) ) {
			return null;
		}
		// The zip must be this plugin's own asset: .../nox-<code>-<version>/<slug>.zip.
		if ( ! preg_match( '#^' . preg_quote( self::PACKAGE_PREFIX, '#' ) . '[a-z]{2}-' . preg_quote( $version, '#' ) . '/' . preg_quote( $slug, '#' ) . '\.zip$#', $zip ) ) {
			return null;
		}

		$rel = array(
			'version' => $version,
			'sha256'  => $sha,
			'channel' => $channel,
			'serial'  => $serial,
			'expires' => $expires,
		);
		if ( ! self::signed( self::message( $slug, $rel ), $sig ) ) {
			return null;
		}

		$text = static function ( $key, $max ) use ( $r ): string {
			return isset( $r[ $key ] ) && is_scalar( $r[ $key ] ) ? substr( sanitize_textarea_field( (string) $r[ $key ] ), 0, $max ) : '';
		};

		return array(
			'version'      => $version,
			'zip'          => $zip,
			'sha256'       => $sha,
			'signature'    => $sig,
			'channel'      => $channel,
			'serial'       => $serial,
			'expires'      => $expires,
			'published'    => $text( 'published', 40 ),
			'requires'     => $text( 'requires_wp', 10 ),
			'requires_php' => $text( 'requires_php', 10 ),
			'tested'       => $text( 'tested_wp', 10 ),
			'changelog'    => $text( 'changelog', 20000 ),
		);
	}

	/**
	 * Release a site should run for a slug: the channel's newest, where
	 * Beta means the newer of beta and stable.
	 */
	public static function latest( string $slug, ?array $manifest = null ): ?array {
		$manifest = null === $manifest ? self::manifest() : $manifest;
		if ( empty( $manifest[ $slug ] ) ) {
			return null;
		}
		$stable = self::fresh( $manifest[ $slug ]['stable'] );
		$beta   = self::fresh( $manifest[ $slug ]['beta'] );
		if ( 'beta' === self::channel() && $beta && ( ! $stable || version_compare( $beta['version'], $stable['version'], '>' ) ) ) {
			return $beta + array( 'beta' => true );
		}
		if ( $stable ) {
			return $stable + array( 'beta' => false );
		}
		return null;
	}

	/** The release while its list is valid, else null (a cached list can expire). */
	private static function fresh( ?array $rel ): ?array {
		return $rel && ! empty( $rel['expires'] ) && (int) $rel['expires'] >= time() ? $rel : null;
	}

	/**
	 * Newer release in the channel the site does not follow (in practice a
	 * beta newer than the stable a Stable site gets), or null.
	 */
	public static function other( string $slug, ?array $manifest = null ): ?array {
		$manifest = null === $manifest ? self::manifest() : $manifest;
		if ( empty( $manifest[ $slug ] ) ) {
			return null;
		}
		$on_beta = 'beta' === self::channel();
		$alt     = self::fresh( $manifest[ $slug ][ $on_beta ? 'stable' : 'beta' ] );
		$cur     = self::latest( $slug, $manifest );
		if ( ! $alt || ( $cur && ! version_compare( $alt['version'], $cur['version'], '>' ) ) ) {
			return null;
		}
		return $alt + array( 'beta' => ! $on_beta );
	}

	/** Version of the other channel's release this user hid for a slug ('' if none). */
	public static function hidden( string $slug ): string {
		$hidden = get_user_meta( get_current_user_id(), self::META_HIDDEN, true );
		return is_array( $hidden ) && isset( $hidden[ $slug ] ) ? (string) $hidden[ $slug ] : '';
	}

	public static function hide( string $slug, string $version ): void {
		$uid    = get_current_user_id();
		$hidden = get_user_meta( $uid, self::META_HIDDEN, true );
		$hidden = is_array( $hidden ) ? $hidden : array();
		$hidden[ $slug ] = $version;
		update_user_meta( $uid, self::META_HIDDEN, $hidden );
	}

	/**
	 * Installed suite plugins with a newer release in the other channel that
	 * this user has not hidden. Reads the cached list only (never fetches), so
	 * the menu can show it on every admin page.
	 */
	public static function other_count(): int {
		$manifest = get_site_transient( self::CACHE );
		if ( ! is_array( $manifest ) || ! $manifest ) {
			return 0;
		}
		// Most of the time no slug has one: skip reading the plugins folder.
		$any = false;
		foreach ( array_keys( $manifest ) as $slug ) {
			if ( self::other( (string) $slug, $manifest ) ) {
				$any = true;
				break;
			}
		}
		if ( ! $any ) {
			return 0;
		}
		$count = 0;
		foreach ( Noxpress_Core::installed() as $slug => $data ) {
			$alt = self::other( $slug, $manifest );
			if ( $alt && version_compare( $alt['version'], (string) $data['Version'], '>' )
				&& version_compare( $alt['version'], self::hidden( $slug ) ?: '0', '>' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The beta picked by a hub "Install this beta" link for this plugin in
	 * this request, or null. WordPress still checks the nonce and capability
	 * of update.php; the package is still verified in pre_download().
	 */
	private static function picked( string $slug ): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- update.php verifies its own nonce.
		if ( ! is_admin() || ! isset( $_GET[ self::PICK ], $_GET['plugin'] ) || 'beta' !== $_GET[ self::PICK ] ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target = sanitize_text_field( wp_unslash( (string) $_GET['plugin'] ) );
		if ( $target !== $slug && $target !== Noxpress_Core::plugin_file( $slug ) ) {
			return null;
		}
		if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'update_plugins' ) ) {
			return null;
		}
		$manifest = self::manifest();
		$beta     = isset( $manifest[ $slug ]['beta'] ) ? self::fresh( $manifest[ $slug ]['beta'] ) : null;
		return $beta ? $beta + array( 'beta' => true ) : null;
	}

	/** Release offered for a slug in this request: a picked beta, else the channel's. */
	private static function offer( string $slug ): ?array {
		$picked = self::picked( $slug );
		return $picked ? $picked : self::latest( $slug );
	}

	/** Update entry for the plugin transient. */
	private static function item( string $slug, array $rel ): object {
		return (object) array(
			'id'           => 'noxpress.tech/' . $slug,
			'slug'         => $slug,
			'plugin'       => Noxpress_Core::plugin_file( $slug ),
			'new_version'  => $rel['version'],
			'url'          => 'https://noxpress.tech',
			'package'      => $rel['zip'],
			'requires'     => $rel['requires'],
			'requires_php' => $rel['requires_php'],
			'tested'       => $rel['tested'],
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/**
	 * site_transient_update_plugins: during a hub "Install this beta" update,
	 * WordPress reads the picked beta. Read-time only, never saved.
	 */
	public static function pick_transient( $transient ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::PICK ] ) || ! is_object( $transient ) ) {
			return $transient;
		}
		foreach ( array_keys( Noxpress_Core::suite() ) as $slug ) {
			$rel = self::picked( $slug );
			if ( ! $rel ) {
				continue;
			}
			$file = Noxpress_Core::plugin_file( $slug );
			$data = Noxpress_Core::installed();
			if ( isset( $data[ $slug ] ) && version_compare( $rel['version'], (string) $data[ $slug ]['Version'], '>' ) ) {
				$transient           = clone $transient;
				$transient->response = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
				$transient->response[ $file ] = self::item( $slug, $rel );
			}
		}
		return $transient;
	}

	/** pre_set_site_transient_update_plugins: suite entries from updates.json. */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$installed = Noxpress_Core::installed();
		if ( ! $installed ) {
			return $transient;
		}
		$manifest = self::manifest();

		foreach ( $installed as $slug => $data ) {
			$file = Noxpress_Core::plugin_file( $slug );
			$rel  = self::latest( $slug, $manifest );

			// Never keep an entry from another source (e.g. a WordPress.org
			// plugin with the same slug, for versions without Update URI).
			if ( isset( $transient->response[ $file ] ) ) {
				unset( $transient->response[ $file ] );
			}
			if ( isset( $transient->no_update[ $file ] ) ) {
				unset( $transient->no_update[ $file ] );
			}
			if ( ! $rel ) {
				continue;
			}

			$item = self::item( $slug, $rel );

			$current = isset( $data['Version'] ) ? (string) $data['Version'] : '0';
			if ( version_compare( $rel['version'], $current, '>' ) ) {
				$transient->response[ $file ] = $item;
			} else {
				$transient->no_update[ $file ] = $item;
			}
		}
		return $transient;
	}

	/** plugins_api: details popup and install source for suite plugins. */
	public static function plugins_api( $result, $action, $args ) {
		$info = self::info( $action, $args );
		return $info ? $info : $result;
	}

	/**
	 * plugins_api and plugins_api_result, last: puts the suite's details back
	 * when a later filter replaced them.
	 */
	public static function plugins_api_last( $result, $action, $args ) {
		if ( is_object( $result ) && ! empty( $result->noxpress ) ) {
			return $result;
		}
		$info = self::info( $action, $args );
		return $info ? $info : $result;
	}

	/** Plugin details of a suite plugin for plugins_api(), or null. */
	private static function info( $action, $args ): ?object {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || ! is_string( $args->slug ) ) {
			return null;
		}
		$slug    = $args->slug;
		$catalog = Noxpress_Core::suite();
		if ( ! isset( $catalog[ $slug ] ) ) {
			return null;
		}
		$rel = self::offer( $slug );
		if ( ! $rel ) {
			return null;
		}

		return (object) array(
			'name'          => $catalog[ $slug ]['name'],
			'slug'          => $slug,
			'version'       => $rel['version'],
			'author'        => '<a href="https://koulaxizis.gr">Christos Koulaxizis</a>',
			'homepage'      => 'https://noxpress.tech',
			'requires'      => $rel['requires'],
			'requires_php'  => $rel['requires_php'],
			'tested'        => $rel['tested'],
			'last_updated'  => $rel['published'],
			'download_link' => $rel['zip'],
			'sections'      => array(
				'description' => '<p>' . esc_html( $catalog[ $slug ]['desc'] ) . '</p>',
				'changelog'   => self::changelog_html( $rel ),
			),
			'external'      => true,
			'noxpress'      => true,
		);
	}

	/** Changelog text (readme "* item" lines) as escaped HTML. */
	public static function changelog_html( array $rel ): string {
		$items = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $rel['changelog'] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$items[] = '<li>' . esc_html( ltrim( $line, "*- \t" ) ) . '</li>';
		}
		$title = '<h4>' . esc_html( $rel['version'] ) . ( ! empty( $rel['beta'] ) ? ' (beta)' : '' ) . '</h4>';
		return $title . ( $items ? '<ul>' . implode( '', $items ) . '</ul>' : '' );
	}

	/**
	 * upgrader_pre_download: suite packages are downloaded here and
	 * verified before WordPress unpacks them.
	 *
	 * @return false|string|WP_Error
	 */
	public static function pre_download( $reply, $package, $upgrader ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, self::PACKAGE_PREFIX ) ) {
			return $reply;
		}

		$found = null;
		$slug  = '';
		foreach ( self::manifest() as $s => $entry ) {
			foreach ( array( 'stable', 'beta' ) as $channel ) {
				if ( $entry[ $channel ] && $entry[ $channel ]['zip'] === $package ) {
					$found = $entry[ $channel ];
					$slug  = $s;
					break 2;
				}
			}
		}
		if ( ! $found ) {
			return new WP_Error( 'noxpress_unknown_package', __( 'Το πακέτο δεν υπάρχει στη λίστα εκδόσεων του Noxpress.', 'noxpress' ) );
		}
		if ( ! self::fresh( $found ) ) {
			return new WP_Error( 'noxpress_expired', __( 'Η λίστα εκδόσεων έχει λήξει. Η εγκατάσταση ακυρώθηκε.', 'noxpress' ) );
		}
		$installed = Noxpress_Core::installed();
		if ( isset( $installed[ $slug ]['Version'] ) && version_compare( $found['version'], (string) $installed[ $slug ]['Version'], '<' ) ) {
			return new WP_Error( 'noxpress_downgrade', __( 'Το πακέτο είναι παλιότερο από την εγκατεστημένη έκδοση. Η εγκατάσταση ακυρώθηκε.', 'noxpress' ) );
		}

		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && isset( $upgrader->strings['downloading_package'] ) ) {
			$upgrader->skin->feedback( 'downloading_package', $package );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$error = self::verify( $file, $slug, $found );
		if ( $error ) {
			wp_delete_file( $file );
			return $error;
		}
		return $file;
	}

	/** Null when the file matches the release, else the error. */
	public static function verify( string $file, string $slug, array $rel ): ?WP_Error {
		$sha = (string) hash_file( 'sha256', $file );
		if ( ! hash_equals( $rel['sha256'], $sha ) ) {
			return new WP_Error( 'noxpress_sha256', __( 'Το πακέτο δεν ταιριάζει με το sha256 της λίστας εκδόσεων. Η εγκατάσταση ακυρώθηκε.', 'noxpress' ) );
		}

		$valid = isset( $rel['channel'], $rel['serial'], $rel['expires'] )
			&& self::signed( self::message( $slug, array( 'sha256' => $sha ) + $rel ), $rel['signature'] );
		if ( ! $valid ) {
			return new WP_Error( 'noxpress_signature', __( 'Η ψηφιακή υπογραφή του πακέτου δεν είναι έγκυρη. Η εγκατάσταση ακυρώθηκε.', 'noxpress' ) );
		}
		return null;
	}

	/** Fresh manifest and a rebuilt update transient (the hub's "Check now"). */
	public static function refresh(): void {
		self::manifest( true );
		delete_site_transient( 'update_plugins' );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}
		wp_update_plugins();
	}

	/** Rebuild the transient from the cached manifest (after a channel change). */
	public static function reapply(): void {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		set_site_transient( 'update_plugins', $transient );
	}
}
