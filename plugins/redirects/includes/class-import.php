<?php
/**
 * RDR_Import — rules from other plugins and CSV, export and backup.
 *
 * Sources (read only: their tables and options are never changed):
 *  - rank_math    {prefix}rank_math_redirections (sources: exact, start,
 *                 contains, end, regex)
 *  - redirection  {prefix}redirection_items (URL matches only)
 *  - yoast        option wpseo-premium-redirects-base (plain and regex)
 *  - simple301    option 301_redirects (from => to, * wildcards)
 *  - csv          an uploaded file: from, to, code, match
 *
 * Flow (Bible §14.24): scan (read only) → preview with a tick per row
 * (new / duplicate / invalid, with the reason) → apply the ticked rows
 * through RDR_Rules::save(), the same checks as a rule written by hand.
 * Imported rules are live at once (not staged): they were live on the
 * other plugin already. The preview lives in a per-user transient for an
 * hour.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Import {

	const MAX_ROWS = 5000;
	const MAX_CSV  = 2097152;

	/** Source key => label. */
	public static function sources(): array {
		return array(
			'rank_math'   => 'Rank Math',
			'redirection' => 'Redirection',
			'yoast'       => 'Yoast SEO Premium',
			'simple301'   => 'Simple 301 Redirects',
		);
	}

	private static function table_exists( string $t ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only check.
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
	}

	/** Which sources have something to import: key => row count. */
	public static function available(): array {
		global $wpdb;
		$out = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only counts of known tables.
		$t = $wpdb->prefix . 'rank_math_redirections';
		if ( self::table_exists( $t ) ) {
			$out['rank_math'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		}
		$t = $wpdb->prefix . 'redirection_items';
		if ( self::table_exists( $t ) ) {
			$out['redirection'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		}
		// phpcs:enable
		$y = get_option( 'wpseo-premium-redirects-base', null );
		if ( is_array( $y ) ) {
			$out['yoast'] = count( $y );
		}
		$s = get_option( '301_redirects', null );
		if ( is_array( $s ) ) {
			$out['simple301'] = count( $s );
		}
		return $out;
	}

	/** Other redirect systems active now (the notice «two systems»). */
	public static function conflicts(): array {
		$out = array();
		$rm  = get_option( 'rank_math_modules', array() );
		if ( defined( 'RANK_MATH_VERSION' ) && is_array( $rm ) ) {
			if ( in_array( 'redirections', $rm, true ) ) {
				$out[] = 'Rank Math: Redirections';
			}
			if ( in_array( '404-monitor', $rm, true ) ) {
				$out[] = 'Rank Math: 404 Monitor';
			}
		}
		if ( defined( 'REDIRECTION_VERSION' ) ) {
			$out[] = 'Redirection';
		}
		if ( defined( 'WPSEO_PREMIUM_FILE' ) || defined( 'WPSEO_PREMIUM_VERSION' ) ) {
			$out[] = 'Yoast SEO Premium';
		}
		if ( class_exists( 'Simple301Redirects' ) || defined( 'SIMPLE301REDIRECTS_VERSION' ) ) {
			$out[] = 'Simple 301 Redirects';
		}
		return $out;
	}

	/* =====================================================================
	 * Scan
	 * =================================================================== */

	/** A candidate row. */
	private static function cand( string $from, string $match, string $to, int $code, bool $active ): array {
		return array(
			'from'   => $from,
			'match'  => $match,
			'to'     => $to,
			'code'   => in_array( $code, RDR_Rules::CODES, true ) ? $code : ( in_array( $code, array( 451, 404 ), true ) ? 410 : 301 ),
			'active' => $active,
		);
	}

	/** Rank Math's comparisons as our match + pattern. */
	private static function from_comparison( string $pattern, string $cmp ): array {
		switch ( $cmp ) {
			case 'exact':
				return array( $pattern, 'exact' );
			case 'regex':
				return array( $pattern, 'regex' );
			case 'start':
				return array( '^' . preg_quote( ltrim( RDR_Path::lower( rawurldecode( $pattern ) ), '/' ), '~' ), 'regex' );
			case 'end':
				return array( preg_quote( RDR_Path::lower( rawurldecode( $pattern ) ), '~' ) . '$', 'regex' );
			default: // contains
				return array( preg_quote( RDR_Path::lower( rawurldecode( $pattern ) ), '~' ), 'regex' );
		}
	}

	/**
	 * Candidate rows of a source.
	 *
	 * @return array[]|WP_Error
	 */
	public static function scan( string $source ) {
		global $wpdb;
		$out = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only reads of known tables.
		switch ( $source ) {
			case 'rank_math':
				$t = $wpdb->prefix . 'rank_math_redirections';
				if ( ! self::table_exists( $t ) ) {
					break;
				}
				$rows = $wpdb->get_results( "SELECT sources, url_to, header_code, status FROM {$t} ORDER BY id ASC LIMIT " . self::MAX_ROWS, ARRAY_A );
				foreach ( (array) $rows as $r ) {
					$src = maybe_unserialize( $r['sources'] );
					foreach ( is_array( $src ) ? $src : array() as $s ) {
						if ( ! is_array( $s ) || empty( $s['pattern'] ) ) {
							continue;
						}
						list( $pat, $m ) = self::from_comparison( (string) $s['pattern'], isset( $s['comparison'] ) ? (string) $s['comparison'] : 'exact' );
						$out[]           = self::cand( $pat, $m, (string) $r['url_to'], (int) $r['header_code'], 'active' === $r['status'] );
					}
				}
				break;
			case 'redirection':
				$t = $wpdb->prefix . 'redirection_items';
				if ( ! self::table_exists( $t ) ) {
					break;
				}
				$rows = $wpdb->get_results( "SELECT url, regex, action_type, action_code, action_data, match_type, status FROM {$t} ORDER BY id ASC LIMIT " . self::MAX_ROWS, ARRAY_A );
				foreach ( (array) $rows as $r ) {
					if ( 'url' !== (string) $r['match_type'] ) {
						continue; // Login, referrer, cookie… matches have no equivalent.
					}
					$code = (int) $r['action_code'];
					$to   = 'url' === (string) $r['action_type'] ? (string) $r['action_data'] : '';
					$data = maybe_unserialize( $r['action_data'] );
					if ( is_array( $data ) && isset( $data['url'] ) ) {
						$to = (string) $data['url'];
					}
					if ( 'error' === (string) $r['action_type'] ) {
						$code = 410;
					}
					$out[] = self::cand( (string) $r['url'], (int) $r['regex'] ? 'regex' : 'exact', $to, $code, 'enabled' === (string) $r['status'] );
				}
				break;
			case 'yoast':
				foreach ( (array) get_option( 'wpseo-premium-redirects-base', array() ) as $r ) {
					if ( ! is_array( $r ) || empty( $r['origin'] ) ) {
						continue;
					}
					$out[] = self::cand( (string) $r['origin'], ( isset( $r['format'] ) && 'regex' === $r['format'] ) ? 'regex' : 'exact', isset( $r['url'] ) ? (string) $r['url'] : '', isset( $r['type'] ) ? (int) $r['type'] : 301, true );
				}
				break;
			case 'simple301':
				foreach ( (array) get_option( '301_redirects', array() ) as $from => $to ) {
					$from = (string) $from;
					$to   = (string) $to;
					if ( false !== strpos( $from, '*' ) ) {
						$pat   = '^' . str_replace( '\*', '(.*)', preg_quote( ltrim( RDR_Path::lower( rawurldecode( $from ) ), '/' ), '~' ) ) . '$';
						$out[] = self::cand( $pat, 'regex', str_replace( '*', '$1', $to ), 301, true );
					} else {
						$out[] = self::cand( $from, 'exact', $to, 301, true );
					}
				}
				break;
			default:
				return new WP_Error( 'rdr_src', __( 'Άγνωστη πηγή.', 'redirects' ) );
		}
		// phpcs:enable
		return array_slice( $out, 0, self::MAX_ROWS );
	}

	/** Rows of an uploaded CSV (from, to, code, match). */
	public static function scan_csv( string $file ) {
		$fh = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new WP_Error( 'rdr_csv', __( 'Το αρχείο δεν διαβάζεται.', 'redirects' ) );
		}
		$out   = array();
		$first = true;
		while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! is_array( $row ) || count( $row ) < 2 ) {
				continue;
			}
			$row = array_map(
				static function ( $c ) {
					return trim( (string) $c ); // Whole bytes only: a BOM list here would also cut the last byte of «ο» (CE BF).
				},
				$row
			);
			if ( $first ) {
				$row[0] = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $row[0] );
			}
			if ( $first ) {
				$first = false;
				if ( in_array( strtolower( $row[0] ), array( 'from', 'source', 'old', 'από' ), true ) ) {
					continue;
				}
			}
			$code  = isset( $row[2] ) && is_numeric( $row[2] ) ? (int) $row[2] : 301;
			$match = isset( $row[3] ) && in_array( strtolower( $row[3] ), RDR_Rules::MATCHES, true ) ? strtolower( $row[3] ) : 'exact';
			$out[] = self::cand( $row[0], $match, $row[1], $code, true );
			if ( count( $out ) >= self::MAX_ROWS ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/**
	 * Marks each candidate: 'new' | 'dup' | 'invalid', with a reason.
	 * Read only (the full checks run again on apply).
	 */
	public static function classify( array $rows ): array {
		$seen = array();
		foreach ( $rows as &$r ) {
			$r['status'] = 'new';
			$r['why']    = '';
			if ( 'regex' === $r['match'] ) {
				$pat = 0 === strpos( $r['from'], '^/' ) ? '^' . substr( $r['from'], 2 ) : $r['from'];
				if ( strlen( $pat ) > RDR_Rules::MAX_REGEX_LEN || false === @preg_match( RDR_Rules::regex( $pat ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- compile check.
					$r['status'] = 'invalid';
					$r['why']    = __( 'Το regex δεν είναι έγκυρο.', 'redirects' );
					continue;
				}
				if ( RDR_Rules::find_same( $pat, 'regex' ) ) {
					$r['status'] = 'dup';
					$r['why']    = __( 'Υπάρχει ήδη κανόνας.', 'redirects' );
					continue;
				}
				$key = 'r|' . $pat;
			} else {
				$n = RDR_Path::norm( $r['from'] );
				if ( null === $n ) {
					$r['status'] = 'invalid';
					$r['why']    = __( 'Η παλιά διεύθυνση πρέπει να είναι αυτού του site.', 'redirects' );
					continue;
				}
				if ( RDR_Rules::is_protected( $n ) ) {
					$r['status'] = 'invalid';
					$r['why']    = __( 'Διεύθυνση που δεν ανακατευθύνεται ποτέ.', 'redirects' );
					continue;
				}
				if ( RDR_Rules::find_same( $n, $r['match'] ) ) {
					$r['status'] = 'dup';
					$r['why']    = __( 'Υπάρχει ήδη κανόνας.', 'redirects' );
					continue;
				}
				$key = $r['match'] . '|' . $n;
			}
			if ( isset( $seen[ $key ] ) ) {
				$r['status'] = 'dup';
				$r['why']    = __( 'Δεύτερη φορά στη λίστα.', 'redirects' );
				continue;
			}
			$seen[ $key ] = true;
			if ( 410 !== $r['code'] ) {
				$t = RDR_Rules::resolve_target( $r['to'], $r['match'] );
				if ( is_wp_error( $t ) ) {
					$r['status'] = 'invalid';
					$r['why']    = $t->get_error_message();
				}
			}
		}
		unset( $r );
		return $rows;
	}

	/**
	 * Saves the chosen rows.
	 *
	 * @param array[] $rows   Classified rows.
	 * @param int[]   $picked Indexes to import.
	 * @param string  $source Source key for the note.
	 * @return array{ok: int, fail: int, errors: string[]}
	 */
	public static function apply( array $rows, array $picked, string $source ): array {
		$res = array(
			'ok'     => 0,
			'fail'   => 0,
			'errors' => array(),
		);
		foreach ( array_unique( array_map( 'intval', $picked ) ) as $i ) {
			if ( ! isset( $rows[ $i ] ) ) {
				continue;
			}
			$r = $rows[ $i ];
			$s = RDR_Rules::save(
				array(
					'src'    => $r['from'],
					'match'  => $r['match'],
					'target' => $r['to'],
					'code'   => $r['code'],
					'staged' => false,
					'active' => (bool) $r['active'],
					'note'   => 'import: ' . $source,
				)
			);
			if ( is_wp_error( $s ) ) {
				++$res['fail'];
				if ( count( $res['errors'] ) < 10 ) {
					$res['errors'][] = $r['from'] . ': ' . $s->get_error_message();
				}
			} else {
				++$res['ok'];
			}
		}
		return $res;
	}

	/* =====================================================================
	 * Export
	 * =================================================================== */

	/** A CSV cell that a spreadsheet never runs as a formula. */
	private static function cell( string $v ): string {
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}

	/** Rules as CSV rows (from, to, code, match, active, note). */
	public static function csv_rows(): array {
		$rows = array( array( 'from', 'to', 'code', 'match', 'active', 'note' ) );
		foreach ( RDR_Rules::export_all() as $r ) {
			$to     = 'none' === $r['target_type'] ? '' : RDR_Rules::describe_target( $r )[0];
			$rows[] = array(
				self::cell( 'regex' === $r['match'] ? $r['src'] : '/' . $r['src'] ),
				self::cell( $to ),
				(string) $r['code'],
				$r['match'],
				$r['active'] ? '1' : '0',
				self::cell( $r['note'] ),
			);
		}
		return $rows;
	}

	/** Backup: settings, rules and the ignored 404 paths. */
	public static function backup(): array {
		$rules = array();
		foreach ( RDR_Rules::export_all() as $r ) {
			$rules[] = array(
				'from'   => 'regex' === $r['match'] ? $r['src'] : '/' . $r['src'],
				'match'  => $r['match'],
				'to'     => 'none' === $r['target_type'] ? '' : RDR_Rules::describe_target( $r )[0],
				'code'   => $r['code'],
				'staged' => $r['staged'],
				'active' => $r['active'],
				'note'   => $r['note'],
			);
		}
		return array(
			'plugin'  => 'redirects',
			'version' => RDR_VERSION,
			'options' => array(
				RDR_Settings::OPT => RDR_Settings::get(),
				'rdr_ignored'     => get_option( 'rdr_ignored', array() ),
			),
			'rules'   => array_reverse( $rules ),
		);
	}
}
