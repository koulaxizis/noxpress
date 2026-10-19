<?php
/**
 * BKF_Client — the Bookpoint / ΟΣΔΕΛ book web service.
 *
 * One endpoint (a Solr front): GET <url>?user=&pass=&core=&q=&fl=&sort=
 * &rows=&start=&wt=json. The service guide gives only http://, which
 * sends the password in clear text on every request. When "try https"
 * is on, https:// is tried first and remembered for 12 hours; on a
 * transport failure the address is used as given and the settings page
 * warns about it.
 *
 * Requests: admin, cron and WP-CLI only, never on a visitor request;
 * wp_safe_remote_get (no private addresses unless the site allows them),
 * timeout 15 s, at most 100 rows per page. The password never reaches a
 * log, a notice or an error: every message goes through redact().
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Client {

	const MAX_ROWS  = 100;
	const TIMEOUT   = 15;
	const SCHEME_TR = 'bkf_scheme';

	/** Fields asked for on books (the guide's list). */
	const BOOK_FIELDS = 'product_id,add_product_ts,last_update,cover,back_cover,product_kind,product_form,publishing_status,edition_no,volume,total_volumes,pub_id,pub_name,imprint,distinctive_title,distinctive_subtitle,alternative_title,original_title,city,isbn13,rel_isbn,rel_descr,coll_title,coll_seq_no,short_descr,description,category,category_main,classification,thema_code,audience,contr_name,contr_id,contr_role,language,translated,original_language,height,width,price,vat,price_type,price_valid_until,last_price_update,pages,weight,pub_year,pub_month,pub_day,avq';

	/** The address and scheme of the last request (redacted), for messages. */
	private static $last = '';

	/**
	 * One request.
	 *
	 * @param array $p core, q, fl, sort, rows, start.
	 * @return array|WP_Error { num: int, start: int, docs: array[] }.
	 */
	public static function query( array $p ) {
		$s    = BKF_Settings::get();
		$pass = BKF_Settings::password();
		if ( '' === $s['user'] || null === $pass || '' === $pass ) {
			return new WP_Error( 'bkf_creds', __( 'Λείπουν το όνομα χρήστη ή ο κωδικός της υπηρεσίας (Ρυθμίσεις).', 'book-feed' ) );
		}

		$core = isset( $p['core'] ) && in_array( $p['core'], array( 'books', 'pubs', 'thema', 'contrs' ), true ) ? $p['core'] : 'books';
		$args = array(
			'user'   => $s['user'],
			'pass'   => $pass,
			'core'   => $core,
			'q'      => isset( $p['q'] ) && is_string( $p['q'] ) && '' !== $p['q'] ? $p['q'] : '*:*',
			'wt'     => 'json',
			'indent' => 'false',
			'rows'   => max( 0, min( self::MAX_ROWS, (int) ( $p['rows'] ?? 20 ) ) ),
			'start'  => max( 0, (int) ( $p['start'] ?? 0 ) ),
		);
		if ( ! empty( $p['fl'] ) && is_string( $p['fl'] ) ) {
			$args['fl'] = $p['fl'];
		}
		if ( ! empty( $p['sort'] ) && is_string( $p['sort'] ) && preg_match( '/^[a-z_]+ (asc|desc)(, ?[a-z_]+ (asc|desc))*$/', $p['sort'] ) ) {
			$args['sort'] = $p['sort'];
		}

		$res = self::fetch( $s, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::parse( $res, $pass );
	}

	/** GET with https first when allowed; returns the body or WP_Error. */
	private static function fetch( array $s, array $args ) {
		$base  = $s['url'];
		$https = preg_replace( '#^http://#i', 'https://', $base );
		$tries = array( $base );
		if ( $s['https'] && $https !== $base ) {
			$known = get_transient( self::SCHEME_TR );
			if ( 'http' === $known ) {
				$tries = array( $base );
			} else {
				$tries = array( $https, $base );
			}
		}

		$err = null;
		foreach ( $tries as $i => $url ) {
			$full       = $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
			self::$last = self::redact( $url, (string) $args['pass'] );
			$r          = wp_safe_remote_get(
				$full,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 2,
					'user-agent'  => 'Book Feed/' . BKF_VERSION . '; ' . home_url( '/' ),
					'headers'     => array( 'Accept' => 'application/json' ),
				)
			);
			if ( is_wp_error( $r ) ) {
				$err = new WP_Error( 'bkf_net', self::redact( $r->get_error_message(), (string) $args['pass'] ) );
				continue; // transport failure: try the next address
			}
			if ( $s['https'] && $https !== $base ) {
				set_transient( self::SCHEME_TR, 0 === stripos( $url, 'https://' ) ? 'https' : 'http', 12 * HOUR_IN_SECONDS );
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$body = (string) wp_remote_retrieve_body( $r );
			if ( 200 !== $code ) {
				/* translators: 1: HTTP status code, 2: start of the reply */
				return new WP_Error( 'bkf_http', sprintf( __( 'Η υπηρεσία απάντησε με κωδικό HTTP %1$d: %2$s', 'book-feed' ), $code, self::snippet( $body, (string) $args['pass'] ) ) );
			}
			return $body;
		}
		return $err ? $err : new WP_Error( 'bkf_net', __( 'Η υπηρεσία δεν απαντά.', 'book-feed' ) );
	}

	/** Solr JSON → { num, start, docs } or WP_Error. */
	public static function parse( string $body, string $pass = '' ) {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			/* translators: %s: start of the reply */
			return new WP_Error( 'bkf_format', sprintf( __( 'Η απάντηση της υπηρεσίας δεν είναι JSON: %s', 'book-feed' ), self::snippet( $body, $pass ) ) );
		}
		if ( isset( $data['error'] ) ) {
			$msg = is_array( $data['error'] ) && isset( $data['error']['msg'] ) ? (string) $data['error']['msg'] : ( is_scalar( $data['error'] ) ? (string) $data['error'] : '' );
			/* translators: %s: error text from the service */
			return new WP_Error( 'bkf_service', sprintf( __( 'Σφάλμα της υπηρεσίας: %s', 'book-feed' ), self::snippet( $msg, $pass ) ) );
		}
		if ( ! isset( $data['response'] ) || ! is_array( $data['response'] ) || ! isset( $data['response']['docs'] ) || ! is_array( $data['response']['docs'] ) ) {
			/* translators: %s: start of the reply */
			return new WP_Error( 'bkf_format', sprintf( __( 'Η απάντηση της υπηρεσίας δεν έχει τη μορφή που περιμέναμε: %s', 'book-feed' ), self::snippet( $body, $pass ) ) );
		}
		$docs = array();
		foreach ( $data['response']['docs'] as $doc ) {
			if ( is_array( $doc ) ) {
				$docs[] = $doc;
			}
		}
		return array(
			'num'   => max( 0, (int) ( $data['response']['numFound'] ?? count( $docs ) ) ),
			'start' => max( 0, (int) ( $data['response']['start'] ?? 0 ) ),
			'docs'  => $docs,
		);
	}

	/** Removes the password (plain and URL-encoded) from a text. */
	public static function redact( string $text, string $pass ): string {
		if ( '' === $pass ) {
			return $text;
		}
		return str_replace( array( $pass, rawurlencode( $pass ), urlencode( $pass ) ), '***', $text );
	}

	/** The start of a reply as plain text, redacted. */
	private static function snippet( string $body, string $pass ): string {
		$t = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $body ) ) );
		$t = self::redact( $t, $pass );
		if ( '' === $t ) {
			return __( '(κενή απάντηση)', 'book-feed' );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $t, 0, 160 ) : substr( $t, 0, 160 );
	}

	/** The address of the last request, without the password. */
	public static function last_address(): string {
		return self::$last;
	}

	/** 'https' | 'http' | '' — what the last successful request used. */
	public static function scheme(): string {
		$s = BKF_Settings::get();
		if ( 0 === stripos( $s['url'], 'https://' ) ) {
			return 'https';
		}
		if ( ! $s['https'] ) {
			return 'http';
		}
		$k = get_transient( self::SCHEME_TR );
		return is_string( $k ) ? $k : '';
	}

	/* ---------------------------------------------------------------------
	 * Query building (Solr syntax)
	 * ------------------------------------------------------------------- */

	/** Escapes Solr special characters in one term. */
	public static function esc( string $term ): string {
		return preg_replace( '/([+\-&|!(){}\[\]^"~*?:\\\\\/])/u', '\\\\$1', $term );
	}

	/** Lower case without accents (the *_search fields fold them). */
	public static function fold( string $text ): string {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $text, Normalizer::FORM_D );
			if ( is_string( $n ) ) {
				$text = preg_replace( '/\p{Mn}+/u', '', $n );
			}
		} else {
			$text = strtr(
				$text,
				array(
					'ά' => 'α',
					'έ' => 'ε',
					'ή' => 'η',
					'ί' => 'ι',
					'ϊ' => 'ι',
					'ΐ' => 'ι',
					'ό' => 'ο',
					'ύ' => 'υ',
					'ϋ' => 'υ',
					'ΰ' => 'υ',
					'ώ' => 'ω',
				)
			);
		}
		return $text;
	}

	/** field:(w1 AND w2 …) from free text, or ''. */
	public static function words( string $field, string $text ): string {
		$parts = preg_split( '/[\s,;]+/u', self::fold( trim( $text ) ) );
		$terms = array();
		foreach ( (array) $parts as $w ) {
			$w = trim( (string) $w );
			if ( '' !== $w ) {
				$terms[] = self::esc( $w );
			}
			if ( count( $terms ) >= 8 ) {
				break;
			}
		}
		return $terms ? $field . ':(' . implode( ' AND ', $terms ) . ')' : '';
	}

	/** field:"value" (exact string). */
	public static function exact( string $field, string $value ): string {
		return $field . ':"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}

	/**
	 * Builds q for books.
	 *
	 * @param array $f isbn, title, author, pub_id, year_from, year_to, kind,
	 *                 since (Unix time, last_update), ids (product_id list).
	 */
	public static function book_query( array $f ): string {
		$and = array();
		if ( ! empty( $f['ids'] ) && is_array( $f['ids'] ) ) {
			$ids = array();
			foreach ( $f['ids'] as $id ) {
				$id = (string) $id;
				if ( '' !== $id && strlen( $id ) <= 64 ) {
					$ids[] = self::exact( 'product_id', $id );
				}
			}
			if ( $ids ) {
				$and[] = '(' . implode( ' OR ', $ids ) . ')';
			}
		}
		$isbn = preg_replace( '/[^0-9Xx]/', '', (string) ( $f['isbn'] ?? '' ) );
		if ( '' !== $isbn ) {
			$and[] = 'isbn13_search:' . self::esc( $isbn );
		}
		foreach ( array( 'title' => 'title_search', 'author' => 'contr_search' ) as $k => $field ) {
			if ( ! empty( $f[ $k ] ) && is_string( $f[ $k ] ) ) {
				$w = self::words( $field, $f[ $k ] );
				if ( '' !== $w ) {
					$and[] = $w;
				}
			}
		}
		if ( ! empty( $f['pub_id'] ) && preg_match( '/^[A-Za-z0-9_\-]+$/', (string) $f['pub_id'] ) ) {
			$and[] = 'pub_id:' . $f['pub_id'];
		}
		$from = (int) ( $f['year_from'] ?? 0 );
		$to   = (int) ( $f['year_to'] ?? 0 );
		if ( $from > 0 || $to > 0 ) {
			$and[] = 'pub_year:[' . ( $from > 0 ? $from : '*' ) . ' TO ' . ( $to > 0 ? $to : '*' ) . ']';
		}
		if ( ! empty( $f['kind'] ) && is_string( $f['kind'] ) ) {
			$and[] = self::exact( 'product_kind', $f['kind'] );
		}
		if ( ! empty( $f['since'] ) ) {
			$and[] = 'last_update:[' . gmdate( 'Y-m-d\TH:i:s\Z', (int) $f['since'] ) . ' TO *]';
		}
		return $and ? implode( ' AND ', $and ) : '*:*';
	}

	/** Publishers whose name contains the words: [ { id, name } ]. */
	public static function publishers( string $text ) {
		$q = self::words( 'pub_search', $text );
		if ( '' === $q ) {
			return array();
		}
		$r = self::query(
			array(
				'core' => 'pubs',
				'q'    => $q,
				'fl'   => 'pub_id,pub_name',
				'rows' => 30,
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = array();
		foreach ( $r['docs'] as $d ) {
			$id   = isset( $d['pub_id'] ) && is_scalar( $d['pub_id'] ) ? (string) $d['pub_id'] : '';
			$name = isset( $d['pub_name'] ) ? BKF_Record::one( $d['pub_name'] ) : '';
			if ( '' !== $id && preg_match( '/^[A-Za-z0-9_\-]+$/', $id ) ) {
				$out[] = array(
					'id'   => $id,
					'name' => BKF_Settings::text( $name, 150 ),
				);
			}
		}
		return $out;
	}
}
