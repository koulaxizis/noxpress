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
 *     downloads it here and checks the sha256 and the signature of
 *     "noxpress|<slug>|<version>|<sha256>". A failed check stops the install.
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

	/** Site transient holding the decoded manifest (or an empty array after a failure). */
	const CACHE = 'noxpress_manifest';

	/** Site option: 'stable' | 'beta'. */
	const OPT_CHANNEL = 'noxpress_channel';

	/** Site option: unix time of the last manifest fetch attempt. */
	const OPT_CHECKED = 'noxpress_checked';

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
		// Other plugins' plugins_api filters run after ours and some of them
		// replace any result (e.g. with WordPress.org's "Plugin not found").
		// The suite's own details win at the very end of both filters.
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api_last' ), PHP_INT_MAX, 3 );
		add_filter( 'plugins_api_result', array( __CLASS__, 'plugins_api_last' ), PHP_INT_MAX, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 3 );
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
		$response = wp_safe_remote_get(
			self::MANIFEST_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = self::parse( (string) wp_remote_retrieve_body( $response ) );
		}

		update_site_option( self::OPT_CHECKED, time() );
		set_site_transient( self::CACHE, $data, $data ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	/** Strict parse of updates.json: unknown plugins and malformed releases are dropped. */
	public static function parse( string $json ): array {
		$raw = json_decode( $json, true );
		if ( ! is_array( $raw ) || ! isset( $raw['plugins'] ) || ! is_array( $raw['plugins'] ) ) {
			return array();
		}
		$out = array();
		foreach ( array_keys( Noxpress_Core::catalog() ) as $slug ) {
			if ( ! isset( $raw['plugins'][ $slug ] ) || ! is_array( $raw['plugins'][ $slug ] ) ) {
				continue;
			}
			$entry = array();
			foreach ( array( 'stable', 'beta' ) as $channel ) {
				$rel               = isset( $raw['plugins'][ $slug ][ $channel ] ) ? self::release( $slug, $raw['plugins'][ $slug ][ $channel ] ) : null;
				$entry[ $channel ] = $rel;
			}
			if ( $entry['stable'] || $entry['beta'] ) {
				$out[ $slug ] = $entry;
			}
		}
		return $out;
	}

	/** One validated release, or null. */
	private static function release( string $slug, $r ): ?array {
		if ( ! is_array( $r ) ) {
			return null;
		}
		$version = isset( $r['version'] ) ? (string) $r['version'] : '';
		$zip     = isset( $r['zip'] ) ? (string) $r['zip'] : '';
		$sha     = isset( $r['sha256'] ) ? strtolower( (string) $r['sha256'] ) : '';
		$sig     = isset( $r['signature'] ) ? (string) $r['signature'] : '';

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

		$text = static function ( $key, $max ) use ( $r ): string {
			return isset( $r[ $key ] ) && is_scalar( $r[ $key ] ) ? substr( sanitize_textarea_field( (string) $r[ $key ] ), 0, $max ) : '';
		};

		return array(
			'version'      => $version,
			'zip'          => $zip,
			'sha256'       => $sha,
			'signature'    => $sig,
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
		$stable = $manifest[ $slug ]['stable'];
		$beta   = $manifest[ $slug ]['beta'];
		if ( 'beta' === self::channel() && $beta && ( ! $stable || version_compare( $beta['version'], $stable['version'], '>' ) ) ) {
			return $beta + array( 'beta' => true );
		}
		if ( $stable ) {
			return $stable + array( 'beta' => false );
		}
		return null;
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

			$item = (object) array(
				'id'           => 'noxpress.tech/' . $slug,
				'slug'         => $slug,
				'plugin'       => $file,
				'new_version'  => $rel['version'],
				'url'          => 'https://noxpress.tech',
				'package'      => $rel['zip'],
				'requires'     => $rel['requires'],
				'requires_php' => $rel['requires_php'],
				'tested'       => $rel['tested'],
				'icons'        => array(),
				'banners'      => array(),
			);

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
		$catalog = Noxpress_Core::catalog();
		if ( ! isset( $catalog[ $slug ] ) ) {
			return null;
		}
		$rel = self::latest( $slug );
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

		$message = 'noxpress|' . $slug . '|' . $rel['version'] . '|' . $sha;
		$valid   = false;
		try {
			$valid = function_exists( 'sodium_crypto_sign_verify_detached' )
				&& sodium_crypto_sign_verify_detached(
					(string) base64_decode( $rel['signature'], true ),
					$message,
					(string) base64_decode( self::PUBLIC_KEY, true )
				);
		} catch ( \Throwable $e ) {
			$valid = false;
		}
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
