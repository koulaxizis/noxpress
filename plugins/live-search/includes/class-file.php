<?php
/**
 * LVS_File — the public index file the browser searches (file mode).
 *
 * uploads/live-search/index-<12 hex>.json, the hex from the content, so a
 * new build is a new address: browsers and CDNs may keep a file for ever.
 * Cached pages (Fast Pages: at most 12 h, Bible §14.34) name the file of
 * their time, so files are deleted only 13 h after a newer one exists.
 * The folder holds only these files and an empty index.php; every delete
 * goes through the name pattern first.
 *
 * What the file holds — only what any visitor sees on the product page:
 *   v       format version (1); no time inside, so an unchanged
 *           catalogue gives the same file name
 *   items   [ id, title, people, terms, codes, phon, work, oos, sales,
 *             card{ t, u, i, b, f, l, o, p, q, r } ], best sellers first
 *   works   { work id: [ product ids in the order of Product Formats ] }
 *   cr      [ [ name, url, normalised name, phonetic ] ] (Featured Creators)
 *   syn     synonyms, normalised word => alternatives
 * Built as a guest (current user 0), so Hide Catalog's test mode and any
 * role price never reach it. Products of a category that Hide Catalog
 * hides from search are left out.
 */

defined( 'ABSPATH' ) || exit;

final class LVS_File {

	const OPT     = 'lvs_file';
	const DIR     = 'live-search';
	const NAME_RE = '/^index-[a-f0-9]{12}\.json$/';
	const KEEP    = 46800; // 13 h.

	public static function state(): array {
		$s = get_option( self::OPT, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function base(): array {
		$up = wp_upload_dir( null, false );
		if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) {
			return array( '', '' );
		}
		return array(
			rtrim( (string) $up['basedir'], '/\\' ) . '/' . self::DIR,
			rtrim( (string) $up['baseurl'], '/' ) . '/' . self::DIR,
		);
	}

	/** URL of the current file, or '' when there is none. */
	public static function current_url(): string {
		$s = self::state();
		if ( empty( $s['name'] ) || ! preg_match( self::NAME_RE, (string) $s['name'] ) ) {
			return '';
		}
		list( $dir, $url ) = self::base();
		if ( '' === $dir || ! is_file( $dir . '/' . $s['name'] ) ) {
			return '';
		}
		return set_url_scheme( $url . '/' . $s['name'] );
	}

	/** Builds the file when the plugin is on and the engine is the file. */
	public static function maybe_build( bool $force = false ): void {
		$s = LVS_Settings::get();
		if ( 'off' === $s['mode'] && ! $force ) {
			return;
		}
		if ( 'file' !== LVS_Settings::engine() ) {
			return;
		}
		self::build();
	}

	/**
	 * Writes a new file. Returns the bytes written, or 0.
	 */
	public static function build(): int {
		list( $dir ) = self::base();
		if ( '' === $dir ) {
			return 0;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return 0;
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$data = self::data();
		} finally {
			wp_set_current_user( $user );
		}

		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return 0;
		}
		$name = 'index-' . substr( md5( $json ), 0, 12 ) . '.json';
		$path = $dir . '/' . $name;
		$old  = self::state();
		if ( ! is_file( $path ) ) {
			$tmp = $path . '.tmp';
			if ( false === @file_put_contents( $tmp, $json ) || ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return 0;
			}
		}
		$replaced = isset( $old['replaced'] ) && is_array( $old['replaced'] ) ? $old['replaced'] : array();
		if ( ! empty( $old['name'] ) && $old['name'] !== $name ) {
			$replaced[ (string) $old['name'] ] = time();
		}
		unset( $replaced[ $name ] );
		update_option(
			self::OPT,
			array(
				'name'     => $name,
				'bytes'    => strlen( $json ),
				'count'    => count( $data['items'] ),
				'built'    => time(),
				'replaced' => $replaced,
			),
			false
		);
		self::cleanup();
		return strlen( $json );
	}

	/** The content of the file (as a guest). */
	public static function data(): array {
		$s      = LVS_Settings::get();
		$hidden = function_exists( 'hic_hidden_terms' ) ? hic_hidden_terms( 'search' ) : array();
		$items  = array();
		$works  = array();
		foreach ( LVS_Index::all_rows() as $r ) {
			if ( 'hide' === $s['oos'] && (int) $r['oos'] ) {
				continue;
			}
			if ( $hidden && '' !== $r['cats'] ) {
				$cats = array_map( 'intval', array_filter( explode( ',', (string) $r['cats'] ), 'strlen' ) );
				if ( array_intersect( $cats, $hidden ) ) {
					continue;
				}
			}
			$card = json_decode( (string) $r['card'], true );
			if ( ! is_array( $card ) ) {
				continue;
			}
			$id      = (int) $r['product_id'];
			$work    = $s['group'] ? (int) $r['work'] : 0;
			$items[] = array(
				$id,
				(string) $r['title'],
				(string) $r['people'],
				(string) $r['terms'],
				(string) $r['codes'],
				(string) $r['phon'],
				$work,
				(int) $r['oos'],
				(int) $r['sales'],
				$card,
			);
			if ( $work ) {
				$works[ $work ][] = $id;
			}
		}
		foreach ( $works as $w => $ids ) {
			if ( count( $ids ) < 2 ) {
				unset( $works[ $w ] );
			} else {
				$works[ $w ] = self::order_members( (int) $w, $ids );
			}
		}
		return array(
			'v'     => 1,
			'items' => $items,
			'works' => (object) $works,
			'cr'    => $s['creators'] ? self::creators() : array(),
			'syn'   => (object) LVS_Settings::synonym_map(),
		);
	}

	/** Members in the order Product Formats gives them. */
	private static function order_members( int $work, array $ids ): array {
		if ( ! function_exists( 'pfm_get_work' ) ) {
			return $ids;
		}
		$w = pfm_get_work( $ids[0] );
		if ( ! is_array( $w ) || (int) ( $w['id'] ?? 0 ) !== $work ) {
			return $ids;
		}
		$out = array();
		foreach ( (array) ( $w['members'] ?? array() ) as $m ) {
			$mid = (int) ( $m['id'] ?? 0 );
			if ( in_array( $mid, $ids, true ) ) {
				$out[] = $mid;
			}
		}
		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** Published creator pages of Featured Creators, at most 2000. */
	public static function creators(): array {
		if ( ! post_type_exists( 'nox_creator' ) ) {
			return array();
		}
		$q   = new WP_Query(
			array(
				'post_type'              => 'nox_creator',
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => 2000,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);
		$out = array();
		foreach ( $q->posts as $post ) {
			$name = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$n    = LVS_Text::norm( $name );
			if ( '' === $n ) {
				continue;
			}
			$out[] = array( $name, (string) get_permalink( $post ), $n, LVS_Text::phon( $n ) );
		}
		return $out;
	}

	/** Deletes files replaced more than 13 h ago (and strays of this folder). */
	public static function cleanup(): int {
		list( $dir ) = self::base();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}
		$s        = self::state();
		$current  = (string) ( $s['name'] ?? '' );
		$replaced = isset( $s['replaced'] ) && is_array( $s['replaced'] ) ? $s['replaced'] : array();
		$n        = 0;
		foreach ( (array) scandir( $dir ) as $f ) {
			$f = (string) $f;
			if ( $f === $current || ! preg_match( self::NAME_RE, $f ) ) {
				continue;
			}
			$since = isset( $replaced[ $f ] ) ? (int) $replaced[ $f ] : (int) @filemtime( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $since > 0 && $since < time() - self::KEEP ) {
				if ( @unlink( $dir . '/' . $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					$n++;
				}
				unset( $replaced[ $f ] );
			}
		}
		foreach ( array_keys( $replaced ) as $f ) {
			if ( ! is_file( $dir . '/' . $f ) ) {
				unset( $replaced[ $f ] );
			}
		}
		if ( $s ) {
			$s['replaced'] = $replaced;
			update_option( self::OPT, $s, false );
		}
		return $n;
	}

	/** Every file and the folder (uninstall runs its own copy of this). */
	public static function delete_all(): void {
		list( $dir ) = self::base();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			$f = (string) $f;
			if ( preg_match( self::NAME_RE, $f ) || 'index.php' === $f || preg_match( '/^index-[a-f0-9]{12}\.json\.tmp$/', $f ) ) {
				@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		delete_option( self::OPT );
	}
}
