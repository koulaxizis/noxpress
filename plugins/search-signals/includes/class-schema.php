<?php
/**
 * SSG_Schema — one JSON-LD @graph per page (pure: build() returns the
 * nodes, SSG_Head prints them).
 *
 * Nodes (each with an @id, so others can point at them):
 *  - Organization (or Person)  {home}#organization, logo {home}#logo,
 *                              contactPoint list, sameAs
 *  - WebSite                   {home}#website, SearchAction (products
 *                              only when search_product is on)
 *  - WebPage                   {url}#webpage: CollectionPage on terms,
 *                              the shop and archives, ItemPage on products,
 *                              SearchResultsPage on searches
 *  - ImageObject               {url}#primaryimage
 *  - BreadcrumbList            {url}#breadcrumb: home › (shop) › primary
 *                              category and its parents › the object
 *  - BlogPosting / Article     {url}#article on posts / pages (other post
 *                              types: Article), never on the shop
 *  - Product                   {url}#product, built by WooCommerce itself
 *                              (WC_Structured_Data, so the filters
 *                              woocommerce_structured_data_product and
 *                              _offer apply: Pre-Orders, Featured
 *                              Creators), plus brand (primary brand),
 *                              gtin / isbn (global_unique_id), category
 *                              and mainEntityOfPage
 *
 * Filter noxpress_seo_graph( array $nodes, SSG_Context $c ): a list (or a
 * keyed array) of nodes, the same shape as Rank Math's rank_math/json_ld,
 * so code written for one works on the other.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Schema {

	/** @return array List of nodes ('' / [] = nothing to print). */
	public static function build( SSG_Context $c ): array {
		if ( 'other' === $c->kind ) {
			return array();
		}
		$home  = home_url( '/' );
		$nodes = array();

		$nodes['organization'] = self::organization( $home );
		$nodes['website']      = self::website( $home );

		if ( '404' !== $c->kind ) {
			$url = SSG_Meta::canonical( $c );
			if ( '' === $url ) {
				$url = SSG_Meta::base_url( $c );
			}
			if ( '' === $url && 'search' === $c->kind ) {
				$url = add_query_arg( 's', rawurlencode( $c->search ), $home );
			}
			if ( '' !== $url ) {
				$image = self::primary_image( $c, $url );
				if ( $image ) {
					$nodes['primaryimage'] = $image;
				}
				$crumbs = self::breadcrumbs( $c, $url );
				if ( $crumbs ) {
					$nodes['breadcrumb'] = $crumbs;
				}
				$nodes['webpage'] = self::webpage( $c, $url, $image ? $image['@id'] : '', $crumbs ? $crumbs['@id'] : '' );

				if ( 'singular' === $c->kind && 'product' === $c->post_type ) {
					$p = self::product( $c, $url );
					if ( $p ) {
						$nodes['product'] = $p;
					}
				} elseif ( in_array( $c->kind, array( 'singular', 'front', 'blog' ), true ) && 'product' !== $c->post_type ) {
					$a = self::article( $c, $url, $image ? $image['@id'] : '' );
					if ( $a ) {
						$nodes['article'] = $a;
					}
				}
			}
		}

		/**
		 * The JSON-LD graph of a page before it is printed. Each node is an
		 * array with @type and @id ({home}#organization, {home}#website,
		 * {url}#webpage, {url}#primaryimage, {url}#breadcrumb,
		 * {url}#article, {url}#product). Return the nodes; keys are
		 * dropped on output. Live Operator adds the hours of every
		 * ContactPoint here, Featured Creators its ProfilePage and Person.
		 *
		 * @param array       $nodes
		 * @param SSG_Context $c
		 */
		$nodes = apply_filters( 'noxpress_seo_graph', $nodes, $c );
		if ( ! is_array( $nodes ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$nodes,
				static function ( $n ) {
					return is_array( $n ) && ! empty( $n['@type'] );
				}
			)
		);
	}

	/** The printed form: one JSON object with @context and @graph. */
	public static function json( array $nodes ): string {
		if ( ! $nodes ) {
			return '';
		}
		$out = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $nodes,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
		);
		return is_string( $out ) ? $out : '';
	}

	public static function organization( string $home ): array {
		$o    = SSG_Settings::get()['org'];
		$node = array(
			'@type' => 'Person' === $o['type'] ? 'Person' : 'Organization',
			'@id'   => $home . '#organization',
			'name'  => SSG_Settings::org_name(),
			'url'   => '' !== $o['url'] ? $o['url'] : $home,
		);
		if ( $o['logo'] ) {
			$img = self::image_object( (int) $o['logo'], $home . '#logo' );
			if ( $img ) {
				$img['caption'] = $node['name'];
				$node['Person' === $node['@type'] ? 'image' : 'logo'] = $img;
			}
		}
		if ( $o['phones'] && 'Organization' === $node['@type'] ) {
			$points = array();
			foreach ( $o['phones'] as $p ) {
				$pt = array(
					'@type'       => 'ContactPoint',
					'telephone'   => $p['number'],
					'contactType' => '' !== $p['type'] ? $p['type'] : 'customer support',
				);
				if ( '' !== $p['lang'] ) {
					$pt['availableLanguage'] = $p['lang'];
				}
				$points[] = $pt;
			}
			$node['contactPoint'] = $points;
		}
		if ( $o['same_as'] ) {
			$node['sameAs'] = $o['same_as'];
		}
		return $node;
	}

	public static function website( string $home ): array {
		$s    = SSG_Settings::get();
		$node = array(
			'@type'      => 'WebSite',
			'@id'        => $home . '#website',
			'url'        => $home,
			'name'       => SSG_Settings::org_name(),
			'publisher'  => array( '@id' => $home . '#organization' ),
			'inLanguage' => self::lang(),
		);
		$desc = SSG_Vars::plain( (string) get_bloginfo( 'description' ) );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}
		$target = $home . '?s={search_term_string}';
		if ( $s['search_product'] && post_type_exists( 'product' ) ) {
			$target .= '&post_type=product';
		}
		$node['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $target,
			),
			'query-input' => 'required name=search_term_string',
		);
		return $node;
	}

	private static function webpage( SSG_Context $c, string $url, string $image_id, string $crumb_id ): array {
		$type = 'WebPage';
		if ( in_array( $c->kind, array( 'term', 'shop', 'pt_archive', 'home', 'blog', 'author', 'date' ), true ) ) {
			$type = 'CollectionPage';
		} elseif ( 'search' === $c->kind ) {
			$type = 'SearchResultsPage';
		} elseif ( 'singular' === $c->kind && 'product' === $c->post_type ) {
			$type = 'ItemPage';
		}
		$home = home_url( '/' );
		$node = array(
			'@type'      => $type,
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => SSG_Meta::title( $c ),
			'isPartOf'   => array( '@id' => $home . '#website' ),
			'inLanguage' => self::lang(),
		);
		$desc = SSG_Meta::description( $c );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}
		if ( $c->has_post() ) {
			$post = get_post( $c->post_id );
			if ( $post instanceof WP_Post ) {
				$node['datePublished'] = get_post_time( 'c', true, $post );
				$node['dateModified']  = get_post_modified_time( 'c', true, $post );
			}
		}
		if ( in_array( $c->kind, array( 'front', 'home' ), true ) ) {
			$node['about'] = array( '@id' => $home . '#organization' );
		}
		if ( '' !== $image_id ) {
			$node['primaryImageOfPage'] = array( '@id' => $image_id );
		}
		if ( '' !== $crumb_id ) {
			$node['breadcrumb'] = array( '@id' => $crumb_id );
		}
		return $node;
	}

	/** ImageObject of the share image of the page (featured image first). */
	private static function primary_image( SSG_Context $c, string $url ): array {
		$id = 0;
		if ( $c->has_post() ) {
			$own = (int) get_post_meta( $c->post_id, SSG_Meta::IMAGE, true );
			$id  = $own ? $own : (int) get_post_thumbnail_id( $c->post_id );
		} elseif ( 'term' === $c->kind ) {
			$own = (int) get_term_meta( $c->term_id, SSG_Meta::IMAGE, true );
			$id  = $own ? $own : (int) get_term_meta( $c->term_id, 'thumbnail_id', true );
		}
		return $id ? self::image_object( $id, $url . '#primaryimage' ) : array();
	}

	public static function image_object( int $id, string $at_id ): array {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return array();
		}
		$node = array(
			'@type'      => 'ImageObject',
			'@id'        => $at_id,
			'url'        => (string) $src[0],
			'contentUrl' => (string) $src[0],
		);
		if ( ! empty( $src[1] ) && ! empty( $src[2] ) ) {
			$node['width']  = (int) $src[1];
			$node['height'] = (int) $src[2];
		}
		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		if ( '' !== $alt ) {
			$node['caption'] = $alt;
		}
		return $node;
	}

	/** BreadcrumbList; [] on the front page and on a 404. */
	private static function breadcrumbs( SSG_Context $c, string $url ): array {
		if ( in_array( $c->kind, array( 'front', 'home', '404', 'other' ), true ) ) {
			return array();
		}
		$items   = array();
		// Visitors read these names (Google shows them): the site's language
		// decides, like %page% (SSG_Vars), with a filter for anything else.
		$home_name = (string) apply_filters( 'ssg_home_crumb', 0 === strpos( (string) get_locale(), 'el' ) ? 'Αρχική' : 'Home' );
		$items[]   = array( $home_name, home_url( '/' ) );
		$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;

		if ( 'singular' === $c->kind && 'product' === $c->post_type ) {
			if ( $shop_id > 0 && 'publish' === get_post_status( $shop_id ) ) {
				$items[] = array( SSG_Vars::plain( (string) get_the_title( $shop_id ) ), (string) get_permalink( $shop_id ) );
			}
			$t = SSG_Meta::primary_term( $c->post_id, 'product_cat' );
			if ( $t ) {
				$items = array_merge( $items, self::term_trail( $t ) );
			}
		} elseif ( 'singular' === $c->kind && 'post' === $c->post_type ) {
			$t = SSG_Meta::primary_term( $c->post_id, 'category' );
			if ( $t ) {
				$items = array_merge( $items, self::term_trail( $t ) );
			}
		} elseif ( 'singular' === $c->kind && is_post_type_hierarchical( $c->post_type ) ) {
			foreach ( array_reverse( get_post_ancestors( $c->post_id ) ) as $pid ) {
				$items[] = array( SSG_Vars::plain( (string) get_the_title( (int) $pid ) ), (string) get_permalink( (int) $pid ) );
			}
		} elseif ( 'term' === $c->kind ) {
			$t = get_term( $c->term_id, $c->taxonomy );
			if ( $t instanceof WP_Term ) {
				if ( in_array( $c->taxonomy, array( 'product_cat', 'product_brand', 'product_tag' ), true ) && $shop_id > 0 && 'publish' === get_post_status( $shop_id ) ) {
					$items[] = array( SSG_Vars::plain( (string) get_the_title( $shop_id ) ), (string) get_permalink( $shop_id ) );
				}
				$trail = self::term_trail( $t );
				array_pop( $trail ); // The term itself is the last item, added below.
				$items = array_merge( $items, $trail );
			}
		}
		$items[] = array( SSG_Meta::title( $c ), $url );
		if ( $c->has_post() || 'term' === $c->kind ) {
			$last = 'term' === $c->kind ? get_term( $c->term_id, $c->taxonomy ) : null;
			$name = $last instanceof WP_Term ? SSG_Vars::plain( $last->name ) : SSG_Vars::plain( (string) get_the_title( $c->post_id ) );
			if ( '' !== $name ) {
				$items[ count( $items ) - 1 ][0] = $name;
			}
		}

		$list = array();
		$pos  = 1;
		foreach ( $items as $it ) {
			if ( '' === $it[0] || '' === $it[1] ) {
				continue;
			}
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => $it[0],
				'item'     => $it[1],
			);
		}
		if ( count( $list ) < 2 ) {
			return array();
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $list,
		);
	}

	/** [ [ name, url ], … ] from the top parent down to the term. */
	private static function term_trail( WP_Term $t ): array {
		$out = array();
		foreach ( array_reverse( get_ancestors( (int) $t->term_id, $t->taxonomy, 'taxonomy' ) ) as $aid ) {
			$a = get_term( (int) $aid, $t->taxonomy );
			if ( $a instanceof WP_Term ) {
				$link  = get_term_link( $a );
				$out[] = array( SSG_Vars::plain( $a->name ), is_string( $link ) ? $link : '' );
			}
		}
		$link  = get_term_link( $t );
		$out[] = array( SSG_Vars::plain( $t->name ), is_string( $link ) ? $link : '' );
		return $out;
	}

	private static function article( SSG_Context $c, string $url, string $image_id ): array {
		$post = get_post( $c->post_id );
		if ( ! $post instanceof WP_Post || post_password_required( $post ) ) {
			return array();
		}
		$home = home_url( '/' );
		$node = array(
			'@type'            => 'post' === $post->post_type ? 'BlogPosting' : 'Article',
			'@id'              => $url . '#article',
			'headline'         => SSG_Vars::cut( SSG_Vars::plain( (string) get_the_title( $post ) ), 110 ),
			'datePublished'    => get_post_time( 'c', true, $post ),
			'dateModified'     => get_post_modified_time( 'c', true, $post ),
			'publisher'        => array( '@id' => $home . '#organization' ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'isPartOf'         => array( '@id' => $url . '#webpage' ),
			'inLanguage'       => self::lang(),
		);
		$desc = SSG_Meta::description( $c );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}
		$author = get_userdata( (int) $post->post_author );
		if ( $author ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => SSG_Vars::plain( (string) $author->display_name ),
			);
		}
		if ( '' !== $image_id ) {
			$node['image'] = array( '@id' => $image_id );
		}
		return $node;
	}

	/** @var object|null|false A private WC_Structured_Data (false = not available). */
	private static $wc_sd = null;

	/**
	 * A private WC_Structured_Data, built without its constructor: the
	 * constructor hooks the output of WooCommerce into the page, and
	 * WC()->structured_data exists only on front-end requests (Compare
	 * and the abilities run in admin and REST). Its own data stays apart,
	 * so nothing is added to what WooCommerce prints by itself.
	 */
	private static function wc_structured_data() {
		if ( null !== self::$wc_sd ) {
			return self::$wc_sd ? self::$wc_sd : null;
		}
		self::$wc_sd = false;
		try {
			if ( ! class_exists( 'WC_Structured_Data' ) && defined( 'WC_ABSPATH' ) && is_readable( WC_ABSPATH . 'includes/class-wc-structured-data.php' ) ) {
				include_once WC_ABSPATH . 'includes/class-wc-structured-data.php';
			}
			if ( class_exists( 'WC_Structured_Data' ) ) {
				self::$wc_sd = ( new ReflectionClass( 'WC_Structured_Data' ) )->newInstanceWithoutConstructor();
			}
		} catch ( \Throwable $e ) {
			self::$wc_sd = false;
		}
		return self::$wc_sd ? self::$wc_sd : null;
	}

	/** The Product node, from WooCommerce's own structured data. */
	private static function product( SSG_Context $c, string $url ): array {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$product = wc_get_product( $c->post_id );
		if ( ! $product instanceof WC_Product ) {
			return array();
		}
		$node = array();
		$sd   = self::wc_structured_data();
		if ( is_object( $sd ) && method_exists( $sd, 'generate_product_data' ) && method_exists( $sd, 'get_data' ) ) {
			$before = count( (array) $sd->get_data() );
			// WooCommerce reads the global product for some fields.
			// WooCommerce (and its Brands filter) read the global product and post.
			$keep               = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
			$keep_post          = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
			$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
			$GLOBALS['post']    = get_post( $c->post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
			try {
				$sd->generate_product_data( $product );
			} finally {
				$GLOBALS['product'] = $keep; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$GLOBALS['post']    = $keep_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
			$all = array_values( (array) $sd->get_data() );
			for ( $i = count( $all ) - 1; $i >= $before; $i-- ) {
				if ( is_array( $all[ $i ] ) && isset( $all[ $i ]['@type'] ) && in_array( 'Product', (array) $all[ $i ]['@type'], true ) ) {
					$node = $all[ $i ];
					break;
				}
			}
		}
		if ( ! $node ) {
			$node = array(
				'@type' => 'Product',
				'name'  => SSG_Vars::plain( $product->get_name() ),
				'url'   => $url,
			);
		}
		unset( $node['@context'] );
		$node['@id']              = $url . '#product';
		$node['mainEntityOfPage'] = array( '@id' => $url . '#webpage' );

		$brand = SSG_Meta::primary_term( $c->post_id, 'product_brand' );
		if ( $brand && empty( $node['brand'] ) ) {
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => SSG_Vars::plain( $brand->name ),
			);
		}
		$cat = SSG_Meta::primary_term( $c->post_id, 'product_cat' );
		if ( $cat && empty( $node['category'] ) ) {
			$node['category'] = SSG_Vars::plain( $cat->name );
		}
		$gtin = method_exists( $product, 'get_global_unique_id' ) ? preg_replace( '/[^0-9]/', '', (string) $product->get_global_unique_id() ) : '';
		if ( is_string( $gtin ) && '' !== $gtin ) {
			$types = (array) $node['@type'];
			if ( in_array( 'Book', $types, true ) && 13 === strlen( $gtin ) && preg_match( '/^97[89]/', $gtin ) ) {
				$node['isbn'] = $gtin;
			}
			if ( empty( $node['gtin'] ) && empty( $node['gtin13'] ) && in_array( strlen( $gtin ), array( 8, 12, 13, 14 ), true ) ) {
				$node[ 13 === strlen( $gtin ) ? 'gtin13' : 'gtin' ] = $gtin;
			}
		}
		$image = self::primary_image( $c, $url );
		if ( $image ) {
			$node['image'] = array( '@id' => $image['@id'] );
		}
		return $node;
	}

	/** Language of the site, e.g. el-GR. */
	public static function lang(): string {
		return str_replace( '_', '-', (string) get_locale() );
	}
}
