<?php
/**
 * SSG_Import — settings and per-object meta from Rank Math: scan (dry run)
 * → import → undo. Rank Math's options and meta are only ever read.
 *
 * Settings (rank-math-options-titles / -general / -sitemap): separator,
 * templates and robots per post type / taxonomy / archive, home, search,
 * 404, noindex of empty terms and archive subpages, knowledge graph
 * (type, name, URL, logo, phone numbers, social profiles), default share
 * image, Twitter handle, the WooCommerce generator tag, sitemap (URLs per
 * file, images, post types, taxonomies, authors), llms.txt post types.
 *
 * Meta (posts and terms): rank_math_title, rank_math_description,
 * rank_math_robots (only noindex / nofollow), rank_math_canonical_url,
 * rank_math_facebook_image_id, rank_math_primary_{product_cat,
 * product_brand, category}. rank_math_og_content_image is Rank Math's own
 * cache of the first content image: it is shown in the scan and never
 * imported (the share image falls back to the content image anyway).
 * Not imported: scores, Content AI, analytics, link counter, focus
 * keywords, redirections and the 404 log (those go to Redirects).
 *
 * Undo: option ssg_import_log keeps the settings before the import and
 * every meta written with its old value. Undo restores both, and leaves
 * alone any value changed by hand after the import.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Import {

	const LOG = 'ssg_import_log';
	const MAX = 20000;

	/** Rank Math meta key => our field. */
	const META = array(
		'rank_math_title'              => 'title',
		'rank_math_description'        => 'desc',
		'rank_math_robots'             => 'robots',
		'rank_math_canonical_url'      => 'canonical',
		'rank_math_facebook_image_id'  => 'image',
		'rank_math_primary_product_cat' => 'primary_product_cat',
		'rank_math_primary_product_brand' => 'primary_product_brand',
		'rank_math_primary_category'   => 'primary_category',
	);

	public static function available(): bool {
		return is_array( get_option( 'rank-math-options-titles' ) );
	}

	/* =====================================================================
	 * Settings
	 * =================================================================== */

	/** Our settings as they would be after the import. */
	public static function settings( array &$notes ): array {
		$t = get_option( 'rank-math-options-titles', array() );
		$g = get_option( 'rank-math-options-general', array() );
		$m = get_option( 'rank-math-options-sitemap', array() );
		$t = is_array( $t ) ? $t : array();
		$g = is_array( $g ) ? $g : array();
		$m = is_array( $m ) ? $m : array();

		$s = SSG_Settings::get();
		$on = static function ( $arr, $k ) {
			return isset( $arr[ $k ] ) && 'on' === $arr[ $k ];
		};
		$str = static function ( $arr, $k ) {
			return isset( $arr[ $k ] ) && is_string( $arr[ $k ] ) ? $arr[ $k ] : '';
		};
		$global_noindex = isset( $t['robots_global'] ) && is_array( $t['robots_global'] ) && in_array( 'noindex', $t['robots_global'], true );
		$robots         = static function ( $prefix ) use ( $t, $on, $global_noindex ) {
			if ( $on( $t, $prefix . '_custom_robots' ) ) {
				return isset( $t[ $prefix . '_robots' ] ) && is_array( $t[ $prefix . '_robots' ] ) && in_array( 'noindex', $t[ $prefix . '_robots' ], true );
			}
			return $global_noindex;
		};

		if ( '' !== $str( $t, 'title_separator' ) ) {
			$s['sep'] = html_entity_decode( $str( $t, 'title_separator' ), ENT_QUOTES, 'UTF-8' );
		}

		$tpl = array();
		foreach ( $t as $k => $v ) {
			if ( preg_match( '/^(pt|tax)_([a-z0-9_\-]+)_title$/', (string) $k, $mm ) && is_string( $v ) ) {
				$prefix          = $mm[1] . '_' . $mm[2];
				$key             = $prefix;
				$tpl[ $key ]     = array(
					't' => $v,
					'd' => $str( $t, $prefix . '_description' ),
					'n' => $robots( $prefix ),
				);
			}
			if ( preg_match( '/^pt_([a-z0-9_\-]+)_archive_title$/', (string) $k, $mm ) && is_string( $v ) ) {
				$tpl[ 'arch_' . $mm[1] ] = array(
					't' => $v,
					'd' => $str( $t, 'pt_' . $mm[1] . '_archive_description' ),
					'n' => false,
				);
			}
		}
		$tpl['home'] = array(
			't' => '' !== $str( $t, 'homepage_title' ) ? $str( $t, 'homepage_title' ) : SSG_Settings::defaults_for( 'home' )['t'],
			'd' => $str( $t, 'homepage_description' ),
			'n' => $on( $t, 'homepage_custom_robots' ) && isset( $t['homepage_robots'] ) && is_array( $t['homepage_robots'] ) && in_array( 'noindex', $t['homepage_robots'], true ),
		);
		$tpl['author'] = array(
			't' => '' !== $str( $t, 'author_archive_title' ) ? $str( $t, 'author_archive_title' ) : SSG_Settings::defaults_for( 'author' )['t'],
			'd' => $str( $t, 'author_archive_description' ),
			'n' => $on( $t, 'disable_author_archives' ) || $robots( 'author' ),
		);
		$tpl['date']   = array(
			't' => '' !== $str( $t, 'date_archive_title' ) ? $str( $t, 'date_archive_title' ) : SSG_Settings::defaults_for( 'date' )['t'],
			'd' => $str( $t, 'date_archive_description' ),
			'n' => $on( $t, 'disable_date_archives' ) || ( isset( $t['date_archive_robots'] ) && is_array( $t['date_archive_robots'] ) && in_array( 'noindex', $t['date_archive_robots'], true ) ),
		);
		$tpl['search'] = array(
			't' => '' !== $str( $t, 'search_title' ) ? $str( $t, 'search_title' ) : SSG_Settings::defaults_for( 'search' )['t'],
			'd' => '',
			'n' => ! isset( $t['noindex_search'] ) || $on( $t, 'noindex_search' ),
		);
		$tpl['404']    = array(
			't' => '' !== $str( $t, '404_title' ) ? $str( $t, '404_title' ) : SSG_Settings::defaults_for( '404' )['t'],
			'd' => '',
			'n' => true,
		);
		foreach ( $tpl as $key => $row ) {
			if ( ! SSG_Settings::valid_key( (string) $key ) ) {
				unset( $tpl[ $key ] );
				continue;
			}
			foreach ( array( 't', 'd' ) as $f ) {
				$unk = SSG_Vars::unknown( (string) $row[ $f ] );
				if ( $unk ) {
					/* translators: 1: setting key, 2: variables */
					$notes[] = sprintf( __( '%1$s: οι μεταβλητές %2$s δεν υπάρχουν εδώ και θα παραλείπονται.', 'search-signals' ), $key, '%' . implode( '%, %', $unk ) . '%' );
				}
			}
		}
		$s['tpl'] = $tpl;

		$s['noindex_empty'] = ! isset( $t['noindex_empty_taxonomies'] ) || $on( $t, 'noindex_empty_taxonomies' );
		$s['paged_noindex'] = $on( $t, 'noindex_archive_subpages' );

		$phones = array();
		if ( isset( $t['phone_numbers'] ) && is_array( $t['phone_numbers'] ) ) {
			foreach ( $t['phone_numbers'] as $p ) {
				if ( is_array( $p ) && ! empty( $p['number'] ) ) {
					$phones[] = array(
						'number' => (string) $p['number'],
						'type'   => isset( $p['type'] ) ? str_replace( '_', ' ', (string) $p['type'] ) : '',
						'lang'   => '',
					);
				}
			}
		}
		$same = array();
		foreach ( array( 'social_url_facebook', 'social_url_twitter', 'social_url_instagram', 'social_url_youtube', 'social_url_linkedin', 'social_url_pinterest' ) as $k ) {
			if ( '' !== $str( $t, $k ) ) {
				$same[] = $str( $t, $k );
			}
		}
		foreach ( preg_split( '/[\r\n]+/', $str( $t, 'social_additional_profiles' ) ) as $u ) {
			if ( '' !== trim( $u ) ) {
				$same[] = trim( $u );
			}
		}
		$s['org'] = array(
			'type'    => 'person' === $str( $t, 'knowledgegraph_type' ) ? 'Person' : 'Organization',
			'name'    => '' !== $str( $t, 'knowledgegraph_name' ) ? $str( $t, 'knowledgegraph_name' ) : $str( $t, 'website_name' ),
			'url'     => $str( $t, 'url' ),
			'logo'    => isset( $t['knowledgegraph_logo_id'] ) ? absint( $t['knowledgegraph_logo_id'] ) : 0,
			'phones'  => $phones,
			'same_as' => $same,
		);
		if ( isset( $t['open_graph_image_id'] ) ) {
			$s['image'] = absint( $t['open_graph_image_id'] );
		}
		if ( '' !== $str( $t, 'twitter_author_names' ) ) {
			$s['twitter'] = $str( $t, 'twitter_author_names' );
		}
		if ( isset( $g['wc_remove_generator'] ) ) {
			$s['wc_generator'] = $on( $g, 'wc_remove_generator' );
		}

		if ( isset( $m['items_per_page'] ) ) {
			$s['sm_per'] = (int) $m['items_per_page'];
		}
		if ( isset( $m['include_images'] ) ) {
			$s['sm_images'] = $on( $m, 'include_images' );
		}
		$types = array();
		$taxes = array();
		foreach ( $m as $k => $v ) {
			if ( 'on' !== $v ) {
				continue;
			}
			if ( preg_match( '/^pt_([a-z0-9_\-]+)_sitemap$/', (string) $k, $mm ) ) {
				$types[] = $mm[1];
			} elseif ( preg_match( '/^tax_([a-z0-9_\-]+)_sitemap$/', (string) $k, $mm ) ) {
				$taxes[] = $mm[1];
			}
		}
		if ( $m ) {
			$s['sm_types']   = $types;
			$s['sm_taxes']   = $taxes;
			$s['sm_authors'] = $on( $m, 'authors_sitemap' );
		}
		if ( isset( $g['llms_post_types'] ) && is_array( $g['llms_post_types'] ) ) {
			$s['llms_types'] = array_values( array_map( 'strval', $g['llms_post_types'] ) );
		}
		if ( $on( $g, 'breadcrumbs' ) ) {
			$notes[] = __( 'Τα ορατά breadcrumbs του Rank Math είναι ανοιχτά· το Search Signals βγάζει breadcrumbs μόνο στο schema.', 'search-signals' );
		}
		return SSG_Settings::sanitize( $s );
	}

	/* =====================================================================
	 * Meta
	 * =================================================================== */

	/** Rank Math meta rows: [ type, id, key, value ]. */
	private static function rows(): array {
		global $wpdb;
		$keys = array_keys( self::META );
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$out  = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- admin import, read only.
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT post_id AS id, meta_key AS k, meta_value AS v FROM {$wpdb->postmeta} WHERE meta_key IN ($in) LIMIT " . (int) self::MAX, $keys ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$terms = $wpdb->get_results( $wpdb->prepare( "SELECT term_id AS id, meta_key AS k, meta_value AS v FROM {$wpdb->termmeta} WHERE meta_key IN ($in) LIMIT " . (int) self::MAX, $keys ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable
		foreach ( (array) $posts as $r ) {
			$out[] = array( 'post', (int) $r['id'], (string) $r['k'], maybe_unserialize( (string) $r['v'] ) );
		}
		foreach ( (array) $terms as $r ) {
			$out[] = array( 'term', (int) $r['id'], (string) $r['k'], maybe_unserialize( (string) $r['v'] ) );
		}
		return $out;
	}

	/** Count of rank_math_og_content_image rows and a short sample. */
	private static function og_content(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin scan.
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'rank_math_og_content_image' LIMIT 20", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$v     = maybe_unserialize( (string) $r['meta_value'] );
			$out[] = array(
				'id'    => (int) $r['post_id'],
				'value' => is_scalar( $v ) ? mb_substr( (string) $v, 0, 160 ) : mb_substr( (string) wp_json_encode( $v ), 0, 160 ),
			);
		}
		return $out;
	}

	/**
	 * What one Rank Math row becomes: [ our meta key, value ] or [] to skip.
	 *
	 * @param mixed $v
	 */
	private static function convert( string $type, int $id, string $key, $v ): array {
		$f = self::META[ $key ];
		switch ( $f ) {
			case 'title':
			case 'desc':
				$v = is_string( $v ) ? SSG_Settings::clean( $v ) : '';
				return '' === $v ? array() : array( 'title' === $f ? SSG_Meta::TITLE : SSG_Meta::DESC, mb_substr( $v, 0, 300 ) );
			case 'robots':
				$r = array();
				foreach ( (array) $v as $d ) {
					if ( in_array( $d, array( 'noindex', 'nofollow' ), true ) ) {
						$r[] = $d;
					}
				}
				return $r ? array( SSG_Meta::ROBOTS, array_values( array_unique( $r ) ) ) : array();
			case 'canonical':
				$u = is_string( $v ) ? esc_url_raw( trim( $v ), array( 'http', 'https' ) ) : '';
				return '' === $u ? array() : array( SSG_Meta::CANON, $u );
			case 'image':
				$i = absint( $v );
				return $i ? array( SSG_Meta::IMAGE, $i ) : array();
		}
		// primary_{taxonomy}: posts only, and only a term the post has.
		$tax = substr( $f, 8 );
		$tid = absint( $v );
		if ( 'post' !== $type || ! $tid || ! taxonomy_exists( $tax ) || ! has_term( $tid, $tax, $id ) ) {
			return array();
		}
		return array( SSG_Meta::PRIMARY . $tax, $tid );
	}

	/**
	 * Dry run: what an import would do, without writing.
	 */
	public static function scan(): array {
		$notes    = array();
		$settings = self::settings( $notes );
		$counts   = array();
		$skipped  = 0;
		$same     = 0;
		$samples  = array();
		foreach ( self::rows() as $r ) {
			list( $type, $id, $key, $v ) = $r;
			$c = self::convert( $type, $id, $key, $v );
			if ( ! $c ) {
				++$skipped;
				continue;
			}
			$cur = 'post' === $type ? get_post_meta( $id, $c[0], true ) : get_term_meta( $id, $c[0], true );
			if ( $cur === $c[1] || ( is_scalar( $cur ) && is_scalar( $c[1] ) && (string) $cur === (string) $c[1] ) ) {
				++$same;
				continue;
			}
			$label            = $type . ':' . $key;
			$counts[ $label ] = isset( $counts[ $label ] ) ? $counts[ $label ] + 1 : 1;
			if ( in_array( $key, array( 'rank_math_title', 'rank_math_description', 'rank_math_robots', 'rank_math_canonical_url' ), true ) && count( $samples ) < 30 ) {
				$samples[] = array(
					'type'  => $type,
					'id'    => $id,
					'key'   => $key,
					'value' => is_array( $c[1] ) ? implode( ', ', $c[1] ) : (string) $c[1],
				);
			}
		}
		ksort( $counts );
		return array(
			'settings'   => $settings,
			'notes'      => $notes,
			'counts'     => $counts,
			'same'       => $same,
			'skipped'    => $skipped,
			'samples'    => $samples,
			'og_content' => self::og_content(),
		);
	}

	/** Runs the import. Returns [ settings => bool, meta => int ]. */
	public static function run(): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$notes  = array();
		$before = SSG_Settings::get();
		$new    = self::settings( $notes );
		// Never change the mode or the modules: the owner switches those.
		$new['mode'] = $before['mode'];
		$new['mods'] = $before['mods'];
		$new['keep'] = $before['keep'];

		$log = self::log();
		if ( ! $log ) {
			$log = array(
				'time'     => time(),
				'settings' => $before,
				'meta'     => array(),
			);
		}
		SSG_Settings::save( $new );

		$written = 0;
		foreach ( self::rows() as $r ) {
			list( $type, $id, $key, $v ) = $r;
			$c = self::convert( $type, $id, $key, $v );
			if ( ! $c ) {
				continue;
			}
			$cur = 'post' === $type ? get_post_meta( $id, $c[0], true ) : get_term_meta( $id, $c[0], true );
			if ( $cur === $c[1] || ( is_scalar( $cur ) && is_scalar( $c[1] ) && '' !== (string) $cur && (string) $cur === (string) $c[1] ) ) {
				continue;
			}
			$had   = 'post' === $type ? metadata_exists( 'post', $id, $c[0] ) : metadata_exists( 'term', $id, $c[0] );
			$value = is_string( $c[1] ) ? wp_slash( $c[1] ) : $c[1];
			if ( 'post' === $type ) {
				update_post_meta( $id, $c[0], $value );
			} else {
				update_term_meta( $id, $c[0], $value );
			}
			$log['meta'][] = array( $type, $id, $c[0], $had ? $cur : null, $c[1] );
			++$written;
		}
		$log['time'] = time();
		update_option( self::LOG, $log, false );
		return array(
			'settings' => true,
			'meta'     => $written,
			'notes'    => $notes,
		);
	}

	public static function log(): array {
		$l = get_option( self::LOG, array() );
		return is_array( $l ) && isset( $l['settings'], $l['meta'] ) ? $l : array();
	}

	/** Undo the import. Returns [ restored, kept ] meta counts, or [] when nothing to undo. */
	public static function undo(): array {
		$log = self::log();
		if ( ! $log ) {
			return array();
		}
		$restored = 0;
		$kept     = 0;
		foreach ( array_reverse( (array) $log['meta'] ) as $m ) {
			if ( ! is_array( $m ) || 5 !== count( $m ) ) {
				continue;
			}
			list( $type, $id, $key, $old, $new ) = $m;
			$cur = 'post' === $type ? get_post_meta( (int) $id, (string) $key, true ) : get_term_meta( (int) $id, (string) $key, true );
			if ( $cur !== $new && ! ( is_scalar( $cur ) && is_scalar( $new ) && (string) $cur === (string) $new ) ) {
				++$kept; // Changed by hand after the import.
				continue;
			}
			if ( null === $old ) {
				'post' === $type ? delete_post_meta( (int) $id, (string) $key ) : delete_term_meta( (int) $id, (string) $key );
			} else {
				$old = is_string( $old ) ? wp_slash( $old ) : $old;
				'post' === $type ? update_post_meta( (int) $id, (string) $key, $old ) : update_term_meta( (int) $id, (string) $key, $old );
			}
			++$restored;
		}
		$cur_settings = SSG_Settings::get();
		$back         = is_array( $log['settings'] ) ? $log['settings'] : array();
		$back['mode'] = $cur_settings['mode'];
		$back['mods'] = $cur_settings['mods'];
		SSG_Settings::save( $back );
		delete_option( self::LOG );
		return array( $restored, $kept );
	}
}
