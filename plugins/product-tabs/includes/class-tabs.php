<?php
/**
 * PTB_Tabs — the per-product data and which tabs a product shows.
 *
 * Product meta _ptb_tabs (JSON, wp_slash on write — Bible, RS 1.9.3):
 *  {
 *    "tabs":     [ { id, title, type, content, video, role, priority,
 *                    on, share, src } ],   up to MAX_TABS
 *    "hide":     [ shared tab ids hidden on this product ],
 *    "over":     { shared tab id: own HTML instead of the shared one },
 *    "imported": true once the import from Custom Product Tabs ran
 *  }
 *  - type     'text' | 'video'
 *  - video    'yt:<11 chars>' | 'vm:<digits>' | ''
 *  - role     '' | 'trailer' | 'presentation' (videos only)
 *  - priority WooCommerce tab priority, 1–200 (description 10,
 *             additional information 20, reviews 30)
 *  - share    also shown on the other formats of the product's work
 *             (Product Formats), unless they have a tab of that role
 *  - src      '' | 'wb' (made by the import; the undo removes those)
 *
 * Reads are cached per request. Nothing here writes on a visitor request.
 */

defined( 'ABSPATH' ) || exit;

final class PTB_Tabs {

	const META     = '_ptb_tabs';
	const MAX_TABS = 12;
	const ROLES    = array( 'trailer', 'presentation' );

	const YT_RE = '[A-Za-z0-9_-]{11}';

	/** @var array<int,array> Per-request cache of get(). */
	private static $cache = array();

	/* =====================================================================
	 * Video addresses
	 * =================================================================== */

	/**
	 * A video address, URL or bare id → 'yt:ID' | 'vm:ID' | ''.
	 * YouTube: watch?v=, youtu.be/, /embed/, /shorts/, /live/ or an
	 * 11-character id; Vimeo: vimeo.com/123, player.vimeo.com/video/123.
	 */
	public static function parse_video( string $in ): string {
		$in = trim( html_entity_decode( $in, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $in ) {
			return '';
		}
		$id  = self::YT_RE;
		$end = '(?![A-Za-z0-9_-])';
		if ( preg_match( '/^yt:(' . $id . ')$/', $in, $m ) || preg_match( '/^(' . $id . ')$/', $in, $m ) ) {
			return 'yt:' . $m[1];
		}
		if ( preg_match( '/^vm:(\d{1,12})$/', $in, $m ) ) {
			return 'vm:' . $m[1];
		}
		$pats = array(
			'#youtube(?:-nocookie)?\.com/watch\?(?:[^"\'\s<]*?&(?:amp;)?)?v=(' . $id . ')' . $end . '#i',
			'#youtu\.be/(' . $id . ')' . $end . '#i',
			'#youtube(?:-nocookie)?\.com/(?:embed|shorts|live)/(' . $id . ')' . $end . '#i',
		);
		foreach ( $pats as $p ) {
			if ( preg_match( $p, $in, $m ) ) {
				return 'yt:' . $m[1];
			}
		}
		if ( preg_match( '#(?:player\.)?vimeo\.com/(?:video/)?(\d{1,12})(?!\d)#i', $in, $m ) ) {
			return 'vm:' . $m[1];
		}
		return '';
	}

	/** The YouTube id of a 'yt:' value, else ''. */
	public static function yt_id( string $video ): string {
		return 0 === strpos( $video, 'yt:' ) ? substr( $video, 3 ) : '';
	}

	/** A link a person can open (admin lists, abilities). */
	public static function video_url( string $video ): string {
		if ( 0 === strpos( $video, 'yt:' ) ) {
			return 'https://www.youtube.com/watch?v=' . substr( $video, 3 );
		}
		if ( 0 === strpos( $video, 'vm:' ) ) {
			return 'https://vimeo.com/' . substr( $video, 3 );
		}
		return '';
	}

	/* =====================================================================
	 * One tab
	 * =================================================================== */

	public static function tab_defaults(): array {
		return array(
			'id'       => '',
			'title'    => '',
			'type'     => 'text',
			'content'  => '',
			'video'    => '',
			'role'     => '',
			'priority' => 40,
			'on'       => true,
			'share'    => false,
			'src'      => '',
		);
	}

	/**
	 * One tab, or null when it shows nothing. A video tab without a title
	 * keeps '' (the role's visitor text is shown).
	 */
	public static function tab( array $row, bool $kses ): ?array {
		$t             = self::tab_defaults();
		$t['id']       = isset( $row['id'] ) && is_string( $row['id'] ) && preg_match( '/^[a-f0-9]{8}$/', $row['id'] ) ? $row['id'] : PTB_Settings::new_id();
		$t['title']    = PTB_Settings::text_field( isset( $row['title'] ) && is_scalar( $row['title'] ) ? (string) $row['title'] : '' );
		$t['type']     = isset( $row['type'] ) && 'video' === $row['type'] ? 'video' : 'text';
		$t['content']  = PTB_Settings::html( isset( $row['content'] ) && is_scalar( $row['content'] ) ? (string) $row['content'] : '', $kses );
		$t['video']    = self::parse_video( isset( $row['video'] ) && is_scalar( $row['video'] ) ? (string) $row['video'] : '' );
		$t['role']     = isset( $row['role'] ) && in_array( $row['role'], self::ROLES, true ) ? $row['role'] : '';
		$t['priority'] = PTB_Settings::int_in( $row['priority'] ?? 40, 1, 200, 40 );
		$t['on']       = ! array_key_exists( 'on', $row ) || (bool) $row['on'];
		$t['share']    = ! empty( $row['share'] );
		$t['src']      = isset( $row['src'] ) && 'wb' === $row['src'] ? 'wb' : '';

		if ( 'video' === $t['type'] ) {
			if ( '' === $t['video'] ) {
				return null;
			}
			$t['content'] = '';
		} else {
			$t['video'] = '';
			$t['role']  = '';
			if ( '' === trim( $t['content'] ) || '' === $t['title'] ) {
				return null;
			}
		}
		return $t;
	}

	/* =====================================================================
	 * Product meta
	 * =================================================================== */

	public static function empty_data(): array {
		return array(
			'tabs'     => array(),
			'hide'     => array(),
			'over'     => array(),
			'imported' => false,
		);
	}

	/** The product's data, validated (reads only). */
	public static function get( int $product_id ): array {
		if ( $product_id <= 0 ) {
			return self::empty_data();
		}
		if ( isset( self::$cache[ $product_id ] ) ) {
			return self::$cache[ $product_id ];
		}
		$raw  = get_post_meta( $product_id, self::META, true );
		$raw  = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$data = self::sanitize( is_array( $raw ) ? $raw : array(), false );

		self::$cache[ $product_id ] = $data;
		return $data;
	}

	/** Validation; $kses on the HTML of users without unfiltered_html. */
	public static function sanitize( array $raw, bool $kses ): array {
		$d    = self::empty_data();
		$seen = array();
		foreach ( isset( $raw['tabs'] ) && is_array( $raw['tabs'] ) ? $raw['tabs'] : array() as $row ) {
			$t = is_array( $row ) ? self::tab( $row, $kses ) : null;
			if ( null === $t || isset( $seen[ $t['id'] ] ) ) {
				continue;
			}
			$seen[ $t['id'] ] = true;
			$d['tabs'][]      = $t;
			if ( count( $d['tabs'] ) >= self::MAX_TABS ) {
				break;
			}
		}
		foreach ( isset( $raw['hide'] ) && is_array( $raw['hide'] ) ? $raw['hide'] : array() as $id ) {
			if ( is_string( $id ) && preg_match( '/^[a-f0-9]{8}$/', $id ) ) {
				$d['hide'][ $id ] = $id;
			}
		}
		$d['hide'] = array_values( array_slice( $d['hide'], 0, PTB_Settings::MAX_SHARED ) );
		foreach ( isset( $raw['over'] ) && is_array( $raw['over'] ) ? $raw['over'] : array() as $id => $html ) {
			if ( is_string( $id ) && preg_match( '/^[a-f0-9]{8}$/', $id ) && is_string( $html ) && '' !== trim( $html ) && count( $d['over'] ) < PTB_Settings::MAX_SHARED ) {
				$d['over'][ $id ] = PTB_Settings::html( $html, $kses );
			}
		}
		$d['imported'] = ! empty( $raw['imported'] );
		return $d;
	}

	/**
	 * Writes the product's data (admin, import). Empty data deletes the
	 * meta. Returns true when something changed.
	 */
	public static function put( int $product_id, array $data ): bool {
		$data   = self::sanitize( $data, false ); // Callers ran kses already.
		$before = get_post_meta( $product_id, self::META, true );
		unset( self::$cache[ $product_id ] );
		if ( ! $data['tabs'] && ! $data['hide'] && ! $data['over'] && ! $data['imported'] ) {
			if ( '' === $before ) {
				return false;
			}
			delete_post_meta( $product_id, self::META );
			return true;
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || $json === $before ) {
			return false;
		}
		update_post_meta( $product_id, self::META, wp_slash( $json ) );
		return true;
	}

	/** Drops the per-request cache (after writes, tests). */
	public static function flush(): void {
		self::$cache = array();
	}

	/* =====================================================================
	 * What a product shows
	 * =================================================================== */

	/**
	 * Shared tabs whose rules match a product (ignores "hide" and "on").
	 *
	 * @return array[]
	 */
	public static function shared_for( WC_Product $p ): array {
		$out = array();
		$pid = (int) $p->get_id();
		$ids = array( $pid );
		if ( $p->get_parent_id() ) {
			$ids[] = (int) $p->get_parent_id();
		}
		$cats  = null;
		$tags  = null;
		$kinds = null;
		foreach ( PTB_Settings::shared() as $t ) {
			if ( in_array( $pid, $t['exclude'], true ) ) {
				continue;
			}
			$hit = $t['all'];
			if ( ! $hit && $t['cats'] ) {
				if ( null === $cats ) {
					$cats = self::terms_with_parents( $pid, 'product_cat' );
				}
				$hit = (bool) array_intersect( $t['cats'], $cats );
			}
			if ( ! $hit && $t['tags'] ) {
				if ( null === $tags ) {
					$tags = array_map( 'intval', wc_get_product_term_ids( $pid, 'product_tag' ) );
				}
				$hit = (bool) array_intersect( $t['tags'], $tags );
			}
			if ( ! $hit && $t['kinds'] ) {
				if ( null === $kinds ) {
					$kinds = array( (string) $p->get_type() );
					if ( $p->is_downloadable() ) {
						$kinds[] = 'downloadable';
					}
					if ( $p->is_virtual() ) {
						$kinds[] = 'virtual';
					}
				}
				$hit = (bool) array_intersect( $t['kinds'], $kinds );
			}
			if ( $hit ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * A product's term ids of a taxonomy, with every ancestor (a rule on
	 * "Books" also covers "Books > Poetry").
	 *
	 * @return int[]
	 */
	private static function terms_with_parents( int $pid, string $tax ): array {
		$ids = array_map( 'intval', wc_get_product_term_ids( $pid, $tax ) );
		$all = $ids;
		foreach ( $ids as $id ) {
			foreach ( get_ancestors( $id, $tax, 'taxonomy' ) as $a ) {
				$all[] = (int) $a;
			}
		}
		return array_values( array_unique( $all ) );
	}

	/**
	 * Tabs that the other formats of the product's work share with it
	 * (Product Formats), each with 'from' (the product id it comes from).
	 * Roles and videos the product already has are left out.
	 *
	 * @param int   $pid  Product id.
	 * @param array $own  The product's own tabs.
	 * @return array[]
	 */
	public static function from_work( int $pid, array $own ): array {
		if ( ! function_exists( 'pfm_get_work' ) ) {
			return array();
		}
		$work = pfm_get_work( $pid );
		if ( ! is_array( $work ) || empty( $work['members'] ) || ! is_array( $work['members'] ) ) {
			return array();
		}
		$roles  = array();
		$videos = array();
		$titles = array();
		foreach ( $own as $t ) {
			if ( ! $t['on'] ) {
				continue;
			}
			if ( '' !== $t['role'] ) {
				$roles[ $t['role'] ] = true;
			}
			if ( '' !== $t['video'] ) {
				$videos[ $t['video'] ] = true;
			}
			$titles[ strtolower( $t['title'] ) ] = true;
		}
		$out = array();
		$n   = 0;
		foreach ( $work['members'] as $m ) {
			$mid = is_array( $m ) && isset( $m['id'] ) ? (int) $m['id'] : 0;
			if ( $mid <= 0 || $mid === $pid || empty( $m['visible'] ) || ( isset( $m['status'] ) && 'publish' !== $m['status'] ) ) {
				continue;
			}
			if ( ++$n > 20 ) {
				break;
			}
			foreach ( self::get( $mid )['tabs'] as $t ) {
				if ( ! $t['on'] || ! $t['share'] ) {
					continue;
				}
				if ( ( '' !== $t['role'] && isset( $roles[ $t['role'] ] ) ) || ( '' !== $t['video'] && isset( $videos[ $t['video'] ] ) ) ) {
					continue;
				}
				if ( 'text' === $t['type'] && isset( $titles[ strtolower( $t['title'] ) ] ) ) {
					continue;
				}
				if ( '' !== $t['role'] ) {
					$roles[ $t['role'] ] = true;
				}
				if ( '' !== $t['video'] ) {
					$videos[ $t['video'] ] = true;
				}
				$titles[ strtolower( $t['title'] ) ] = true;
				$t['from'] = $mid;
				$out[]     = $t;
			}
		}
		return $out;
	}

	/** The YouTube id for a role: own tabs, then the work's (API). */
	public static function video_for_role( int $product_id, string $role ): string {
		if ( ! in_array( $role, self::ROLES, true ) ) {
			return '';
		}
		$own = self::get( $product_id )['tabs'];
		foreach ( $own as $t ) {
			if ( $t['on'] && $role === $t['role'] && '' !== self::yt_id( $t['video'] ) ) {
				return self::yt_id( $t['video'] );
			}
		}
		foreach ( self::from_work( $product_id, $own ) as $t ) {
			if ( $role === $t['role'] && '' !== self::yt_id( $t['video'] ) ) {
				return self::yt_id( $t['video'] );
			}
		}
		return '';
	}
}
