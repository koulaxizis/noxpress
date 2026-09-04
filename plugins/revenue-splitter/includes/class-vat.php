<?php
/**
 * ΦΠΑ ανά προϊόν + global default.
 *
 * Πηγές αλήθειας:
 *  - Option 'rs_default_vat_rate' → global default (%)
 *  - Post meta '_rs_vat_rate'      → override ανά προϊόν (%)
 *
 * Το meta '' (κενό / ανύπαρκτο) σημαίνει «δεν ορίστηκε» → πέφτει στο default
 * ΚΑΙ επισημαίνεται ως warning στο dashboard.
 *
 * ΔΙΟΡΘΩΣΗ v1.0.1: Το πεδίο ΦΠΑ εμφανίζεται πλέον ΜΕΣΑ στο metabox
 * «Revenue Splitter — Δικαιούχοι» (RS_Beneficiaries::render_metabox),
 * ώστε όλος ο έλεγχος του plugin να βρίσκεται σε ένα σημείο.
 * Το General tab hook αφαιρέθηκε.
 *
 * Αποθήκευση: το 'woocommerce_admin_process_product_object' εξακολουθεί
 * να καλείται σε ΚΑΘΕ save του προϊόντος (classic editor) και διαβάζει
 * το $_POST['_rs_vat_rate'] ανεξάρτητα από το πού το πεδίο είναι rendered.
 *
 * v1.3.1 FIX (#3): rs_invalidate_cache στα saves — τα cached reports
 * (dashboard / portal / CLI) παύουν να σερβίρουν stale ΦΠΑ μετά από
 * αλλαγή συντελεστή σε προϊόν.
 */

defined( 'ABSPATH' ) || exit;

class RS_VAT {

	const META_KEY   = '_rs_vat_rate';
	const OPTION_KEY = 'rs_default_vat_rate';

	public static function init(): void {

		/*
		 * Αποθήκευση μαζί με τα υπόλοιπα πεδία του προϊόντος.
		 * Το πεδίο RENDERED στο metabox των δικαιούχων (class-beneficiaries.php),
		 * αλλά το save γίνεται εδώ ώστε να αξιοποιεί τον δικό του Woo-validated
		 * hook: το WooCommerce καλεί αυτό το hook ΜΕΤΑ τον δικό του nonce/cap check
		 * (woocommerce_process_shop_post_meta).
		 */
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_field' ) );
	}

	/* ---------------------------------------------------------------------
	 * Global default
	 * ------------------------------------------------------------------- */

	/** Global default ΦΠΑ (%). */
	public static function default_rate(): float {
		return (float) get_option( self::OPTION_KEY, '24' );
	}

	/* ---------------------------------------------------------------------
	 * Ανά προϊόν
	 * ------------------------------------------------------------------- */

	/**
	 * Raw τιμή του meta (χωρίς fallback) — '' αν δεν έχει οριστεί ποτέ.
	 */
	public static function raw_rate( int $product_id ): string {
		$raw = get_post_meta( $product_id, self::META_KEY, true );
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * Ο ισχύων συντελεστής για υπολογισμούς:
	 * explicit meta override, αλλιώς global default.
	 */
	public static function get_rate( int $product_id ): float {
		$raw = trim( self::raw_rate( $product_id ) );
		return ( '' !== $raw ) ? (float) $raw : self::default_rate();
	}

	/** Έχει οριστεί ρητά ΦΠΑ στο προϊόν; (για warnings στο dashboard) */
	public static function has_explicit_rate( int $product_id ): bool {
		return '' !== trim( self::raw_rate( $product_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Αποθήκευση (από το πεδίο στο metabox των δικαιούχων)
	 * ------------------------------------------------------------------- */

	public static function save_field( WC_Product $product ): void {

		// Το Woo έχει ήδη επαληθεύσει το nonce + capability πριν καλέσει εδώ.

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo ελέγχει ήδη το nonce.
		if ( ! isset( $_POST[ self::META_KEY ] ) ) {
			return; // Το πεδίο δεν στάλθηκε (π.χ. quick/bulk edit) — δεν αγγίζουμε το meta.
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$submitted = trim( (string) wp_unslash( $_POST[ self::META_KEY ] ) );

		if ( '' === $submitted ) {
			// Κενό = «δεν ορίστηκε» → πέφτει στο global default.
			$deleted = delete_post_meta( $product->get_id(), self::META_KEY );

			// Invalidate μόνο αν άλλαξε κάτι.
			if ( $deleted ) {
				do_action( 'rs_invalidate_cache' );
			}
			return;
		}

		$value = wc_format_decimal( $submitted );

		if ( '' !== $value && is_numeric( $value ) && (float) $value >= 0 && (float) $value <= 100 ) {
			update_post_meta( $product->get_id(), self::META_KEY, (string) $value );
			do_action( 'rs_invalidate_cache' );
		}
		// Άκυρη τιμή → δεν γράφουμε τίποτα, δεν σβήνουμε τίποτα.
		// (Το number input min/max/step του browser κάνει το πρώτο επίπεδο
		// αποφυγής· αν στείλει κάποιος crafted POST με άκυρη τιμή, το meta
		// παραμένει απρόσβλητο — fail-safe σιωπηλά.)
	}
}