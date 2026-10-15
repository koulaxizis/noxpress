<?php
/**
 * SBR_Guard — what keeps the tools safe.
 *
 *  - SQL: one read-only statement, no comments, no secret names in string
 *    literals, no locking or file functions, a row cap and a time limit.
 *  - Secrets: values under secret-looking names (passwords, tokens, API
 *    and private keys, salts, session tokens, application passwords) are
 *    replaced with "***" in every result and in the audit log.
 *  - Personal data: e-mail, phone, address, post code and IP values are
 *    masked when the site's "mask personal data" setting is on.
 *  - Paths: file tools stay inside wp-content (uploads only through
 *    upload_read); configuration, keys, databases, backups and .git are
 *    never served.
 *  - REST routes and options a tool may not write (users, plugins, themes,
 *    core URLs, roles, this plugin's own state).
 *  - IP lists (CIDR).
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Guard {

	const MASK = '***';

	/** Names whose values are never shown. */
	const SECRET_RE = '/(passw|secret|token|api_?key|private_?key|consumer_?key|access_?key|auth_?key|license_?key|activation_?key|signing_?key|_salt$|^salt$|^sbr_keys$|application_passwords|session_tokens)/i';

	/** Names whose values are personal data (masked when the setting is on). */
	const PII_RE = '/(e-?mail|phone|mobile|address_?[12]|_address$|^address$|ip_?address|remote_?addr|^ip$|_ip$|postcode|post_code|zip_?code)/i';

	/** Tables no query may read. */
	const BLOCKED_TABLES = array( 'woocommerce_api_keys', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta', 'sbr_grants', 'sbr_log' );

	/** Columns no query may name (SELECT * still works: they come back masked). */
	const BLOCKED_COLUMNS = array( 'user_pass', 'user_activation_key' );

	/* =====================================================================
	 * SQL
	 * =================================================================== */

	/**
	 * Validates a read-only query and returns array( 'sql' => final SQL,
	 * 'kind' => select|show|describe|explain ) or array( 'error' => text ).
	 */
	public static function sql( string $sql, int $max_rows ): array {
		global $wpdb;
		$sql = trim( $sql );
		$sql = rtrim( $sql, " \t\r\n;" );
		if ( '' === $sql ) {
			return array( 'error' => 'Empty query.' );
		}
		if ( strlen( $sql ) > 20000 ) {
			return array( 'error' => 'The query is too long (20000 characters at most).' );
		}
		if ( false !== strpos( $sql, "\0" ) ) {
			return array( 'error' => 'The query contains a NUL byte.' );
		}

		// Split string literals out; everything outside them is checked.
		$literals = array();
		$bare     = self::strip_literals( $sql, $literals );
		if ( null === $bare ) {
			return array( 'error' => 'Unbalanced quotes.' );
		}
		if ( preg_match( '/;|--|#|\/\*|\*\//', $bare ) ) {
			return array( 'error' => 'One statement only, without comments or semicolons.' );
		}
		if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $bare, $m ) ) {
			return array( 'error' => 'Only SELECT, SHOW, DESCRIBE, EXPLAIN and WITH … SELECT are allowed.' );
		}
		$kind = strtolower( $m[1] );
		$kind = 'desc' === $kind ? 'describe' : ( 'with' === $kind ? 'select' : $kind );

		$words = 'INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME|GRANT|REVOKE|SET|HANDLER|LOAD|CALL|DO|EXECUTE|PREPARE|DEALLOCATE|LOCK|UNLOCK|INTO|OUTFILE|DUMPFILE|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|RELEASE_ALL_LOCKS|IS_FREE_LOCK|IS_USED_LOCK|MASTER_POS_WAIT|SOURCE_POS_WAIT|WAIT_FOR_EXECUTED_GTID_SET|SYS_EXEC|SYS_EVAL|SHUTDOWN|KILL|FLUSH|RESET|PURGE|OPTIMIZE|REPAIR|ANALYZE|CHECKSUM|INSTALL|UNINSTALL|SHARE';
		if ( preg_match( '/\b(' . $words . ')\b/i', $bare, $w ) ) {
			return array( 'error' => 'Not allowed in a read-only query: ' . strtoupper( $w[1] ) . '.' );
		}
		if ( preg_match( '/\bFOR\s+UPDATE\b/i', $bare ) ) {
			return array( 'error' => 'Not allowed in a read-only query: FOR UPDATE.' );
		}
		foreach ( self::BLOCKED_COLUMNS as $col ) {
			if ( preg_match( '/\b' . $col . '\b/i', $bare ) ) {
				return array( 'error' => 'This column is never readable: ' . $col . '.' );
			}
		}
		foreach ( self::BLOCKED_TABLES as $t ) {
			if ( preg_match( '/\b(' . preg_quote( $wpdb->prefix, '/' ) . ')?' . $t . '\b/i', $bare ) ) {
				return array( 'error' => 'This table is never readable: ' . $t . '.' );
			}
		}
		foreach ( $literals as $lit ) {
			if ( preg_match( self::SECRET_RE, $lit ) ) {
				return array( 'error' => 'The query names a secret (' . $lit . '): its value is never readable.' );
			}
		}

		if ( 'select' === $kind ) {
			$sql = self::cap_limit( $sql, $bare, $max_rows );
		}
		return array(
			'sql'  => $sql,
			'kind' => $kind,
		);
	}

	/**
	 * Replaces quoted literals ('…', "…") and back-quoted identifiers with
	 * placeholders. $literals receives the text of the quoted literals.
	 * Returns null on unbalanced quotes.
	 */
	private static function strip_literals( string $sql, array &$literals ): ?string {
		$out = '';
		$len = strlen( $sql );
		$i   = 0;
		while ( $i < $len ) {
			$ch = $sql[ $i ];
			if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
				$q   = $ch;
				$j   = $i + 1;
				$buf = '';
				$end = false;
				while ( $j < $len ) {
					$c = $sql[ $j ];
					if ( '\\' === $c && '`' !== $q && $j + 1 < $len ) {
						$buf .= $sql[ $j + 1 ];
						$j   += 2;
						continue;
					}
					if ( $c === $q ) {
						if ( $j + 1 < $len && $sql[ $j + 1 ] === $q ) {
							$buf .= $q;
							$j   += 2;
							continue;
						}
						$end = true;
						break;
					}
					$buf .= $c;
					++$j;
				}
				if ( ! $end ) {
					return null;
				}
				if ( '`' === $q ) {
					// An identifier: keep its name visible to the checks.
					$out .= ' ' . preg_replace( '/[^A-Za-z0-9_$]/', '_', $buf ) . ' ';
				} else {
					$literals[] = $buf;
					$out       .= " '' ";
				}
				$i = $j + 1;
				continue;
			}
			$out .= $ch;
			++$i;
		}
		return $out;
	}

	/** Appends LIMIT, or lowers a final LIMIT, so at most $max + 1 rows come back. */
	private static function cap_limit( string $sql, string $bare, int $max ): string {
		$cap = $max + 1;
		if ( preg_match( '/\bLIMIT\s+(\d+)\s*(,\s*(\d+))?\s*(OFFSET\s+\d+\s*)?$/i', $sql, $m, PREG_OFFSET_CAPTURE ) && preg_match( '/\bLIMIT\s+\d+\s*(,\s*\d+)?\s*(OFFSET\s+\d+\s*)?$/i', $bare ) ) {
			if ( isset( $m[3] ) && '' !== $m[3][0] ) {
				// LIMIT offset, count.
				$count = (int) $m[3][0];
				if ( $count > $cap ) {
					return substr( $sql, 0, $m[0][1] ) . 'LIMIT ' . (int) $m[1][0] . ', ' . $cap;
				}
				return $sql;
			}
			$count = (int) $m[1][0];
			if ( $count > $cap ) {
				$tail = isset( $m[4] ) ? ' ' . trim( $m[4][0] ) : '';
				return substr( $sql, 0, $m[0][1] ) . 'LIMIT ' . $cap . $tail;
			}
			return $sql;
		}
		return $sql . ' LIMIT ' . $cap;
	}

	/**
	 * Cleans the rows of a query result: blocked columns, secret and
	 * personal-data values, serialized meta / option values (decoded,
	 * cleaned, returned as arrays).
	 */
	public static function clean_rows( array $rows, string $sql ): array {
		if ( ! $rows ) {
			return $rows;
		}
		$pii       = (bool) SBR_Settings::v( 'pii_mask' );
		$cols      = array_keys( (array) $rows[0] );
		// Any meta table (postmeta, usermeta, wc_orders_meta, order_itemmeta…)
		// or options: a value is judged by its key, so it needs the key.
		$sensitive = (bool) preg_match( '/(meta|options)\b/i', $sql );
		$blind     = array();
		foreach ( array( 'option_value' => 'option_name', 'meta_value' => 'meta_key' ) as $val => $name ) {
			if ( $sensitive && in_array( $val, $cols, true ) && ! in_array( $name, $cols, true ) ) {
				$blind[ $val ] = $name;
			}
		}
		// The value read under another name (alias, CONCAT, SUBSTR…): every
		// column except plain ids and keys is hidden.
		$hidden = false;
		foreach ( array( 'option_value', 'meta_value' ) as $val ) {
			if ( preg_match( '/\b' . $val . '\b/i', self::select_list( $sql ) ) && ! in_array( $val, $cols, true ) ) {
				$hidden = true;
			}
		}
		$out = array();
		foreach ( $rows as $row ) {
			$row  = (array) $row;
			$name = '';
			if ( isset( $row['option_name'] ) ) {
				$name = (string) $row['option_name'];
			} elseif ( isset( $row['meta_key'] ) ) {
				$name = (string) $row['meta_key'];
			}
			foreach ( $row as $col => $v ) {
				$lc = strtolower( (string) $col );
				if ( in_array( $lc, self::BLOCKED_COLUMNS, true ) || self::is_secret( (string) $col ) ) {
					$row[ $col ] = null === $v ? null : self::MASK;
					continue;
				}
				if ( $hidden && ! preg_match( '/^(id|[a-z_]*_id|meta_key|option_name|autoload)$/i', (string) $col ) ) {
					$row[ $col ] = null === $v ? null : self::MASK . ' (select meta_value / option_value by its own name, with meta_key / option_name)';
					continue;
				}
				if ( isset( $blind[ $col ] ) ) {
					$row[ $col ] = self::MASK . ' (select ' . $blind[ $col ] . ' too to see this value)';
					continue;
				}
				if ( ( 'option_value' === $col || 'meta_value' === $col ) && '' !== $name ) {
					if ( self::is_secret( $name ) ) {
						$row[ $col ] = null === $v ? null : self::MASK;
						continue;
					}
					if ( $pii && self::is_pii( $name ) ) {
						$row[ $col ] = self::mask_value( $name, $v );
						continue;
					}
				}
				if ( is_string( $v ) && is_serialized( $v ) ) {
					$data = @unserialize( trim( $v ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- no objects.
					if ( false !== $data || 'b:0;' === trim( $v ) ) {
						$row[ $col ] = self::clean_tree( $data );
						continue;
					}
				}
				if ( $pii && self::is_pii( (string) $col ) ) {
					$row[ $col ] = self::mask_value( (string) $col, $v );
				} elseif ( $pii && is_string( $v ) && false !== strpos( $v, '@' ) ) {
					$row[ $col ] = self::mask_text( $v ); // E-mails under any column name.
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/** The part of a SELECT between SELECT and its first FROM (whole SQL otherwise). */
	private static function select_list( string $sql ): string {
		if ( preg_match( '/\bselect\b(.*?)\bfrom\b/is', $sql, $m ) ) {
			return $m[1];
		}
		return $sql;
	}

	/* =====================================================================
	 * Secrets and personal data in any structure
	 * =================================================================== */

	public static function is_secret( string $name ): bool {
		return (bool) preg_match( self::SECRET_RE, $name );
	}

	public static function is_pii( string $name ): bool {
		return (bool) preg_match( self::PII_RE, $name );
	}

	/**
	 * Recursively replaces secret values and (setting on) masks personal
	 * data, by key name. Meta entries {key, value} are judged by their key.
	 */
	public static function clean_tree( $data, int $depth = 0 ) {
		if ( $depth > 40 ) {
			return self::MASK;
		}
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$pii = (bool) SBR_Settings::v( 'pii_mask' );
		if ( isset( $data['key'] ) && is_string( $data['key'] ) && array_key_exists( 'value', $data ) ) {
			if ( self::is_secret( $data['key'] ) ) {
				$data['value'] = self::MASK;
			} elseif ( $pii && self::is_pii( $data['key'] ) ) {
				$data['value'] = self::mask_value( $data['key'], $data['value'] );
			}
		}
		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && 'key' !== $k && self::is_secret( $k ) ) {
				$data[ $k ] = ( null === $v || '' === $v || false === $v ) ? $v : self::MASK;
			} elseif ( is_string( $k ) && $pii && 'key' !== $k && self::is_pii( $k ) ) {
				$data[ $k ] = self::mask_value( $k, $v );
			} elseif ( is_array( $v ) || is_object( $v ) ) {
				$data[ $k ] = self::clean_tree( $v, $depth + 1 );
			}
		}
		return $data;
	}

	/** Masks a personal value according to its name. */
	public static function mask_value( string $name, $v ) {
		if ( null === $v || '' === $v || false === $v ) {
			return $v;
		}
		if ( is_array( $v ) || is_object( $v ) ) {
			$out = array();
			foreach ( (array) $v as $k => $x ) {
				$out[ $k ] = self::mask_value( is_string( $k ) ? $k : $name, $x );
			}
			return $out;
		}
		$s = (string) $v;
		if ( preg_match( '/e-?mail/i', $name ) || false !== strpos( $s, '@' ) ) {
			return self::mask_email( $s );
		}
		if ( preg_match( '/ip|addr$/i', $name ) && false !== filter_var( $s, FILTER_VALIDATE_IP ) ) {
			return self::mask_ip( $s );
		}
		if ( preg_match( '/phone|mobile/i', $name ) ) {
			$digits = preg_replace( '/\D/', '', $s );
			return str_repeat( '*', max( 0, strlen( $digits ) - 2 ) ) . substr( $digits, -2 );
		}
		$first = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 2 ) : substr( $s, 0, 2 );
		return $first . self::MASK;
	}

	public static function mask_email( string $e ): string {
		$at = strrpos( $e, '@' );
		if ( false === $at ) {
			return self::MASK;
		}
		$user   = substr( $e, 0, $at );
		$domain = substr( $e, $at + 1 );
		$dot    = strrpos( $domain, '.' );
		$tld    = false === $dot ? '' : substr( $domain, $dot );
		return substr( $user, 0, 1 ) . '***@' . substr( $domain, 0, 1 ) . '***' . $tld;
	}

	public static function mask_ip( string $ip ): string {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$p = explode( '.', $ip );
			return $p[0] . '.' . $p[1] . '.*.*';
		}
		$p = explode( ':', $ip );
		return $p[0] . ':' . ( $p[1] ?? '' ) . ':*';
	}

	/** Masks e-mail addresses inside free text (log files). */
	public static function mask_text( string $text ): string {
		if ( ! SBR_Settings::v( 'pii_mask' ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
			static function ( $m ) {
				return self::mask_email( $m[0] );
			},
			$text
		);
	}

	/** Arguments for the audit log: secrets out, long strings cut. */
	public static function redact_args( array $args, int $depth = 0 ): array {
		foreach ( $args as $k => $v ) {
			if ( is_string( $k ) && self::is_secret( $k ) ) {
				$args[ $k ] = self::MASK;
			} elseif ( is_array( $v ) ) {
				$args[ $k ] = $depth > 8 ? self::MASK : self::redact_args( $v, $depth + 1 );
			} elseif ( is_string( $v ) && strlen( $v ) > 1000 ) {
				$args[ $k ] = substr( $v, 0, 1000 ) . '…';
			}
		}
		return $args;
	}

	/* =====================================================================
	 * Paths
	 * =================================================================== */

	/** File names never served by the file tools. */
	private static function blocked_name( string $base ): bool {
		return (bool) preg_match( '/^(wp-config.*|\.env.*|\.htaccess|\.htpasswd|\.user\.ini|php\.ini|auth\.json|id_rsa.*|id_ed25519.*)$|\.(sql|sql\.gz|sqlite\d?|db|key|pem|p12|pfx|crt|kdbx|wpress|daf)$/i', $base );
	}

	/** Folder names never entered (any level). */
	private static function blocked_dir( string $seg ): bool {
		return (bool) preg_match( '/^(\.git|\.svn|database|updraft|ai1wm-backups|backups?-dup.*|wpvividbackups|backwpup.*|backup.*|wp-staging.*|.*-backups?)$/i', $seg );
	}

	/**
	 * Resolves a path inside $root (realpath) and returns the absolute
	 * path, or a WP_Error. $rel is relative to $root. $first_blocked lists
	 * first-level folders refused under this root.
	 */
	public static function path_in( string $root, string $rel, array $first_blocked = array(), bool $must_exist = true ) {
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return new WP_Error( 'sbr_path', 'The base folder does not exist.' );
		}
		$rel = str_replace( '\\', '/', $rel );
		if ( false !== strpos( $rel, "\0" ) ) {
			return new WP_Error( 'sbr_path', 'Invalid path.' );
		}
		$rel  = ltrim( $rel, '/' );
		$segs = array_values( array_filter( explode( '/', $rel ), 'strlen' ) );
		foreach ( $segs as $i => $seg ) {
			if ( '..' === $seg || '.' === $seg ) {
				return new WP_Error( 'sbr_path', 'Relative segments (. and ..) are not allowed.' );
			}
			if ( 0 === $i && in_array( strtolower( $seg ), $first_blocked, true ) ) {
				return new WP_Error( 'sbr_path', 'This folder is not readable with this tool.' );
			}
			if ( self::blocked_dir( $seg ) && $i < count( $segs ) - 1 ) {
				return new WP_Error( 'sbr_path', 'This folder is never readable (configuration, database, backups or version control).' );
			}
		}
		$path = $root_real . ( $segs ? '/' . implode( '/', $segs ) : '' );
		$real = realpath( $path );
		if ( false === $real ) {
			return $must_exist ? new WP_Error( 'sbr_path', 'Not found.' ) : $path;
		}
		if ( $real !== $root_real && 0 !== strpos( $real, $root_real . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error( 'sbr_path', 'The path leaves the allowed folder.' );
		}
		$base = basename( $real );
		if ( is_file( $real ) && self::blocked_name( $base ) ) {
			return new WP_Error( 'sbr_path', 'This file is never readable (configuration, keys, databases or backups).' );
		}
		if ( is_dir( $real ) && $real !== $root_real && self::blocked_dir( $base ) ) {
			return new WP_Error( 'sbr_path', 'This folder is never readable (configuration, database, backups or version control).' );
		}
		return $real;
	}

	/** Whether a directory entry is listed by the file tools. */
	public static function listable( string $name, bool $is_dir ): bool {
		return $is_dir ? ! self::blocked_dir( $name ) : ! self::blocked_name( $name );
	}

	/* =====================================================================
	 * REST routes and options
	 * =================================================================== */

	/** Why a REST call is refused, or '' when allowed. */
	public static function blocked_route( string $route, string $method ): string {
		$route = '/' . ltrim( strtolower( $route ), '/' );
		if ( preg_match( '#^/(site-bridge|batch)(/|$)#', $route ) ) {
			return 'This route is not reachable through Site Bridge.';
		}
		if ( preg_match( '#^/wp-abilities(/|$)#', $route ) ) {
			return 'Use abilities_list and ability_run for abilities.';
		}
		if ( false !== strpos( $route, 'application-passwords' ) ) {
			return 'Application passwords are never reachable through Site Bridge.';
		}
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			if ( preg_match( '#^/wp/v2/(users|plugins|themes|settings)(/|$)#', $route ) ) {
				return 'Users, plugins, themes and site settings do not change through rest_write: use the plugin tools or option_set.';
			}
		}
		return '';
	}

	/** Options no tool writes. */
	public static function blocked_option( string $name ): string {
		global $wpdb;
		$name = strtolower( $name );
		$core = array( 'siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role', $wpdb->prefix . 'user_roles', 'active_plugins', 'active_sitewide_plugins', 'recently_activated', 'uninstall_plugins', 'template', 'stylesheet', 'cron', 'db_version', 'initial_db_version', 'auto_update_plugins', 'auto_update_themes', 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major' );
		if ( in_array( $name, $core, true ) ) {
			return 'This core option does not change through Site Bridge.';
		}
		if ( 0 === strpos( $name, 'sbr_' ) || 0 === strpos( $name, 'noxpress_' ) || 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ) ) {
			return 'This option belongs to Site Bridge, Noxpress Core or the transient cache.';
		}
		if ( self::is_secret( $name ) ) {
			return 'Options with secrets do not change through Site Bridge.';
		}
		return '';
	}

	/* =====================================================================
	 * IP lists
	 * =================================================================== */

	public static function valid_cidr( string $c ): bool {
		$parts = explode( '/', $c, 2 );
		$ip    = $parts[0];
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( ! isset( $parts[1] ) ) {
			return true;
		}
		$max = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? 32 : 128;
		return ctype_digit( $parts[1] ) && (int) $parts[1] <= $max;
	}

	/** Whether $ip is in one of the CIDR lines (empty list = allowed). */
	public static function ip_allowed( string $ip, array $list ): bool {
		if ( ! $list ) {
			return true;
		}
		$bin = '' === $ip ? false : inet_pton( $ip );
		if ( false === $bin ) {
			return false;
		}
		foreach ( $list as $c ) {
			$parts = explode( '/', $c, 2 );
			$net   = inet_pton( $parts[0] );
			if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;
			if ( substr( $net, 0, $bytes ) !== substr( $bin, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest ) {
				return true;
			}
			$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
			if ( ( $net[ $bytes ] & $mask ) === ( $bin[ $bytes ] & $mask ) ) {
				return true;
			}
		}
		return false;
	}
}
