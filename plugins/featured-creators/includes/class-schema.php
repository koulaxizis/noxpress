<?php
/**
 * CRT_Schema — structured data (design §6).
 *
 * Creator page: ProfilePage whose mainEntity is a Person (@id = page URL
 * + #person): name, url, image, description, jobTitle, sameAs (links).
 *  - With Rank Math: inside its graph (rank_math/json_ld). Its WebPage
 *    node becomes ProfilePage, its Article node for the page (and the
 *    article's author) is dropped, our Person is added. One graph.
 *  - Without it (or when Rank Math printed no graph on the page): our
 *    own <script type="application/ld+json"> in wp_head.
 *
 * Products: schema.org Product has no author / contributor. When a
 * product category is mapped to a work type (Book, Audiobook, MusicAlbum,
 * Movie), the Product node becomes [ "Product", type ] and every role adds
 * its property (author, illustrator…) with the creators as Person nodes.
 * A property the type does not have falls back to contributor. Applies to
 * Rank Math's Product node and to WooCommerce's own product markup.
 *
 * Only public creators, only when visitors may see the plugin.
 */

defined( 'ABSPATH' ) || exit;

final class CRT_Schema {

	/** Properties every CreativeWork has. */
	const BASE_PROPS = array( 'author', 'contributor', 'editor', 'producer', 'translator' );

	/** Extra properties per work type. */
	const TYPE_PROPS = array(
		'Book'       => array( 'illustrator' ),
		'Audiobook'  => array( 'illustrator', 'readBy' ),
		'Movie'      => array( 'director', 'actor' ),
		'MusicAlbum' => array( 'byArtist' ),
	);

	/** Whether Rank Math's graph already carries the creator (this request). */
	private static $in_graph = false;

	public static function init(): void {
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'rank_math' ), 99, 2 );
		add_action( 'wp_head', array( __CLASS__, 'own_jsonld' ), 30 );
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'wc_product' ), 20, 2 );
	}

	/* =====================================================================
	 * Nodes
	 * =================================================================== */

	/** The full Person node of a creator page. */
	public static function person( int $cid ): array {
		$url  = (string) get_permalink( $cid );
		$node = array(
			'@type' => 'Person',
			'@id'   => $url . '#person',
			'name'  => get_the_title( $cid ),
			'url'   => $url,
		);
		$img = get_the_post_thumbnail_url( $cid, 'medium' );
		if ( $img ) {
			$node['image'] = $img;
		}
		$bio = CRT_Front::short_bio( $cid );
		if ( '' !== $bio ) {
			$node['description'] = $bio;
		}
		$job = CRT_Front::roles_line( $cid );
		if ( '' !== $job ) {
			$node['jobTitle'] = $job;
		}
		$same = array();
		foreach ( CRT_CPT::links( $cid ) as $l ) {
			$same[] = $l['url'];
		}
		if ( $same ) {
			$node['sameAs'] = $same;
		}
		return $node;
	}

	/** A short Person reference (used on product pages). */
	private static function person_ref( int $cid ): array {
		$url = (string) get_permalink( $cid );
		return array(
			'@type' => 'Person',
			'@id'   => $url . '#person',
			'name'  => get_the_title( $cid ),
			'url'   => $url,
		);
	}

	private static function profile_page( int $cid ): array {
		$url = (string) get_permalink( $cid );
		return array(
			'@type'        => 'ProfilePage',
			'@id'          => $url . '#webpage',
			'url'          => $url,
			'name'         => get_the_title( $cid ),
			'dateCreated'  => get_post_time( 'c', true, $cid ),
			'dateModified' => get_post_modified_time( 'c', true, $cid ),
			'mainEntity'   => array( '@id' => $url . '#person' ),
		);
	}

	/** Creator page the visitor is on (0 = none). */
	private static function current_creator(): int {
		if ( ! is_singular( CRT_CPT::PT ) || ! CRT_Settings::get()['schema'] || ! CRT_Front::visible() ) {
			return 0;
		}
		$cid = (int) get_queried_object_id();
		return CRT_CPT::is_public( $cid ) ? $cid : 0;
	}

	/** The work type of a product from its categories (ancestors included). */
	public static function product_type( int $product_id ): string {
		$map = CRT_Settings::get()['schema_types'];
		if ( ! $map ) {
			return '';
		}
		$cats = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $cats ) ) {
			return '';
		}
		foreach ( $cats as $c ) {
			$chain = array_merge( array( (int) $c->term_id ), array_map( 'intval', get_ancestors( (int) $c->term_id, 'product_cat', 'taxonomy' ) ) );
			foreach ( $chain as $id ) {
				if ( isset( $map[ $id ] ) ) {
					return $map[ $id ];
				}
			}
		}
		return '';
	}

	/**
	 * Role properties of a product: [ type, { prop => Person[] } ], or
	 * [ '', [] ] when nothing applies.
	 */
	public static function product_props( int $product_id ): array {
		$s = CRT_Settings::get();
		if ( ! $s['schema_products'] || ! CRT_Front::visible() ) {
			return array( '', array() );
		}
		$type = self::product_type( $product_id );
		if ( '' === $type ) {
			return array( '', array() );
		}
		$valid = array_merge( self::BASE_PROPS, isset( self::TYPE_PROPS[ $type ] ) ? self::TYPE_PROPS[ $type ] : array() );
		$props = array();
		foreach ( CRT_Match::product_creators( $product_id ) as $c ) {
			foreach ( $c['roles'] as $tax ) {
				$role = CRT_Settings::role( $tax );
				$prop = null !== $role ? $role['schema'] : '';
				if ( '' === $prop ) {
					continue;
				}
				if ( ! in_array( $prop, $valid, true ) ) {
					$prop = 'contributor';
				}
				$props[ $prop ][ (int) $c['id'] ] = self::person_ref( (int) $c['id'] );
			}
		}
		foreach ( $props as $k => $list ) {
			$list        = array_values( $list );
			$props[ $k ] = 1 === count( $list ) ? $list[0] : $list;
		}
		return array( $type, $props );
	}

	/** Adds the type and the properties to a Product node. */
	private static function patch_product( array $node, int $product_id ): array {
		list( $type, $props ) = self::product_props( $product_id );
		if ( '' === $type || ! $props ) {
			return $node;
		}
		$types = isset( $node['@type'] ) ? (array) $node['@type'] : array( 'Product' );
		if ( ! in_array( $type, $types, true ) ) {
			$types[] = $type;
		}
		$node['@type'] = array_values( $types );
		foreach ( $props as $k => $v ) {
			if ( ! isset( $node[ $k ] ) ) {
				$node[ $k ] = $v;
			}
		}
		return $node;
	}

	/* =====================================================================
	 * Rank Math
	 * =================================================================== */

	/**
	 * @param array $data   Graph entities, keyed by Rank Math's names.
	 * @param mixed $jsonld Rank Math's JsonLD object (unused).
	 */
	public static function rank_math( $data, $jsonld = null ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		try {
			$cid = self::current_creator();
			if ( $cid ) {
				return self::rank_math_creator( $data, $cid );
			}
			if ( function_exists( 'is_product' ) && is_product() ) {
				$pid = (int) get_queried_object_id();
				foreach ( $data as $k => $node ) {
					if ( is_array( $node ) && isset( $node['@type'] ) && in_array( 'Product', (array) $node['@type'], true ) ) {
						$data[ $k ] = self::patch_product( $node, $pid );
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $data;
	}

	private static function rank_math_creator( array $data, int $cid ): array {
		$url     = (string) get_permalink( $cid );
		$page_id = '';
		foreach ( $data as $k => $node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
				continue;
			}
			$types = (array) $node['@type'];
			if ( array_intersect( $types, array( 'WebPage', 'ItemPage', 'ProfilePage', 'AboutPage', 'CollectionPage' ) ) ) {
				$node['@type']      = 'ProfilePage';
				$node['mainEntity'] = array( '@id' => $url . '#person' );
				$data[ $k ]         = $node;
				$page_id            = isset( $node['@id'] ) ? (string) $node['@id'] : '';
			}
		}
		// The Article Rank Math makes for a new post type, and its author.
		$author_ids = array();
		foreach ( $data as $k => $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) && array_intersect( (array) $node['@type'], array( 'Article', 'BlogPosting', 'NewsArticle' ) ) ) {
				if ( isset( $node['author']['@id'] ) ) {
					$author_ids[] = (string) $node['author']['@id'];
				}
				unset( $data[ $k ] );
			}
		}
		foreach ( $data as $k => $node ) {
			if ( is_array( $node ) && isset( $node['@id'], $node['@type'] ) && in_array( (string) $node['@id'], $author_ids, true ) && in_array( 'Person', (array) $node['@type'], true ) ) {
				unset( $data[ $k ] );
			}
		}
		if ( '' === $page_id ) {
			$data['crt_profile'] = self::profile_page( $cid );
		}
		$data['crt_person'] = self::person( $cid );
		self::$in_graph     = true;
		return $data;
	}

	/* =====================================================================
	 * Without Rank Math
	 * =================================================================== */

	/**
	 * Rank Math prints its graph at wp_head priority 1 (rank_math/head).
	 * When it did not (its head output or schema module off), ours is printed.
	 */
	public static function own_jsonld(): void {
		if ( self::$in_graph ) {
			return;
		}
		try {
			$cid = self::current_creator();
			if ( ! $cid ) {
				return;
			}
			$graph = array(
				'@context' => 'https://schema.org',
				'@graph'   => array( self::profile_page( $cid ), self::person( $cid ) ),
			);
			echo '<script type="application/ld+json" class="crt-schema">' . wp_json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with JSON_HEX_TAG.
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * WooCommerce's own Product markup (used when no SEO plugin replaces it).
	 *
	 * @param array      $markup
	 * @param WC_Product $product
	 */
	public static function wc_product( $markup, $product ) {
		if ( ! is_array( $markup ) || ! $product instanceof WC_Product ) {
			return $markup;
		}
		try {
			return self::patch_product( $markup, (int) $product->get_id() );
		} catch ( \Throwable $e ) {
			return $markup;
		}
	}
}
