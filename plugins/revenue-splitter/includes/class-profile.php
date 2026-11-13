<?php
/**
 * RS_Profile — the creator's own titles, page and requests in the Author
 * Portal (1.16.0).
 *
 * Asked by Christos 2026-11-12; design and his answers (cards):
 * notes/rs-portal-profile/01-sxediasmos.md.
 *
 * Which titles are a creator's: the products whose own split names the
 * beneficiary (not those that only inherit the default split), plus, with
 * Featured Creators, the products whose author attribute belongs to the
 * creator page linked to the beneficiary. «Author» = the role taxonomies
 * chosen in «RS Δημιουργοί» (default: the Featured Creators roles mapped to
 * schema.org author). Illustrators and other roles do not count (Chris).
 * Variations count under their parent product.
 *
 * Portal (the signed-in beneficiary only, never a request parameter):
 *  - «Η σελίδα σου»: link to the creator page, and a change request (bio
 *    text, up to 6 links, a note and one photo, JPG/PNG/WebP up to 5 MB).
 *    The creator never edits the page; the shop applies the change by hand.
 *  - «Οι τίτλοι σου»: downloads of the digital files (in the period and in
 *    total, how many got the file, last download), sample opens (Book
 *    Preview), page views (Koko Analytics), wish lists (Wish Shelf),
 *    pre-orders (Pre-Orders), ratings and the latest reviews, a promotion
 *    kit per title, and a request for author copies.
 *  - «Οι καταστάσεις σου»: the monthly statements already sent, as PDF.
 *  Numbers only: never a buyer's name, email, IP or order. Downloads by
 *  shop staff (manage_woocommerce) do not count.
 *
 * Requests: option rs_profile_requests (autoload no), types bio|copies,
 * states new|done|declined. A new one emails the site's admin address;
 * nothing is emailed to the creator. Photos live in
 * uploads/noxpress-rs-photos/ (random names, index.php and .htaccess deny)
 * and are served to shop managers only; they are deleted 30 days after the
 * request is closed, and closed requests after 12 months.
 *
 * Options: rs_profiles (beneficiary → creator page / URL), rs_profile_settings
 * (author roles), rs_profile_requests. Transients: rs_dl_* (10 min cache),
 * rs_rl_p_* (rate limits).
 */

defined( 'ABSPATH' ) || exit;

final class RS_Profile {

	const OPT_MAP = 'rs_profiles';
	const OPT_SET = 'rs_profile_settings';
	const OPT_REQ = 'rs_profile_requests';
	/** 1.18.0: '1' = no admin email for new requests (orOS imports them). */
	const OPT_NOMAIL = 'rs_profile_no_email';
	const SLUG    = 'revenue-splitter-creators';
	const DIR     = 'noxpress-rs-photos';
	const CPT     = 'nox_creator';

	const BIO_MAX     = 5000;
	const NOTE_MAX    = 1000;
	const REPLY_MAX   = 500;
	const LINKS_MAX   = 6;
	const PHOTO_MAX   = 5242880; // 5 MB.
	const PHOTO_PX    = 8000;
	const QTY_MAX     = 500;
	const BEN_DAY     = 3;
	const IP_HOUR     = 10;
	const OPEN_MAX    = 300;
	const COPIES_OPEN = 3;
	const KEEP_DAYS   = 365;
	const PHOTO_DAYS  = 30;
	const SHOW_DAYS   = 60;
	const CACHE_TTL   = 600;

	/** @var array<string,int[]> Per-request cache of a beneficiary's titles. */
	private static $titles = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'route_portal' ), 11 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'route_admin' ) );
		add_action( 'admin_post_rs_pf_photo', array( __CLASS__, 'stream_photo' ) );
		add_action( RS_Emails::CRON_HOOK, array( __CLASS__, 'cleanup' ), 30 );
	}

	/* =====================================================================
	 * Storage
	 * =================================================================== */

	/** JSON text (what this plugin writes) or an array (what a remote tool such as Site Bridge may store). */
	private static function read_json( string $opt ): array {
		$raw = get_option( $opt, '' );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$raw = json_decode( is_string( $raw ) ? $raw : '', true );
		// JSON text stored as a JSON string (a remote tool encoded it twice).
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? $raw : array();
	}

	private static function write_json( string $opt, array $value ): void {
		update_option( $opt, wp_json_encode( $value, JSON_UNESCAPED_UNICODE ), false );
	}

	/** @return array<string,array{creator:int,url:string}> */
	public static function map(): array {
		$out = array();
		foreach ( self::read_json( self::OPT_MAP ) as $ben => $row ) {
			if ( is_array( $row ) ) {
				$out[ (string) $ben ] = array(
					'creator' => (int) ( $row['creator'] ?? 0 ),
					'url'     => (string) ( $row['url'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/** @return array{roles:string[]} */
	public static function settings(): array {
		$raw   = self::read_json( self::OPT_SET );
		$roles = isset( $raw['roles'] ) && is_array( $raw['roles'] ) ? array_values( array_filter( array_map( 'strval', $raw['roles'] ) ) ) : null;
		return array( 'roles' => null === $roles ? self::default_roles() : $roles );
	}

	/** True when Featured Creators is active (its post type exists). */
	public static function has_creators(): bool {
		return post_type_exists( self::CPT );
	}

	/**
	 * Role taxonomies of Featured Creators: tax => label.
	 *
	 * @return array<string,string>
	 */
	public static function role_taxes(): array {
		$out = array();
		if ( ! class_exists( 'CRT_Settings' ) || ! method_exists( 'CRT_Settings', 'get' ) ) {
			return $out;
		}
		$s = CRT_Settings::get();
		foreach ( isset( $s['roles'] ) && is_array( $s['roles'] ) ? $s['roles'] : array() as $r ) {
			$tax = is_array( $r ) ? (string) ( $r['tax'] ?? '' ) : '';
			if ( '' !== $tax && taxonomy_exists( $tax ) ) {
				$t           = get_taxonomy( $tax );
				$out[ $tax ] = $t ? (string) $t->labels->singular_name : $tax;
			}
		}
		return $out;
	}

	/** Roles mapped to schema.org author in Featured Creators. */
	private static function default_roles(): array {
		$out = array();
		if ( ! class_exists( 'CRT_Settings' ) || ! method_exists( 'CRT_Settings', 'get' ) ) {
			return $out;
		}
		$s = CRT_Settings::get();
		foreach ( isset( $s['roles'] ) && is_array( $s['roles'] ) ? $s['roles'] : array() as $r ) {
			if ( is_array( $r ) && 'author' === ( $r['schema'] ?? '' ) && ! empty( $r['tax'] ) ) {
				$out[] = (string) $r['tax'];
			}
		}
		return $out;
	}

	/** The creator page linked to a beneficiary (0 = none). */
	public static function creator_of( string $ben ): int {
		$m   = self::map();
		$cid = (int) ( $m[ $ben ]['creator'] ?? 0 );
		if ( $cid <= 0 || ! self::has_creators() || self::CPT !== get_post_type( $cid ) ) {
			return 0;
		}
		return $cid;
	}

	/** A creator page with exactly the beneficiary's name (accents and case ignored), or 0. */
	public static function suggest( string $ben ): int {
		if ( ! self::has_creators() ) {
			return 0;
		}
		$want = self::fold( $ben );
		$ids  = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		foreach ( $ids as $id ) {
			if ( self::fold( (string) get_the_title( (int) $id ) ) === $want ) {
				return (int) $id;
			}
		}
		return 0;
	}

	private static function fold( string $v ): string {
		$v = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $v ), 'UTF-8' ) : strtolower( trim( $v ) );
		$v = strtr( $v, array( 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ' ) );
		return (string) preg_replace( '/\s+/u', ' ', remove_accents( $v ) );
	}

	/**
	 * The beneficiary's page: [ url, public, image ] ('' url = none).
	 *
	 * @return array{url:string,public:bool,image:string,creator:int}
	 */
	public static function page( string $ben ): array {
		$cid = self::creator_of( $ben );
		if ( $cid > 0 ) {
			$p      = get_post( $cid );
			$public = $p instanceof WP_Post && 'publish' === $p->post_status && '' === $p->post_password;
			return array(
				'url'     => $public ? (string) get_permalink( $cid ) : '',
				'public'  => $public,
				'image'   => (string) get_the_post_thumbnail_url( $cid, 'thumbnail' ),
				'creator' => $cid,
			);
		}
		$m   = self::map();
		$url = (string) ( $m[ $ben ]['url'] ?? '' );
		return array(
			'url'     => $url,
			'public'  => '' !== $url,
			'image'   => '',
			'creator' => 0,
		);
	}

	/* =====================================================================
	 * Titles of a beneficiary
	 * =================================================================== */

	/** @return int[] Published parent products that are the beneficiary's (see the docblock). */
	public static function titles( string $ben ): array {
		if ( isset( self::$titles[ $ben ] ) ) {
			return self::$titles[ $ben ];
		}
		global $wpdb;
		$ids = array();

		// 1) The product's own split names the beneficiary.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only, one query per request.
		$with = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", RS_Beneficiaries::META_KEY ) );
		foreach ( (array) $with as $pid ) {
			$pid = (int) $pid;
			foreach ( RS_Beneficiaries::get_map( $pid ) as $s ) {
				if ( isset( $s['name'] ) && $s['name'] === $ben ) {
					$ids[] = $pid;
					break;
				}
			}
		}

		// 2) The linked creator page holds the product's author term.
		$cid   = self::creator_of( $ben );
		$roles = self::settings()['roles'];
		if ( $cid > 0 && ! empty( $roles ) ) {
			// Featured Creators keeps the owner of each attribute term in term meta crt_creator.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only, small table.
			$terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = 'crt_creator' AND meta_value = %s", (string) $cid ) );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$by_tax = array();
				foreach ( $terms as $tid ) {
					$t = get_term( (int) $tid );
					if ( $t instanceof WP_Term && in_array( $t->taxonomy, $roles, true ) ) {
						$by_tax[ $t->taxonomy ][] = (int) $t->term_id;
					}
				}
				$tq = array( 'relation' => 'OR' );
				foreach ( $by_tax as $tax => $tids ) {
					$tq[] = array(
						'taxonomy' => $tax,
						'field'    => 'term_id',
						'terms'    => $tids,
					);
				}
				$found = get_posts(
					array(
						'post_type'        => 'product',
						'post_status'      => 'publish',
						'posts_per_page'   => 500,
						'fields'           => 'ids',
						'tax_query'        => $tq, // phpcs:ignore WordPress.DB.SlowDBQuery
						'suppress_filters' => true,
						'no_found_rows'    => true,
					)
				);
				$ids = array_merge( $ids, array_map( 'intval', $found ) );
			}
		}

		$out = array();
		foreach ( array_unique( $ids ) as $pid ) {
			if ( 'product' === get_post_type( $pid ) && 'publish' === get_post_status( $pid ) ) {
				$out[] = (int) $pid;
			}
		}
		sort( $out );
		self::$titles[ $ben ] = $out;
		return $out;
	}

	/**
	 * Children (variations) of products: child id => parent id, parents included.
	 *
	 * @param int[] $parents Parent products.
	 * @return array<int,int>
	 */
	private static function with_children( array $parents ): array {
		global $wpdb;
		$map = array();
		foreach ( $parents as $p ) {
			$map[ (int) $p ] = (int) $p;
		}
		if ( empty( $parents ) ) {
			return $map;
		}
		$in = implode( ',', array_map( 'intval', $parents ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		$rows = $wpdb->get_results( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent IN ({$in})" );
		foreach ( (array) $rows as $r ) {
			$map[ (int) $r->ID ] = (int) $r->post_parent;
		}
		return $map;
	}

	/** @return int[] Ids of shop staff (manage_woocommerce), whose downloads do not count. */
	private static function staff_ids(): array {
		$ids = get_users(
			array(
				'capability' => 'manage_woocommerce',
				'fields'     => 'ID',
				'number'     => 500,
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/** Local day bounds → GMT 'Y-m-d H:i:s'. */
	private static function gmt( string $ymd, bool $end ): string {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		if ( ! $d ) {
			return $end ? '9999-12-31 23:59:59' : '1970-01-01 00:00:00';
		}
		if ( $end ) {
			$d = $d->setTime( 23, 59, 59 );
		}
		return $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Downloads of the beneficiary's digital files.
	 *
	 * @return array{rows:array<int,array>,months:array<string,int>,total:array<string,int>}
	 *   rows: parent id => { dl, dl_all, got, got_all, last (GMT) }; only titles
	 *   that have (or had) a downloadable file.
	 */
	public static function downloads( string $ben, string $from, string $to ): array {
		$titles = self::titles( $ben );
		$key    = 'rs_dl_' . md5( wp_json_encode( array( $ben, $from, $to, $titles ) ) );
		$hit    = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$out = array(
			'rows'   => array(),
			'months' => array(),
			'total'  => array(
				'dl'      => 0,
				'dl_all'  => 0,
				'got'     => 0,
				'got_all' => 0,
			),
		);
		if ( empty( $titles ) ) {
			return $out;
		}
		global $wpdb;
		$map   = self::with_children( $titles );
		$in    = implode( ',', array_map( 'intval', array_keys( $map ) ) );
		$staff = self::staff_ids();
		$log   = $wpdb->prefix . 'wc_download_log';
		$perm  = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

		$not_l = empty( $staff ) ? '' : ' AND ( l.user_id IS NULL OR l.user_id NOT IN (' . implode( ',', $staff ) . ') )';
		$not_p = empty( $staff ) ? '' : ' AND ( p.user_id IS NULL OR p.user_id NOT IN (' . implode( ',', $staff ) . ') )';
		$f     = self::gmt( $from, false );
		$t     = self::gmt( $to, true );

		// Which titles are digital: a downloadable product/variation now, or any permission ever granted.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers and fixed table names only; values through prepare().
		$digital = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_downloadable' AND meta_value = 'yes' AND post_id IN ({$in})" );
		$granted = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$perm} WHERE product_id IN ({$in})" );

		$dl_all  = $wpdb->get_results( "SELECT p.product_id AS pid, COUNT(*) AS n, MAX(l.timestamp) AS last FROM {$log} l JOIN {$perm} p ON p.permission_id = l.permission_id WHERE p.product_id IN ({$in}){$not_l} GROUP BY p.product_id" );
		$dl_per  = $wpdb->get_results( $wpdb->prepare( "SELECT p.product_id AS pid, COUNT(*) AS n FROM {$log} l JOIN {$perm} p ON p.permission_id = l.permission_id WHERE p.product_id IN ({$in}) AND l.timestamp >= %s AND l.timestamp <= %s{$not_l} GROUP BY p.product_id", $f, $t ) );
		$got_all = $wpdb->get_results( "SELECT p.product_id AS pid, COUNT(DISTINCT p.order_id) AS n FROM {$perm} p WHERE p.product_id IN ({$in}){$not_p} GROUP BY p.product_id" );
		$got_per = $wpdb->get_results( $wpdb->prepare( "SELECT p.product_id AS pid, COUNT(DISTINCT p.order_id) AS n FROM {$perm} p WHERE p.product_id IN ({$in}) AND p.access_granted >= %s AND p.access_granted <= %s{$not_p} GROUP BY p.product_id", $f, $t ) );

		$since  = self::gmt( wp_date( 'Y-m-01', strtotime( '-11 months', time() ) ), false );
		$months = $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTR(l.timestamp, 1, 7) AS m, COUNT(*) AS n FROM {$log} l JOIN {$perm} p ON p.permission_id = l.permission_id WHERE p.product_id IN ({$in}) AND l.timestamp >= %s{$not_l} GROUP BY SUBSTR(l.timestamp, 1, 7)", $since ) );
		// phpcs:enable

		$parents = array();
		foreach ( array_merge( (array) $digital, (array) $granted ) as $id ) {
			if ( isset( $map[ (int) $id ] ) ) {
				$parents[ $map[ (int) $id ] ] = true;
			}
		}
		foreach ( array_keys( $parents ) as $pid ) {
			$out['rows'][ $pid ] = array(
				'dl'      => 0,
				'dl_all'  => 0,
				'got'     => 0,
				'got_all' => 0,
				'last'    => '',
			);
		}
		$add = static function ( $rows, string $field ) use ( &$out, $map ) {
			foreach ( (array) $rows as $r ) {
				$pid = $map[ (int) $r->pid ] ?? 0;
				if ( $pid <= 0 || ! isset( $out['rows'][ $pid ] ) ) {
					continue;
				}
				$out['rows'][ $pid ][ $field ] += (int) $r->n;
				$out['total'][ $field ]        += (int) $r->n;
				if ( 'dl_all' === $field && isset( $r->last ) && (string) $r->last > $out['rows'][ $pid ]['last'] ) {
					$out['rows'][ $pid ]['last'] = (string) $r->last;
				}
			}
		};
		$add( $dl_all, 'dl_all' );
		$add( $dl_per, 'dl' );
		$add( $got_all, 'got_all' );
		$add( $got_per, 'got' );

		// Last 12 months, oldest first, empty months included.
		for ( $i = 11; $i >= 0; $i-- ) {
			$out['months'][ wp_date( 'Y-m', strtotime( "-{$i} months", strtotime( wp_date( 'Y-m-15' ) ) ) ) ] = 0;
		}
		foreach ( (array) $months as $r ) {
			if ( isset( $out['months'][ (string) $r->m ] ) ) {
				$out['months'][ (string) $r->m ] += (int) $r->n;
			}
		}

		set_transient( $key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Koko Analytics page views of products in a period: pid => views.
	 * Empty when Koko Analytics is not there.
	 *
	 * @param int[] $ids Products.
	 * @return array<int,int>|null Null = Koko Analytics not available.
	 */
	public static function page_views( array $ids, string $from, string $to ): ?array {
		global $wpdb;
		if ( ! defined( 'KOKO_ANALYTICS_VERSION' ) ) {
			return null;
		}
		$table = $wpdb->prefix . 'koko_analytics_post_stats';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, integers, prepare() for values.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( empty( $cols ) ) {
			return null;
		}
		$id_col = in_array( 'post_id', (array) $cols, true ) ? 'post_id' : ( in_array( 'id', (array) $cols, true ) ? 'id' : '' );
		if ( '' === $id_col || empty( $ids ) ) {
			return '' === $id_col ? null : array();
		}
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$id_col} AS pid, SUM(pageviews) AS n FROM {$table} WHERE {$id_col} IN ({$in}) AND date >= %s AND date <= %s GROUP BY {$id_col}", $from, $to ) );
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->pid ] = (int) $r->n;
		}
		return $out;
	}

	/* =====================================================================
	 * Requests
	 * =================================================================== */

	/** @return array[] Newest first. */
	public static function requests( string $state = 'all', string $ben = '' ): array {
		$out = array();
		foreach ( self::read_json( self::OPT_REQ ) as $r ) {
			if ( ! is_array( $r ) || empty( $r['id'] ) ) {
				continue;
			}
			if ( ( 'all' === $state || $r['state'] === $state ) && ( '' === $ben || $r['ben'] === $ben ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	public static function request( string $id ): ?array {
		foreach ( self::requests() as $r ) {
			if ( $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	public static function new_count(): int {
		return count( self::requests( 'new' ) );
	}

	/* =====================================================================
	 * orOS route (1.18.0): rs/v1/profile-requests, pulled with the narrow key
	 * =================================================================== */

	/**
	 * Requests for orOS. new = open and not yet taken by orOS, oros = open and
	 * taken, closed = done or declined. The photo file name never leaves the site.
	 *
	 * @return array[]
	 */
	public static function api_requests( string $state ): array {
		$out = array();
		foreach ( array_reverse( self::requests() ) as $r ) {
			$open  = 'new' === $r['state'];
			$taken = ! empty( $r['oros_at'] );
			if ( ( 'new' === $state && ( ! $open || $taken ) )
				|| ( 'oros' === $state && ( ! $open || ! $taken ) )
				|| ( 'closed' === $state && $open ) ) {
				continue;
			}
			$out[] = self::api_row( $r );
		}
		return $out;
	}

	public static function api_row( array $r ): array {
		$row = array(
			'id'    => (string) $r['id'],
			'type'  => (string) $r['type'],
			'ben'   => (string) $r['ben'],
			'at'    => (string) $r['at'],
			'state' => 'new' === $r['state'] ? ( empty( $r['oros_at'] ) ? 'new' : 'oros' ) : (string) $r['state'],
			'note'  => (string) ( $r['note'] ?? '' ),
		);
		if ( 'bio' === $r['type'] ) {
			$row['bio']       = (string) ( $r['bio'] ?? '' );
			$row['was']       = (string) ( $r['was'] ?? '' );
			$row['links']     = array_values( (array) ( $r['links'] ?? array() ) );
			$row['has_photo'] = ! empty( $r['photo'] );
		} else {
			$row['product_id'] = (int) ( $r['product_id'] ?? 0 );
			$row['title']      = (string) ( $r['title'] ?? '' );
			$row['qty']        = (int) ( $r['qty'] ?? 0 );
		}
		if ( ! empty( $r['oros_at'] ) ) {
			$row['oros_at'] = (string) $r['oros_at'];
		}
		if ( 'new' !== $r['state'] ) {
			$row['closed_at'] = (string) ( $r['closed_at'] ?? '' );
			$row['reply']     = (string) ( $r['reply'] ?? '' );
		}
		return $row;
	}

	/** orOS took an open request. False when unknown or already closed. */
	public static function mark_oros( string $id ): bool {
		$all = self::requests();
		foreach ( $all as $i => $r ) {
			if ( $r['id'] === $id && 'new' === $r['state'] ) {
				if ( empty( $r['oros_at'] ) ) {
					$all[ $i ]['oros_at'] = gmdate( 'c' );
					self::write_json( self::OPT_REQ, $all );
				}
				return true;
			}
		}
		return false;
	}

	/** «Μαρία Παπαδοπούλου» → «Μαρία Π.»: reviews are public, but the portal shows no more than needed. */
	private static function short_name( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( wp_strip_all_tags( $name ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) || array() === $parts ) {
			return '—';
		}
		$out = (string) $parts[0];
		if ( count( $parts ) > 1 ) {
			$out .= ' ' . mb_substr( (string) end( $parts ), 0, 1, 'UTF-8' ) . '.';
		}
		return $out;
	}

	/** Rate limits shared by both request types; true = allowed (and counted). */
	private static function take_slot( string $ben, string $ip ): bool {
		$k_ben = 'rs_rl_p_b_' . md5( $ben );
		$k_ip  = 'rs_rl_p_i_' . substr( hash( 'sha256', wp_salt( 'nonce' ) . '|' . $ip ), 0, 32 );
		$n_ben = (int) get_transient( $k_ben );
		$n_ip  = (int) get_transient( $k_ip );
		if ( $n_ben >= self::BEN_DAY || $n_ip >= self::IP_HOUR ) {
			return false;
		}
		set_transient( $k_ben, $n_ben + 1, DAY_IN_SECONDS );
		set_transient( $k_ip, $n_ip + 1, HOUR_IN_SECONDS );
		return true;
	}

	private static function store( array $req, string $replace_bio_of = '' ): void {
		$all = array();
		foreach ( self::requests() as $r ) {
			if ( '' !== $replace_bio_of && 'bio' === $r['type'] && 'new' === $r['state'] && $r['ben'] === $replace_bio_of ) {
				self::delete_photo( (string) ( $r['photo'] ?? '' ) );
				continue; // The new request replaces the open one.
			}
			$all[] = $r;
		}
		array_unshift( $all, $req );
		self::write_json( self::OPT_REQ, $all );
		self::notify( $req );
	}

	private static function new_id(): string {
		return 'p' . strtolower( wp_generate_password( 15, false, false ) );
	}

	private static function cut( string $v, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max, 'UTF-8' ) : substr( $v, 0, $max );
	}

	/**
	 * A bio change request.
	 *
	 * @param array|null $file The $_FILES entry of the photo, or null.
	 * @return string sent | rl | full | empty | photo_* (refused photo)
	 */
	public static function add_bio( string $ben, string $bio, array $links, string $note, ?array $file, string $ip ): string {
		$bio  = self::cut( trim( $bio ), self::BIO_MAX );
		$note = self::cut( trim( $note ), self::NOTE_MAX );
		$ok   = array();
		foreach ( $links as $l ) {
			$l = esc_url_raw( trim( (string) $l ), array( 'http', 'https' ) );
			if ( '' !== $l && count( $ok ) < self::LINKS_MAX ) {
				$ok[] = self::cut( $l, 500 );
			}
		}
		$has_file = is_array( $file ) && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'];
		if ( '' === $bio && '' === $note && empty( $ok ) && ! $has_file ) {
			return 'empty';
		}
		if ( count( self::requests( 'new' ) ) >= self::OPEN_MAX ) {
			return 'full';
		}
		if ( ! self::take_slot( $ben, $ip ) ) {
			return 'rl';
		}
		$photo = '';
		if ( $has_file ) {
			$photo = self::save_photo( (array) $file );
			if ( 0 === strpos( $photo, 'photo_' ) ) {
				return $photo;
			}
		}
		self::store(
			array(
				'id'    => self::new_id(),
				'type'  => 'bio',
				'ben'   => $ben,
				'at'    => gmdate( 'c' ),
				'state' => 'new',
				'bio'   => $bio,
				'links' => $ok,
				'note'  => $note,
				'photo' => $photo,
				'was'   => self::current_bio( $ben ),
			),
			$ben
		);
		return 'sent';
	}

	/** @return string sent | rl | full | bad | many */
	public static function add_copies( string $ben, int $pid, int $qty, string $note, string $ip ): string {
		if ( ! in_array( $pid, self::titles( $ben ), true ) || $qty < 1 || $qty > self::QTY_MAX ) {
			return 'bad';
		}
		$open = 0;
		foreach ( self::requests( 'new', $ben ) as $r ) {
			$open += 'copies' === $r['type'] ? 1 : 0;
		}
		if ( $open >= self::COPIES_OPEN ) {
			return 'many';
		}
		if ( count( self::requests( 'new' ) ) >= self::OPEN_MAX ) {
			return 'full';
		}
		if ( ! self::take_slot( $ben, $ip ) ) {
			return 'rl';
		}
		self::store(
			array(
				'id'         => self::new_id(),
				'type'       => 'copies',
				'ben'        => $ben,
				'at'         => gmdate( 'c' ),
				'state'      => 'new',
				'product_id' => $pid,
				'title'      => wp_strip_all_tags( (string) get_the_title( $pid ) ),
				'qty'        => $qty,
				'note'       => self::cut( trim( $note ), self::NOTE_MAX ),
			)
		);
		return 'sent';
	}

	/** Closes a request: done | declined, with an optional reply the creator sees. */
	public static function close( string $id, string $state, string $reply ): bool {
		if ( ! in_array( $state, array( 'done', 'declined' ), true ) ) {
			return false;
		}
		$all = self::requests();
		foreach ( $all as $i => $r ) {
			if ( $r['id'] === $id && 'new' === $r['state'] ) {
				$all[ $i ]['state']     = $state;
				$all[ $i ]['closed_at'] = gmdate( 'c' );
				$all[ $i ]['reply']     = self::cut( trim( $reply ), self::REPLY_MAX );
				self::write_json( self::OPT_REQ, $all );
				return true;
			}
		}
		return false;
	}

	/** The bio as it is now on the linked page (plain text). */
	public static function current_bio( string $ben ): string {
		$cid = self::creator_of( $ben );
		if ( $cid <= 0 ) {
			return '';
		}
		$p = get_post( $cid );
		if ( ! $p instanceof WP_Post ) {
			return '';
		}
		$txt = wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br />', '<br/>' ), "\n", (string) $p->post_content ) );
		$txt = html_entity_decode( $txt, ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $txt ) );
	}

	/** The links on the linked page (Featured Creators _crt_links). */
	private static function current_links( string $ben ): array {
		$cid = self::creator_of( $ben );
		if ( $cid <= 0 ) {
			return array();
		}
		$raw  = get_post_meta( $cid, '_crt_links', true );
		$list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $l ) {
			$u = is_array( $l ) ? (string) ( $l['url'] ?? '' ) : (string) $l;
			if ( '' !== $u ) {
				$out[] = $u;
			}
		}
		return array_slice( $out, 0, self::LINKS_MAX );
	}

	/** A short email to the site's admin address; nothing goes to the creator. */
	private static function notify( array $req ): void {
		if ( '1' === (string) get_option( self::OPT_NOMAIL, '' ) ) {
			return;
		}
		$to = (string) get_option( 'admin_email', '' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$what = 'bio' === $req['type']
			? __( 'αλλαγή στη σελίδα δημιουργού', 'revenue-splitter' )
			: sprintf(
				/* translators: 1: number of copies, 2: title */
				__( '%1$d αντίτυπα του «%2$s»', 'revenue-splitter' ),
				(int) $req['qty'],
				(string) $req['title']
			);
		/* translators: 1: site name, 2: beneficiary */
		$subject = sprintf( __( '[%1$s] Νέο αίτημα από %2$s', 'revenue-splitter' ), $site, (string) $req['ben'] );
		$body    = sprintf(
			/* translators: 1: beneficiary, 2: what was asked */
			__( 'Ο δημιουργός %1$s ζήτησε από το Author Portal: %2$s.', 'revenue-splitter' ),
			(string) $req['ben'],
			$what
		) . "\n\n" . admin_url( 'admin.php?page=' . self::SLUG ) . "\n";
		wp_mail( $to, $subject, $body );
	}

	/* =====================================================================
	 * Photos
	 * =================================================================== */

	private static function photo_dir(): string {
		$up  = wp_get_upload_dir();
		$dir = rtrim( (string) $up['basedir'], '/\\' ) . '/' . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/** @return string file name, or photo_size | photo_type | photo_error */
	private static function save_photo( array $file ): string {
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'photo_size' : 'photo_error';
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return 'photo_error';
		}
		$size = (int) filesize( $tmp );
		if ( $size <= 0 || $size > self::PHOTO_MAX ) {
			return 'photo_size';
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad file is refused below.
		$ext  = array(
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_PNG  => 'png',
		);
		if ( defined( 'IMAGETYPE_WEBP' ) ) {
			$ext[ IMAGETYPE_WEBP ] = 'webp';
		}
		if ( ! is_array( $info ) || ! isset( $ext[ (int) $info[2] ] ) || (int) $info[0] < 1 || (int) $info[1] < 1
			|| (int) $info[0] > self::PHOTO_PX || (int) $info[1] > self::PHOTO_PX ) {
			return 'photo_type';
		}
		$name = bin2hex( random_bytes( 16 ) ) . '.' . $ext[ (int) $info[2] ];
		$dest = self::photo_dir() . '/' . $name;
		if ( ! move_uploaded_file( $tmp, $dest ) ) { // phpcs:ignore Generic.PHP.ForbiddenFunctions
			return 'photo_error';
		}
		// Re-encode when the server can (drops anything hidden in the file and the EXIF data).
		$ed = wp_get_image_editor( $dest );
		if ( ! is_wp_error( $ed ) ) {
			$ed->resize( 3000, 3000, false );
			$saved = $ed->save( $dest );
			if ( is_wp_error( $saved ) ) {
				unset( $saved );
			}
		}
		return $name;
	}

	private static function delete_photo( string $name ): void {
		if ( '' !== $name && preg_match( '/^[0-9a-f]{32}\.(jpg|png|webp)$/', $name ) ) {
			$up   = wp_get_upload_dir();
			$file = rtrim( (string) $up['basedir'], '/\\' ) . '/' . self::DIR . '/' . $name;
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/** Shop managers only (admin-post, nonce + cap). */
	public static function stream_photo(): void {
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		if ( ! current_user_can( RS_Admin_UI::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rs_pf_photo_' . $id ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$ph = self::photo_file( $id );
		if ( null === $ph ) {
			wp_die( esc_html__( 'Η φωτογραφία δεν βρέθηκε.', 'revenue-splitter' ) );
		}
		self::send_photo( $id, $ph );
	}

	/**
	 * The stored photo of a request: path, MIME type and extension, or null.
	 *
	 * @return array{file:string,type:string,ext:string}|null
	 */
	public static function photo_file( string $id ): ?array {
		$r    = self::request( $id );
		$name = null !== $r ? (string) ( $r['photo'] ?? '' ) : '';
		$up   = wp_get_upload_dir();
		$file = rtrim( (string) $up['basedir'], '/\\' ) . '/' . self::DIR . '/' . $name;
		if ( '' === $name || ! preg_match( '/^[0-9a-f]{32}\.(jpg|png|webp)$/', $name, $m ) || ! is_file( $file ) ) {
			return null;
		}
		$types = array(
			'jpg'  => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);
		return array(
			'file' => $file,
			'type' => $types[ $m[1] ],
			'ext'  => $m[1],
		);
	}

	/** @param array{file:string,type:string,ext:string} $ph */
	public static function send_photo( string $id, array $ph ): void {
		nocache_headers();
		header( 'Content-Type: ' . $ph['type'] );
		// ASCII only: a Greek name in this header breaks some servers.
		header( 'Content-Disposition: inline; filename="photo-' . preg_replace( '/[^A-Za-z0-9_-]/', '', $id ) . '.' . $ph['ext'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . (string) filesize( $ph['file'] ) );
		readfile( $ph['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/** Daily: photos 30 days after closing, closed requests after 12 months. */
	public static function cleanup(): void {
		$all     = self::requests();
		$keep    = array();
		$changed = false;
		$now     = time();
		foreach ( $all as $r ) {
			$closed = ! empty( $r['closed_at'] ) ? (int) strtotime( (string) $r['closed_at'] ) : 0;
			if ( 'new' !== $r['state'] && $closed > 0 && $now - $closed > self::KEEP_DAYS * DAY_IN_SECONDS ) {
				self::delete_photo( (string) ( $r['photo'] ?? '' ) );
				$changed = true;
				continue;
			}
			if ( 'new' !== $r['state'] && $closed > 0 && ! empty( $r['photo'] ) && $now - $closed > self::PHOTO_DAYS * DAY_IN_SECONDS ) {
				self::delete_photo( (string) $r['photo'] );
				$r['photo'] = '';
				$changed    = true;
			}
			$keep[] = $r;
		}
		if ( $changed ) {
			self::write_json( self::OPT_REQ, $keep );
		}
	}

	/* =====================================================================
	 * Portal: POST routes and the statement PDF (init, before output)
	 * =================================================================== */

	public static function route_portal(): void {
		if ( is_admin() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- nonces checked right below.
		if ( isset( $_GET['rs_pf_stmt'] ) ) {
			self::stream_statement();
			return;
		}
		$act = isset( $_POST['rs_pf_act'] ) ? sanitize_key( wp_unslash( $_POST['rs_pf_act'] ) ) : '';
		// phpcs:enable
		if ( '' === $act ) {
			return;
		}
		if ( ! isset( $_POST['rs_pf_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_pf_nonce'] ) ), 'rs_pf_' . $act ) ) {
			return;
		}
		$back = RS_Portal::portal_url();
		$who  = RS_Portal::current();
		if ( null === $who ) {
			wp_safe_redirect( $back );
			exit;
		}
		$ip   = RS_Portal::client_ip();
		$note = isset( $_POST['rs_pf_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rs_pf_note'] ) ) : '';
		if ( 'bio' === $act ) {
			$bio   = isset( $_POST['rs_pf_bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rs_pf_bio'] ) ) : '';
			$links = isset( $_POST['rs_pf_links'] ) && is_array( $_POST['rs_pf_links'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['rs_pf_links'] ) ) : array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked in save_photo().
			$file = isset( $_FILES['rs_pf_photo'] ) && is_array( $_FILES['rs_pf_photo'] ) ? $_FILES['rs_pf_photo'] : null;
			// A bio identical to the page is not a change.
			if ( trim( $bio ) === self::current_bio( $who ) ) {
				$bio = '';
			}
			$code = self::add_bio( $who, $bio, array_slice( $links, 0, self::LINKS_MAX ), $note, $file, $ip );
			wp_safe_redirect( add_query_arg( 'rs_pf_msg', $code, $back ) . '#rs-page' );
			exit;
		}
		if ( 'copies' === $act ) {
			$pid  = isset( $_POST['rs_pf_pid'] ) ? absint( wp_unslash( $_POST['rs_pf_pid'] ) ) : 0;
			$qty  = isset( $_POST['rs_pf_qty'] ) ? absint( wp_unslash( $_POST['rs_pf_qty'] ) ) : 0;
			$code = self::add_copies( $who, $pid, $qty, $note, $ip );
			wp_safe_redirect( add_query_arg( 'rs_pf_cmsg', $code, $back ) . '#rs-copies' );
			exit;
		}
	}

	/** A sent statement of the signed-in beneficiary, as PDF. */
	private static function stream_statement(): void {
		$month = isset( $_GET['rs_pf_stmt'] ) ? sanitize_text_field( wp_unslash( $_GET['rs_pf_stmt'] ) ) : '';
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rs_pf_stmt' ) ) {
			return;
		}
		$who = RS_Portal::current();
		if ( null === $who || ! RS_Statements::is_month( $month ) ) {
			return;
		}
		$st = RS_Statements::get( $month, $who );
		if ( null === $st || 'sent' !== ( $st['status'] ?? '' ) || ! RS_PDF::available() ) {
			return;
		}
		$bin = RS_Statements::pdf( $st );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="katastasi-' . preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $st['number'] ) . '.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $bin ) );
		echo $bin; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/* =====================================================================
	 * Portal: rendering
	 * =================================================================== */

	private static function dmy_iso( string $iso ): string {
		$ts = '' !== $iso ? strtotime( $iso ) : false;
		return $ts ? wp_date( 'd/m/Y', $ts ) : '';
	}

	/** «Η σελίδα σου» with the change request form. */
	public static function render_card( string $who ): void {
		$page = self::page( $who );
		$open = null;
		$last = null;
		foreach ( self::requests( 'all', $who ) as $r ) {
			if ( 'bio' !== $r['type'] ) {
				continue;
			}
			if ( 'new' === $r['state'] && null === $open ) {
				$open = $r;
			} elseif ( 'new' !== $r['state'] && null === $last ) {
				$last = $r;
			}
		}
		if ( null !== $last && ( time() - (int) strtotime( (string) ( $last['closed_at'] ?? '' ) ) ) > self::SHOW_DAYS * DAY_IN_SECONDS ) {
			$last = null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only message flag.
		$msg   = isset( $_GET['rs_pf_msg'] ) ? sanitize_key( wp_unslash( $_GET['rs_pf_msg'] ) ) : '';
		$links = self::current_links( $who );
		self::print_css();
		?>
		<div class="rs-pf-card" id="rs-page">
			<?php if ( '' !== $page['image'] ) : ?>
				<img class="rs-pf-photo" src="<?php echo esc_url( $page['image'] ); ?>" alt="" width="64" height="64" loading="lazy" />
			<?php endif; ?>
			<div class="rs-pf-card-body">
				<strong><?php esc_html_e( 'Η σελίδα σου', 'revenue-splitter' ); ?></strong>
				<?php if ( '' !== $page['url'] ) : ?>
					<a class="rs-pf-link" href="<?php echo esc_url( $page['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Δες τη σελίδα σου', 'revenue-splitter' ); ?> ↗</a>
				<?php elseif ( $page['creator'] > 0 ) : ?>
					<span class="rs-pt-muted"><?php esc_html_e( 'Η σελίδα σου ετοιμάζεται.', 'revenue-splitter' ); ?></span>
				<?php else : ?>
					<span class="rs-pt-muted"><?php esc_html_e( 'Δεν έχεις ακόμη σελίδα δημιουργού. Στείλε μας το βιογραφικό σου και θα τη φτιάξουμε.', 'revenue-splitter' ); ?></span>
				<?php endif; ?>

				<?php if ( 'sent' === $msg ) : ?>
					<p class="rs-pt-msg-ok rs-pf-msg"><?php esc_html_e( 'Το αίτημά σου στάλθηκε στον εκδότη.', 'revenue-splitter' ); ?></p>
				<?php elseif ( 'empty' === $msg ) : ?>
					<p class="rs-pt-error rs-pf-msg"><?php esc_html_e( 'Δεν άλλαξε τίποτα: γράψε το νέο κείμενο, έναν σύνδεσμο, ένα σημείωμα ή πρόσθεσε φωτογραφία.', 'revenue-splitter' ); ?></p>
				<?php elseif ( 'photo_size' === $msg ) : ?>
					<p class="rs-pt-error rs-pf-msg"><?php esc_html_e( 'Η φωτογραφία είναι μεγαλύτερη από 5 MB.', 'revenue-splitter' ); ?></p>
				<?php elseif ( 'photo_type' === $msg || 'photo_error' === $msg ) : ?>
					<p class="rs-pt-error rs-pf-msg"><?php esc_html_e( 'Η φωτογραφία πρέπει να είναι JPG, PNG ή WebP (έως 8000 pixel ανά πλευρά).', 'revenue-splitter' ); ?></p>
				<?php elseif ( 'rl' === $msg || 'full' === $msg ) : ?>
					<p class="rs-pt-error rs-pf-msg"><?php esc_html_e( 'Πολλά αιτήματα σε λίγο χρόνο — δοκίμασε ξανά αργότερα.', 'revenue-splitter' ); ?></p>
				<?php endif; ?>

				<?php if ( null !== $open ) : ?>
					<p class="rs-pf-state rs-pf-wait">
						<?php
						/* translators: %s: date */
						echo esc_html( sprintf( __( 'Το αίτημά σου στάλθηκε στις %s και περιμένει τον εκδότη.', 'revenue-splitter' ), self::dmy_iso( (string) $open['at'] ) ) );
						?>
					</p>
				<?php elseif ( null !== $last ) : ?>
					<p class="rs-pf-state <?php echo 'done' === $last['state'] ? 'rs-pf-done' : 'rs-pf-no'; ?>">
						<?php
						if ( 'done' === $last['state'] ) {
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Η σελίδα σου ενημερώθηκε στις %s.', 'revenue-splitter' ), self::dmy_iso( (string) $last['closed_at'] ) ) );
						} else {
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Το αίτημά σου της %s δεν έγινε.', 'revenue-splitter' ), self::dmy_iso( (string) $last['at'] ) ) );
						}
						?>
						<?php if ( ! empty( $last['reply'] ) ) : ?>
							<br /><em><?php echo esc_html( (string) $last['reply'] ); ?></em>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<details class="rs-pf-ask">
					<summary><?php echo null !== $open ? esc_html__( 'Αλλαγή του αιτήματος (το νέο αντικαθιστά το προηγούμενο)', 'revenue-splitter' ) : esc_html__( 'Ζήτησε αλλαγή ή συμπλήρωση', 'revenue-splitter' ); ?></summary>
					<form method="post" enctype="multipart/form-data" class="rs-pf-form">
						<?php wp_nonce_field( 'rs_pf_bio', 'rs_pf_nonce' ); ?>
						<input type="hidden" name="rs_pf_act" value="bio" />
						<p class="rs-pt-note"><?php esc_html_e( 'Δεν αλλάζει τίποτα αμέσως: το αίτημα έρχεται σε εμάς, το ελέγχουμε και το περνάμε στη σελίδα σου.', 'revenue-splitter' ); ?></p>
						<label for="rs-pf-bio"><?php esc_html_e( 'Βιογραφικό', 'revenue-splitter' ); ?></label>
						<textarea id="rs-pf-bio" name="rs_pf_bio" rows="8" maxlength="<?php echo esc_attr( (string) self::BIO_MAX ); ?>"><?php echo esc_textarea( null !== $open && '' !== $open['bio'] ? (string) $open['bio'] : self::current_bio( $who ) ); ?></textarea>
						<label><?php esc_html_e( 'Σύνδεσμοι (site, Instagram, Facebook κ.λπ.)', 'revenue-splitter' ); ?></label>
						<?php
						$pre = null !== $open && ! empty( $open['links'] ) ? (array) $open['links'] : $links;
						for ( $i = 0; $i < self::LINKS_MAX; $i++ ) :
							?>
							<input type="url" name="rs_pf_links[]" value="<?php echo esc_attr( (string) ( $pre[ $i ] ?? '' ) ); ?>" placeholder="https://" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: link number */ __( 'Σύνδεσμος %d', 'revenue-splitter' ), $i + 1 ) ); ?>" />
						<?php endfor; ?>
						<label for="rs-pf-photo"><?php esc_html_e( 'Νέα φωτογραφία (προαιρετικά, JPG, PNG ή WebP έως 5 MB)', 'revenue-splitter' ); ?></label>
						<input type="file" id="rs-pf-photo" name="rs_pf_photo" accept="image/jpeg,image/png,image/webp" />
						<label for="rs-pf-note"><?php esc_html_e( 'Σημείωμα προς τον εκδότη (προαιρετικό)', 'revenue-splitter' ); ?></label>
						<textarea id="rs-pf-note" name="rs_pf_note" rows="3" maxlength="<?php echo esc_attr( (string) self::NOTE_MAX ); ?>"></textarea>
						<button type="submit" class="rs-pt-btn"><?php esc_html_e( 'Αποστολή αιτήματος', 'revenue-splitter' ); ?></button>
					</form>
				</details>
			</div>
		</div>
		<?php
	}

	/** «Οι τίτλοι σου»: downloads, opens, views, wish lists, pre-orders, reviews, promotion, copies. */
	public static function render_titles( string $who, array $per ): void {
		$titles = self::titles( $who );
		if ( empty( $titles ) ) {
			return;
		}
		$from  = (string) $per['start'];
		$to    = (string) $per['end'];
		$dl    = self::downloads( $who, $from, $to );
		$views = self::page_views( $titles, $from, $to );
		$opens = function_exists( 'bpv_get_opens' );
		$wish  = function_exists( 'wsh_product_count' );
		$preo  = function_exists( 'por_get_release' );
		self::print_css();
		?>
		<hr class="rs-pt-sep" />
		<h3 id="rs-titles"><?php esc_html_e( 'Οι τίτλοι σου', 'revenue-splitter' ); ?> — <?php echo esc_html( (string) $per['label'] ); ?></h3>

		<?php if ( ! empty( $dl['rows'] ) ) : ?>
			<h4 class="rs-pf-h4"><?php esc_html_e( 'Λήψεις ψηφιακών αρχείων', 'revenue-splitter' ); ?></h4>
			<div class="rs-pf-scroll">
			<table class="rs-pt-table rs-pf-dl">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Λήψεις (περίοδος)', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Λήψεις (συνολικά)', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Το απέκτησαν (περίοδος)', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Το απέκτησαν (συνολικά)', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Τελευταία λήψη', 'revenue-splitter' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $dl['rows'] as $pid => $r ) : ?>
					<tr>
						<td><?php echo esc_html( (string) get_the_title( (int) $pid ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $r['dl'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $r['dl_all'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $r['got'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $r['got_all'] ) ); ?></td>
						<td><?php echo '' !== $r['last'] ? esc_html( wp_date( 'd/m/Y', (int) strtotime( $r['last'] . ' UTC' ) ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th><?php esc_html_e( 'Σύνολο', 'revenue-splitter' ); ?></th>
						<th class="num"><?php echo esc_html( number_format_i18n( (int) $dl['total']['dl'] ) ); ?></th>
						<th class="num"><?php echo esc_html( number_format_i18n( (int) $dl['total']['dl_all'] ) ); ?></th>
						<th class="num"><?php echo esc_html( number_format_i18n( (int) $dl['total']['got'] ) ); ?></th>
						<th class="num"><?php echo esc_html( number_format_i18n( (int) $dl['total']['got_all'] ) ); ?></th>
						<th></th>
					</tr>
				</tfoot>
			</table>
			</div>
			<?php
			$max = max( 1, (int) max( $dl['months'] ) );
			?>
			<div class="rs-pt-chart rs-pf-chart" aria-label="<?php esc_attr_e( 'Λήψεις ανά μήνα', 'revenue-splitter' ); ?>">
				<?php foreach ( $dl['months'] as $m => $n ) : ?>
					<?php
					$h   = max( 3, (int) round( ( $n / $max ) * 100 ) );
					$lbl = wp_date( 'M Y', (int) strtotime( $m . '-15' ) );
					?>
					<div class="rs-pt-col" title="<?php echo esc_attr( $lbl . ': ' . $n ); ?>">
						<span class="rs-pt-val"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
						<div class="rs-pt-bar" style="height:<?php echo esc_attr( (string) $h ); ?>%;"></div>
						<small><?php echo esc_html( $lbl ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="rs-pt-note"><?php esc_html_e( 'Μετρώνται οι λήψεις από το κατάστημα. «Το απέκτησαν» = πόσες παραγγελίες (δωρεάν ή επί πληρωμή) έδωσαν το αρχείο. Αρχεία που μοιράστηκαν αλλού (π.χ. email, άλλες πλατφόρμες) δεν φαίνονται εδώ. Οι λήψεις του εκδότη δεν μετρώνται.', 'revenue-splitter' ); ?></p>
		<?php endif; ?>

		<h4 class="rs-pf-h4"><?php esc_html_e( 'Ενδιαφέρον και κριτικές', 'revenue-splitter' ); ?></h4>
		<div class="rs-pf-scroll">
		<table class="rs-pt-table rs-pf-stats">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></th>
					<?php if ( null !== $views ) : ?>
						<th class="num"><?php esc_html_e( 'Προβολές σελίδας', 'revenue-splitter' ); ?></th>
					<?php endif; ?>
					<?php if ( $opens ) : ?>
						<th class="num"><?php esc_html_e( 'Ανοίγματα δείγματος', 'revenue-splitter' ); ?></th>
					<?php endif; ?>
					<?php if ( $wish ) : ?>
						<th class="num"><?php esc_html_e( 'Σε λίστες επιθυμιών', 'revenue-splitter' ); ?></th>
					<?php endif; ?>
					<th class="num"><?php esc_html_e( 'Βαθμολογία', 'revenue-splitter' ); ?></th>
					<?php if ( $preo ) : ?>
						<th><?php esc_html_e( 'Προπαραγγελίες', 'revenue-splitter' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $titles as $pid ) : ?>
				<?php
				$p = wc_get_product( $pid );
				if ( ! $p ) {
					continue;
				}
				$rel = $preo ? por_get_release( $pid ) : null;
				?>
				<tr>
					<td><a href="<?php echo esc_url( (string) get_permalink( $pid ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p->get_name() ); ?></a></td>
					<?php if ( null !== $views ) : ?>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) ( $views[ $pid ] ?? 0 ) ) ); ?></td>
					<?php endif; ?>
					<?php if ( $opens ) : ?>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) bpv_get_opens( $pid, $from, $to ) ) ); ?></td>
					<?php endif; ?>
					<?php if ( $wish ) : ?>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) wsh_product_count( $pid ) ) ); ?></td>
					<?php endif; ?>
					<td class="num">
						<?php if ( $p->get_review_count() > 0 ) : ?>
							<?php echo esc_html( number_format_i18n( (float) $p->get_average_rating(), 1 ) ); ?> ★ <small>(<?php echo esc_html( number_format_i18n( (int) $p->get_review_count() ) ); ?>)</small>
						<?php else : ?>
							<span class="rs-pt-muted">—</span>
						<?php endif; ?>
					</td>
					<?php if ( $preo ) : ?>
						<td>
							<?php if ( is_array( $rel ) && ! empty( $rel['active'] ) ) : ?>
								<?php
								$n = ( class_exists( 'POR_Orders' ) && method_exists( 'POR_Orders', 'count_open' ) ) ? (int) POR_Orders::count_open( $pid ) : 0;
								echo esc_html(
									sprintf(
										/* translators: 1: number of orders, 2: release date or "soon" */
										__( '%1$s παραγγελίες · κυκλοφορία %2$s', 'revenue-splitter' ),
										number_format_i18n( $n ),
										! empty( $rel['date'] ) ? RS_Contracts::dmy( (string) $rel['date'] ) : __( 'σύντομα', 'revenue-splitter' )
									)
								);
								?>
							<?php else : ?>
								<span class="rs-pt-muted">—</span>
							<?php endif; ?>
						</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p class="rs-pt-note">
			<?php
			$bits = array();
			if ( null !== $views ) {
				$bits[] = __( 'Οι προβολές και τα ανοίγματα δείγματος αφορούν την περίοδο που διάλεξες.', 'revenue-splitter' );
			} elseif ( $opens ) {
				$bits[] = __( 'Τα ανοίγματα δείγματος αφορούν την περίοδο που διάλεξες.', 'revenue-splitter' );
			}
			if ( $wish ) {
				$bits[] = __( 'Οι λίστες επιθυμιών και η βαθμολογία είναι σημερινές.', 'revenue-splitter' );
			}
			echo esc_html( implode( ' ', $bits ) );
			?>
		</p>

		<?php self::render_reviews( $titles ); ?>
		<?php self::render_promo( $titles ); ?>
		<?php self::render_copies( $who, $titles ); ?>
		<?php
	}

	private static function render_reviews( array $titles ): void {
		$list = get_comments(
			array(
				'post__in' => $titles,
				'type'     => 'review',
				'status'   => 'approve',
				'number'   => 5,
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
			)
		);
		if ( empty( $list ) ) {
			return;
		}
		?>
		<details class="rs-pf-reviews">
			<summary><?php esc_html_e( 'Οι τελευταίες κριτικές', 'revenue-splitter' ); ?></summary>
			<ul>
			<?php foreach ( $list as $c ) : ?>
				<?php
				$stars = (int) get_comment_meta( (int) $c->comment_ID, 'rating', true );
				$text  = wp_strip_all_tags( (string) $c->comment_content );
				if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > 300 ) {
					$text = mb_substr( $text, 0, 300, 'UTF-8' ) . '…';
				}
				?>
				<li>
					<strong><?php echo esc_html( (string) get_the_title( (int) $c->comment_post_ID ) ); ?></strong>
					<?php if ( $stars > 0 ) : ?>
						<span class="rs-pf-stars" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: stars */ __( '%d από 5', 'revenue-splitter' ), $stars ) ); ?>"><?php echo esc_html( str_repeat( '★', min( 5, $stars ) ) . str_repeat( '☆', max( 0, 5 - $stars ) ) ); ?></span>
					<?php endif; ?>
					<small class="rs-pt-muted"><?php echo esc_html( self::short_name( (string) $c->comment_author ) . ' · ' . wp_date( 'd/m/Y', (int) strtotime( (string) $c->comment_date_gmt . ' UTC' ) ) ); ?></small>
					<br /><?php echo esc_html( $text ); ?>
				</li>
			<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	private static function render_promo( array $titles ): void {
		?>
		<details class="rs-pf-promo">
			<summary><?php esc_html_e( 'Υλικό προώθησης', 'revenue-splitter' ); ?></summary>
			<p class="rs-pt-note"><?php esc_html_e( 'Μοιράσου τα βιβλία σου: το εξώφυλλο, ο σύνδεσμος και ένα έτοιμο κείμενο για social. Το κείμενο μπορείς να το αλλάξεις όπως θέλεις.', 'revenue-splitter' ); ?></p>
			<?php foreach ( $titles as $pid ) : ?>
				<?php
				$p = wc_get_product( $pid );
				if ( ! $p ) {
					continue;
				}
				$url   = (string) get_permalink( $pid );
				$img   = (string) wp_get_attachment_image_url( (int) $p->get_image_id(), 'full' );
				$thumb = (string) wp_get_attachment_image_url( (int) $p->get_image_id(), 'thumbnail' );
				$desc  = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $p->get_short_description() ) ) );
				if ( function_exists( 'mb_strlen' ) && mb_strlen( $desc, 'UTF-8' ) > 240 ) {
					$desc = rtrim( mb_substr( $desc, 0, 240, 'UTF-8' ) ) . '…';
				}
				$text = $p->get_name() . ( '' !== $desc ? "\n\n" . $desc : '' ) . "\n\n" . $url;
				?>
				<div class="rs-pf-kit">
					<?php if ( '' !== $thumb ) : ?>
						<img src="<?php echo esc_url( $thumb ); ?>" alt="" width="72" height="72" loading="lazy" />
					<?php endif; ?>
					<div>
						<strong><?php echo esc_html( $p->get_name() ); ?></strong>
						<div class="rs-pf-kit-row">
							<input type="text" readonly value="<?php echo esc_attr( $url ); ?>" aria-label="<?php esc_attr_e( 'Σύνδεσμος βιβλίου', 'revenue-splitter' ); ?>" />
							<button type="button" class="rs-pt-btn rs-pf-copy" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Αντιγραφή συνδέσμου', 'revenue-splitter' ); ?></button>
						</div>
						<textarea readonly rows="4" aria-label="<?php esc_attr_e( 'Κείμενο για social', 'revenue-splitter' ); ?>"><?php echo esc_textarea( $text ); ?></textarea>
						<div class="rs-pf-kit-row">
							<button type="button" class="rs-pt-btn rs-pf-copy" data-copy="<?php echo esc_attr( $text ); ?>"><?php esc_html_e( 'Αντιγραφή κειμένου', 'revenue-splitter' ); ?></button>
							<?php if ( '' !== $img ) : ?>
								<a class="rs-pf-link" href="<?php echo esc_url( $img ); ?>" download target="_blank" rel="noopener"><?php esc_html_e( 'Εξώφυλλο σε μεγάλη ανάλυση', 'revenue-splitter' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</details>
		<script>
		document.addEventListener('click', function (e) {
			var b = e.target.closest ? e.target.closest('.rs-pf-copy') : null;
			if (!b) { return; }
			var t = b.getAttribute('data-copy') || '';
			var done = function () { var o = b.textContent; b.textContent = '✓'; setTimeout(function () { b.textContent = o; }, 1200); };
			if (navigator.clipboard) {
				navigator.clipboard.writeText(t).then(done, function () {});
				return;
			}
			var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select();
			try { document.execCommand('copy'); done(); } catch (x) {}
			document.body.removeChild(a);
		});
		</script>
		<?php
	}

	private static function render_copies( string $who, array $titles ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only message flag.
		$msg  = isset( $_GET['rs_pf_cmsg'] ) ? sanitize_key( wp_unslash( $_GET['rs_pf_cmsg'] ) ) : '';
		$mine = array();
		foreach ( self::requests( 'all', $who ) as $r ) {
			if ( 'copies' === $r['type'] && ( 'new' === $r['state'] || time() - (int) strtotime( (string) ( $r['closed_at'] ?? '' ) ) < self::SHOW_DAYS * DAY_IN_SECONDS ) ) {
				$mine[] = $r;
			}
		}
		?>
		<details class="rs-pf-copies" id="rs-copies"<?php echo '' !== $msg ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Αίτημα αντιτύπων συγγραφέα', 'revenue-splitter' ); ?></summary>
			<?php if ( 'sent' === $msg ) : ?>
				<p class="rs-pt-msg-ok rs-pf-msg"><?php esc_html_e( 'Το αίτημά σου στάλθηκε στον εκδότη.', 'revenue-splitter' ); ?></p>
			<?php elseif ( 'many' === $msg ) : ?>
				<p class="rs-pt-error rs-pf-msg"><?php esc_html_e( 'Έχεις ήδη 3 ανοιχτά αιτήματα αντιτύπων.', 'revenue-splitter' ); ?></p>
			<?php elseif ( 'rl' === $msg || 'full' === $msg ) : ?>
				<p class="rs-pt-error rs-pf-msg"><?php esc_html_e( 'Πολλά αιτήματα σε λίγο χρόνο — δοκίμασε ξανά αργότερα.', 'revenue-splitter' ); ?></p>
			<?php elseif ( 'bad' === $msg ) : ?>
				<p class="rs-pt-error rs-pf-msg"><?php esc_html_e( 'Διάλεξε έναν τίτλο σου και από 1 έως 500 αντίτυπα.', 'revenue-splitter' ); ?></p>
			<?php endif; ?>
			<p class="rs-pt-note"><?php esc_html_e( 'Το αίτημα έρχεται σε εμάς και επικοινωνούμε μαζί σου για τιμή και παράδοση. Δεν γίνεται παραγγελία ή πληρωμή από εδώ.', 'revenue-splitter' ); ?></p>
			<form method="post" class="rs-pf-form">
				<?php wp_nonce_field( 'rs_pf_copies', 'rs_pf_nonce' ); ?>
				<input type="hidden" name="rs_pf_act" value="copies" />
				<label for="rs-pf-pid"><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></label>
				<select id="rs-pf-pid" name="rs_pf_pid">
					<?php foreach ( $titles as $pid ) : ?>
						<option value="<?php echo esc_attr( (string) $pid ); ?>"><?php echo esc_html( (string) get_the_title( $pid ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="rs-pf-qty"><?php esc_html_e( 'Αντίτυπα', 'revenue-splitter' ); ?></label>
				<input type="number" id="rs-pf-qty" name="rs_pf_qty" min="1" max="<?php echo esc_attr( (string) self::QTY_MAX ); ?>" value="10" />
				<label for="rs-pf-cnote"><?php esc_html_e( 'Σημείωμα (προαιρετικό)', 'revenue-splitter' ); ?></label>
				<textarea id="rs-pf-cnote" name="rs_pf_note" rows="3" maxlength="<?php echo esc_attr( (string) self::NOTE_MAX ); ?>"></textarea>
				<button type="submit" class="rs-pt-btn"><?php esc_html_e( 'Αποστολή αιτήματος', 'revenue-splitter' ); ?></button>
			</form>
			<?php if ( ! empty( $mine ) ) : ?>
				<ul class="rs-pf-reqs">
				<?php foreach ( $mine as $r ) : ?>
					<li>
						<?php
						echo esc_html( sprintf( /* translators: 1: date, 2: copies, 3: title */ __( '%1$s · %2$d × %3$s', 'revenue-splitter' ), self::dmy_iso( (string) $r['at'] ), (int) $r['qty'], (string) $r['title'] ) );
						echo ' — ';
						echo esc_html( self::state_label( (string) $r['state'] ) );
						if ( ! empty( $r['reply'] ) ) {
							echo ': <em>' . esc_html( (string) $r['reply'] ) . '</em>';
						}
						?>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</details>
		<?php
	}

	/** «Οι καταστάσεις σου»: sent statements as PDF. */
	public static function render_statements( string $who ): void {
		if ( ! RS_PDF::available() ) {
			return;
		}
		$rows = array();
		foreach ( RS_Statements::months() as $m ) {
			$st = RS_Statements::get( $m, $who );
			if ( null !== $st && 'sent' === ( $st['status'] ?? '' ) ) {
				$rows[] = $st;
			}
		}
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<h3 id="rs-statements"><?php esc_html_e( 'Οι καταστάσεις σου', 'revenue-splitter' ); ?></h3>
		<ul class="rs-pf-stmts">
		<?php foreach ( $rows as $st ) : ?>
			<?php
			$url = wp_nonce_url(
				add_query_arg( 'rs_pf_stmt', rawurlencode( (string) $st['month'] ), RS_Portal::portal_url() ),
				'rs_pf_stmt'
			);
			?>
			<li>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( RS_Statements::title( $st ) ); ?></a>
				<small class="rs-pt-muted"><?php echo esc_html( (string) $st['number'] . ' · ' . self::dmy_iso( (string) ( $st['sent_at'] ?? '' ) ) ); ?></small>
			</li>
		<?php endforeach; ?>
		</ul>
		<?php
	}

	public static function state_label( string $s ): string {
		if ( 'done' === $s ) {
			return __( 'Ολοκληρώθηκε', 'revenue-splitter' );
		}
		if ( 'declined' === $s ) {
			return __( 'Δεν έγινε', 'revenue-splitter' );
		}
		return __( 'Σε αναμονή', 'revenue-splitter' );
	}

	private static function print_css(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
<style>
.rs-portal .rs-pf-card { display: flex; gap: 14px; align-items: flex-start; padding: 14px 16px; margin: 0 0 1.2em; border: 1px solid #2b2e36; border-radius: 6px; background: #161922 !important; }
.rs-portal .rs-pf-photo { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; flex: none; }
.rs-portal .rs-pf-card-body { flex: 1; min-width: 0; display: grid; gap: 6px; }
.rs-portal .rs-pf-link { color: #beb1ff !important; }
.rs-portal .rs-pf-state { margin: 0; font-size: 0.9em; }
.rs-portal .rs-pf-wait { color: #f5c97e !important; }
.rs-portal .rs-pf-done { color: #8fe6ab !important; }
.rs-portal .rs-pf-no { color: #ff9a9a !important; }
.rs-portal .rs-pf-ask summary, .rs-portal .rs-pf-reviews summary, .rs-portal .rs-pf-promo summary, .rs-portal .rs-pf-copies summary { cursor: pointer; color: #beb1ff; font-size: 0.92em; }
.rs-portal .rs-pf-reviews, .rs-portal .rs-pf-promo, .rs-portal .rs-pf-copies { margin: 0.8em 0; }
.rs-portal .rs-pf-form { display: grid; gap: 6px; margin-top: 8px; max-width: 560px; }
.rs-portal .rs-pf-form label { font-size: 0.85em; color: #b7b2d6; }
.rs-portal .rs-pf-form textarea, .rs-portal .rs-pf-form input[type="url"], .rs-portal .rs-pf-form input[type="number"], .rs-portal .rs-pf-form select, .rs-portal .rs-pf-kit input, .rs-portal .rs-pf-kit textarea { width: 100%; padding: 8px 10px; border: 1px solid #3a3f52 !important; border-radius: 3px; background: #1a1d26 !important; color: #f0eefc !important; font: inherit; }
.rs-portal .rs-pf-form input[type="file"] { color: #eae8fa; }
.rs-portal .rs-pf-form button { justify-self: start; }
.rs-portal .rs-pf-msg { text-align: left; margin: 0; }
.rs-portal .rs-pf-scroll { overflow-x: auto; }
.rs-portal .rs-pf-h4 { color: #f6f4ff; margin: 1em 0 0.4em; }
.rs-portal .rs-pf-chart { margin-top: 0.8em; }
.rs-portal .rs-pf-reviews ul, .rs-portal .rs-pf-stmts, .rs-portal .rs-pf-reqs { margin: 0.5em 0; padding-left: 1.2em; }
.rs-portal .rs-pf-reviews li { margin-bottom: 0.6em; }
.rs-portal .rs-pf-stars { color: #f5c97e; margin: 0 6px; letter-spacing: 1px; }
.rs-portal .rs-pf-kit { display: flex; gap: 12px; padding: 10px 0; border-top: 1px solid #2b2e36; }
.rs-portal .rs-pf-kit img { width: 72px; height: 72px; object-fit: cover; border-radius: 4px; flex: none; }
.rs-portal .rs-pf-kit > div { flex: 1; min-width: 0; display: grid; gap: 6px; }
.rs-portal .rs-pf-kit-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.rs-portal .rs-pf-kit-row input { flex: 1; min-width: 160px; }
@media (max-width: 600px) { .rs-portal .rs-pf-card { flex-direction: column; } }
</style>
		<?php
	}

	/* =====================================================================
	 * Admin: «RS Δημιουργοί»
	 * =================================================================== */

	public static function admin_menu(): void {
		$n     = self::new_count();
		$badge = $n > 0 ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
		add_submenu_page(
			RS_Admin_UI::SLUG_MENU,
			__( 'Revenue Splitter — Δημιουργοί', 'revenue-splitter' ),
			__( 'RS Δημιουργοί', 'revenue-splitter' ) . $badge,
			RS_Admin_UI::CAP,
			self::SLUG,
			array( __CLASS__, 'render_admin' )
		);
	}

	public static function route_admin(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked right below.
		if ( ! isset( $_POST['rs_pf_admin'] ) ) {
			return;
		}
		if ( ! isset( $_POST['rs_pf_admin_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_pf_admin_nonce'] ) ), 'rs_pf_admin' )
			|| ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$act  = sanitize_key( wp_unslash( $_POST['rs_pf_admin'] ) );
		$done = 'none';
		if ( 'map' === $act ) {
			$names = RS_Beneficiaries::collect_names();
			$cre   = isset( $_POST['rs_pf_creator'] ) && is_array( $_POST['rs_pf_creator'] ) ? wp_unslash( $_POST['rs_pf_creator'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value absint below.
			$urls  = isset( $_POST['rs_pf_url'] ) && is_array( $_POST['rs_pf_url'] ) ? wp_unslash( $_POST['rs_pf_url'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value esc_url_raw below.
			$map   = array();
			foreach ( $names as $i => $ben ) {
				$cid = absint( $cre[ $i ] ?? 0 );
				if ( $cid > 0 && self::CPT !== get_post_type( $cid ) ) {
					$cid = 0;
				}
				$url = esc_url_raw( trim( (string) ( $urls[ $i ] ?? '' ) ), array( 'http', 'https' ) );
				if ( '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
					$url = ''; // Same site only.
				}
				if ( $cid > 0 || '' !== $url ) {
					$map[ $ben ] = array(
						'creator' => $cid,
						'url'     => $url,
					);
				}
			}
			self::write_json( self::OPT_MAP, $map );
			$done = 'map';
		} elseif ( 'mail' === $act ) {
			update_option( self::OPT_NOMAIL, isset( $_POST['rs_pf_no_email'] ) ? '1' : '', false );
			$done = 'mail';
		} elseif ( 'roles' === $act ) {
			$taxes = array_keys( self::role_taxes() );
			$sel   = isset( $_POST['rs_pf_roles'] ) && is_array( $_POST['rs_pf_roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['rs_pf_roles'] ) ) : array();
			self::write_json( self::OPT_SET, array( 'roles' => array_values( array_intersect( $taxes, $sel ) ) ) );
			$done = 'roles';
		} elseif ( 'done' === $act || 'declined' === $act ) {
			$id    = isset( $_POST['rs_pf_rid'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_pf_rid'] ) ) : '';
			$reply = isset( $_POST['rs_pf_reply'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rs_pf_reply'] ) ) : '';
			$done  = self::close( $id, $act, $reply ) ? 'closed' : 'none';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&rs_pf_done=' . $done ) );
		exit;
	}

	public static function render_admin(): void {
		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only message flag.
		$done    = isset( $_GET['rs_pf_done'] ) ? sanitize_key( wp_unslash( $_GET['rs_pf_done'] ) ) : '';
		$open    = self::requests( 'new' );
		$closed  = array_slice( array_merge( self::requests( 'done' ), self::requests( 'declined' ) ), 0, 30 );
		$names   = RS_Beneficiaries::collect_names();
		$map     = self::map();
		$has_fc  = self::has_creators();
		$taxes   = self::role_taxes();
		$roles   = self::settings()['roles'];
		$pages   = array();
		if ( $has_fc ) {
			$pages = get_posts(
				array(
					'post_type'        => self::CPT,
					'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page'   => -1,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			);
		}
		if ( ! function_exists( 'wp_text_diff' ) ) {
			require_once ABSPATH . 'wp-admin/includes/revision.php';
		}
		?>
		<div class="wrap rs-wrap">
			<h1><?php esc_html_e( 'Revenue Splitter — Δημιουργοί', 'revenue-splitter' ); ?></h1>
			<?php if ( 'map' === $done ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Οι συνδέσεις αποθηκεύτηκαν.', 'revenue-splitter' ); ?></p></div>
			<?php elseif ( 'mail' === $done ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Η ρύθμιση email αποθηκεύτηκε.', 'revenue-splitter' ); ?></p></div>
			<?php elseif ( 'roles' === $done ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Οι ρόλοι αποθηκεύτηκαν.', 'revenue-splitter' ); ?></p></div>
			<?php elseif ( 'closed' === $done ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Το αίτημα έκλεισε. Ο δημιουργός βλέπει την έκβαση στο portal.', 'revenue-splitter' ); ?></p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'Τα αιτήματα των δημιουργών από το Author Portal και η σύνδεση κάθε δικαιούχου με τη σελίδα του. Οι δημιουργοί δεν αλλάζουν τίποτα μόνοι τους: εσύ περνάς την αλλαγή στη σελίδα και κλείνεις το αίτημα.', 'revenue-splitter' ); ?></p>

			<h2 class="rs-h2">
				<?php
				/* translators: %d: number of requests */
				echo esc_html( sprintf( __( 'Ανοιχτά αιτήματα (%d)', 'revenue-splitter' ), count( $open ) ) );
				?>
			</h2>
			<?php if ( empty( $open ) ) : ?>
				<p class="rs-muted"><?php esc_html_e( 'Κανένα ανοιχτό αίτημα.', 'revenue-splitter' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $open as $r ) : ?>
				<div class="rs-pf-req">
					<h3>
						<span class="rs-chip" style="background:<?php echo esc_attr( RS_Admin_UI::ben_color( (string) $r['ben'] ) ); ?>;color:<?php echo esc_attr( RS_Admin_UI::chip_fg( RS_Admin_UI::ben_color( (string) $r['ben'] ) ) ); ?>;"><?php echo esc_html( (string) $r['ben'] ); ?></span>
						<?php echo 'bio' === $r['type'] ? esc_html__( 'Αλλαγή στη σελίδα', 'revenue-splitter' ) : esc_html__( 'Αντίτυπα συγγραφέα', 'revenue-splitter' ); ?>
						<small class="rs-muted"><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) strtotime( (string) $r['at'] ) ) ); ?></small>
					</h3>
					<?php if ( 'bio' === $r['type'] ) : ?>
						<?php
						$cid = self::creator_of( (string) $r['ben'] );
						$now = self::current_bio( (string) $r['ben'] );
						?>
						<?php if ( '' !== (string) $r['bio'] ) : ?>
							<?php
							$diff = wp_text_diff( $now, (string) $r['bio'], array( 'title_left' => __( 'Σήμερα στη σελίδα', 'revenue-splitter' ), 'title_right' => __( 'Προτείνει', 'revenue-splitter' ) ) );
							echo '' !== $diff ? wp_kses_post( $diff ) : '<p class="rs-muted">' . esc_html__( 'Το κείμενο είναι ίδιο με τη σελίδα.', 'revenue-splitter' ) . '</p>';
							?>
							<details><summary><?php esc_html_e( 'Όλο το προτεινόμενο κείμενο', 'revenue-splitter' ); ?></summary><textarea class="large-text" rows="8" readonly><?php echo esc_textarea( (string) $r['bio'] ); ?></textarea></details>
						<?php endif; ?>
						<?php if ( ! empty( $r['links'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Σύνδεσμοι:', 'revenue-splitter' ); ?></strong><br />
							<?php foreach ( (array) $r['links'] as $l ) : ?>
								<a href="<?php echo esc_url( (string) $l ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $l ); ?></a><br />
							<?php endforeach; ?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $r['photo'] ) ) : ?>
							<?php $purl = wp_nonce_url( admin_url( 'admin-post.php?action=rs_pf_photo&id=' . rawurlencode( (string) $r['id'] ) ), 'rs_pf_photo_' . $r['id'] ); ?>
							<p><a href="<?php echo esc_url( $purl ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $purl ); ?>" alt="" style="max-width:160px;max-height:160px;border-radius:4px;" /></a><br /><small class="rs-muted"><?php esc_html_e( 'Νέα φωτογραφία: άνοιξέ την, κατέβασέ την και ανέβασέ την στη σελίδα. Σβήνεται 30 ημέρες μετά το κλείσιμο του αιτήματος.', 'revenue-splitter' ); ?></small></p>
						<?php endif; ?>
						<?php if ( $cid > 0 ) : ?>
							<p><a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $cid, 'url' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Άνοιγμα σελίδας για επεξεργασία', 'revenue-splitter' ); ?></a></p>
						<?php else : ?>
							<p class="rs-muted"><?php esc_html_e( 'Ο δικαιούχος δεν είναι συνδεδεμένος με σελίδα δημιουργού (δες παρακάτω).', 'revenue-splitter' ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p><strong><?php echo esc_html( (int) $r['qty'] . ' × ' . (string) $r['title'] ); ?></strong></p>
					<?php endif; ?>
					<?php if ( ! empty( $r['note'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Σημείωμα:', 'revenue-splitter' ); ?></strong> <?php echo nl2br( esc_html( (string) $r['note'] ) ); ?></p>
					<?php endif; ?>
					<form method="post" class="rs-pf-close">
						<?php wp_nonce_field( 'rs_pf_admin', 'rs_pf_admin_nonce' ); ?>
						<input type="hidden" name="rs_pf_rid" value="<?php echo esc_attr( (string) $r['id'] ); ?>" />
						<label><?php esc_html_e( 'Σχόλιο προς τον δημιουργό (προαιρετικό, το βλέπει στο portal)', 'revenue-splitter' ); ?><br />
						<textarea name="rs_pf_reply" rows="2" class="large-text" maxlength="<?php echo esc_attr( (string) self::REPLY_MAX ); ?>"></textarea></label>
						<span class="rs-pf-btns">
							<button type="submit" name="rs_pf_admin" value="done" class="button button-primary"><?php esc_html_e( 'Ολοκληρώθηκε', 'revenue-splitter' ); ?></button>
							<button type="submit" name="rs_pf_admin" value="declined" class="button"><?php esc_html_e( 'Απόρριψη', 'revenue-splitter' ); ?></button>
						</span>
					</form>
				</div>
			<?php endforeach; ?>

			<h2 class="rs-h2"><?php esc_html_e( 'Σύνδεση δικαιούχων με σελίδες', 'revenue-splitter' ); ?></h2>
			<p class="description">
				<?php
				echo $has_fc
					? esc_html__( 'Με τη σύνδεση, ο δημιουργός βλέπει στο portal τη σελίδα του και τις λήψεις των βιβλίων όπου είναι συγγραφέας, ακόμη κι αν το προϊόν δεν έχει δικό του split. Η «Πρόταση» είναι σελίδα με το ίδιο όνομα· αποθηκεύεται μόνο αν την επιλέξεις.', 'revenue-splitter' )
					: esc_html__( 'Χωρίς το Featured Creators, γράψε τη διεύθυνση της σελίδας βιογραφικού (του ίδιου site). Οι τίτλοι του δημιουργού είναι τα προϊόντα με δικό τους split που τον αναφέρει.', 'revenue-splitter' );
				?>
			</p>
			<?php if ( empty( $names ) ) : ?>
				<p class="rs-muted"><?php esc_html_e( 'Δεν υπάρχουν δικαιούχοι ακόμη.', 'revenue-splitter' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'rs_pf_admin', 'rs_pf_admin_nonce' ); ?>
					<input type="hidden" name="rs_pf_admin" value="map" />
					<table class="widefat striped rs-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
								<?php if ( $has_fc ) : ?>
									<th><?php esc_html_e( 'Σελίδα δημιουργού', 'revenue-splitter' ); ?></th>
									<th><?php esc_html_e( 'Πρόταση', 'revenue-splitter' ); ?></th>
								<?php endif; ?>
								<th><?php esc_html_e( 'Ή διεύθυνση σελίδας', 'revenue-splitter' ); ?></th>
								<th class="num"><?php esc_html_e( 'Τίτλοι', 'revenue-splitter' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $names as $i => $ben ) : ?>
							<?php
							$cur = (int) ( $map[ $ben ]['creator'] ?? 0 );
							$sug = $cur > 0 ? 0 : self::suggest( $ben );
							?>
							<tr>
								<td><?php echo esc_html( $ben ); ?></td>
								<?php if ( $has_fc ) : ?>
									<td>
										<select name="rs_pf_creator[<?php echo esc_attr( (string) $i ); ?>]" id="rs-pf-c-<?php echo esc_attr( (string) $i ); ?>">
											<option value="0">—</option>
											<?php foreach ( $pages as $pg ) : ?>
												<option value="<?php echo esc_attr( (string) $pg->ID ); ?>"<?php selected( $cur, (int) $pg->ID ); ?>><?php echo esc_html( get_the_title( $pg ) . ( 'publish' !== $pg->post_status ? ' (' . __( 'πρόχειρο', 'revenue-splitter' ) . ')' : '' ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<?php if ( $sug > 0 ) : ?>
											<button type="button" class="button button-small rs-pf-use" data-target="rs-pf-c-<?php echo esc_attr( (string) $i ); ?>" data-id="<?php echo esc_attr( (string) $sug ); ?>"><?php echo esc_html( sprintf( /* translators: %s: page title */ __( 'Χρήση: %s', 'revenue-splitter' ), get_the_title( $sug ) ) ); ?></button>
										<?php else : ?>
											<span class="rs-muted">—</span>
										<?php endif; ?>
									</td>
								<?php endif; ?>
								<td><input type="url" class="regular-text" name="rs_pf_url[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $map[ $ben ]['url'] ?? '' ) ); ?>" placeholder="https://…" /></td>
								<td class="num"><?php echo esc_html( number_format_i18n( count( self::titles( $ben ) ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση συνδέσεων', 'revenue-splitter' ); ?></button></p>
				</form>
				<script>
				document.addEventListener('click', function (e) {
					var b = e.target.closest ? e.target.closest('.rs-pf-use') : null;
					if (!b) { return; }
					var s = document.getElementById(b.getAttribute('data-target'));
					if (s) { s.value = b.getAttribute('data-id'); b.disabled = true; }
				});
				</script>
			<?php endif; ?>

			<?php if ( $has_fc && ! empty( $taxes ) ) : ?>
				<h2 class="rs-h2"><?php esc_html_e( 'Ποιοι ρόλοι μετρούν ως «συγγραφέας»', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Οι τίτλοι ενός δημιουργού είναι όσα προϊόντα τον έχουν σε αυτούς τους ρόλους. Προεπιλογή: οι ρόλοι του Featured Creators με schema «author».', 'revenue-splitter' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'rs_pf_admin', 'rs_pf_admin_nonce' ); ?>
					<input type="hidden" name="rs_pf_admin" value="roles" />
					<?php foreach ( $taxes as $tax => $label ) : ?>
						<label style="margin-right:16px;"><input type="checkbox" name="rs_pf_roles[]" value="<?php echo esc_attr( $tax ); ?>"<?php checked( in_array( $tax, $roles, true ) ); ?> /> <?php echo esc_html( $label ); ?> <code><?php echo esc_html( $tax ); ?></code></label>
					<?php endforeach; ?>
					<p><button type="submit" class="button"><?php esc_html_e( 'Αποθήκευση ρόλων', 'revenue-splitter' ); ?></button></p>
				</form>
			<?php endif; ?>

			<h2 class="rs-h2"><?php esc_html_e( 'Αιτήματα προς orOS', 'revenue-splitter' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Το orOS παίρνει τα αιτήματα με το κλειδί του «RS Συμφωνητικά»:', 'revenue-splitter' ); ?></p>
			<ul class="rs-list">
				<li><code><?php echo esc_html( 'GET ' . rest_url( 'rs/v1/profile-requests?state=new' ) ); ?></code></li>
				<li><code><?php echo esc_html( 'POST ' . rest_url( 'rs/v1/profile-requests/<id>' ) ); ?></code> <code>{"state":"oros"}</code> · <code>{"state":"done|declined","reply":"…"}</code></li>
				<li><code><?php echo esc_html( 'GET ' . rest_url( 'rs/v1/profile-requests/<id>/photo' ) ); ?></code></li>
			</ul>
			<form method="post">
				<?php wp_nonce_field( 'rs_pf_admin', 'rs_pf_admin_nonce' ); ?>
				<input type="hidden" name="rs_pf_admin" value="mail" />
				<label><input type="checkbox" name="rs_pf_no_email" value="1"<?php checked( '1' === (string) get_option( self::OPT_NOMAIL, '' ) ); ?> /> <?php esc_html_e( 'Μην στέλνεις email για νέα αιτήματα (τα παίρνει το orOS)', 'revenue-splitter' ); ?></label>
				<p><button type="submit" class="button"><?php esc_html_e( 'Αποθήκευση', 'revenue-splitter' ); ?></button></p>
			</form>

			<?php if ( ! empty( $closed ) ) : ?>
				<h2 class="rs-h2"><?php esc_html_e( 'Κλεισμένα αιτήματα', 'revenue-splitter' ); ?></h2>
				<table class="widefat striped rs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ημερομηνία', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Αίτημα', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Κατάσταση', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Σχόλιο', 'revenue-splitter' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $closed as $r ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'd/m/Y', (int) strtotime( (string) $r['at'] ) ) ); ?></td>
							<td><?php echo esc_html( (string) $r['ben'] ); ?></td>
							<td><?php echo 'bio' === $r['type'] ? esc_html__( 'Αλλαγή στη σελίδα', 'revenue-splitter' ) : esc_html( (int) $r['qty'] . ' × ' . (string) $r['title'] ); ?></td>
							<td><?php echo esc_html( self::state_label( (string) $r['state'] ) ); ?></td>
							<td><?php echo esc_html( (string) ( $r['reply'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php RS_Admin_UI::footer(); ?>
		</div>
<style>
.rs-wrap .rs-pf-req { background: #17191f !important; border: 1px solid #2b2e36; border-radius: 6px; padding: 12px 16px; margin: 12px 0; color: #eae8fa; }
.rs-wrap .rs-pf-req h3 { margin: 0 0 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; color: #f6f4ff; }
.rs-wrap .rs-pf-req table.diff { margin: 8px 0; width: 100%; background: #14161d !important; color: #eae8fa; }
.rs-wrap .rs-pf-req table.diff th { color: #b7b2d6; }
.rs-wrap .rs-pf-req table.diff .diff-deletedline { background: rgba(255, 154, 154, 0.14) !important; }
.rs-wrap .rs-pf-req table.diff .diff-addedline { background: rgba(143, 230, 171, 0.14) !important; }
.rs-wrap .rs-pf-req table.diff del { background: rgba(255, 154, 154, 0.35); color: inherit; }
.rs-wrap .rs-pf-req table.diff ins { background: rgba(143, 230, 171, 0.35); color: inherit; }
.rs-wrap .rs-pf-req a { color: #beb1ff; }
.rs-pf-close { display: grid; gap: 8px; max-width: 640px; }
.rs-pf-btns { display: flex; gap: 8px; }
.rs-wrap .rs-pf-req textarea, .rs-wrap .rs-table input[type="url"] { background: #1a1d26 !important; color: #f0eefc !important; border: 1px solid #3a3f52 !important; }
</style>
		<?php
	}

	/* =====================================================================
	 * Ability: revenue-splitter/downloads
	 * =================================================================== */

	public static function ability_downloads( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$today = wp_date( 'Y-m-d' );
		$from  = isset( $input['date_start'] ) ? (string) $input['date_start'] : wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$to    = isset( $input['date_end'] ) ? (string) $input['date_end'] : $today;
		$names = isset( $input['name'] ) && '' !== (string) $input['name'] ? array( (string) $input['name'] ) : RS_Beneficiaries::collect_names();
		$out   = array();
		foreach ( $names as $ben ) {
			$d = self::downloads( (string) $ben, $from, $to );
			if ( empty( $d['rows'] ) ) {
				continue;
			}
			$rows = array();
			foreach ( $d['rows'] as $pid => $r ) {
				$rows[] = array(
					'product_id' => (int) $pid,
					'title'      => (string) get_the_title( (int) $pid ),
				) + $r;
			}
			$out[] = array(
				'name'    => (string) $ben,
				'creator' => self::creator_of( (string) $ben ),
				'total'   => $d['total'],
				'titles'  => $rows,
			);
		}
		return array(
			'date_start'    => $from,
			'date_end'      => $to,
			'beneficiaries' => $out,
		);
	}
}
