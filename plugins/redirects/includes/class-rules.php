<?php
/**
 * RDR_Rules — the rules table and everything that decides whether a rule
 * may exist.
 *
 * Table {prefix}rdr_rules (one row per rule):
 *  - src          normalised path (exact, prefix) or the regex itself
 *  - src_hash     md5 of src (index: one indexed query per 404)
 *  - match_type   'exact' | 'prefix' | 'regex'
 *  - target       URL or path as written ('' for 410; may hold $1…$9 for
 *                 a regex)
 *  - target_type  'url' | 'post' | 'term' | 'none' (410). A path that is a
 *                 post or a term when the rule is saved is kept by ID, so
 *                 the rule follows later slug changes.
 *  - target_id, target_tax
 *  - code         301 | 302 | 307 | 410
 *  - staged       1 = answers 302 until «Make it 301» (the page suggests
 *                 it after RDR_Settings::STAGE_DAYS days)
 *  - active, note, created, updated (unix times)
 *
 * Prefix and regex rules (at most 200 and 50) are copied into the option
 * rdr_patterns (not autoloaded), read only on a 404 after the exact
 * lookup missed. Every change rebuilds it.
 *
 * Checks on save: the old address belongs to this site and is not the
 * home page, wp-admin, wp-login.php, the REST API, admin-ajax, XML-RPC,
 * cron or the cart / checkout / account pages; a regex compiles and
 * matches none of those; no loop (A→A, A→B→A); chains are flattened
 * (A→B and B→C makes A→C) both ways; one exact rule per address.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Rules {

	const DBV           = '1';
	const DB_OPT        = 'rdr_db';
	const PAT_OPT       = 'rdr_patterns';
	const MAX_PREFIX    = 200;
	const MAX_REGEX     = 50;
	const MAX_REGEX_LEN = 300;
	const CODES         = array( 301, 302, 307, 410 );
	const MATCHES       = array( 'exact', 'prefix', 'regex' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rdr_rules';
	}

	/** Creates or upgrades the table (activation and admin requests). */
	public static function install(): void {
		if ( self::DBV === get_option( self::DB_OPT ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  src varchar(500) NOT NULL DEFAULT '',
  src_hash char(32) NOT NULL DEFAULT '',
  match_type varchar(10) NOT NULL DEFAULT 'exact',
  target text NOT NULL,
  target_type varchar(10) NOT NULL DEFAULT 'url',
  target_id bigint(20) unsigned NOT NULL DEFAULT 0,
  target_tax varchar(32) NOT NULL DEFAULT '',
  code smallint(5) unsigned NOT NULL DEFAULT 301,
  staged tinyint(1) unsigned NOT NULL DEFAULT 0,
  active tinyint(1) unsigned NOT NULL DEFAULT 1,
  note varchar(200) NOT NULL DEFAULT '',
  created int(10) unsigned NOT NULL DEFAULT 0,
  updated int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY src_hash (src_hash),
  KEY match_type (match_type)
) {$charset};"
		);
		update_option( self::DB_OPT, self::DBV, true );
	}

	/** True once the table exists (the version option is written after it). */
	public static function ready(): bool {
		return self::DBV === get_option( self::DB_OPT );
	}

	/* =====================================================================
	 * Read
	 * =================================================================== */

	/** A row as typed array. */
	private static function row( $r ): ?array {
		if ( ! is_array( $r ) ) {
			return null;
		}
		return array(
			'id'          => (int) $r['id'],
			'src'         => (string) $r['src'],
			'match'       => (string) $r['match_type'],
			'target'      => (string) $r['target'],
			'target_type' => (string) $r['target_type'],
			'target_id'   => (int) $r['target_id'],
			'target_tax'  => (string) $r['target_tax'],
			'code'        => (int) $r['code'],
			'staged'      => (bool) (int) $r['staged'],
			'active'      => (bool) (int) $r['active'],
			'note'        => (string) $r['note'],
			'created'     => (int) $r['created'],
			'updated'     => (int) $r['updated'],
		);
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 || ! self::ready() ) {
			return null;
		}
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return self::row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) );
	}

	/**
	 * A page of rules for the admin list.
	 *
	 * @param array $args search, page (1+), per (1–500), only ('' | 'staged' | 'off').
	 * @return array{rows: array[], total: int}
	 */
	public static function all( array $args = array() ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}
		$t     = self::table();
		$per   = isset( $args['per'] ) ? max( 1, min( 5000, (int) $args['per'] ) ) : 50;
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( RDR_Path::lower( (string) $args['search'] ) ) . '%';
			$where[] = '(src LIKE %s OR target LIKE %s OR note LIKE %s)';
			array_push( $vals, $like, $like, $like );
		}
		if ( isset( $args['only'] ) && 'staged' === $args['only'] ) {
			$where[] = 'staged = 1 AND active = 1';
		} elseif ( isset( $args['only'] ) && 'off' === $args['only'] ) {
			$where[] = 'active = 0';
		}
		$w = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- own table, placeholders built above.
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );
		$sql       = "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'total' => $total,
		);
	}

	/** Counts: total, active, staged (active and staged), off, prefix, regex. */
	public static function counts(): array {
		global $wpdb;
		$c = array(
			'total'  => 0,
			'active' => 0,
			'staged' => 0,
			'off'    => 0,
			'prefix' => 0,
			'regex'  => 0,
		);
		if ( ! self::ready() ) {
			return $c;
		}
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$r = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(active = 1) AS active, SUM(active = 1 AND staged = 1) AS staged, SUM(active = 0) AS off, SUM(match_type = 'prefix') AS prefix, SUM(match_type = 'regex') AS regex FROM {$t}", ARRAY_A );
		if ( is_array( $r ) ) {
			foreach ( $c as $k => $v ) {
				$c[ $k ] = (int) $r[ $k ];
			}
		}
		return $c;
	}

	/** Active exact rule for a normalised path. */
	public static function find_exact( string $norm, int $except = 0 ): ?array {
		return self::find_same( $norm, 'exact', $except );
	}

	/** A rule with the same source and match type (exact, prefix or regex). */
	public static function find_same( string $src, string $match, int $except = 0 ): ?array {
		global $wpdb;
		if ( ! self::ready() ) {
			return null;
		}
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE src_hash = %s AND match_type = %s AND id <> %d ORDER BY active DESC, id ASC LIMIT 5", RDR_Path::hash( $src ), $match, $except ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( (string) $r['src'] === $src ) {
				return self::row( $r );
			}
		}
		return null;
	}

	/** Active prefix and regex rules (cached in rdr_patterns). */
	public static function patterns(): array {
		$p = get_option( self::PAT_OPT, null );
		if ( ! is_array( $p ) ) {
			$p = self::rebuild_patterns();
		}
		return $p;
	}

	public static function rebuild_patterns(): array {
		global $wpdb;
		$out = array();
		if ( self::ready() ) {
			$t = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE active = 1 AND match_type IN ('prefix','regex') ORDER BY match_type ASC, id ASC", ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $r ) {
				$out[] = self::row( $r );
			}
			// Prefix rules first, the longest first; then regex in id order.
			usort(
				$out,
				static function ( $a, $b ) {
					if ( $a['match'] !== $b['match'] ) {
						return 'prefix' === $a['match'] ? -1 : 1;
					}
					if ( 'prefix' === $a['match'] ) {
						return strlen( $b['src'] ) <=> strlen( $a['src'] );
					}
					return $a['id'] <=> $b['id'];
				}
			);
		}
		update_option( self::PAT_OPT, $out, false );
		return $out;
	}

	/** The regex of a rule with its delimiter and flags. */
	public static function regex( string $src ): string {
		return '~' . str_replace( '~', '\~', $src ) . '~iu';
	}

	/** True when a prefix rule covers $norm (whole segments only). */
	public static function prefix_hit( string $src, string $norm ): bool {
		return '' !== $src && ( $norm === $src || 0 === strpos( $norm, $src . '/' ) );
	}

	/**
	 * The rule for a normalised path: exact first, then prefix (longest),
	 * then regex. Returns array( rule, captures ) or null.
	 */
	public static function match( string $norm ): ?array {
		$rule = self::find_exact( $norm );
		if ( $rule && $rule['active'] ) {
			return array( $rule, array() );
		}
		foreach ( self::patterns() as $r ) {
			if ( 'prefix' === $r['match'] ) {
				if ( self::prefix_hit( $r['src'], $norm ) ) {
					return array( $r, array() );
				}
				continue;
			}
			$m  = array();
			$ok = @preg_match( self::regex( $r['src'] ), $norm, $m ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad pattern is skipped (checked on save).
			if ( 1 === $ok ) {
				return array( $r, $m );
			}
		}
		return null;
	}

	/**
	 * The URL a rule sends to now ('' = nothing: 410, or a target that is
	 * gone, which leaves the 404).
	 *
	 * @param array $rule Rule row.
	 * @param array $caps Regex captures (index 1…9 used).
	 */
	public static function target_url( array $rule, array $caps = array() ): string {
		switch ( $rule['target_type'] ) {
			case 'none':
				return '';
			case 'post':
				$p = get_post( $rule['target_id'] );
				if ( ! $p || 'publish' !== $p->post_status || ! is_post_type_viewable( $p->post_type ) ) {
					return '';
				}
				$url = (string) get_permalink( $p );
				break;
			case 'term':
				$link = get_term_link( $rule['target_id'], $rule['target_tax'] );
				if ( is_wp_error( $link ) ) {
					return '';
				}
				$url = (string) $link;
				break;
			default:
				$url = $rule['target'];
				if ( 'regex' === $rule['match'] && $caps ) {
					$url = preg_replace_callback(
						'/\$([1-9])/',
						static function ( $m ) use ( $caps ) {
							$v = isset( $caps[ (int) $m[1] ] ) ? (string) $caps[ (int) $m[1] ] : '';
							return implode( '/', array_map( 'rawurlencode', explode( '/', $v ) ) );
						},
						$url
					);
				}
				if ( '' === $url ) {
					return '';
				}
				if ( '/' === $url[0] && 0 !== strpos( $url, '//' ) ) {
					$url = home_url( $url );
				}
		}
		return $url;
	}

	/** Target as the admin list shows it, and whether it still exists. */
	public static function describe_target( array $rule ): array {
		if ( 'none' === $rule['target_type'] ) {
			return array( '', true );
		}
		$url = self::target_url( $rule );
		if ( 'post' === $rule['target_type'] ) {
			$title = get_the_title( $rule['target_id'] );
			return array( '' !== $url ? $url : '#' . $rule['target_id'] . ( '' !== $title ? ' ' . $title : '' ), '' !== $url );
		}
		if ( 'term' === $rule['target_type'] ) {
			return array( '' !== $url ? $url : $rule['target_tax'] . ' #' . $rule['target_id'], '' !== $url );
		}
		return array( '' !== $url ? $url : $rule['target'], true );
	}

	/* =====================================================================
	 * Checks
	 * =================================================================== */

	/** Normalised paths no rule may ever take. */
	public static function protected_paths(): array {
		$paths = array( '', 'wp-admin', 'wp-admin/index.php', 'wp-admin/admin-ajax.php', 'wp-admin/admin-post.php', 'wp-login.php', 'wp-json', 'wp-json/wp/v2', 'xmlrpc.php', 'wp-cron.php', 'wp-signup.php', 'wp-activate.php', 'robots.txt', 'favicon.ico', 'sitemap.xml', 'wp-sitemap.xml' );
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$n = RDR_Path::norm( (string) get_permalink( $id ) );
					if ( null !== $n && '' !== $n ) {
						$paths[] = $n;
					}
				}
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/** True when a normalised path is one we never touch. */
	public static function is_protected( string $norm ): bool {
		foreach ( array( 'wp-admin', 'wp-json', 'wp-includes' ) as $dir ) {
			if ( $norm === $dir || 0 === strpos( $norm, $dir . '/' ) ) {
				return true;
			}
		}
		return in_array( $norm, self::protected_paths(), true );
	}

	/**
	 * Resolves a written target to (type, target, id, tax, norm path or null
	 * for an external URL) — or a WP_Error.
	 */
	public static function resolve_target( string $to, string $match ) {
		$to = trim( $to );
		if ( '' === $to ) {
			return new WP_Error( 'rdr_target', __( 'Γράψε πού πηγαίνει η ανακατεύθυνση (ή διάλεξε 410).', 'redirects' ) );
		}
		if ( strlen( $to ) > 2000 ) {
			return new WP_Error( 'rdr_target', __( 'Ο στόχος είναι πολύ μεγάλος.', 'redirects' ) );
		}
		$has_caps = 'regex' === $match && preg_match( '/\$[1-9]/', $to );

		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $to ) || 0 === strpos( $to, '//' ) ) {
			$scheme = strtolower( (string) wp_parse_url( $to, PHP_URL_SCHEME ) );
			$host   = strtolower( (string) wp_parse_url( $to, PHP_URL_HOST ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || preg_match( '/[\s<>"]/', $to ) ) {
				return new WP_Error( 'rdr_target', __( 'Ο στόχος πρέπει να είναι διεύθυνση http(s) ή διαδρομή του site που αρχίζει με /.', 'redirects' ) );
			}
			if ( ! RDR_Path::is_own_host( $host ) ) {
				return array(
					'type'   => 'url',
					'target' => esc_url_raw( $to, array( 'http', 'https' ) ),
					'id'     => 0,
					'tax'    => '',
					'norm'   => null,
				);
			}
			$path  = (string) wp_parse_url( $to, PHP_URL_PATH );
			$query = (string) wp_parse_url( $to, PHP_URL_QUERY );
			$to    = '/' . ltrim( $path, '/' ) . ( '' !== $query ? '?' . $query : '' );
		}
		if ( '/' !== $to[0] ) {
			$to = '/' . $to;
		}
		if ( preg_match( '/[\s<>"]/', $to ) ) {
			return new WP_Error( 'rdr_target', __( 'Ο στόχος πρέπει να είναι διεύθυνση http(s) ή διαδρομή του site που αρχίζει με /.', 'redirects' ) );
		}
		$norm = RDR_Path::norm( $to );

		// A path that is a post or a term now is kept by ID (follows slug changes).
		if ( ! $has_caps && false === strpos( $to, '?' ) && null !== $norm ) {
			$url = home_url( $to );
			$pid = url_to_postid( $url );
			if ( $pid > 0 && 'publish' === get_post_status( $pid ) && RDR_Path::norm( (string) get_permalink( $pid ) ) === $norm ) {
				return array(
					'type'   => 'post',
					'target' => '',
					'id'     => (int) $pid,
					'tax'    => '',
					'norm'   => $norm,
				);
			}
			$parts = explode( '/', $norm );
			$last  = (string) end( $parts );
			if ( '' !== $last ) {
				foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
					$term = get_term_by( 'slug', sanitize_title( $last ), $tax );
					if ( ! $term instanceof WP_Term ) {
						$term = get_term_by( 'slug', rawurlencode( $last ), $tax );
					}
					if ( $term instanceof WP_Term ) {
						$link = get_term_link( $term );
						if ( ! is_wp_error( $link ) && RDR_Path::norm( (string) $link ) === $norm ) {
							return array(
								'type'   => 'term',
								'target' => '',
								'id'     => (int) $term->term_id,
								'tax'    => (string) $tax,
								'norm'   => $norm,
							);
						}
					}
				}
			}
		}
		return array(
			'type'   => 'url',
			'target' => $to,
			'id'     => 0,
			'tax'    => '',
			'norm'   => $has_caps ? null : $norm,
		);
	}

	/** The normalised path a saved rule points to (null: external, 410 or dynamic). */
	public static function target_norm( array $rule ): ?string {
		if ( 'none' === $rule['target_type'] || ( 'regex' === $rule['match'] && preg_match( '/\$[1-9]/', $rule['target'] ) ) ) {
			return null;
		}
		$url = self::target_url( $rule );
		return '' === $url ? null : RDR_Path::norm( $url );
	}

	/* =====================================================================
	 * Write
	 * =================================================================== */

	/**
	 * Validates and saves a rule (new when $id is 0).
	 *
	 * @param array $in  src, match, target, code, staged, active, note.
	 * @param int   $id  Existing rule id to update.
	 * @return array|WP_Error The saved row plus 'msgs' (string[]) or an error.
	 */
	public static function save( array $in, int $id = 0 ) {
		global $wpdb;
		self::install();

		$old = $id > 0 ? self::get( $id ) : null;
		if ( $id > 0 && ! $old ) {
			return new WP_Error( 'rdr_missing', __( 'Ο κανόνας δεν βρέθηκε.', 'redirects' ) );
		}
		$msgs  = array();
		$match = isset( $in['match'] ) && in_array( $in['match'], self::MATCHES, true ) ? $in['match'] : 'exact';
		$code  = isset( $in['code'] ) ? (int) $in['code'] : 301;
		if ( ! in_array( $code, self::CODES, true ) ) {
			$code = 301;
		}
		$src_in = isset( $in['src'] ) ? trim( (string) $in['src'] ) : '';
		if ( '' === $src_in ) {
			return new WP_Error( 'rdr_src', __( 'Γράψε την παλιά διεύθυνση.', 'redirects' ) );
		}

		// Old address.
		if ( 'regex' === $match ) {
			$src = $src_in;
			if ( 0 === strpos( $src, '^/' ) ) {
				$src = '^' . substr( $src, 2 );
			}
			if ( strlen( $src ) > self::MAX_REGEX_LEN ) {
				return new WP_Error( 'rdr_src', __( 'Το regex είναι πολύ μεγάλο (έως 300 χαρακτήρες).', 'redirects' ) );
			}
			if ( false === @preg_match( self::regex( $src ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- compile check.
				return new WP_Error( 'rdr_src', __( 'Το regex δεν είναι έγκυρο.', 'redirects' ) );
			}
			foreach ( self::protected_paths() as $p ) {
				if ( 1 === @preg_match( self::regex( $src ), $p ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- compiled above.
					/* translators: %s: a protected path, e.g. wp-login.php */
					return new WP_Error( 'rdr_src', sprintf( __( 'Το regex πιάνει και τη διεύθυνση «/%s», που δεν ανακατευθύνεται ποτέ. Κάν\' το πιο συγκεκριμένο (π.χ. με ^ στην αρχή).', 'redirects' ), $p ) );
				}
			}
		} else {
			$src = RDR_Path::norm( $src_in );
			if ( null === $src ) {
				return new WP_Error( 'rdr_src', __( 'Η παλιά διεύθυνση πρέπει να είναι αυτού του site.', 'redirects' ) );
			}
			if ( self::is_protected( $src ) ) {
				/* translators: %s: a path */
				return new WP_Error( 'rdr_src', sprintf( __( 'Η διεύθυνση «/%s» δεν ανακατευθύνεται ποτέ (αρχική, διαχείριση, σύνδεση, REST, καλάθι, ταμείο ή λογαριασμός).', 'redirects' ), $src ) );
			}
		}

		// Limits of the pattern list.
		if ( 'exact' !== $match && ( ! $old || $old['match'] !== $match ) ) {
			$c = self::counts();
			if ( 'prefix' === $match && $c['prefix'] >= self::MAX_PREFIX ) {
				return new WP_Error( 'rdr_limit', __( 'Έως 200 κανόνες «αρχίζει με».', 'redirects' ) );
			}
			if ( 'regex' === $match && $c['regex'] >= self::MAX_REGEX ) {
				return new WP_Error( 'rdr_limit', __( 'Έως 50 κανόνες regex.', 'redirects' ) );
			}
		}

		// One rule per address (and per prefix or regex).
		$dup = self::find_same( $src, $match, $id );
		if ( $dup ) {
			/* translators: %d: rule id */
			return new WP_Error( 'rdr_dup', sprintf( __( 'Υπάρχει ήδη κανόνας #%d για αυτή τη διεύθυνση.', 'redirects' ), $dup['id'] ), array( 'id' => $dup['id'] ) );
		}

		// Target.
		if ( 410 === $code ) {
			$t = array(
				'type'   => 'none',
				'target' => '',
				'id'     => 0,
				'tax'    => '',
				'norm'   => null,
			);
		} else {
			$t = self::resolve_target( isset( $in['target'] ) ? (string) $in['target'] : '', $match );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
		}

		// Loops and chains (internal targets only).
		if ( null !== $t['norm'] ) {
			$hit_self = 'exact' === $match ? $t['norm'] === $src
				: ( 'prefix' === $match ? self::prefix_hit( $src, $t['norm'] ) : 1 === @preg_match( self::regex( $src ), $t['norm'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- compiled above.
			if ( $hit_self ) {
				return new WP_Error( 'rdr_loop', __( 'Ο στόχος είναι η ίδια η παλιά διεύθυνση: αυτό θα ήταν ατέρμονος βρόχος.', 'redirects' ) );
			}
			// Forward: the target is itself the old address of another rule.
			$seen = array( $src );
			for ( $i = 0; $i < 5; $i++ ) {
				$next = self::find_exact( (string) $t['norm'], $id );
				if ( ! $next || ! $next['active'] ) {
					break;
				}
				if ( 'none' === $next['target_type'] ) {
					break; // A → B where B is gone (410): A goes to B; B answers 410.
				}
				$nn = self::target_norm( $next );
				if ( in_array( (string) $nn, $seen, true ) ) {
					return new WP_Error( 'rdr_loop', __( 'Αυτός ο κανόνας κλείνει κύκλο με άλλους κανόνες (Α→Β→Α).', 'redirects' ) );
				}
				$seen[] = (string) $t['norm'];
				$t      = array(
					'type'   => $next['target_type'],
					'target' => $next['target'],
					'id'     => $next['target_id'],
					'tax'    => $next['target_tax'],
					'norm'   => $nn,
				);
				/* translators: %d: rule id */
				$msgs[] = sprintf( __( 'Ο στόχος είναι η παλιά διεύθυνση του κανόνα #%d: ο νέος κανόνας πηγαίνει κατευθείαν στον τελικό στόχο.', 'redirects' ), $next['id'] );
				if ( null === $nn ) {
					break;
				}
			}
		}

		$now  = time();
		$data = array(
			'src'         => $src,
			'src_hash'    => RDR_Path::hash( $src ),
			'match_type'  => $match,
			'target'      => (string) $t['target'],
			'target_type' => (string) $t['type'],
			'target_id'   => (int) $t['id'],
			'target_tax'  => (string) $t['tax'],
			'code'        => $code,
			'staged'      => ( 410 !== $code && 301 === $code && ! empty( $in['staged'] ) ) ? 1 : 0,
			'active'      => isset( $in['active'] ) ? ( $in['active'] ? 1 : 0 ) : ( $old ? (int) $old['active'] : 1 ),
			'note'        => isset( $in['note'] ) ? mb_substr( sanitize_text_field( (string) $in['note'] ), 0, 200 ) : ( $old ? $old['note'] : '' ),
			'updated'     => $now,
		);
		// An edit keeps the stage of the rule unless asked otherwise.
		if ( $old && ! isset( $in['staged'] ) ) {
			$data['staged'] = ( 301 === $code && $old['staged'] ) ? 1 : 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- own table.
		if ( $old ) {
			$ok = false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) );
		} else {
			$data['created'] = $now;
			$ok              = false !== $wpdb->insert( self::table(), $data );
			$id              = (int) $wpdb->insert_id;
		}
		// phpcs:enable
		if ( ! $ok || $id <= 0 ) {
			return new WP_Error( 'rdr_db', __( 'Ο κανόνας δεν αποθηκεύτηκε (σφάλμα βάσης).', 'redirects' ) );
		}

		// Backward: rules that pointed to this old address now go to its target.
		if ( 'exact' === $match && 410 !== $code ) {
			$n = self::repoint( $src, $id, $t );
			if ( $n > 0 ) {
				/* translators: %d: number of rules */
				$msgs[] = sprintf( _n( '%d κανόνας που έστελνε σε αυτή τη διεύθυνση πηγαίνει τώρα κατευθείαν στον νέο στόχο.', '%d κανόνες που έστελναν σε αυτή τη διεύθυνση πηγαίνουν τώρα κατευθείαν στον νέο στόχο.', $n, 'redirects' ), $n );
			}
		}

		self::rebuild_patterns();
		$row         = self::get( $id );
		$row['msgs'] = $msgs;
		return $row;
	}

	/** Points the exact rules whose target is $src to $t (chain flattening). */
	private static function repoint( string $src, int $self_id, array $t ): int {
		global $wpdb;
		$t_tbl = self::table();
		$n     = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t_tbl} WHERE id <> %d AND active = 1 AND match_type = 'exact' AND target_type = 'url' LIMIT 5000", $self_id ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$r = self::row( $r );
			if ( RDR_Path::norm( $r['target'] ) !== $src || false !== strpos( $r['target'], '?' ) ) {
				continue;
			}
			if ( null !== $t['norm'] && $t['norm'] === $r['src'] ) {
				continue; // Would point a rule to itself: leave it.
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$wpdb->update(
				$t_tbl,
				array(
					'target'      => (string) $t['target'],
					'target_type' => (string) $t['type'],
					'target_id'   => (int) $t['id'],
					'target_tax'  => (string) $t['tax'],
					'updated'     => time(),
				),
				array( 'id' => $r['id'] )
			);
			++$n;
		}
		return $n;
	}

	/** @param int[] $ids */
	public static function delete( array $ids ): int {
		global $wpdb;
		$n = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				$n += (int) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
			}
		}
		self::rebuild_patterns();
		return $n;
	}

	/**
	 * Sets a field on many rules.
	 *
	 * @param int[]  $ids
	 * @param string $what 'on' | 'off' | 'unstage'.
	 */
	public static function bulk( array $ids, string $what ): int {
		global $wpdb;
		$set = 'on' === $what ? array( 'active' => 1 ) : ( 'off' === $what ? array( 'active' => 0 ) : array( 'staged' => 0 ) );
		$n   = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				$n += (int) $wpdb->update( self::table(), $set + array( 'updated' => time() ), array( 'id' => $id ) );
			}
		}
		self::rebuild_patterns();
		return $n;
	}

	/** Staged rules older than the stage period (the page suggests 301). */
	public static function due_for_301(): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE active = 1 AND staged = 1 AND created < %d", time() - RDR_Settings::STAGE_DAYS * DAY_IN_SECONDS ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/** The code a rule answers with now (a staged 301 answers 302). */
	public static function live_code( array $rule ): int {
		return ( $rule['staged'] && 301 === $rule['code'] ) ? 302 : $rule['code'];
	}

	/**
	 * What a visit to an address would meet, without opening it («Test an
	 * address» and redirects/test-url).
	 *
	 * @return array{path: ?string, result: string, rule: int, code: int, target: string, live: bool}
	 *   result: 'external' | 'protected' | 'exists' | 'rule' | 'old_slug'
	 *   | 'greeklish' | '404'.
	 */
	public static function explain( string $url ): array {
		$out  = array(
			'path'   => null,
			'result' => '404',
			'rule'   => 0,
			'code'   => 404,
			'target' => '',
			'live'   => RDR_Settings::live(),
		);
		$path = RDR_Path::norm( $url );
		if ( null === $path ) {
			$out['result'] = 'external';
			return $out;
		}
		$out['path'] = $path;
		if ( self::is_protected( $path ) ) {
			$out['result'] = 'protected';
			$out['code']   = 200;
			return $out;
		}
		// A page or a file that exists: rules never run there.
		$t = self::resolve_target( '/' . $path, 'exact' );
		if ( ! is_wp_error( $t ) && in_array( $t['type'], array( 'post', 'term' ), true ) ) {
			$out['result'] = 'exists';
			$out['code']   = 200;
			return $out;
		}
		$up  = wp_upload_dir( null, false );
		$pre = RDR_Path::norm( (string) $up['baseurl'] );
		if ( null !== $pre && '' !== $pre && 0 === strpos( $path, $pre . '/' ) && is_file( rtrim( (string) $up['basedir'], '/\\' ) . '/' . substr( $path, strlen( $pre ) + 1 ) ) ) {
			$out['result'] = 'exists';
			$out['code']   = 200;
			return $out;
		}
		$hit = self::match( $path );
		if ( $hit ) {
			list( $rule, $caps ) = $hit;
			$out['result']       = 'rule';
			$out['rule']         = $rule['id'];
			$out['code']         = self::live_code( $rule );
			$out['target']       = self::target_url( $rule, $caps );
			if ( 410 !== $rule['code'] && '' === $out['target'] ) {
				$out['code'] = 404; // The target is gone: the 404 stays.
			}
			return $out;
		}
		global $wpdb;
		$parts = explode( '/', $path );
		$last  = (string) end( $parts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin / ability lookup.
		$old = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value IN (%s, %s) LIMIT 1", $last, strtolower( rawurlencode( $last ) ) ) );
		if ( $old > 0 && 'publish' === get_post_status( $old ) ) {
			$out['result'] = 'old_slug';
			$out['code']   = 301;
			$out['target'] = (string) get_permalink( $old );
			return $out;
		}
		if ( function_exists( 'glp_convert' ) && preg_match( '/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u', $path ) ) {
			$out['result'] = 'greeklish';
			$out['code']   = 0;
		}
		return $out;
	}

	/** Every rule (export, backup). */
	public static function export_all(): array {
		$all = self::all( array( 'per' => 5000 ) );
		return $all['rows'];
	}
}
