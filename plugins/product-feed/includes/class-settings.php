<?php
/**
 * PFD_Settings — options and validation.
 *
 *  - pfd_settings (autoload no): key (random folder name under
 *    uploads/product-feed/), drop (delete the feed files on uninstall).
 *  - pfd_feeds (autoload no): id => feed (see defaults()). At most 20.
 *  - pfd_status_{id} (autoload no): the last / running build (state,
 *    started, finished, count, skipped, warnings, issues, error, cursor,
 *    tmp, why).
 *
 * Every value that reaches a file name or a path is whitelisted here; the
 * builder never writes outside uploads/.
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Settings {

	const OPTION    = 'pfd_settings';
	const FEEDS     = 'pfd_feeds';
	const STATUS    = 'pfd_status_';
	const MAX_FEEDS = 20;
	const DIR       = 'product-feed';

	/** @var array|null */
	private static $cache = null;

	/** @var array|null */
	private static $feeds = null;

	/* =====================================================================
	 * Global settings
	 * =================================================================== */

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw = get_option( self::OPTION, array() );
			$raw = is_array( $raw ) ? $raw : array();
			$out = array(
				'key'  => isset( $raw['key'] ) && preg_match( '/^[a-z0-9]{16,40}$/', (string) $raw['key'] ) ? (string) $raw['key'] : '',
				'drop' => isset( $raw['drop'] ) ? (bool) $raw['drop'] : true,
			);
			if ( '' === $out['key'] ) {
				$out['key'] = strtolower( wp_generate_password( 24, false, false ) );
				update_option( self::OPTION, $out, false );
			}
			self::$cache = $out;
		}
		return self::$cache;
	}

	public static function save( array $in ): void {
		$cur         = self::get();
		$cur['drop'] = ! empty( $in['drop'] );
		update_option( self::OPTION, $cur, false );
		self::$cache = $cur;
	}

	/** New random folder: every feed URL changes (old files are removed). */
	public static function new_key(): void {
		$old = self::dir();
		$cur = self::get();
		$cur['key'] = strtolower( wp_generate_password( 24, false, false ) );
		update_option( self::OPTION, $cur, false );
		self::$cache = $cur;
		self::rmdir_own( $old );
	}

	/** Absolute folder of the feeds (no trailing slash). */
	public static function dir(): string {
		$up = wp_upload_dir( null, false );
		return untrailingslashit( (string) $up['basedir'] ) . '/' . self::DIR . '/' . self::get()['key'];
	}

	public static function base_url(): string {
		$up = wp_upload_dir( null, false );
		return untrailingslashit( set_url_scheme( (string) $up['baseurl'] ) ) . '/' . self::DIR . '/' . self::get()['key'];
	}

	public static function feed_path( array $f ): string {
		return self::dir() . '/' . $f['file'] . '.' . PFD_Channels::ext( $f['channel'] );
	}

	public static function feed_url( array $f ): string {
		return self::base_url() . '/' . rawurlencode( $f['file'] ) . '.' . PFD_Channels::ext( $f['channel'] );
	}

	/** Absolute legacy path, or '' (validated again on every use). */
	public static function legacy_path( array $f ): string {
		if ( '' === $f['legacy'] || ! self::valid_legacy( $f['legacy'], $f['channel'] ) ) {
			return '';
		}
		$up = wp_upload_dir( null, false );
		return untrailingslashit( (string) $up['basedir'] ) . '/' . $f['legacy'];
	}

	public static function legacy_url( array $f ): string {
		if ( '' === self::legacy_path( $f ) ) {
			return '';
		}
		$up = wp_upload_dir( null, false );
		return untrailingslashit( set_url_scheme( (string) $up['baseurl'] ) ) . '/' . $f['legacy'];
	}

	/**
	 * A path relative to uploads/: letters, digits, - _ . /, no «..», no
	 * leading slash, never inside our own folder, with the channel's
	 * extension.
	 */
	public static function valid_legacy( string $rel, string $channel ): bool {
		if ( '' === $rel || strlen( $rel ) > 160 || ! preg_match( '#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-.]+)*\.[a-z]{3}$#', $rel ) ) {
			return false;
		}
		if ( false !== strpos( $rel, '..' ) || 0 === strpos( $rel, self::DIR . '/' ) ) {
			return false;
		}
		return substr( $rel, -4 ) === '.' . PFD_Channels::ext( $channel );
	}

	/** Remove one of our own feed folders (only below uploads/product-feed/). */
	public static function rmdir_own( string $dir ): void {
		$up   = wp_upload_dir( null, false );
		$root = untrailingslashit( (string) $up['basedir'] ) . '/' . self::DIR . '/';
		if ( 0 !== strpos( $dir, $root ) || ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			$file = $dir . '/' . $name;
			if ( '.' !== $name && '..' !== $name && is_file( $file ) && ! is_link( $file ) ) {
				wp_delete_file( $file );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own empty folder.
	}

	/* =====================================================================
	 * Feeds
	 * =================================================================== */

	/** Defaults of a new feed of a channel. */
	public static function defaults( string $channel = 'google' ): array {
		$ch  = PFD_Channels::exists( $channel ) ? $channel : 'google';
		$cmp = in_array( $ch, array( 'skroutz', 'bestprice' ), true );
		$sv  = PFD_Channels::ship_values( $ch );
		return array(
			'name'         => PFD_Channels::label( $ch ),
			'channel'      => $ch,
			'file'         => $ch,
			'active'       => true,
			'every'        => 6,
			'on_change'    => true,
			'cats_in'      => array(),
			'cats_out'     => array(),
			'tags_out'     => array(),
			'instock_only' => $cmp,
			'hidden'       => false,
			'bookfeed'     => 'google' === $ch,
			'digital'      => false,
			'skip_errors'  => true,
			'variations'   => true,
			'legacy'       => '',
			'utm_source'   => '',
			'utm_medium'   => '',
			'utm_campaign' => '',
			'brand_src'    => 'brand',
			'brand_attr'   => '',
			'brand_fixed'  => '',
			'gtin_meta'    => '',
			'color_attr'   => '',
			'size_attr'    => '',
			'desc_src'     => 'long',
			'ship'         => $sv ? (string) $sv[0] : '',
			'ship_back'    => $sv ? (string) $sv[ count( $sv ) - 1 ] : '',
			'ship_cats'    => array(),
			'gcat'         => array(),
			'gcat_default' => 0,
			'qty_default'  => 1,
		);
	}

	public static function feeds(): array {
		if ( null === self::$feeds ) {
			$raw = get_option( self::FEEDS, array() );
			$out = array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $id => $f ) {
					if ( self::valid_id( (string) $id ) && is_array( $f ) ) {
						$out[ (string) $id ] = self::sanitize( $f );
					}
				}
			}
			self::$feeds = array_slice( $out, 0, self::MAX_FEEDS, true );
		}
		return self::$feeds;
	}

	public static function feed( string $id ): ?array {
		$all = self::feeds();
		return $all[ $id ] ?? null;
	}

	public static function valid_id( string $id ): bool {
		return (bool) preg_match( '/^f[a-f0-9]{8}$/', $id );
	}

	/**
	 * Save one feed (new when $id is ''). Returns the id, or a WP_Error for
	 * a file name another feed already uses or a full list.
	 *
	 * @return string|WP_Error
	 */
	public static function put( string $id, array $in ) {
		$all = self::feeds();
		$f   = self::sanitize( $in );
		if ( '' === $id ) {
			if ( count( $all ) >= self::MAX_FEEDS ) {
				return new WP_Error( 'full', 'full' );
			}
			do {
				$id = 'f' . substr( md5( wp_generate_password( 20, true, true ) ), 0, 8 );
			} while ( isset( $all[ $id ] ) );
		} elseif ( ! isset( $all[ $id ] ) ) {
			return new WP_Error( 'missing', 'missing' );
		}
		foreach ( $all as $oid => $o ) {
			if ( $oid !== $id && $o['file'] === $f['file'] && PFD_Channels::ext( $o['channel'] ) === PFD_Channels::ext( $f['channel'] ) ) {
				return new WP_Error( 'file', 'file' );
			}
			if ( $oid !== $id && '' !== $f['legacy'] && $o['legacy'] === $f['legacy'] ) {
				return new WP_Error( 'legacy', 'legacy' );
			}
		}
		$old = $all[ $id ] ?? null;
		if ( $old && ( $old['file'] !== $f['file'] || $old['channel'] !== $f['channel'] ) ) {
			$path = self::feed_path( $old );
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$all[ $id ] = $f;
		update_option( self::FEEDS, $all, false );
		self::$feeds = null;
		return $id;
	}

	public static function delete( string $id ): bool {
		$all = self::feeds();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		PFD_Builder::stop( $id ); // A running build drops its temporary file.
		$path = self::feed_path( $all[ $id ] );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		unset( $all[ $id ] );
		update_option( self::FEEDS, $all, false );
		delete_option( self::STATUS . $id );
		self::$feeds = null;
		return true;
	}

	/** Replace every feed (settings import): keeps only valid ones. */
	public static function replace_all( array $in ): int {
		$out = array();
		foreach ( $in as $id => $f ) {
			if ( self::valid_id( (string) $id ) && is_array( $f ) && count( $out ) < self::MAX_FEEDS ) {
				$out[ (string) $id ] = self::sanitize( $f );
			}
		}
		update_option( self::FEEDS, $out, false );
		self::$feeds = null;
		return count( $out );
	}

	/**
	 * Strict validation of one feed. Unknown keys go; a bad value falls
	 * back to its default.
	 */
	public static function sanitize( array $in ): array {
		$ch  = isset( $in['channel'] ) && PFD_Channels::exists( (string) $in['channel'] ) ? (string) $in['channel'] : 'google';
		$d   = self::defaults( $ch );
		$out = $d;

		$name = isset( $in['name'] ) ? trim( sanitize_text_field( (string) $in['name'] ) ) : '';
		if ( '' !== $name ) {
			$out['name'] = mb_substr( $name, 0, 80 );
		}
		$file = isset( $in['file'] ) ? strtolower( (string) $in['file'] ) : '';
		if ( preg_match( '/^[a-z0-9][a-z0-9\-]{0,39}$/', $file ) ) {
			$out['file'] = $file;
		}
		foreach ( array( 'active', 'on_change', 'instock_only', 'hidden', 'bookfeed', 'digital', 'skip_errors', 'variations' ) as $b ) {
			if ( isset( $in[ $b ] ) ) {
				$out[ $b ] = (bool) $in[ $b ];
			}
		}
		if ( 'skroutz' === $ch ) {
			$out['instock_only'] = true; // Skroutz has no «out of stock» value.
		}
		if ( isset( $in['every'] ) && in_array( (int) $in['every'], array( 1, 2, 3, 6, 12, 24 ), true ) ) {
			$out['every'] = (int) $in['every'];
		}
		foreach ( array( 'cats_in', 'cats_out', 'tags_out' ) as $k ) {
			if ( isset( $in[ $k ] ) && is_array( $in[ $k ] ) ) {
				$ids = array_values( array_unique( array_filter( array_map( 'absint', $in[ $k ] ) ) ) );
				sort( $ids );
				$out[ $k ] = array_slice( $ids, 0, 500 );
			}
		}
		if ( isset( $in['legacy'] ) ) {
			$rel           = ltrim( trim( (string) $in['legacy'] ), '/' );
			$out['legacy'] = self::valid_legacy( $rel, $ch ) ? $rel : '';
		}
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = mb_substr( trim( sanitize_text_field( (string) $in[ $k ] ) ), 0, 60 );
			}
		}
		if ( isset( $in['brand_src'] ) && in_array( $in['brand_src'], array( 'brand', 'attr', 'fixed' ), true ) ) {
			$out['brand_src'] = (string) $in['brand_src'];
		}
		foreach ( array( 'brand_attr', 'color_attr', 'size_attr' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$v         = sanitize_key( (string) $in[ $k ] );
				$out[ $k ] = strlen( $v ) <= 32 ? $v : '';
			}
		}
		if ( isset( $in['brand_fixed'] ) ) {
			$out['brand_fixed'] = mb_substr( trim( sanitize_text_field( (string) $in['brand_fixed'] ) ), 0, 70 );
		}
		if ( isset( $in['gtin_meta'] ) && preg_match( '/^[A-Za-z0-9_\-]{0,64}$/', (string) $in['gtin_meta'] ) ) {
			$out['gtin_meta'] = (string) $in['gtin_meta'];
		}
		if ( isset( $in['desc_src'] ) && in_array( $in['desc_src'], array( 'long', 'short' ), true ) ) {
			$out['desc_src'] = (string) $in['desc_src'];
		}
		$ships = PFD_Channels::ship_values( $ch );
		foreach ( array( 'ship', 'ship_back' ) as $k ) {
			if ( isset( $in[ $k ] ) && in_array( (string) $in[ $k ], $ships, true ) ) {
				$out[ $k ] = (string) $in[ $k ];
			}
		}
		if ( isset( $in['ship_cats'] ) && is_array( $in['ship_cats'] ) ) {
			$m = array();
			foreach ( $in['ship_cats'] as $cat => $v ) {
				if ( absint( $cat ) && in_array( (string) $v, $ships, true ) && count( $m ) < 200 ) {
					$m[ absint( $cat ) ] = (string) $v;
				}
			}
			$out['ship_cats'] = $m;
		}
		if ( isset( $in['gcat'] ) && is_array( $in['gcat'] ) ) {
			$m = array();
			foreach ( $in['gcat'] as $cat => $v ) {
				if ( absint( $cat ) && absint( $v ) && absint( $v ) < 1000000 && count( $m ) < 500 ) {
					$m[ absint( $cat ) ] = absint( $v );
				}
			}
			$out['gcat'] = $m;
		}
		if ( isset( $in['gcat_default'] ) && absint( $in['gcat_default'] ) < 1000000 ) {
			$out['gcat_default'] = absint( $in['gcat_default'] );
		}
		if ( isset( $in['qty_default'] ) && absint( $in['qty_default'] ) >= 1 && absint( $in['qty_default'] ) <= 9999 ) {
			$out['qty_default'] = absint( $in['qty_default'] );
		}
		return $out;
	}

	/* =====================================================================
	 * Build status
	 * =================================================================== */

	public static function status( string $id ): array {
		$raw = get_option( self::STATUS . $id, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array_merge(
			array(
				'state'    => 'idle',
				'started'  => 0,
				'finished' => 0,
				'count'    => 0,
				'products' => 0,
				'skipped'  => array(),
				'warnings' => array(),
				'issues'   => array(),
				'error'    => '',
				'cursor'   => 0,
				'tmp'      => '',
				'why'      => '',
				'size'     => 0,
				'run'      => array(),
			),
			$raw
		);
	}

	public static function set_status( string $id, array $st ): void {
		update_option( self::STATUS . $id, $st, false );
	}
}
