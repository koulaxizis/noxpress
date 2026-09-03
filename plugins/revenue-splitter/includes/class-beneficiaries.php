<?php
/**
 * Δικαιούχοι: global defaults + override ανά προϊόν.
 *
 * Πηγές αλήθειας:
 *  - Option 'rs_beneficiaries'  → global defaults (JSON array)
 *  - Post meta '_rs_split'      → override ανά προϊόν (ίδιο σχήμα)
 *  - Post meta '_rs_vat_rate'   → ΦΠΑ ανά προϊόν (πεδίο στο metabox, δες RS_VAT)
 *
 * Ένας (1) δικός χωρίς override = 100% φυσιολογικά.
 * Empty/default meta → πέφτουν τα global defaults (warning στο dashboard).
 *
 * ΔΙΟΡΘΩΣΗ v1.0.2 (self-healing): Ονόματα που αποθηκεύτηκαν κατεστραμμένα
 * ως sequences «uXXXX» (Unicode escapes χωρίς backslashes — παρενέργεια
 * του παλιού διπλού wp_unslash) επισκευάζονται αυτόματα:
 *  - στο ΔΙΑΒΑΣΜΑ (get_map → sanitize_list) → σωστή εμφάνιση παντού,
 *  - στην ΑΠΟΘΗΚΕΥΣΗ → μόνιμη επισκευή του meta.
 *
 * v1.1.3 (#6): Νέο κεντρικό helper collect_names() — το ΜΟΝΑΔΙΚΟ σημείο
 * που συγκεντρώνει όλα τα ονόματα δικαιούχων (globals + per-product
 * overrides). Το Admin UI dropdown και το Portal το καλούν από εδώ —
 * τέλος στη διπλο-υλοποίηση.
 */

defined( 'ABSPATH' ) || exit;

class RS_Beneficiaries {

	const META_KEY   = '_rs_split';
	const OPTION_KEY = 'rs_beneficiaries';

	public static function init(): void {

		// Metabox στη σελίδα προϊόντος.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_meta' ), 10, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Global defaults
	 * ------------------------------------------------------------------- */

	/**
	 * @return array[]|false False αν δεν έχουν ρυθμιστεί ποτέ
	 *                      (ξεχωρίζει το «κενό» από το «σκόπιμα παντού 100% σε έναν»).
	 */
	public static function get_defaults() {
		$raw = get_option( self::OPTION_KEY, '' );
		if ( '' === $raw || null === $raw ) {
			return false;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : false;
	}

	public static function set_defaults( array $beneficiaries ): bool {
		$clean = self::sanitize_list( $beneficiaries );
		if ( null === $clean ) {
			return false;
		}
		return update_option( self::OPTION_KEY, wp_json_encode( $clean ) );
	}

	/* ---------------------------------------------------------------------
	 * Ανά προϊόν
	 * ------------------------------------------------------------------- */

	/** Raw meta (κενό string αν δεν υπάρχει). */
	public static function raw( int $product_id ): string {
		$v = get_post_meta( $product_id, self::META_KEY, true );
		return is_scalar( $v ) ? (string) $v : '';
	}

	/**
	 * Ο ισχύων καταμερισμός: override, αλλιώς global defaults,
	 * αλλιώς trivial [[εσύ, 100]].
	 *
	 * @return array[] Array of ['name' => string, 'percent' => float]
	 */
	public static function get_map( int $product_id ): array {

		$raw = self::raw( $product_id );

		if ( '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$clean   = is_array( $decoded ) ? self::sanitize_list( $decoded ) : null;
			if ( null !== $clean ) {
				return $clean;
			}
		}

		$defaults = self::get_defaults();
		if ( is_array( $defaults ) && ! empty( $defaults ) ) {
			return $defaults;
		}

		return array(
			array(
				'name'    => __( 'Προϊόν — πλήρης δικαιούχος', 'revenue-splitter' ),
				'percent' => 100.0,
			),
		);
	}

	/** Έχει σκόπιμο override στο προϊόν; (για warnings στο dashboard) */
	public static function has_override( int $product_id ): bool {
		return '' !== trim( self::raw( $product_id ) );
	}

	/* ---------------------------------------------------------------------
	 * v1.1.3 (#6): Κεντρικό σημείο αλήθειας για ΟΛΑ τα ονόματα.
	 * ------------------------------------------------------------------- */

		/**
	 * Όλα τα ονόματα δικαιούχων που υπάρχουν στο σύστημα:
	 * global defaults + όλα τα per-product overrides (αποθηκευμένα).
	 *
	 * Χρησιμοποιείται από: Admin UI (dropdown φίλτρου dashboard) και
	 * Portal (lazy-key generation). Μία υλοποίηση, μηδέν drift.
	 *
	 * v1.1.3 (#3-fix): εφαρμόζεται και εδώ το repair_unicode_escapes()
	 * ώστε παλιά κατεστραμμένα «uXXXX» ονόματα να εμφανίζονται διορθωμένα
	 * στο dropdown και στα Portal keys — σύμφωνα με το report (get_map).
	 *
	 * @return string[] Ταξινομημένα ονόματα (unique).
	 */
	public static function collect_names(): array {

		$names = array();

		// 1) Global defaults.
		$defaults = self::get_defaults();
		if ( is_array( $defaults ) ) {
			foreach ( $defaults as $d ) {
				if ( is_array( $d ) && ! empty( $d['name'] ) ) {
					$name = self::repair_unicode_escapes(
						sanitize_text_field( (string) $d['name'] )
					);
					if ( '' !== $name ) {
						$names[ $name ] = true;
					}
				}
			}
		}

		// 2) Per-product overrides (raw meta — δεν κάνουμε fallback ώστε
		//    να μη διπλο-μετράμε τα global defaults).
		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'none',
				'no_found_rows'  => true,
			)
		);

		foreach ( $product_ids as $pid ) {
			$decoded = json_decode( self::raw( (int) $pid ), true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $row ) {
					if ( is_array( $row ) && ! empty( $row['name'] ) ) {
						$name = self::repair_unicode_escapes(
							sanitize_text_field( (string) $row['name'] )
						);
						if ( '' !== $name ) {
							$names[ $name ] = true;
						}
					}
				}
			}
		}

		$flat = array_keys( $names );
		sort( $flat );

		return $flat;
	}

	/* ---------------------------------------------------------------------
	 * Sanitization / Validation / Repair
	 * ------------------------------------------------------------------- */

	/**
	 * Επισκευή ονομάτων που αποθηκεύτηκαν ως «u03a3u03c4...»
	 * (JSON \uXXXX escapes χωρίς τα backslashes).
	 *
	 * Ενεργοποιείται ΜΟΝΟ όταν ολόκληρο το string αποτελείται από
	 * tokens «u» + 4 hex digits (με κενά μεταξύ λέξεων), ώστε να μην
	 * αγγίζει ποτέ φυσιολογικό κείμενο.
	 */
	private static function repair_unicode_escapes( string $name ): string {

		if ( '' === $name ) {
			return $name;
		}

		// Ολόκληρο το string: sequences uXXXX (κενά επιτρέπονται μόνο μεταξύ ομάδων).
		if ( ! preg_match( '/^(?:u[0-9a-fA-F]{4})+(?:\s+(?:u[0-9a-fA-F]{4})+)*$/u', $name ) ) {
			return $name;
		}

		return preg_replace_callback(
			'/u([0-9a-fA-F]{4})/u',
			static function ( $m ) {
				$cp = (int) hexdec( $m[1] );

				// Έλεγχος έγκυρου Unicode code point.
				if ( $cp < 0x20 || $cp > 0x10FFFF ) {
					return $m[0];
				}

				if ( function_exists( 'mb_chr' ) ) {
					return mb_chr( $cp, 'UTF-8' );
				}

				// Fallback χωρίς mbstring.
				return iconv( 'UCS-4BE', 'UTF-8', pack( 'N', $cp ) );
			},
			$name
		);
	}

	/**
	 * Καθαρίζει μία λίστα δικαιούχων.
	 *
	 * @param  array[] $list Σχέδιο input από POST (untrusted) ή από τη βάση.
	 * @return array[]|null null όταν η λίστα είναι άκυρη (Σ ≠ 100, κενά ονόματα, άρνητα ποσοστά).
	 */
	public static function sanitize_list( $list ): ?array {

		if ( ! is_array( $list ) || empty( $list ) ) {
			return null;
		}

		$clean = array();

		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( $row['name'] ) ) : '';
			$pct  = isset( $row['percent'] ) ? wc_format_decimal( $row['percent'] ) : '';

			// Self-healing: κατεστραμμένα «uXXXX» ονόματα επισκευάζονται εδώ,
			// τόσο σε νέα όσο και σε αποθηκευμένα δεδομένα (μέσω get_map).
			$name = self::repair_unicode_escapes( $name );

			if ( '' === $name || '' === $pct || ! is_numeric( $pct ) ) {
				return null;
			}

			$pct_f = (float) $pct;
			if ( $pct_f <= 0 || $pct_f > 100 ) {
				return null;
			}

			$clean[] = array(
				'name'    => $name,
				'percent' => round( $pct_f, 2 ),
			);
		}

		if ( empty( $clean ) ) {
			return null;
		}

		// Σ πρέπει να είναι 100% (tolerance 0.05 για roundings).
		$total = 0.0;
		foreach ( $clean as $c ) {
			$total += $c['percent'];
		}
		if ( abs( $total - 100 ) > 0.05 ) {
			return null;
		}

		return $clean;
	}

	/** Μήνυμα λάθους για το nonce-fail/validation fail. */
	public static function error_message_for( $list ): string {
		if ( ! is_array( $list ) || empty( $list ) ) {
			return __( 'Μη έγκυρη λίστα δικαιούχων.', 'revenue-splitter' );
		}
		$total = 0.0;
		foreach ( $list as $row ) {
			if ( is_array( $row ) && isset( $row['percent'] ) && is_numeric( $row['percent'] ) ) {
				$total += (float) $row['percent'];
			}
		}
		// translators: %s: the actual sum the user submitted.
		return sprintf( __( 'Τα ποσοστά δικαιούχων αθροίζουν %s%% — πρέπει να αθροίζουν 100%%.', 'revenue-splitter' ), $total );
	}

	/* ---------------------------------------------------------------------
	 * Metabox στο product
	 * ------------------------------------------------------------------- */

	public static function register_metabox(): void {
		add_meta_box(
			'rs_split_metabox',
			__( 'Revenue Splitter — Δικαιούχοι', 'revenue-splitter' ),
			array( __CLASS__, 'render_metabox' ),
			'product',
			'normal',
			'high'
		);
	}

	public static function render_metabox( WP_Post $post ): void {

		wp_nonce_field( 'rs_save_split', 'rs_split_nonce' );

		$product_id  = (int) $post->ID;
		$has_overr   = self::has_override( $product_id );
		$map         = self::get_map( $product_id );
		$fallback    = ! $has_overr;
		$defaults_ex = is_array( self::get_defaults() );

		// ΦΠΑ: εμφανίζουμε ΜΟΝΟ την explicit τιμή αν υπάρχει.
		$vat_explicit = trim( RS_VAT::raw_rate( $product_id ) );
		$vat_default  = RS_VAT::default_rate();
		?>
		<div class="rs-split-wrap" data-product-id="<?php echo esc_attr( $product_id ); ?>">

			<p>
				<label class="rs-fallback-toggle">
					<input type="checkbox" name="rs_use_fallback" value="1" <?php checked( $fallback ); ?> />
					<?php esc_html_e( 'Χρησιμοποιεί τα global defaults (δικαιούχοι)', 'revenue-splitter' ); ?>
				</label>
			</p>

			<div class="rs-rows<?php echo $fallback ? ' rs-hidden' : ''; ?>">
				<table class="widefat striped rs-split-table">
					<thead>
						<tr>
							<th class="rs-col-name"><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
							<th class="rs-col-pct"><?php esc_html_e( 'Ποσοστό (%)', 'revenue-splitter' ); ?></th>
							<th class="rs-col-del"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $map as $row ) : ?>
						<tr class="rs-row">
							<td><input type="text" name="rs_ben_name[]" value="<?php echo esc_attr( $row['name'] ); ?>" class="rs-ben-name" /></td>
							<td><input type="number" step="0.01" min="0" max="100" name="rs_ben_pct[]" value="<?php echo esc_attr( $row['percent'] ); ?>" class="rs-ben-pct" /></td>
							<td><button type="button" class="button-link rs-remove-row" aria-label="<?php esc_attr_e( 'Αφαίρεση γραμμής', 'revenue-splitter' ); ?>">×</button></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<button type="button" class="button rs-add-row">
					+ <?php esc_html_e( 'Προσθήκη δικαιούχου', 'revenue-splitter' ); ?>
				</button>

				<p class="rs-total-line">
					<?php esc_html_e( 'Σύνολο:', 'revenue-splitter' ); ?>
					<strong class="rs-total">100</strong>%
				</p>
			</div>

			<hr />

			<p>
				<label for="rs-vat-rate">
					<strong><?php esc_html_e( 'ΦΠΑ (%)', 'revenue-splitter' ); ?></strong>
				</label><br />
				<input type="number" step="0.01" min="0" max="100" id="rs-vat-rate"
					name="<?php echo esc_attr( RS_VAT::META_KEY ); ?>"
					value="<?php echo esc_attr( $vat_explicit ); ?>"
					placeholder="<?php echo esc_attr( $vat_default ); ?>"
					class="small-text rs-vat-input" />
				<span class="description">
					<?php
					printf(
						/* translators: %s: global default rate */
						esc_html__( 'Κενό = global default (%s%%).', 'revenue-splitter' ),
						esc_html( $vat_default )
					);
					?>
				</span>
			</p>

			<?php if ( ! $defaults_ex ) : ?>
				<p class="rs-note warning">
					<?php esc_html_e( 'Δεν έχουν ρυθμιστεί global defaults. Πήγαινε στο WP-admin → Revenue Splitter → Ρυθμίσεις.', 'revenue-splitter' ); ?>
				</p>
			<?php endif; ?>

			<input type="hidden" class="rs-template-row"
				data-name-placeholder="<?php esc_attr_e( 'Όνομα δικαιούχου', 'revenue-splitter' ); ?>" />
		</div>
		<?php
	}

	public static function save_meta( int $post_id ): void {

		// Autosave / revision guard.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ελέγχεται διπλάνω.
		if ( ! isset( $_POST['rs_split_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['rs_split_nonce'] ), 'rs_save_split' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$use_fallback = isset( $_POST['rs_use_fallback'] ); // checkbox ticked = όχι override.

		if ( $use_fallback ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- πολυδιάστατο input.
		// ΟΧΙ wp_unslash εδώ: η sanitize_list() κάνει το unslash ΜΙΑ φορά.
		$rows = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['rs_ben_name'], $_POST['rs_ben_pct'] ) && is_array( $_POST['rs_ben_name'] ) && is_array( $_POST['rs_ben_pct'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$names = array_values( (array) $_POST['rs_ben_name'] );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$percs = array_values( (array) $_POST['rs_ben_pct'] );

			foreach ( $names as $i => $n ) {
				$rows[] = array(
					'name'    => $names[ $i ],
					'percent' => $percs[ $i ] ?? '',
				);
			}
		}

		$clean = self::sanitize_list( $rows );

		if ( null === $clean ) {
			// Μη περνάμε λάθος δεδομένα. Notice + δεν γράφουμε override
			// (το προϊόν συνεχίζει να χρησιμοποιεί defaults/προηγούμενη τιμή).
			set_transient( 'rs_split_error_' . get_current_user_id(), self::error_message_for( $rows ), 60 );
			return;
		}

		update_post_meta( $post_id, self::META_KEY, wp_json_encode( $clean ) );

		/*
		 * Το ΦΠΑ (_rs_vat_rate) σώζεται στο RS_VAT::save_field()
		 * (woocommerce_admin_process_product_object).
		 */
	}
}