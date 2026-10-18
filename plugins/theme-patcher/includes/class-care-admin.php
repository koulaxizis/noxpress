<?php
/**
 * TP_Care_Admin — admin side of the "Care & Safety" tab:
 *  - the "Φροντίδα" tab of the Theme Patcher page (settings, profiles,
 *    manufacturers, preview, restore defaults), rendered by TP_Admin_UI;
 *  - the POST actions care_* (routed by TP_Admin_UI::route(), which has
 *    already checked the page, the nonce and the capability);
 *  - the "Φροντίδα και ασφάλεια" side box on the product edit screen.
 *
 * No admin JavaScript: plain forms, PRG (Bible §11). The preview and the
 * profile editor are read-only GET views.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Care_Admin {

	const BOX_NONCE       = 'tp_care_box';
	const BOX_NONCE_FIELD = 'tp_care_box_nonce';

	/** Empty manufacturer rows offered under the list. */
	const NEW_MAKER_ROWS = 2;

	/** Extra query string for the redirect after a POST (e.g. back to a profile). */
	public static $back = '';

	public static function init(): void {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_box' ) );
	}

	/* =====================================================================
	 * Labels
	 * =================================================================== */

	/** Visitor text ids => admin label. */
	private static function text_labels(): array {
		return array(
			'tab'    => __( 'Τίτλος καρτέλας', 'theme-patcher' ),
			'care'   => __( 'Επικεφαλίδα φροντίδας', 'theme-patcher' ),
			'safety' => __( 'Επικεφαλίδα ασφάλειας', 'theme-patcher' ),
			'maker'  => __( 'Επικεφαλίδα κατασκευαστή', 'theme-patcher' ),
			'eu'     => __( 'Επικεφαλίδα υπευθύνου στην ΕΕ', 'theme-patcher' ),
			'more'   => __( 'Τελευταία γραμμή ({terms} = σύνδεσμος)', 'theme-patcher' ),
			'terms'  => __( 'Κείμενο συνδέσμου όρων', 'theme-patcher' ),
		);
	}

	private static function reason_label( string $reason ): string {
		$labels = array(
			'product'      => __( 'ορίστηκε στο προϊόν', 'theme-patcher' ),
			'type'         => __( 'από το είδος του προϊόντος', 'theme-patcher' ),
			'sku'          => __( 'από το πρόθεμα του SKU', 'theme-patcher' ),
			'filter'       => __( 'από φίλτρο κώδικα', 'theme-patcher' ),
			'product-none' => __( '«Κανένα» στο προϊόν', 'theme-patcher' ),
			'excluded'     => __( 'εξωτερικό, ομαδοποιημένο ή εικονικό προϊόν', 'theme-patcher' ),
			'nomatch'      => __( 'κανένα προφίλ δεν ταιριάζει', 'theme-patcher' ),
			'disabled'     => __( 'το προφίλ του προϊόντος λείπει ή είναι ανενεργό', 'theme-patcher' ),
			'none'         => __( 'καμία καρτέλα', 'theme-patcher' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}

	/** Product attribute taxonomies: taxonomy => label. */
	private static function attr_options(): array {
		$out = array( '' => __( '— Κανένα —', 'theme-patcher' ) );
		foreach ( function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array() as $a ) {
			$out[ 'pa_' . $a->attribute_name ] = $a->attribute_label . ' (pa_' . $a->attribute_name . ')';
		}
		return $out;
	}

	private static function nonce_field(): void {
		wp_nonce_field( TP_Admin_UI::NONCE, TP_Admin_UI::NONCE_FIELD );
	}

	private static function action_fields( string $action ): void {
		self::nonce_field();
		echo '<input type="hidden" name="tp_action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="tp_tab" value="care" />';
	}

	private static function tab_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . TP_Admin_UI::SLUG_MAIN . '&tab=care' ) );
	}

	/* =====================================================================
	 * Tab
	 * =================================================================== */

	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only views.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : '';
		$key  = isset( $_GET['key'] ) ? TP_Care::valid_key( (string) wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		if ( 'profile' === $view ) {
			self::render_profile( $key );
			return;
		}
		if ( 'restore' === $view ) {
			self::render_restore();
			return;
		}
		self::render_general();
		self::render_profiles();
		self::render_makers();
		self::render_preview();
	}

	private static function render_general(): void {
		$c     = TP_Care::get();
		$attrs = self::attr_options();
		$tp    = TP_Settings::get();
		?>
		<h2 class="tp-h2"><?php esc_html_e( 'Φροντίδα και ασφάλεια', 'theme-patcher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Καρτέλα στη σελίδα προϊόντος με οδηγίες φροντίδας, στοιχεία ασφάλειας, τον κατασκευαστή και τον υπεύθυνο στην ΕΕ (GPSR). Το κείμενο εξαρτάται από το είδος του προϊόντος. Ισχύει για όλο το κατάστημα, με οποιοδήποτε θέμα.', 'theme-patcher' ); ?></p>
		<?php if ( $c['enabled'] && empty( $tp['enabled'] ) ) : ?>
			<p class="tp-warn"><?php esc_html_e( 'Το Theme Patcher είναι ανενεργό στις Ρυθμίσεις: η καρτέλα δεν εμφανίζεται ακόμη.', 'theme-patcher' ); ?></p>
		<?php elseif ( $c['enabled'] && ! empty( $tp['test_mode'] ) ) : ?>
			<p class="tp-warn"><?php esc_html_e( 'Λειτουργία δοκιμής: η καρτέλα φαίνεται μόνο σε όσους διαχειρίζονται το κατάστημα.', 'theme-patcher' ); ?></p>
		<?php endif; ?>

		<form method="post">
			<?php self::action_fields( 'care_save_general' ); ?>
			<div class="tp-zone">
				<label class="tp-check">
					<input type="checkbox" name="tp_care[enabled]" value="1" <?php checked( $c['enabled'] ); ?> />
					<?php esc_html_e( 'Εμφάνιση της καρτέλας στις σελίδες προϊόντων', 'theme-patcher' ); ?>
				</label>
				<span class="tp-hint"><?php esc_html_e( 'Ακολουθεί τον κεντρικό διακόπτη και τη λειτουργία δοκιμής του Theme Patcher. Η γλώσσα των κειμένων είναι η γλώσσα επισκέπτη της καρτέλας «Τιμές».', 'theme-patcher' ); ?></span>
			</div>

			<h3 class="tp-h3"><?php esc_html_e( 'Χαρακτηριστικά προϊόντων', 'theme-patcher' ); ?></h3>
			<div class="tp-zone tp-row">
				<?php
				$fields = array(
					'type'     => __( 'Είδος (επιλέγει το προφίλ)', 'theme-patcher' ),
					'material' => __( 'Υλικό ({material})', 'theme-patcher' ),
					'method'   => __( 'Μέθοδος ({method})', 'theme-patcher' ),
				);
				foreach ( $fields as $k => $label ) :
					?>
					<label class="tp-field">
						<span><?php echo esc_html( $label ); ?></span>
						<select name="<?php echo esc_attr( 'tp_care[attrs][' . $k . ']' ); ?>">
							<?php foreach ( $attrs as $tax => $name ) : ?>
								<option value="<?php echo esc_attr( $tax ); ?>" <?php selected( $c['attrs'][ $k ], $tax ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endforeach; ?>
			</div>

			<h3 class="tp-h3"><?php esc_html_e( 'Κείμενα της καρτέλας', 'theme-patcher' ); ?></h3>
			<table class="widefat striped tp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Κείμενο', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Ελληνικά', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'English', 'theme-patcher' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::text_labels() as $id => $label ) : ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( 'tp_care[texts][' . $id . '][el]' ); ?>" value="<?php echo esc_attr( $c['texts'][ $id ]['el'] ); ?>" maxlength="<?php echo esc_attr( (string) TP_Care::MAX_TEXT_LEN ); ?>" /></td>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( 'tp_care[texts][' . $id . '][en]' ); ?>" value="<?php echo esc_attr( $c['texts'][ $id ]['en'] ); ?>" maxlength="<?php echo esc_attr( (string) TP_Care::MAX_TEXT_LEN ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description tp-mt-8"><?php esc_html_e( 'Κενό ελληνικό κείμενο επαναφέρει την προεπιλογή. Κενό αγγλικό κείμενο δείχνει το ελληνικό.', 'theme-patcher' ); ?></p>

			<h3 class="tp-h3"><?php esc_html_e( 'Υπεύθυνος για την ασφάλεια στην ΕΕ', 'theme-patcher' ); ?></h3>
			<div class="tp-zone tp-row">
				<label class="tp-field">
					<span><?php esc_html_e( 'Όνομα', 'theme-patcher' ); ?></span>
					<input type="text" class="regular-text" name="tp_care[eu][name]" value="<?php echo esc_attr( $c['eu']['name'] ); ?>" maxlength="<?php echo esc_attr( (string) TP_Care::MAX_LINE_LEN ); ?>" />
				</label>
				<label class="tp-field">
					<span><?php esc_html_e( 'Email', 'theme-patcher' ); ?></span>
					<input type="email" class="regular-text" name="tp_care[eu][email]" value="<?php echo esc_attr( $c['eu']['email'] ); ?>" />
				</label>
			</div>
			<p class="description tp-mt-8"><?php esc_html_e( 'Εμφανίζεται σε κάθε προϊόν, εκτός αν ο κατασκευαστής του έχει δικό του υπεύθυνο στην ΕΕ. Κενό όνομα = η ενότητα δεν εμφανίζεται.', 'theme-patcher' ); ?></p>

			<h3 class="tp-h3"><?php esc_html_e( 'Σύνδεσμος όρων και εμφάνιση', 'theme-patcher' ); ?></h3>
			<div class="tp-zone tp-row">
				<label class="tp-field">
					<span><?php esc_html_e( 'Διεύθυνση όρων (κενό = σελίδα όρων του WooCommerce)', 'theme-patcher' ); ?></span>
					<input type="url" class="regular-text" name="tp_care[terms_url]" value="<?php echo esc_attr( $c['terms_url'] ); ?>" placeholder="<?php echo esc_attr( (string) ( function_exists( 'wc_get_page_permalink' ) && wc_get_page_id( 'terms' ) > 0 ? wc_get_page_permalink( 'terms' ) : '' ) ); ?>" />
				</label>
				<label class="tp-field">
					<span><?php esc_html_e( 'Άγκυρα (#)', 'theme-patcher' ); ?></span>
					<input type="text" class="small-text" name="tp_care[anchor]" value="<?php echo esc_attr( $c['anchor'] ); ?>" maxlength="60" />
				</label>
				<label class="tp-field">
					<span><?php esc_html_e( 'Χρώμα συνδέσμων (κενό = του θέματος)', 'theme-patcher' ); ?></span>
					<input type="text" class="tp-care-color" name="tp_care[color]" value="<?php echo esc_attr( $c['color'] ); ?>" placeholder="#DD183B" maxlength="7" />
				</label>
			</div>
			<?php if ( '' === TP_Care::terms_url() ) : ?>
				<p class="tp-warn"><?php esc_html_e( 'Δεν βρέθηκε σελίδα όρων: η τελευταία γραμμή δεν εμφανίζεται.', 'theme-patcher' ); ?></p>
			<?php endif; ?>

			<p class="tp-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων καρτέλας', 'theme-patcher' ); ?></button>
			</p>
		</form>
		<?php
	}

	private static function render_profiles(): void {
		$c     = TP_Care::get();
		$names = array();
		foreach ( $c['makers'] as $m ) {
			$names[ $m['key'] ] = $m['name'];
		}
		?>
		<h3 class="tp-h3"><?php esc_html_e( 'Προφίλ', 'theme-patcher' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Με «Αυτόματο» στο προϊόν ισχύει το πρώτο ενεργό προφίλ του οποίου κάποιο είδος ταιριάζει στο προϊόν, αλλιώς το πρώτο του οποίου το πρόθεμα ταιριάζει στο SKU. Εξωτερικά, ομαδοποιημένα και εικονικά προϊόντα (και όσα είναι μόνο για λήψη) παίρνουν καρτέλα μόνο με ρητό προφίλ.', 'theme-patcher' ); ?></p>
		<form method="post">
			<?php self::action_fields( 'care_save_profiles' ); ?>
			<table class="widefat striped tp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Σειρά', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Ενεργό', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Προφίλ', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Είδη', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Προθέματα SKU', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Κατασκευαστής', 'theme-patcher' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $c['profiles'] ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'Κανένα προφίλ.', 'theme-patcher' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $c['profiles'] as $i => $p ) : ?>
						<?php $n = 'tp_care_profiles[' . $p['key'] . ']'; ?>
						<tr>
							<td><input type="number" min="1" max="999" class="small-text" name="<?php echo esc_attr( $n . '[order]' ); ?>" value="<?php echo (int) $i + 1; ?>" /></td>
							<td><input type="checkbox" name="<?php echo esc_attr( $n . '[enabled]' ); ?>" value="1" <?php checked( $p['enabled'] ); ?> /></td>
							<td><strong><?php echo esc_html( $p['label'] ); ?></strong><br /><code><?php echo esc_html( $p['key'] ); ?></code></td>
							<td><?php echo esc_html( $p['types'] ? implode( ', ', $p['types'] ) : '—' ); ?></td>
							<td><?php echo esc_html( $p['sku'] ? implode( ', ', $p['sku'] ) : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $p['maker'] && isset( $names[ $p['maker'] ] ) ? $names[ $p['maker'] ] : '—' ); ?></td>
							<td><a href="
							<?php
							echo esc_url(
								self::tab_url(
									array(
										'view' => 'profile',
										'key'  => $p['key'],
									)
								)
							);
							?>
											"><?php esc_html_e( 'Επεξεργασία', 'theme-patcher' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="tp-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση σειράς', 'theme-patcher' ); ?></button>
				<?php if ( count( $c['profiles'] ) < TP_Care::MAX_PROFILES ) : ?>
					<a class="button" href="<?php echo esc_url( self::tab_url( array( 'view' => 'profile' ) ) ); ?>"><?php esc_html_e( 'Νέο προφίλ', 'theme-patcher' ); ?></a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( self::tab_url( array( 'view' => 'restore' ) ) ); ?>"><?php esc_html_e( 'Επαναφορά προεπιλογών', 'theme-patcher' ); ?></a>
			</p>
		</form>
		<?php
	}

	private static function render_makers(): void {
		$makers = TP_Care::get()['makers'];
		$free   = max( 0, min( self::NEW_MAKER_ROWS, TP_Care::MAX_MAKERS - count( $makers ) ) );
		for ( $i = 0; $i < $free; $i++ ) {
			$makers[] = array(
				'key'     => '',
				'name'    => '',
				'address' => '',
				'email'   => '',
				'url'     => '',
				'eu'      => '',
			);
		}
		?>
		<h3 class="tp-h3"><?php esc_html_e( 'Κατασκευαστές', 'theme-patcher' ); ?></h3>
		<form method="post">
			<?php self::action_fields( 'care_save_makers' ); ?>
			<div class="tp-care-makers">
			<table class="widefat striped tp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Κλειδί', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Όνομα', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Ταχυδρομική διεύθυνση', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Email', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Ιστότοπος', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Υπεύθυνος στην ΕΕ (προαιρετικά)', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Διαγραφή', 'theme-patcher' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $makers as $i => $m ) : ?>
						<?php
						$n           = 'tp_care_makers[' . $i . ']';
						$placeholder = 'printful' === $m['key'] ? __( 'Συμπληρώστε τη διεύθυνση της Printful στην ΕΕ', 'theme-patcher' ) : '';
						?>
						<tr>
							<td><input type="text" name="<?php echo esc_attr( $n . '[key]' ); ?>" value="<?php echo esc_attr( $m['key'] ); ?>" maxlength="60" <?php echo '' !== $m['key'] ? 'readonly' : ''; ?> /></td>
							<td><input type="text" name="<?php echo esc_attr( $n . '[name]' ); ?>" value="<?php echo esc_attr( $m['name'] ); ?>" maxlength="<?php echo esc_attr( (string) TP_Care::MAX_LINE_LEN ); ?>" /></td>
							<td><textarea rows="2" cols="28" name="<?php echo esc_attr( $n . '[address]' ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $m['address'] ); ?></textarea></td>
							<td><input type="email" name="<?php echo esc_attr( $n . '[email]' ); ?>" value="<?php echo esc_attr( $m['email'] ); ?>" /></td>
							<td><input type="url" name="<?php echo esc_attr( $n . '[url]' ); ?>" value="<?php echo esc_attr( $m['url'] ); ?>" /></td>
							<td><textarea rows="2" cols="24" name="<?php echo esc_attr( $n . '[eu]' ); ?>"><?php echo esc_textarea( $m['eu'] ); ?></textarea></td>
							<td>
							<?php
							if ( '' !== $m['key'] ) :
								?>
								<input type="checkbox" name="<?php echo esc_attr( $n . '[delete]' ); ?>" value="1" /><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p class="description tp-mt-8"><?php esc_html_e( 'Το κλειδί (λατινικά, π.χ. printful) δεν αλλάζει μετά την αποθήκευση, γιατί το χρησιμοποιούν τα προϊόντα και τα προφίλ. Μετά την αποθήκευση εμφανίζονται δύο νέες κενές γραμμές.', 'theme-patcher' ); ?></p>
			<?php self::render_class_makers(); ?>
			<p class="tp-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση κατασκευαστών', 'theme-patcher' ); ?></button>
			</p>
		</form>
		<?php
	}

	/** Shipping class => manufacturer (e.g. every product of the class "printful" is made by Printful). */
	private static function render_class_makers(): void {
		$c       = TP_Care::get();
		$classes = function_exists( 'WC' ) ? WC()->shipping()->get_shipping_classes() : array();
		?>
		<h4 class="tp-mt-8"><?php esc_html_e( 'Κατασκευαστής ανά κλάση αποστολής', 'theme-patcher' ); ?></h4>
		<?php if ( ! $classes ) : ?>
			<p class="description"><?php esc_html_e( 'Το κατάστημα δεν έχει κλάσεις αποστολής.', 'theme-patcher' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping&section=classes' ) ); ?>"><?php esc_html_e( 'Κλάσεις αποστολής του WooCommerce', 'theme-patcher' ); ?></a></p>
			<?php
			foreach ( $c['class_makers'] as $slug => $mk ) {
				echo '<input type="hidden" name="tp_care_class_makers[' . esc_attr( $slug ) . ']" value="' . esc_attr( $mk ) . '" />';
			}
			return;
		endif;
		?>
		<table class="widefat striped tp-table tp-care-classes">
			<thead><tr><th><?php esc_html_e( 'Κλάση αποστολής', 'theme-patcher' ); ?></th><th><?php esc_html_e( 'Κατασκευαστής', 'theme-patcher' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $classes as $term ) : ?>
				<?php $cur = isset( $c['class_makers'][ $term->slug ] ) ? $c['class_makers'][ $term->slug ] : ''; ?>
				<tr>
					<td><?php echo esc_html( $term->name ); ?> <code><?php echo esc_html( $term->slug ); ?></code></td>
					<td><select name="tp_care_class_makers[<?php echo esc_attr( $term->slug ); ?>]">
						<option value="" <?php selected( $cur, '' ); ?>><?php esc_html_e( '— από το προφίλ —', 'theme-patcher' ); ?></option>
						<?php foreach ( $c['makers'] as $m ) : ?>
							<option value="<?php echo esc_attr( $m['key'] ); ?>" <?php selected( $cur, $m['key'] ); ?>><?php echo esc_html( $m['name'] ); ?></option>
						<?php endforeach; ?>
					</select></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description tp-mt-8"><?php esc_html_e( 'Τα προϊόντα μιας κλάσης παίρνουν αυτόματα αυτόν τον κατασκευαστή (οι παραλλαγές ακολουθούν το γονικό). Ο κατασκευαστής που ορίζεις στο ίδιο το προϊόν μετράει πρώτος.', 'theme-patcher' ); ?></p>
		<?php
	}

	private static function render_preview(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview.
		$pid = isset( $_GET['tp_care_preview'] ) ? absint( wp_unslash( $_GET['tp_care_preview'] ) ) : 0;
		?>
		<h3 class="tp-h3" id="tp-care-preview"><?php esc_html_e( 'Προεπισκόπηση', 'theme-patcher' ); ?></h3>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>#tp-care-preview" class="tp-inline-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( TP_Admin_UI::SLUG_MAIN ); ?>" />
			<input type="hidden" name="tab" value="care" />
			<label>
				<?php esc_html_e( 'ID προϊόντος', 'theme-patcher' ); ?>
				<input type="number" min="1" class="small-text" name="tp_care_preview" value="<?php echo $pid ? (int) $pid : ''; ?>" />
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Προεπισκόπηση', 'theme-patcher' ); ?></button>
		</form>
		<p class="description tp-mt-8"><?php esc_html_e( 'Μόνο ανάγνωση: δείχνει την καρτέλα όπως θα τη δει ο επισκέπτης, ακόμα κι όταν είναι ανενεργή.', 'theme-patcher' ); ?></p>
		<?php
		if ( ! $pid ) {
			return;
		}
		$product = wc_get_product( $pid );
		if ( ! $product instanceof WC_Product ) {
			echo '<p class="tp-warn">' . esc_html__( 'Δεν βρέθηκε προϊόν με αυτό το ID.', 'theme-patcher' ) . '</p>';
			return;
		}
		$r = TP_Care::resolve( $product );
		?>
		<div class="tp-zone tp-mt-8">
			<p>
				<strong><?php echo esc_html( $product->get_name() ); ?></strong>
				<?php
				printf(
					/* translators: 1: profile label or "none", 2: reason, 3: manufacturer name or "none" */
					esc_html__( 'Προφίλ: %1$s (%2$s). Κατασκευαστής: %3$s.', 'theme-patcher' ),
					esc_html( null !== $r['profile'] ? $r['profile']['label'] . ' [' . $r['profile']['key'] . ']' : __( 'κανένα', 'theme-patcher' ) ),
					esc_html( self::reason_label( $r['reason'] ) ),
					esc_html( null !== $r['maker'] ? $r['maker']['name'] . ' [' . $r['maker']['key'] . ']' : __( 'κανένας', 'theme-patcher' ) )
				);
				?>
			</p>
			<?php $html = TP_Care::html( $product, $r ); ?>
			<?php if ( '' !== $html ) : ?>
				<p><strong><?php echo esc_html( TP_Care::title( $product ) ); ?></strong></p>
				<div class="tp-care-preview">
					<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts and wp_kses_post texts. ?>
				</div>
				<details class="tp-details tp-mt-8">
					<summary><?php esc_html_e( 'HTML', 'theme-patcher' ); ?></summary>
					<pre class="tp-scroll"><?php echo esc_html( $html ); ?></pre>
				</details>
			<?php else : ?>
				<p><?php esc_html_e( 'Αυτό το προϊόν δεν παίρνει καρτέλα.', 'theme-patcher' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------- View: one profile ---------- */

	private static function render_profile( string $key ): void {
		$c      = TP_Care::get();
		$p      = '' !== $key ? TP_Care::profile( $key ) : null;
		$is_new = null === $p;
		if ( $is_new ) {
			$p = array(
				'key'     => '',
				'label'   => '',
				'care'    => array(
					'el' => '',
					'en' => '',
				),
				'safety'  => array(
					'el' => '',
					'en' => '',
				),
				'types'   => array(),
				'sku'     => array(),
				'maker'   => '',
				'enabled' => true,
			);
		}
		$terms = array();
		if ( '' !== $c['attrs']['type'] && taxonomy_exists( $c['attrs']['type'] ) ) {
			$found = get_terms(
				array(
					'taxonomy'   => $c['attrs']['type'],
					'hide_empty' => false,
					'orderby'    => 'name',
				)
			);
			$terms = is_array( $found ) ? $found : array();
		}
		$known = array();
		?>
		<p><a href="<?php echo esc_url( self::tab_url() ); ?>">&larr; <?php esc_html_e( 'Πίσω στη Φροντίδα', 'theme-patcher' ); ?></a></p>
		<h2 class="tp-h2"><?php echo esc_html( $is_new ? __( 'Νέο προφίλ', 'theme-patcher' ) : $p['label'] ); ?></h2>
		<form method="post">
			<?php self::action_fields( 'care_save_profile' ); ?>
			<input type="hidden" name="tp_care_profile[orig]" value="<?php echo esc_attr( $p['key'] ); ?>" />
			<div class="tp-zone tp-row">
				<label class="tp-field">
					<span><?php esc_html_e( 'Κλειδί (λατινικά, π.χ. clothing)', 'theme-patcher' ); ?></span>
					<input type="text" class="regular-text" name="tp_care_profile[key]" value="<?php echo esc_attr( $p['key'] ); ?>" maxlength="60" required <?php echo $is_new ? '' : 'readonly'; ?> />
				</label>
				<label class="tp-field">
					<span><?php esc_html_e( 'Όνομα', 'theme-patcher' ); ?></span>
					<input type="text" class="regular-text" name="tp_care_profile[label]" value="<?php echo esc_attr( $p['label'] ); ?>" maxlength="<?php echo esc_attr( (string) TP_Care::MAX_LINE_LEN ); ?>" />
				</label>
				<label class="tp-field">
					<span><?php esc_html_e( 'Κατασκευαστής (προεπιλογή)', 'theme-patcher' ); ?></span>
					<select name="tp_care_profile[maker]">
						<option value=""><?php esc_html_e( '— Κανένας —', 'theme-patcher' ); ?></option>
						<?php foreach ( $c['makers'] as $m ) : ?>
							<option value="<?php echo esc_attr( $m['key'] ); ?>" <?php selected( $p['maker'], $m['key'] ); ?>><?php echo esc_html( $m['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="tp-check">
					<input type="checkbox" name="tp_care_profile[enabled]" value="1" <?php checked( $p['enabled'] ); ?> />
					<?php esc_html_e( 'Ενεργό', 'theme-patcher' ); ?>
				</label>
			</div>

			<h3 class="tp-h3"><?php esc_html_e( 'Πότε ισχύει', 'theme-patcher' ); ?></h3>
			<div class="tp-zone">
				<?php if ( '' === $c['attrs']['type'] ) : ?>
					<p class="tp-hint"><?php esc_html_e( 'Δεν έχει οριστεί χαρακτηριστικό «Είδος»: ισχύουν μόνο τα προθέματα SKU.', 'theme-patcher' ); ?></p>
				<?php else : ?>
					<p class="tp-hint"><?php esc_html_e( 'Είδη:', 'theme-patcher' ); ?></p>
					<?php foreach ( $terms as $t ) : ?>
						<?php $known[] = $t->slug; ?>
						<label class="tp-check tp-inline">
							<input type="checkbox" name="tp_care_profile[types][]" value="<?php echo esc_attr( $t->slug ); ?>" <?php checked( in_array( $t->slug, $p['types'], true ) ); ?> />
							<?php echo esc_html( $t->name . ' (' . $t->slug . ')' ); ?>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>
				<label class="tp-field tp-mt-8">
					<span><?php esc_html_e( 'Άλλα είδη (slugs, με κόμμα, και για όρους που δεν υπάρχουν ακόμη)', 'theme-patcher' ); ?></span>
					<input type="text" class="large-text" name="tp_care_profile[types_extra]" value="<?php echo esc_attr( implode( ', ', array_diff( $p['types'], $known ) ) ); ?>" placeholder="hat, mug" />
				</label>
				<label class="tp-field tp-mt-8">
					<span><?php esc_html_e( 'Προθέματα SKU (με κόμμα)', 'theme-patcher' ); ?></span>
					<input type="text" class="regular-text" name="tp_care_profile[sku]" value="<?php echo esc_attr( implode( ', ', $p['sku'] ) ); ?>" placeholder="CLTH" />
				</label>
			</div>

			<p class="description"><?php esc_html_e( 'Placeholders: {product_name}, {material}, {method}. Μια πρόταση με placeholder χωρίς τιμή δεν εμφανίζεται. Κενό αγγλικό κείμενο δείχνει το ελληνικό.', 'theme-patcher' ); ?></p>
			<?php
			$editors = array(
				'care'   => __( 'Φροντίδα', 'theme-patcher' ),
				'safety' => __( 'Ασφάλεια', 'theme-patcher' ),
			);
			foreach ( $editors as $field => $title ) :
				foreach ( array( 'el', 'en' ) as $lang ) :
					?>
					<h3 class="tp-h3"><?php echo esc_html( $title . ' — ' . ( 'el' === $lang ? __( 'Ελληνικά', 'theme-patcher' ) : __( 'English', 'theme-patcher' ) ) ); ?></h3>
					<div class="tp-care-editor">
						<?php
						wp_editor(
							$p[ $field ][ $lang ],
							'tp_care_' . $field . '_' . $lang,
							array(
								'textarea_name' => 'tp_care_profile[' . $field . '][' . $lang . ']',
								'textarea_rows' => 5,
								'media_buttons' => false,
								'teeny'         => true,
							)
						);
						?>
					</div>
					<?php
				endforeach;
			endforeach;
			?>
			<p class="tp-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση προφίλ', 'theme-patcher' ); ?></button>
			</p>
		</form>

		<?php if ( ! $is_new ) : ?>
			<h3 class="tp-h3"><?php esc_html_e( 'Διαγραφή προφίλ', 'theme-patcher' ); ?></h3>
			<form method="post">
				<?php self::action_fields( 'care_delete_profile' ); ?>
				<input type="hidden" name="tp_care_key" value="<?php echo esc_attr( $p['key'] ); ?>" />
				<label class="tp-check">
					<input type="checkbox" name="tp_care_confirm" value="1" required />
					<?php esc_html_e( 'Ναι, διάγραψε αυτό το προφίλ (τα προϊόντα που το έχουν επιλέξει δεν θα δείχνουν καρτέλα)', 'theme-patcher' ); ?>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Διαγραφή', 'theme-patcher' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/* ---------- View: restore defaults (confirm step) ---------- */

	private static function render_restore(): void {
		?>
		<p><a href="<?php echo esc_url( self::tab_url() ); ?>">&larr; <?php esc_html_e( 'Πίσω στη Φροντίδα', 'theme-patcher' ); ?></a></p>
		<h2 class="tp-h2"><?php esc_html_e( 'Επαναφορά προεπιλογών', 'theme-patcher' ); ?></h2>
		<p><?php esc_html_e( 'Τα προφίλ αντικαθίστανται από τα πέντε προφίλ του plugin (Ρούχα, Καπέλα, Κούπες, Αξεσουάρ, Είδη σπιτιού) με τα αρχικά τους κείμενα. Ό,τι άλλαξες ή πρόσθεσες στα προφίλ χάνεται. Οι κατασκευαστές, ο υπεύθυνος στην ΕΕ, τα κείμενα της καρτέλας και οι ρυθμίσεις των προϊόντων μένουν όπως είναι.', 'theme-patcher' ); ?></p>
		<form method="post">
			<?php self::action_fields( 'care_restore' ); ?>
			<label class="tp-check">
				<input type="checkbox" name="tp_care_confirm" value="1" required />
				<?php esc_html_e( 'Ναι, επανάφερε τα προφίλ', 'theme-patcher' ); ?>
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Επαναφορά', 'theme-patcher' ); ?></button>
		</form>
		<?php
	}

	/* =====================================================================
	 * POST actions (verified in TP_Admin_UI::route())
	 * =================================================================== */

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function posted( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in TP_Admin_UI::route(); validated by TP_Care::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	private static function confirmed(): bool {
		return ! empty( $_POST['tp_care_confirm'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in TP_Admin_UI::route().
	}

	/** Handle a care_* action; returns the notices. */
	public static function handle( string $action ): array {
		self::$back = '';
		switch ( $action ) {
			case 'care_save_general':
				$msgs = self::do_save_general();
				break;
			case 'care_save_profiles':
				$msgs = self::do_save_profiles();
				break;
			case 'care_save_profile':
				$msgs = self::do_save_profile();
				break;
			case 'care_delete_profile':
				$msgs = self::do_delete_profile();
				break;
			case 'care_save_makers':
				$msgs = self::do_save_makers();
				break;
			case 'care_restore':
				$msgs = self::do_restore();
				break;
			default:
				return array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'theme-patcher' ) ) );
		}
		$cache = self::msg( 'info', __( 'Αν το site έχει cache σελίδων (π.χ. WP Rocket), καθάρισέ το για να δουν οι επισκέπτες τις αλλαγές.', 'theme-patcher' ) );
		if ( TP_Care::get()['enabled'] && 'error' !== $msgs[0]['type'] ) {
			$msgs[] = $cache;
		}
		return $msgs;
	}

	private static function do_save_general(): array {
		$raw = self::posted( 'tp_care' );
		$c   = TP_Care::get();
		foreach ( array( 'attrs', 'texts', 'eu' ) as $k ) {
			if ( isset( $raw[ $k ] ) && is_array( $raw[ $k ] ) ) {
				$c[ $k ] = $raw[ $k ];
			}
		}
		$c['enabled'] = ! empty( $raw['enabled'] );
		foreach ( array( 'terms_url', 'anchor', 'color' ) as $k ) {
			$c[ $k ] = isset( $raw[ $k ] ) ? (string) $raw[ $k ] : '';
		}
		$color_in = trim( $c['color'] );
		TP_Care::save( $c );
		$saved = TP_Care::get();

		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις της καρτέλας αποθηκεύτηκαν.', 'theme-patcher' ) ) );
		if ( '' !== $color_in && '' === $saved['color'] ) {
			$msgs[] = self::msg( 'warning', __( 'Το χρώμα δεν είναι έγκυρο (π.χ. #DD183B) και αγνοήθηκε.', 'theme-patcher' ) );
		}
		if ( isset( $raw['eu']['email'] ) && '' !== trim( (string) $raw['eu']['email'] ) && '' === $saved['eu']['email'] ) {
			$msgs[] = self::msg( 'warning', __( 'Το email δεν είναι έγκυρο και αγνοήθηκε.', 'theme-patcher' ) );
		}
		return $msgs;
	}

	/** Order + enabled flags of the profile list. */
	private static function do_save_profiles(): array {
		$raw  = self::posted( 'tp_care_profiles' );
		$c    = TP_Care::get();
		$rank = array();
		foreach ( $c['profiles'] as $pos => $p ) {
			$row                              = isset( $raw[ $p['key'] ] ) && is_array( $raw[ $p['key'] ] ) ? $raw[ $p['key'] ] : array();
			$order                            = isset( $row['order'] ) && is_numeric( $row['order'] ) && (int) $row['order'] > 0 ? (int) $row['order'] : 1000;
			$c['profiles'][ $pos ]['enabled'] = ! empty( $row['enabled'] );
			$rank[ $pos ]                     = $order * 1000 + $pos; // Ties keep the current order.
		}
		asort( $rank );
		$sorted = array();
		foreach ( array_keys( $rank ) as $pos ) {
			$sorted[] = $c['profiles'][ $pos ];
		}
		$c['profiles'] = $sorted;
		TP_Care::save( $c );
		return array( self::msg( 'success', __( 'Τα προφίλ αποθηκεύτηκαν.', 'theme-patcher' ) ) );
	}

	private static function do_save_profile(): array {
		$raw  = self::posted( 'tp_care_profile' );
		$orig = isset( $raw['orig'] ) ? TP_Care::valid_key( (string) $raw['orig'] ) : '';
		$c    = TP_Care::get();

		$types = isset( $raw['types'] ) && is_array( $raw['types'] ) ? $raw['types'] : array();
		$extra = isset( $raw['types_extra'] ) ? explode( ',', (string) $raw['types_extra'] ) : array();
		$input = array(
			'key'     => '' !== $orig ? $orig : ( isset( $raw['key'] ) ? (string) $raw['key'] : '' ),
			'label'   => isset( $raw['label'] ) ? $raw['label'] : '',
			'care'    => isset( $raw['care'] ) ? $raw['care'] : array(),
			'safety'  => isset( $raw['safety'] ) ? $raw['safety'] : array(),
			'types'   => array_merge( $types, $extra ),
			'sku'     => isset( $raw['sku'] ) ? (string) $raw['sku'] : '',
			'maker'   => isset( $raw['maker'] ) ? (string) $raw['maker'] : '',
			'enabled' => ! empty( $raw['enabled'] ),
		);
		$p     = TP_Care::sanitize_profile( $input );
		if ( null === $p ) {
			self::$back = '&view=profile';
			return array( self::msg( 'error', __( 'Το κλειδί του προφίλ δεν είναι έγκυρο (λατινικά γράμματα, αριθμοί και -).', 'theme-patcher' ) ) );
		}
		if ( '' !== $p['maker'] && null === TP_Care::maker( $p['maker'] ) ) {
			$p['maker'] = '';
		}

		$msgs = array();
		foreach ( array( 'care', 'safety' ) as $field ) {
			foreach ( array( 'el', 'en' ) as $lang ) {
				$in = isset( $input[ $field ][ $lang ] ) ? trim( (string) $input[ $field ][ $lang ] ) : '';
				if ( '' !== $in && '' === $p[ $field ][ $lang ] ) {
					/* translators: %d: maximum length in characters */
					$msgs[] = self::msg( 'warning', sprintf( __( 'Ένα κείμενο ξεπερνά τους %d χαρακτήρες και δεν αποθηκεύτηκε.', 'theme-patcher' ), (int) TP_Care::MAX_HTML_LEN ) );
				}
			}
		}

		$found = false;
		foreach ( $c['profiles'] as $i => $old ) {
			if ( $old['key'] === $p['key'] ) {
				if ( '' === $orig ) {
					self::$back = '&view=profile';
					return array( self::msg( 'error', __( 'Υπάρχει ήδη προφίλ με αυτό το κλειδί.', 'theme-patcher' ) ) );
				}
				$c['profiles'][ $i ] = $p;
				$found               = true;
			}
		}
		if ( ! $found ) {
			if ( count( $c['profiles'] ) >= TP_Care::MAX_PROFILES ) {
				/* translators: %d: maximum number of profiles */
				return array( self::msg( 'error', sprintf( __( 'Έφτασες το όριο των %d προφίλ.', 'theme-patcher' ), (int) TP_Care::MAX_PROFILES ) ) );
			}
			$c['profiles'][] = $p;
		}
		TP_Care::save( $c );
		self::$back = '&view=profile&key=' . rawurlencode( $p['key'] );
		array_unshift( $msgs, self::msg( 'success', __( 'Το προφίλ αποθηκεύτηκε.', 'theme-patcher' ) ) );
		return $msgs;
	}

	private static function do_delete_profile(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in TP_Admin_UI::route().
		$key = isset( $_POST['tp_care_key'] ) ? TP_Care::valid_key( (string) wp_unslash( $_POST['tp_care_key'] ) ) : '';
		if ( ! self::confirmed() ) {
			self::$back = '&view=profile&key=' . rawurlencode( $key );
			return array( self::msg( 'error', __( 'Δεν επιβεβαιώθηκε η διαγραφή.', 'theme-patcher' ) ) );
		}
		$c    = TP_Care::get();
		$keep = array();
		foreach ( $c['profiles'] as $p ) {
			if ( $p['key'] !== $key ) {
				$keep[] = $p;
			}
		}
		if ( count( $keep ) === count( $c['profiles'] ) ) {
			return array( self::msg( 'error', __( 'Άγνωστο προφίλ.', 'theme-patcher' ) ) );
		}
		$c['profiles'] = $keep;
		TP_Care::save( $c );
		return array( self::msg( 'success', __( 'Το προφίλ διαγράφηκε.', 'theme-patcher' ) ) );
	}

	private static function do_save_makers(): array {
		$rows   = array_values( self::posted( 'tp_care_makers' ) );
		$c      = TP_Care::get();
		$makers = array();
		$bad    = 0;
		$emails = 0;
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || ! empty( $r['delete'] ) ) {
				continue;
			}
			$blank = '' === trim( implode( '', array_map( 'strval', array_intersect_key( $r, array_flip( array( 'key', 'name', 'address', 'email', 'url', 'eu' ) ) ) ) ) );
			if ( $blank ) {
				continue;
			}
			$m = TP_Care::sanitize_maker( $r );
			if ( null === $m ) {
				++$bad;
				continue;
			}
			if ( isset( $r['email'] ) && '' !== trim( (string) $r['email'] ) && '' === $m['email'] ) {
				++$emails;
			}
			$makers[] = $m;
		}
		$c['makers']       = $makers;
		$c['class_makers'] = array_filter( self::posted( 'tp_care_class_makers' ), 'is_string' );
		TP_Care::save( $c );

		$saved = count( TP_Care::get()['makers'] );
		$msgs  = array( self::msg( 'success', __( 'Οι κατασκευαστές αποθηκεύτηκαν.', 'theme-patcher' ) ) );
		if ( $bad ) {
			$msgs[] = self::msg( 'warning', __( 'Γραμμές χωρίς έγκυρο κλειδί αγνοήθηκαν.', 'theme-patcher' ) );
		}
		if ( $saved < count( $makers ) ) {
			$msgs[] = self::msg( 'warning', __( 'Κάθε κλειδί μπαίνει μία φορά: οι επαναλήψεις αγνοήθηκαν.', 'theme-patcher' ) );
		}
		if ( $emails ) {
			$msgs[] = self::msg( 'warning', __( 'Το email δεν είναι έγκυρο και αγνοήθηκε.', 'theme-patcher' ) );
		}
		return $msgs;
	}

	private static function do_restore(): array {
		if ( ! self::confirmed() ) {
			self::$back = '&view=restore';
			return array( self::msg( 'error', __( 'Δεν επιβεβαιώθηκε η επαναφορά.', 'theme-patcher' ) ) );
		}
		$c             = TP_Care::get();
		$c['profiles'] = TP_Care::default_profiles();
		TP_Care::save( $c );
		return array( self::msg( 'success', __( 'Τα προφίλ επανήλθαν στις προεπιλογές.', 'theme-patcher' ) ) );
	}

	/* =====================================================================
	 * Product edit screen: side box
	 * =================================================================== */

	public static function add_box(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		add_meta_box( 'tp-care', __( 'Φροντίδα και ασφάλεια', 'theme-patcher' ), array( __CLASS__, 'render_box' ), 'product', 'side', 'default' );
	}

	public static function render_box( $post ): void {
		$c       = TP_Care::get();
		$id      = (int) $post->ID;
		$profile = TP_Care::valid_choice( (string) get_post_meta( $id, TP_Care::META_PROFILE, true ) );
		$maker   = TP_Care::valid_choice( (string) get_post_meta( $id, TP_Care::META_MAKER, true ) );
		$note    = (string) get_post_meta( $id, TP_Care::META_NOTE, true );
		$product = wc_get_product( $id );
		$auto    = '';
		if ( $product instanceof WC_Product && 'auto-draft' !== $post->post_status ) {
			$r    = TP_Care::resolve( $product );
			$auto = null !== $r['profile']
				/* translators: 1: profile name, 2: manufacturer name or "none" */
				? sprintf( __( 'Τώρα: %1$s, κατασκευαστής %2$s.', 'theme-patcher' ), $r['profile']['label'], null !== $r['maker'] ? $r['maker']['name'] : __( 'κανένας', 'theme-patcher' ) )
				/* translators: %s: reason */
				: sprintf( __( 'Τώρα: καμία καρτέλα (%s).', 'theme-patcher' ), self::reason_label( $r['reason'] ) );
		}
		wp_nonce_field( self::BOX_NONCE, self::BOX_NONCE_FIELD );
		?>
		<p>
			<label for="tp-care-profile"><strong><?php esc_html_e( 'Προφίλ', 'theme-patcher' ); ?></strong></label><br />
			<select id="tp-care-profile" name="tp_care_profile" style="width:100%">
				<option value="" <?php selected( $profile, '' ); ?>><?php esc_html_e( 'Αυτόματο', 'theme-patcher' ); ?></option>
				<?php foreach ( $c['profiles'] as $p ) : ?>
					<option value="<?php echo esc_attr( $p['key'] ); ?>" <?php selected( $profile, $p['key'] ); ?>><?php echo esc_html( $p['label'] . ( $p['enabled'] ? '' : ' — ' . __( 'ανενεργό', 'theme-patcher' ) ) ); ?></option>
				<?php endforeach; ?>
				<option value="<?php echo esc_attr( TP_Care::NONE ); ?>" <?php selected( $profile, TP_Care::NONE ); ?>><?php esc_html_e( 'Κανένα (χωρίς καρτέλα)', 'theme-patcher' ); ?></option>
			</select>
		</p>
		<p>
			<label for="tp-care-maker"><strong><?php esc_html_e( 'Κατασκευαστής', 'theme-patcher' ); ?></strong></label><br />
			<select id="tp-care-maker" name="tp_care_maker" style="width:100%">
				<option value="" <?php selected( $maker, '' ); ?>><?php esc_html_e( 'Αυτόματο (κλάση αποστολής ή προφίλ)', 'theme-patcher' ); ?></option>
				<?php foreach ( $c['makers'] as $m ) : ?>
					<option value="<?php echo esc_attr( $m['key'] ); ?>" <?php selected( $maker, $m['key'] ); ?>><?php echo esc_html( $m['name'] ); ?></option>
				<?php endforeach; ?>
				<option value="<?php echo esc_attr( TP_Care::NONE ); ?>" <?php selected( $maker, TP_Care::NONE ); ?>><?php esc_html_e( 'Κανένας', 'theme-patcher' ); ?></option>
			</select>
		</p>
		<p>
			<label for="tp-care-note"><strong><?php esc_html_e( 'Επιπλέον σημείωση ασφάλειας', 'theme-patcher' ); ?></strong></label><br />
			<textarea id="tp-care-note" name="tp_care_note" rows="3" style="width:100%"><?php echo esc_textarea( $note ); ?></textarea>
		</p>
		<?php if ( '' !== $auto ) : ?>
			<p class="description"><?php echo esc_html( $auto ); ?></p>
		<?php endif; ?>
		<?php if ( ! $c['enabled'] ) : ?>
			<p class="description"><?php esc_html_e( 'Η καρτέλα είναι ανενεργή στις ρυθμίσεις του Theme Patcher.', 'theme-patcher' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function save_box( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! isset( $_POST[ self::BOX_NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::BOX_NONCE_FIELD ] ) ), self::BOX_NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$values = array(
			TP_Care::META_PROFILE => isset( $_POST['tp_care_profile'] ) ? TP_Care::valid_choice( sanitize_text_field( wp_unslash( (string) $_POST['tp_care_profile'] ) ) ) : '',
			TP_Care::META_MAKER   => isset( $_POST['tp_care_maker'] ) ? TP_Care::valid_choice( sanitize_text_field( wp_unslash( (string) $_POST['tp_care_maker'] ) ) ) : '',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_kses_post in TP_Care::kses().
			TP_Care::META_NOTE    => isset( $_POST['tp_care_note'] ) ? TP_Care::kses( wp_unslash( (string) $_POST['tp_care_note'] ), TP_Care::MAX_NOTE_LEN ) : '',
		);
		foreach ( $values as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}
}
