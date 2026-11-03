<?php
/**
 * SSG_Llms — /llms.txt (markdown list of the site for AI assistants),
 * served on parse_request like the sitemaps.
 *
 *  # Site title
 *  > intro (setting) or tagline
 *  ## Pages / Products … (newest first, llms_limit per type)
 *  - [Title](url)
 *  ## Product categories …
 *  - [Name](url)
 *
 * Only indexable objects (same rules as the sitemaps). Never personal
 * data: no e-mail, phone, user name or address is ever written, and any
 * e-mail address that slips into a title or the intro is removed.
 * /llms-full.txt answers 410 (not offered: the whole text of a shop is
 * heavy and nobody needs it).
 *
 * A static llms.txt / llms-full.txt in the web root is served by the web
 * server before WordPress runs and hides this one: SSG_Static moves it to
 * a backup folder on a manager's click.
 *
 * Nothing is written on a visitor request: the text is kept in the object
 * cache under the «last changed» of posts and terms.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Llms {

	const GROUP = 'ssg_llms';

	public static function init(): void {
		add_action( 'parse_request', array( __CLASS__, 'serve' ), 1 );
	}

	/** @param WP $wp */
	public static function serve( $wp ): void {
		try {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
			$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
			$path = strtolower( ltrim( '' !== $base && 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : $path, '/' ) );
			if ( 'llms.txt' !== $path && 'llms-full.txt' !== $path ) {
				return;
			}
			if ( ! Search_Signals::serving() ) {
				return;
			}
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/plain; charset=UTF-8' );
				header( 'X-Robots-Tag: noindex' );
				header( 'X-Search-Signals: llms' );
				nocache_headers();
			}
			if ( 'llms-full.txt' === $path ) {
				status_header( 410 );
				echo "Gone. See /llms.txt\n";
				exit;
			}
			status_header( 200 );
			echo self::text(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain text, built from plain values.
			exit;
		} catch ( \Throwable $e ) {
			Search_Signals::fail( $e );
		}
	}

	/** The whole file. */
	public static function text(): string {
		$key = 'text_' . md5( wp_cache_get_last_changed( 'posts' ) . '|' . wp_cache_get_last_changed( 'terms' ) . '|' . wp_json_encode( SSG_Settings::get() ) . '|' . SSG_VERSION );
		$hit = wp_cache_get( $key, self::GROUP );
		if ( is_string( $hit ) ) {
			return $hit;
		}
		$s     = SSG_Settings::get();
		$intro = '' !== $s['llms_intro'] ? $s['llms_intro'] : (string) get_bloginfo( 'description' );

		$out = '# ' . self::line( (string) get_bloginfo( 'name' ) ) . "\n\n";
		if ( '' !== trim( $intro ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', self::clean( $intro ) ) as $l ) {
				$out .= '> ' . trim( $l ) . "\n";
			}
			$out .= "\n";
		}

		$sections = array();
		foreach ( SSG_Settings::llms_types() as $pt ) {
			if ( SSG_Settings::tpl( 'pt_' . $pt )['n'] ) {
				continue;
			}
			$obj = get_post_type_object( $pt );
			if ( ! $obj ) {
				continue;
			}
			$items = self::posts( $pt, (int) $s['llms_limit'] );
			if ( $items ) {
				$sections[] = array(
					'title' => self::line( (string) $obj->labels->name ),
					'items' => $items,
				);
			}
		}
		foreach ( SSG_Settings::llms_taxes() as $tx ) {
			if ( SSG_Settings::tpl( 'tax_' . $tx )['n'] ) {
				continue;
			}
			$obj = get_taxonomy( $tx );
			if ( ! $obj ) {
				continue;
			}
			$items = self::terms( $tx );
			if ( $items ) {
				$sections[] = array(
					'title' => self::line( (string) $obj->labels->name ),
					'items' => $items,
				);
			}
		}
		/**
		 * Sections of llms.txt: [ [ 'title' => string, 'items' => [ [ title, url ], … ] ], … ].
		 *
		 * @param array $sections
		 */
		$sections = apply_filters( 'noxpress_seo_llms_sections', $sections );
		foreach ( is_array( $sections ) ? $sections : array() as $sec ) {
			if ( ! is_array( $sec ) || empty( $sec['items'] ) || ! is_array( $sec['items'] ) ) {
				continue;
			}
			$out .= '## ' . self::line( (string) ( isset( $sec['title'] ) ? $sec['title'] : '' ) ) . "\n\n";
			foreach ( $sec['items'] as $it ) {
				if ( is_array( $it ) && isset( $it[0], $it[1] ) && '' !== (string) $it[1] ) {
					$out .= '- [' . str_replace( array( '[', ']' ), array( '(', ')' ), self::line( (string) $it[0] ) ) . '](' . esc_url_raw( (string) $it[1] ) . ")\n";
				}
			}
			$out .= "\n";
		}
		$out = rtrim( $out ) . "\n";
		wp_cache_set( $key, $out, self::GROUP, HOUR_IN_SECONDS );
		return $out;
	}

	/** [ [ title, url ], … ] newest first. */
	private static function posts( string $pt, int $limit ): array {
		$args = array(
			'post_type'              => $pt,
			'post_status'            => 'publish',
			'has_password'           => false,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'posts_per_page'         => $limit > 0 ? $limit : -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'meta_query'             => SSG_Meta::indexable_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached.
		);
		$skip = SSG_Meta::skipped_ids( $pt );
		if ( $skip ) {
			$args['post__not_in'] = $skip; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- small list.
		}
		$q   = new WP_Query( $args );
		$ids = array_map( 'intval', (array) $q->posts );
		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$url = (string) get_permalink( $id );
			if ( '' !== $url ) {
				$out[] = array( SSG_Vars::plain( (string) get_the_title( $id ) ), $url );
			}
		}
		return $out;
	}

	private static function terms( string $tx ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $tx,
				'hide_empty' => true,
				'orderby'    => 'name',
				'number'     => 1000,
				'meta_query' => SSG_Meta::indexable_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached.
			)
		);
		$terms = apply_filters( 'ssg_llms_terms', is_array( $terms ) ? $terms : array(), $tx );
		$out   = array();
		foreach ( (array) $terms as $t ) {
			if ( ! $t instanceof WP_Term ) {
				continue;
			}
			$link = get_term_link( $t );
			if ( is_string( $link ) ) {
				$out[] = array( SSG_Vars::plain( $t->name ), $link );
			}
		}
		return $out;
	}

	/** One line of plain text, without e-mail addresses. */
	private static function line( string $s ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', self::clean( $s ) ) );
	}

	/** Plain text without e-mail addresses (never personal data). */
	public static function clean( string $s ): string {
		$s = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '', $s ) );
	}
}
