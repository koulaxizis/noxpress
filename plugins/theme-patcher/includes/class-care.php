<?php
/**
 * TP_Care — "Care & Safety" tab on single product pages (product safety
 * information, GPSR): a care text and a safety text chosen by the product
 * type, the manufacturer, the EU responsible person and a link to the
 * store's terms.
 *
 * Storage (Bible §12): one option `tp_care` (JSON, NOT autoloaded: it is
 * read on product pages only), shape:
 *  {
 *    "enabled":  bool,                      module switch (default false, §14.1)
 *    "attrs":    { "type": taxonomy, "material": taxonomy, "method": taxonomy },
 *    "profiles": [ { "key", "label", "care": {el,en}, "safety": {el,en},
 *                    "types": [ term slugs ], "sku": [ prefixes ],
 *                    "maker": manufacturer key | "", "enabled": bool } ],
 *    "makers":   [ { "key", "name", "address", "email", "url", "eu" } ],
 *    "class_makers": { shipping class slug: manufacturer key },
 *    "eu":       { "name", "email" },       global EU responsible person
 *    "texts":    { "<id>": {el,en} },       headings and the terms line
 *    "terms_url": url | "",  "anchor": str,  "color": "#hex" | "",
 *    "delete_meta": bool                    uninstall also deletes the product meta
 *  }
 *
 * Product meta (set in the product's side box or over REST):
 *  _tp_care_profile       '' = automatic, 'none' = no tab, else a profile key
 *  _tp_care_manufacturer  '' = automatic (shipping class map, else the
 *                         profile's manufacturer), 'none', else a key
 *  _tp_care_note          extra safety note (HTML, wp_kses_post)
 *
 * Resolution ("automatic"): the product's own profile wins; otherwise the
 * first enabled profile whose types match one of the product's terms in
 * the type attribute; otherwise the first enabled profile whose SKU prefix
 * matches the (parent) SKU; otherwise no tab. External, grouped and
 * virtual products (downloadable-only ones included) never match
 * automatically.
 *
 * Visitor texts are store settings (Greek and English, Bible §14.17); the
 * language follows the Theme Patcher visitor language (Prices tab). Profile
 * texts and the note are HTML (wp_kses_post): documented exception to the
 * plain-text rule of §14.17.
 *
 * Front-end rules (Bible §14): follows the module switch, the master switch
 * and the test mode of tp_settings; TP_DISABLE stops it (not initialised);
 * front-end page requests only; no database writes; the same output for
 * every visitor (page caches stay valid); every callback in try/catch.
 *
 * Filters: tp_care_tab_title, tp_care_resolved_profile, tp_care_manufacturer,
 * tp_care_tab_html.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Care {

	const OPT = 'tp_care';

	const META_PROFILE = '_tp_care_profile';
	const META_MAKER   = '_tp_care_manufacturer';
	const META_NOTE    = '_tp_care_note';

	const NONE = 'none';

	const TAB_KEY      = 'tp_care';
	const TAB_PRIORITY = 25;

	const MAX_PROFILES = 30;
	const MAX_MAKERS   = 30;
	const MAX_TYPES    = 50;
	const MAX_SKU      = 20;
	const MAX_CLASSES  = 20;
	const MAX_HTML_LEN = 5000;
	const MAX_NOTE_LEN = 2000;
	const MAX_LINE_LEN = 200;
	const MAX_TEXT_LEN = 120;

	/** Visitor text ids => default { el, en }. */
	const TEXT_IDS = array( 'tab', 'care', 'safety', 'maker', 'eu', 'more', 'terms' );

	/** Per-request cache of the decoded option. */
	private static $cache = null;

	/** Set after any Throwable: no further work this request. */
	private static $failed = false;

	/** Per-request cache: product id => tab HTML ('' = no tab). */
	private static $html = array();

	public static function init(): void {
		// Product meta exists (and is editable over REST) even with TP_DISABLE.
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		if ( Theme_Patcher::killed() ) {
			return;
		}
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'tabs' ), 50 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* =====================================================================
	 * Defaults
	 * =================================================================== */

	public static function defaults(): array {
		return array(
			'enabled'      => false,
			'attrs'        => self::default_attrs(),
			'profiles'     => self::default_profiles(),
			'makers'       => self::default_makers(),
			'class_makers' => array(),
			'eu'           => array(
				'name'  => '',
				'email' => '',
			),
			'texts'        => self::default_texts(),
			'terms_url'    => '',
			'anchor'       => 'gpsr',
			'color'        => '',
			'delete_meta'  => false,
		);
	}

	/** Attribute taxonomies: the usual Greek ones when the store has them. */
	public static function default_attrs(): array {
		$out = array();
		foreach ( array(
			'type'     => 'pa_eidos',
			'material' => 'pa_yliko',
			'method'   => 'pa_methodos',
		) as $k => $tax ) {
			$out[ $k ] = taxonomy_exists( $tax ) ? $tax : '';
		}
		return $out;
	}

	public static function default_texts(): array {
		return array(
			'tab'    => array(
				'el' => 'Φροντίδα και ασφάλεια',
				'en' => 'Care & safety',
			),
			'care'   => array(
				'el' => 'Φροντίδα',
				'en' => 'Care',
			),
			'safety' => array(
				'el' => 'Ασφάλεια προϊόντος',
				'en' => 'Product safety',
			),
			'maker'  => array(
				'el' => 'Κατασκευαστής',
				'en' => 'Manufacturer',
			),
			'eu'     => array(
				'el' => 'Υπεύθυνος στην ΕΕ',
				'en' => 'Responsible person in the EU',
			),
			'more'   => array(
				'el' => 'Περισσότερα στους {terms}.',
				'en' => 'More in our {terms}.',
			),
			'terms'  => array(
				'el' => 'Όρους και Προϋποθέσεις',
				'en' => 'Terms and Conditions',
			),
		);
	}

	public static function default_makers(): array {
		return array(
			array(
				'key'     => 'printful',
				'name'    => 'Printful',
				'address' => '',
				'email'   => '',
				'url'     => 'https://www.printful.com/',
				'eu'      => '',
			),
		);
	}

	/** The five shipped profiles (Greek texts; English left empty → Greek). */
	public static function default_profiles(): array {
		$p = static function ( string $key, string $label, string $care, string $safety, array $types, array $sku, string $maker = '' ): array {
			return array(
				'key'     => $key,
				'label'   => $label,
				'care'    => array(
					'el' => $care,
					'en' => '',
				),
				'safety'  => array(
					'el' => $safety,
					'en' => '',
				),
				'types'   => $types,
				'sku'     => $sku,
				'maker'   => $maker,
				'enabled' => true,
			);
		};
		return array(
			$p(
				'clothing',
				'Ρούχα',
				'Πλύσιμο στο πλυντήριο με κρύο νερό (έως 30 °C), γυρισμένο ανάποδα, σε ήπιο πρόγραμμα, με παρόμοια χρώματα. Χωρίς χλωρίνη και μαλακτικό. Στέγνωμα στον αέρα ή σε χαμηλή θερμοκρασία. Μη σιδερώνετε πάνω στο τύπωμα· αν χρειαστεί, σιδερώστε ανάποδα σε χαμηλή θερμοκρασία. Όχι στεγνό καθάρισμα.',
				'Για ενήλικες. Υλικό: {material}. Μέθοδος τύπωσης: {method}. Τα μελάνια είναι κατάλληλα για ενδύματα και πληρούν τις ισχύουσες απαιτήσεις της ΕΕ για τις περιορισμένες χημικές ουσίες. Κρατήστε μακριά από φλόγες και πηγές θερμότητας.',
				array( 't-shirt', 'hoodie' ),
				array( 'CLTH' )
			),
			$p(
				'hats',
				'Καπέλα',
				'Καθαρισμός τοπικά με υγρό πανί και ήπιο σαπούνι. Μην το βάζετε στο πλυντήριο ή στο στεγνωτήριο. Αφήστε το να στεγνώσει στον αέρα, σε φυσικό σχήμα. Μη σιδερώνετε το κέντημα ή το τύπωμα.',
				'Για ενήλικες. Υλικό: {material}. Κρατήστε μακριά από φλόγες και πηγές θερμότητας.',
				array(),
				array( 'HATS' )
			),
			$p(
				'mugs',
				'Κούπες',
				'Πλύσιμο στο χέρι για μεγαλύτερη διάρκεια του τυπώματος. Κατάλληλη για φούρνο μικροκυμάτων, εκτός αν αναφέρεται διαφορετικά στην περιγραφή. Αποφύγετε τα λειαντικά σφουγγάρια.',
				'Κατάλληλη για επαφή με τρόφιμα και ποτά. Προσοχή: το περιεχόμενο και η κούπα μπορεί να είναι πολύ ζεστά. Μη χρησιμοποιείτε την κούπα αν έχει ραγίσει ή σπάσει.',
				array(),
				array( 'MUGS' )
			),
			$p(
				'small-accessories',
				'Αξεσουάρ',
				'Καθαρισμός με στεγνό ή ελαφρώς υγρό πανί. Αποφύγετε το νερό σε μεγάλη ποσότητα, τα διαλυτικά και την παρατεταμένη έκθεση στον ήλιο.',
				'Δεν είναι παιχνίδι. Περιέχει μικρά μέρη ή αιχμηρή καρφίτσα· κρατήστε μακριά από παιδιά κάτω των 3 ετών, λόγω κινδύνου πνιγμού. Οι μαγνήτες μπορεί να επηρεάσουν βηματοδότες, κάρτες και ηλεκτρονικές συσκευές.',
				array( 'konkarda', 'magnitaki', 'mprelok', 'aytokollito' ),
				array( 'ACCS' )
			),
			$p(
				'home',
				'Είδη σπιτιού',
				'Καθαρισμός σύμφωνα με τις οδηγίες της περιγραφής του προϊόντος.',
				'Κρατήστε μακριά από φλόγες και πηγές θερμότητας.',
				array(),
				array( 'HOME' )
			),
		);
	}

	/* =====================================================================
	 * Read / write
	 * =================================================================== */

	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw         = get_option( self::OPT, '' );
		$decoded     = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		self::$cache = is_array( $decoded ) ? self::sanitize( $decoded ) : self::defaults();
		return self::$cache;
	}

	public static function save( array $data ): bool {
		$clean       = self::sanitize( $data );
		self::$cache = $clean;
		self::$html  = array();
		$json        = wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === get_option( self::OPT, false ) ) {
			return add_option( self::OPT, $json, '', false );
		}
		return false !== update_option( self::OPT, $json, false );
	}

	public static function flush(): void {
		self::$cache = null;
		self::$html  = array();
	}

	/** Profile by key (null when unknown). */
	public static function profile( string $key ): ?array {
		foreach ( self::get()['profiles'] as $p ) {
			if ( $p['key'] === $key ) {
				return $p;
			}
		}
		return null;
	}

	/** Manufacturer by key (null when unknown). */
	public static function maker( string $key ): ?array {
		foreach ( self::get()['makers'] as $m ) {
			if ( $m['key'] === $key ) {
				return $m;
			}
		}
		return null;
	}

	/* =====================================================================
	 * Validation (one implementation — admin POST, import, read)
	 * =================================================================== */

	public static function sanitize( array $raw ): array {
		$d   = self::defaults();
		$out = $d;

		$out['enabled']     = ! empty( $raw['enabled'] );
		$out['delete_meta'] = ! empty( $raw['delete_meta'] );

		if ( isset( $raw['attrs'] ) && is_array( $raw['attrs'] ) ) {
			foreach ( array( 'type', 'material', 'method' ) as $k ) {
				$out['attrs'][ $k ] = isset( $raw['attrs'][ $k ] ) ? self::valid_taxonomy( (string) $raw['attrs'][ $k ] ) : '';
			}
		}

		if ( isset( $raw['profiles'] ) && is_array( $raw['profiles'] ) ) {
			$out['profiles'] = array();
			$seen            = array();
			foreach ( $raw['profiles'] as $p ) {
				$clean = is_array( $p ) ? self::sanitize_profile( $p ) : null;
				if ( null === $clean || isset( $seen[ $clean['key'] ] ) ) {
					continue;
				}
				$seen[ $clean['key'] ] = true;
				$out['profiles'][]     = $clean;
				if ( count( $out['profiles'] ) >= self::MAX_PROFILES ) {
					break;
				}
			}
		}

		if ( isset( $raw['makers'] ) && is_array( $raw['makers'] ) ) {
			$out['makers'] = array();
			$seen          = array();
			foreach ( $raw['makers'] as $m ) {
				$clean = is_array( $m ) ? self::sanitize_maker( $m ) : null;
				if ( null === $clean || isset( $seen[ $clean['key'] ] ) ) {
					continue;
				}
				$seen[ $clean['key'] ] = true;
				$out['makers'][]       = $clean;
				if ( count( $out['makers'] ) >= self::MAX_MAKERS ) {
					break;
				}
			}
		}

		// Shipping class => manufacturer, only for manufacturers that exist.
		$out['class_makers'] = array();
		if ( isset( $raw['class_makers'] ) && is_array( $raw['class_makers'] ) ) {
			$keys = array_column( $out['makers'], 'key' );
			foreach ( $raw['class_makers'] as $class => $mk ) {
				$class = sanitize_title( (string) $class );
				$mk    = self::valid_key( is_scalar( $mk ) ? (string) $mk : '' );
				if ( '' === $class || ! in_array( $mk, $keys, true ) ) {
					continue;
				}
				$out['class_makers'][ $class ] = $mk;
				if ( count( $out['class_makers'] ) >= self::MAX_CLASSES ) {
					break;
				}
			}
		}

		if ( isset( $raw['eu'] ) && is_array( $raw['eu'] ) ) {
			$out['eu'] = array(
				'name'  => self::line( isset( $raw['eu']['name'] ) ? $raw['eu']['name'] : '' ),
				'email' => self::email( isset( $raw['eu']['email'] ) ? $raw['eu']['email'] : '' ),
			);
		}

		if ( isset( $raw['texts'] ) && is_array( $raw['texts'] ) ) {
			foreach ( self::TEXT_IDS as $id ) {
				if ( isset( $raw['texts'][ $id ] ) && is_array( $raw['texts'][ $id ] ) ) {
					$el = self::text( isset( $raw['texts'][ $id ]['el'] ) ? $raw['texts'][ $id ]['el'] : '' );
					$en = self::text( isset( $raw['texts'][ $id ]['en'] ) ? $raw['texts'][ $id ]['en'] : '' );
					// An empty Greek text falls back to the default (headings never vanish by accident).
					$out['texts'][ $id ] = array(
						'el' => '' === $el ? $d['texts'][ $id ]['el'] : $el,
						'en' => $en,
					);
				}
			}
		}

		$out['terms_url'] = isset( $raw['terms_url'] ) ? esc_url_raw( trim( (string) $raw['terms_url'] ), array( 'http', 'https' ) ) : '';
		$out['anchor']    = isset( $raw['anchor'] ) ? self::valid_anchor( (string) $raw['anchor'] ) : $d['anchor'];
		$color            = isset( $raw['color'] ) ? sanitize_hex_color( trim( (string) $raw['color'] ) ) : '';
		$out['color']     = is_string( $color ) ? $color : '';

		return $out;
	}

	/** One profile, or null when it has no valid key. */
	public static function sanitize_profile( array $p ): ?array {
		$key = self::valid_key( isset( $p['key'] ) ? (string) $p['key'] : '' );
		if ( '' === $key || self::NONE === $key ) {
			return null;
		}
		$label = self::line( isset( $p['label'] ) ? $p['label'] : '' );
		return array(
			'key'     => $key,
			'label'   => '' === $label ? $key : $label,
			'care'    => self::html_pair( isset( $p['care'] ) ? $p['care'] : array() ),
			'safety'  => self::html_pair( isset( $p['safety'] ) ? $p['safety'] : array() ),
			'types'   => self::slug_list( isset( $p['types'] ) ? $p['types'] : array(), self::MAX_TYPES ),
			'sku'     => self::sku_list( isset( $p['sku'] ) ? $p['sku'] : array() ),
			'maker'   => isset( $p['maker'] ) ? self::valid_key( (string) $p['maker'] ) : '',
			'enabled' => ! empty( $p['enabled'] ),
		);
	}

	/** One manufacturer, or null when it has no valid key. */
	public static function sanitize_maker( array $m ): ?array {
		$key = self::valid_key( isset( $m['key'] ) ? (string) $m['key'] : '' );
		if ( '' === $key || self::NONE === $key ) {
			return null;
		}
		$name = self::line( isset( $m['name'] ) ? $m['name'] : '' );
		return array(
			'key'     => $key,
			'name'    => '' === $name ? $key : $name,
			'address' => self::multiline( isset( $m['address'] ) ? $m['address'] : '' ),
			'email'   => self::email( isset( $m['email'] ) ? $m['email'] : '' ),
			'url'     => isset( $m['url'] ) ? esc_url_raw( trim( (string) $m['url'] ), array( 'http', 'https' ) ) : '',
			'eu'      => self::multiline( isset( $m['eu'] ) ? $m['eu'] : '' ),
		);
	}

	/** Key: Latin letters, digits, - and _ (spaces become -), at most 60 characters. */
	public static function valid_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		$key = (string) preg_replace( '/[\s]+/', '-', $key );
		$key = (string) preg_replace( '/[^a-z0-9_-]/', '', $key );
		return substr( trim( $key, '-' ), 0, 60 );
	}

	public static function valid_taxonomy( string $tax ): string {
		$tax = sanitize_key( $tax );
		return ( '' !== $tax && 0 === strpos( $tax, 'pa_' ) ) ? $tax : '';
	}

	public static function valid_anchor( string $anchor ): string {
		$anchor = ltrim( trim( $anchor ), '#' );
		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', substr( $anchor, 0, 60 ) );
	}

	/** Profile key from a meta value: '' (automatic), 'none' or a key. */
	public static function valid_choice( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || self::NONE === $value ) {
			return $value;
		}
		return self::valid_key( $value );
	}

	private static function html_pair( $pair ): array {
		$pair = is_array( $pair ) ? $pair : array();
		return array(
			'el' => self::kses( isset( $pair['el'] ) ? $pair['el'] : '', self::MAX_HTML_LEN ),
			'en' => self::kses( isset( $pair['en'] ) ? $pair['en'] : '', self::MAX_HTML_LEN ),
		);
	}

	public static function kses( $value, int $max ): string {
		$value = trim( wp_kses_post( (string) $value ) );
		return mb_strlen( $value ) > $max ? '' : $value; // Too long: refused, never cut inside a tag.
	}

	private static function text( $value ): string {
		return mb_substr( sanitize_text_field( (string) $value ), 0, self::MAX_TEXT_LEN );
	}

	private static function line( $value ): string {
		return mb_substr( sanitize_text_field( (string) $value ), 0, self::MAX_LINE_LEN );
	}

	private static function multiline( $value ): string {
		return mb_substr( trim( str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( (string) $value ) ) ), 0, self::MAX_LINE_LEN * 3 );
	}

	private static function email( $value ): string {
		$value = sanitize_email( (string) $value );
		return is_email( $value ) ? $value : '';
	}

	/** List of term slugs (array, or a comma separated string). */
	public static function slug_list( $raw, int $max ): array {
		$raw = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$out = array();
		foreach ( $raw as $s ) {
			$s = sanitize_title( (string) $s );
			if ( '' !== $s && ! in_array( $s, $out, true ) ) {
				$out[] = $s;
			}
		}
		return array_slice( $out, 0, $max );
	}

	/** SKU prefixes, upper case, letters / digits / - / _ only. */
	public static function sku_list( $raw ): array {
		$raw = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$out = array();
		foreach ( $raw as $s ) {
			$s = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $s ) );
			$s = substr( $s, 0, 20 );
			if ( '' !== $s && ! in_array( $s, $out, true ) ) {
				$out[] = $s;
			}
		}
		return array_slice( $out, 0, self::MAX_SKU );
	}

	/* =====================================================================
	 * Product meta
	 * =================================================================== */

	public static function register_meta(): void {
		$auth = static function ( $allowed, $meta_key, $object_id ) {
			return current_user_can( 'edit_post', (int) $object_id );
		};
		$args = array(
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'default'       => '',
			'auth_callback' => $auth,
		);
		register_post_meta( 'product', self::META_PROFILE, $args + array( 'sanitize_callback' => array( __CLASS__, 'sanitize_meta_choice' ) ) );
		register_post_meta( 'product', self::META_MAKER, $args + array( 'sanitize_callback' => array( __CLASS__, 'sanitize_meta_choice' ) ) );
		register_post_meta( 'product', self::META_NOTE, $args + array( 'sanitize_callback' => array( __CLASS__, 'sanitize_meta_note' ) ) );
	}

	public static function sanitize_meta_choice( $value ): string {
		return self::valid_choice( (string) $value );
	}

	public static function sanitize_meta_note( $value ): string {
		return self::kses( $value, self::MAX_NOTE_LEN );
	}

	/* =====================================================================
	 * Resolution
	 * =================================================================== */

	/** The product the tab is about (the parent of a variation). */
	private static function base( WC_Product $product ): WC_Product {
		if ( $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof WC_Product ) {
				return $parent;
			}
		}
		return $product;
	}

	/** True when the product can get a profile automatically (physical goods only). */
	public static function auto_eligible( WC_Product $product ): bool {
		if ( $product->is_type( 'external' ) || $product->is_type( 'grouped' ) ) {
			return false;
		}
		return ! $product->is_virtual();
	}

	/**
	 * Resolve the tab for a product.
	 *
	 * @return array { profile: array|null, maker: array|null, reason: string }
	 *               reason: product | type | sku | none | product-none |
	 *                       excluded | nomatch | disabled
	 */
	public static function resolve( WC_Product $product ): array {
		$product = self::base( $product );
		$c       = self::get();
		$id      = (int) $product->get_id();
		$choice  = self::valid_choice( (string) get_post_meta( $id, self::META_PROFILE, true ) );
		$profile = null;
		$reason  = 'nomatch';

		if ( self::NONE === $choice ) {
			$reason = 'product-none';
		} elseif ( '' !== $choice ) {
			$p = self::profile( $choice );
			if ( null !== $p && $p['enabled'] ) {
				$profile = $p;
				$reason  = 'product';
			} else {
				$reason = 'disabled';
			}
		} elseif ( ! self::auto_eligible( $product ) ) {
			$reason = 'excluded';
		} else {
			$terms = array();
			if ( '' !== $c['attrs']['type'] ) {
				$found = wc_get_product_terms( $id, $c['attrs']['type'], array( 'fields' => 'slugs' ) );
				$terms = is_array( $found ) ? array_map( 'strval', $found ) : array();
			}
			if ( $terms ) {
				foreach ( $c['profiles'] as $p ) {
					if ( $p['enabled'] && array_intersect( $p['types'], $terms ) ) {
						$profile = $p;
						$reason  = 'type';
						break;
					}
				}
			}
			if ( null === $profile ) {
				$sku = strtoupper( (string) $product->get_sku( 'edit' ) );
				if ( '' !== $sku ) {
					foreach ( $c['profiles'] as $p ) {
						if ( ! $p['enabled'] ) {
							continue;
						}
						foreach ( $p['sku'] as $prefix ) {
							if ( 0 === strpos( $sku, $prefix ) ) {
								$profile = $p;
								$reason  = 'sku';
								break 2;
							}
						}
					}
				}
			}
		}

		/**
		 * Filters the profile key resolved for a product ('' = no tab).
		 *
		 * @param string     $key     Profile key or ''.
		 * @param WC_Product $product Product (the parent for variations).
		 * @param string     $reason  Why: product, type, sku, product-none, excluded, nomatch, disabled.
		 */
		$key = (string) apply_filters( 'tp_care_resolved_profile', null === $profile ? '' : $profile['key'], $product, $reason );
		if ( ( null === $profile ? '' : $profile['key'] ) !== $key ) {
			$profile = '' === $key ? null : self::profile( $key );
			if ( null !== $profile && ! $profile['enabled'] ) {
				$profile = null;
			}
			$reason = null === $profile ? 'none' : 'filter';
		}

		$maker = null;
		if ( null !== $profile ) {
			$mk = self::valid_choice( (string) get_post_meta( $id, self::META_MAKER, true ) );
			if ( '' === $mk ) {
				$class = (string) $product->get_shipping_class();
				$mk    = '' !== $class && isset( $c['class_makers'][ $class ] ) ? $c['class_makers'][ $class ] : $profile['maker'];
			}
			/**
			 * Filters the manufacturer key for a product ('' or 'none' = no manufacturer).
			 *
			 * @param string     $key     Manufacturer key.
			 * @param WC_Product $product Product.
			 * @param array      $profile Resolved profile.
			 */
			$mk    = (string) apply_filters( 'tp_care_manufacturer', $mk, $product, $profile );
			$maker = ( '' === $mk || self::NONE === $mk ) ? null : self::maker( $mk );
		}

		return array(
			'profile' => $profile,
			'maker'   => $maker,
			'reason'  => $reason,
		);
	}

	/* =====================================================================
	 * Rendering
	 * =================================================================== */

	/** Visitor language: the Theme Patcher visitor language (Prices tab). */
	public static function lang(): string {
		return TP_Prices::lang();
	}

	private static function pick( array $pair ): string {
		if ( 'en' === self::lang() && '' !== $pair['en'] ) {
			return $pair['en'];
		}
		return $pair['el'];
	}

	public static function label( string $id ): string {
		$texts = self::get()['texts'];
		return isset( $texts[ $id ] ) ? self::pick( $texts[ $id ] ) : '';
	}

	/** Tab title (filterable). */
	public static function title( WC_Product $product ): string {
		/**
		 * Filters the tab title.
		 *
		 * @param string     $title   Title (plain text).
		 * @param WC_Product $product Product.
		 * @param string     $lang    'el' or 'en'.
		 */
		return (string) apply_filters( 'tp_care_tab_title', self::label( 'tab' ), $product, self::lang() );
	}

	/**
	 * Fill {product_name}, {material} and {method} (values escaped). A
	 * sentence with a placeholder that has no value is removed whole;
	 * unknown placeholders are removed.
	 */
	public static function fill( string $html, WC_Product $product ): string {
		if ( false === strpos( $html, '{' ) ) {
			return $html;
		}
		$c    = self::get();
		$vals = array(
			'product_name' => $product->get_name(),
			'material'     => '' !== $c['attrs']['material'] ? (string) $product->get_attribute( $c['attrs']['material'] ) : '',
			'method'       => '' !== $c['attrs']['method'] ? (string) $product->get_attribute( $c['attrs']['method'] ) : '',
		);
		foreach ( $vals as $k => $v ) {
			$v = trim( html_entity_decode( wp_strip_all_tags( $v ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $v ) {
				// The sentence that holds it (text between tags, up to . ! ; · or the tag).
				$html = (string) preg_replace( '/[^.!;·<>]*\{' . $k . '\}[^.!;·<>]*[.!;·]?/u', '', $html );
			} else {
				$html = str_replace( '{' . $k . '}', esc_html( $v ), $html );
			}
		}
		$html = (string) preg_replace( '/\{[a-z_]+\}/', '', $html );
		return trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $html ) );
	}

	/** Terms page link: the override URL, else WooCommerce's terms page; '' when none. */
	public static function terms_url(): string {
		$c   = self::get();
		$url = '' !== $c['terms_url'] ? $c['terms_url'] : '';
		if ( '' === $url && function_exists( 'wc_get_page_permalink' ) && (int) wc_get_page_id( 'terms' ) > 0 ) {
			$url = (string) wc_get_page_permalink( 'terms' );
		}
		if ( '' === $url ) {
			return '';
		}
		$url = (string) strtok( $url, '#' );
		return '' !== $c['anchor'] ? $url . '#' . $c['anchor'] : $url;
	}

	/** Escaped multi-line text, lines joined with <br>. */
	private static function lines( string $text ): string {
		$out = array();
		foreach ( preg_split( '/\R/u', $text ) as $line ) {
			if ( '' !== trim( $line ) ) {
				$out[] = esc_html( trim( $line ) );
			}
		}
		return implode( '<br>', $out );
	}

	/** Tab HTML for a resolved product ('' = no tab). */
	public static function html( WC_Product $product, ?array $resolved = null ): string {
		$product  = self::base( $product );
		$resolved = null === $resolved ? self::resolve( $product ) : $resolved;
		if ( null === $resolved['profile'] ) {
			return '';
		}
		$c       = self::get();
		$profile = $resolved['profile'];
		$maker   = $resolved['maker'];
		$out     = '';

		$care = self::fill( self::pick( $profile['care'] ), $product );
		if ( '' !== $care ) {
			$out .= '<h2>' . esc_html( self::label( 'care' ) ) . '</h2>' . wpautop( $care );
		}

		$safety = self::fill( self::pick( $profile['safety'] ), $product );
		$note   = self::kses( (string) get_post_meta( $product->get_id(), self::META_NOTE, true ), self::MAX_NOTE_LEN );
		$note   = self::fill( $note, $product );
		if ( '' !== $safety || '' !== $note ) {
			$out .= '<h2>' . esc_html( self::label( 'safety' ) ) . '</h2>' . wpautop( $safety );
			if ( '' !== $note ) {
				$out .= '<div class="tp-care__note">' . wpautop( $note ) . '</div>';
			}
		}

		if ( null !== $maker ) {
			$lines = array( esc_html( $maker['name'] ) );
			if ( '' !== $maker['address'] ) {
				$lines[] = self::lines( $maker['address'] );
			}
			if ( '' !== $maker['email'] ) {
				$lines[] = '<a href="' . esc_url( 'mailto:' . $maker['email'] ) . '">' . esc_html( $maker['email'] ) . '</a>';
			}
			if ( '' !== $maker['url'] ) {
				$lines[] = '<a href="' . esc_url( $maker['url'] ) . '" rel="noopener">' . esc_html( preg_replace( '#^https?://(www\.)?|/$#', '', $maker['url'] ) ) . '</a>';
			}
			$out .= '<h3>' . esc_html( self::label( 'maker' ) ) . '</h3><p class="tp-care__maker">' . implode( '<br>', $lines ) . '</p>';
		}

		// The manufacturer's own EU responsible person wins over the store-wide one.
		$eu = array();
		if ( null !== $maker && '' !== $maker['eu'] ) {
			$eu[] = self::lines( $maker['eu'] );
		} elseif ( '' !== $c['eu']['name'] ) {
			$eu[] = esc_html( $c['eu']['name'] );
			if ( '' !== $c['eu']['email'] ) {
				$eu[] = '<a href="' . esc_url( 'mailto:' . $c['eu']['email'] ) . '">' . esc_html( $c['eu']['email'] ) . '</a>';
			}
		}
		if ( $eu ) {
			$out .= '<h3>' . esc_html( self::label( 'eu' ) ) . '</h3><p class="tp-care__eu">' . implode( '<br>', $eu ) . '</p>';
		}

		$url = self::terms_url();
		if ( '' !== $url ) {
			$link = '<a href="' . esc_url( $url ) . '">' . esc_html( self::label( 'terms' ) ) . '</a>';
			$more = esc_html( self::label( 'more' ) );
			$more = false !== strpos( $more, '{terms}' ) ? str_replace( '{terms}', $link, $more ) : trim( $more . ' ' . $link );
			$out .= '<p class="tp-care__more">' . $more . '</p>';
		}

		$out = '<div class="tp-care">' . $out . '</div>';

		/**
		 * Filters the tab HTML (already escaped; return '' to hide the tab).
		 *
		 * @param string     $html     Tab HTML.
		 * @param WC_Product $product  Product.
		 * @param array      $resolved { profile, maker, reason }.
		 */
		return (string) apply_filters( 'tp_care_tab_html', $out, $product, $resolved );
	}

	/* =====================================================================
	 * Front end
	 * =================================================================== */

	/** Front-end page request where the tab may show (Bible §14.4). */
	public static function request_ok(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( did_action( 'wp' ) && ( is_feed() || is_embed() || is_robots() || is_trackback() ) ) {
			return false;
		}
		return true;
	}

	/** Module switch + master switch + test mode (shop managers only). */
	public static function on(): bool {
		if ( self::$failed || ! self::get()['enabled'] ) {
			return false;
		}
		$s = TP_Settings::get();
		return ! empty( $s['enabled'] )
			&& ( empty( $s['test_mode'] ) || current_user_can( 'manage_woocommerce' ) )
			&& self::request_ok();
	}

	/** Cached tab HTML for the product ('' = no tab). */
	private static function cached_html( WC_Product $product ): string {
		$id = (int) self::base( $product )->get_id();
		if ( ! isset( self::$html[ $id ] ) ) {
			self::$html[ $id ] = self::html( $product );
		}
		return self::$html[ $id ];
	}

	/** woocommerce_product_tabs: add the tab when the product resolves to a profile. */
	public static function tabs( $tabs ) {
		try {
			if ( ! is_array( $tabs ) || ! self::on() ) {
				return $tabs;
			}
			global $product;
			if ( ! $product instanceof WC_Product ) {
				return $tabs;
			}
			if ( '' === self::cached_html( $product ) ) {
				return $tabs;
			}
			$title                 = self::title( $product );
			$tabs[ self::TAB_KEY ] = array(
				'title'    => '' === $title ? self::label( 'tab' ) : $title,
				'priority' => self::TAB_PRIORITY,
				'callback' => array( __CLASS__, 'tab_content' ),
			);
			return $tabs;
		} catch ( \Throwable $e ) {
			self::$failed = true;
			return $tabs;
		}
	}

	/** Tab callback: prints the cached HTML (escaped when built). */
	public static function tab_content(): void {
		try {
			global $product;
			if ( $product instanceof WC_Product ) {
				echo self::cached_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts and wp_kses_post texts.
			}
		} catch ( \Throwable $e ) {
			self::$failed = true;
		}
	}

	/** One small CSS block, on product pages that show the tab only (§14.9). */
	public static function assets(): void {
		try {
			if ( ! self::on() || ! function_exists( 'is_product' ) || ! is_product() ) {
				return;
			}
			$product = wc_get_product( get_queried_object_id() );
			if ( ! $product instanceof WC_Product || '' === self::cached_html( $product ) ) {
				return;
			}
			wp_register_style( 'tp-care', false, array(), TP_VERSION );
			wp_enqueue_style( 'tp-care' );
			wp_add_inline_style( 'tp-care', self::css() );
		} catch ( \Throwable $e ) {
			self::$failed = true;
		}
	}

	/** Spacing only: the theme's typography and colours apply, except the link colour when set. */
	public static function css(): string {
		$css   = '.tp-care h2,.tp-care h3{margin:1.25em 0 .5em}'
			. '.tp-care>h2:first-child{margin-top:0}'
			. '.tp-care p{margin:0 0 .75em}'
			. '.tp-care__more{margin-top:1.25em}';
		$color = self::get()['color'];
		if ( '' !== $color ) {
			$css .= '.tp-care a{color:' . $color . '}';
		}
		return $css;
	}
}
