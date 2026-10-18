<?php
/**
 * CKE_Admin_UI — menu, settings page, PRG routes, backup (Bible §3–§8, §11).
 *
 * One page, submenu «Ταμείο» of the shared "Noxpress" menu (admin_menu
 * priority 210), slug cke-settings, with tabs:
 *  - gift      mode, position, fee and tax, gift message, where it shows,
 *              customer texts
 *  - thankyou  mode, message (paid / waiting for payment), WooCommerce's
 *              line, products, customer texts
 *  - coupon    mode, statuses, issue rules, the coupon, delivery, reminder,
 *              cleanup, customer texts
 *  - stats     coupons issued / used / revenue for a period, latest coupons
 *  - tools     visitor text language, settings export / import, uninstall
 *              option, cleanup now, filters and API
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation (CKE_Settings::sanitize), PRG with the message in the
 * transient cke_aui_msg_{uid}. Export: privileged GET (slug + nonce +
 * capability).
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'cke-settings';
	const NONCE       = 'cke_admin';
	const NONCE_FIELD = 'cke_nonce';
	const NONCE_BAK   = 'cke_backup';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 210 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CKE_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label msgid. */
	private static function tabs(): array {
		return array(
			'gift'     => __( 'Δώρο', 'checkout-extras' ),
			'thankyou' => __( 'Σελίδα ευχαριστίας', 'checkout-extras' ),
			'coupon'   => __( 'Κουπόνι', 'checkout-extras' ),
			'stats'    => __( 'Στατιστικά', 'checkout-extras' ),
			'tools'    => __( 'Εργαλεία', 'checkout-extras' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Checkout Extras', 'checkout-extras' ),
			__( 'Ταμείο', 'checkout-extras' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'checkout-extras' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/** A sanitized GET parameter (read-only routing). */
	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'gift';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'cke-admin', CKE_URL . 'assets/admin.css', array(), CKE_VERSION );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'checkout-extras' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap cke-wrap">
			<h1><?php esc_html_e( 'Checkout Extras', 'checkout-extras' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Δώρο στο ταμείο, μήνυμα και προϊόντα στη σελίδα ευχαριστίας, και κουπόνι ευχαριστίας για την επόμενη παραγγελία. Κάθε μέρος ενεργοποιείται χωριστά.', 'checkout-extras' ); ?></p>

			<?php
			if ( Checkout_Extras::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το CKE_DISABLE είναι ενεργό στο wp-config.php: τίποτα δεν εμφανίζεται στο ταμείο και στη σελίδα ευχαριστίας, και δεν δημιουργούνται κουπόνια.', 'checkout-extras' ) . '</p></div>';
			}
			self::print_notices( self::take_msgs() );
			?>

			<nav class="nav-tab-wrapper cke-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ),
						$slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'thankyou':
					self::render_thankyou();
					break;
				case 'coupon':
					self::render_coupon();
					break;
				case 'stats':
					self::render_stats();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_gift();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Field helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Mode radios (off / test / live).
	 *
	 * @param string $name  Field name.
	 * @param string $value Current mode.
	 * @param string $test  Test mode label.
	 */
	private static function mode_field( string $name, string $value, string $test ): void {
		$opts = array(
			'off'  => __( 'Ανενεργό', 'checkout-extras' ),
			'test' => $test,
			'live' => __( 'Ενεργό για όλους', 'checkout-extras' ),
		);
		foreach ( $opts as $k => $label ) {
			echo '<label class="cke-check"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
	}

	/** A checkbox line. */
	private static function check( string $name, bool $on, string $label ): void {
		echo '<label class="cke-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	/** A select line. */
	private static function select( string $name, string $value, array $opts, string $label ): void {
		echo '<p class="cke-field"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '">';
		foreach ( $opts as $k => $l ) {
			echo '<option value="' . esc_attr( (string) $k ) . '" ' . selected( $value, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p>';
	}

	/** A short input line (number or money). */
	private static function input( string $name, string $value, string $label, string $after = '', string $type = 'text' ): void {
		echo '<p class="cke-field"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" class="cke-num" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $type ? ' min="0"' : '' ) . ' />' . ( '' !== $after ? ' <span class="cke-muted">' . esc_html( $after ) . '</span>' : '' ) . '</p>';
	}

	/**
	 * The visitor texts table of a tab.
	 *
	 * @param array $rows key => label.
	 */
	private static function texts_table( array $rows, string $hint = '' ): void {
		$s = CKE_Settings::get();
		echo '<table class="widefat cke-table cke-texts"><thead><tr><th>' . esc_html__( 'Κείμενο', 'checkout-extras' ) . '</th><th>' . esc_html__( 'Ελληνικά', 'checkout-extras' ) . '</th><th>' . esc_html__( 'English', 'checkout-extras' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $key => $label ) {
			$spec = CKE_Settings::TEXTS[ $key ];
			echo '<tr><td>' . esc_html( $label ) . '</td>';
			foreach ( array( 'el', 'en' ) as $l ) {
				$n = 'cke_t[' . $key . '][' . $l . ']';
				if ( $spec[1] ) {
					echo '<td><textarea rows="3" name="' . esc_attr( $n ) . '" maxlength="' . (int) $spec[0] . '">' . esc_textarea( $s[ $key ][ $l ] ) . '</textarea></td>';
				} else {
					echo '<td><input type="text" name="' . esc_attr( $n ) . '" value="' . esc_attr( $s[ $key ][ $l ] ) . '" maxlength="' . (int) $spec[0] . '" /></td>';
				}
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description cke-mt-8">' . esc_html__( 'Κενό αγγλικό κείμενο = δείχνει το ελληνικό. Η γλώσσα των κειμένων ορίζεται στα Εργαλεία.', 'checkout-extras' ) . ( '' !== $hint ? ' ' . esc_html( $hint ) : '' ) . '</p>';
	}

	/** Product categories as checkboxes. */
	private static function cats_field( string $name, array $selected ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) || ! $terms ) {
			echo '<p class="description">' . esc_html__( 'Δεν υπάρχουν κατηγορίες προϊόντων.', 'checkout-extras' ) . '</p>';
			return;
		}
		echo '<div class="cke-scroll">';
		foreach ( $terms as $t ) {
			if ( $t instanceof WP_Term ) {
				echo '<label class="cke-check"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . (int) $t->term_id . '" ' . checked( in_array( (int) $t->term_id, $selected, true ), true, false ) . ' /> ' . esc_html( $t->name ) . ' <span class="cke-muted">(' . (int) $t->count . ')</span></label>';
			}
		}
		echo '</div>';
	}

	/** Comma list of product ids, with the product names under it. */
	private static function ids_field( string $name, array $ids, string $label ): void {
		echo '<label class="cke-label">' . esc_html( $label ) . '<input type="text" class="cke-ids" name="' . esc_attr( $name ) . '" value="' . esc_attr( implode( ', ', $ids ) ) . '" placeholder="12, 34" /></label>';
		if ( $ids ) {
			$names = array();
			foreach ( array_slice( $ids, 0, 20 ) as $id ) {
				$p       = wc_get_product( $id );
				$names[] = $p ? $p->get_name() . ' (#' . $id . ')' : '#' . $id . ' ?';
			}
			echo '<span class="cke-hint">' . esc_html( implode( ' · ', $names ) ) . '</span>';
		}
	}

	/** True when the checkout page holds the Checkout block. */
	private static function checkout_is_block(): bool {
		$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		return $id > 0 && has_block( 'woocommerce/checkout', $id );
	}

	/* ---------------------------------------------------------------------
	 * Tab: gift
	 * ------------------------------------------------------------------- */

	private static function render_gift(): void {
		$s = CKE_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="cke_action" value="save_gift" />

			<h2 class="cke-h2"><?php esc_html_e( 'Λειτουργία', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php self::mode_field( 'cke_g_mode', $s['g_mode'], __( 'Λειτουργία δοκιμής: το κουτάκι φαίνεται μόνο στους διαχειριστές του καταστήματος', 'checkout-extras' ) ); ?>
				<p class="description"><?php esc_html_e( 'Ο πελάτης τσεκάρει «Είναι δώρο» στο ταμείο. Η παραγγελία σημειώνεται ως δώρο στο admin, στα emails και στη σελίδα ευχαριστίας, μαζί με την αφιέρωση αν υπάρχει.', 'checkout-extras' ); ?></p>
				<?php
				self::select(
					'cke_g_position',
					$s['g_position'],
					array(
						'before_customer' => __( 'Πριν από τα στοιχεία του πελάτη', 'checkout-extras' ),
						'after_notes'     => __( 'Μετά τις σημειώσεις παραγγελίας', 'checkout-extras' ),
						'before_submit'   => __( 'Πριν από το κουμπί «Ολοκλήρωση παραγγελίας»', 'checkout-extras' ),
					),
					__( 'Θέση στο ταμείο', 'checkout-extras' )
				);
				self::check( 'cke_g_shippable_only', $s['g_shippable_only'], __( 'Μόνο όταν το καλάθι έχει προϊόν που αποστέλλεται (όχι για καλάθια μόνο με ψηφιακά προϊόντα)', 'checkout-extras' ) );
				?>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Χρέωση συσκευασίας', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php self::input( 'cke_g_fee', wc_format_localized_decimal( $s['g_fee'] ), __( 'Κόστος', 'checkout-extras' ), get_woocommerce_currency_symbol() . ' · ' . __( '0 = χωρίς χρέωση', 'checkout-extras' ) ); ?>
				<?php
				self::select(
					'cke_g_tax',
					$s['g_tax'],
					array(
						'none'    => __( 'Χωρίς φόρο', 'checkout-extras' ),
						'taxable' => __( 'Φορολογήσιμο', 'checkout-extras' ),
					),
					__( 'Φόρος', 'checkout-extras' )
				);
				$classes = array( '' => __( 'Βασικός συντελεστής', 'checkout-extras' ) );
				foreach ( WC_Tax::get_tax_classes() as $name ) {
					$classes[ sanitize_title( $name ) ] = $name;
				}
				if ( '' !== $s['g_tax_class'] && ! isset( $classes[ $s['g_tax_class'] ] ) ) {
					$classes[ $s['g_tax_class'] ] = $s['g_tax_class'];
				}
				self::select( 'cke_g_tax_class', $s['g_tax_class'], $classes, __( 'Κλάση φόρου', 'checkout-extras' ) );
				?>
				<p class="description"><?php esc_html_e( 'Με κόστος μπαίνει γραμμή χρέωσης στην παραγγελία (όνομα από τα κείμενα παρακάτω). Όταν οι τιμές του καταστήματος περιλαμβάνουν ΦΠΑ, το ποσό περιλαμβάνει κι αυτό ΦΠΑ.', 'checkout-extras' ); ?></p>
				<?php if ( self::checkout_is_block() ) : ?>
					<p class="cke-warn"><?php esc_html_e( 'Η σελίδα ταμείου σου είναι το Checkout block: εκεί το δώρο εμφανίζεται μόνο χωρίς κόστος (το block δεν δέχεται χρέωση από ένα πεδίο). Με κόστος, χρησιμοποίησε το κλασικό ταμείο [woocommerce_checkout].', 'checkout-extras' ); ?></p>
				<?php endif; ?>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Αφιέρωση', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php
				self::select(
					'cke_g_msg',
					$s['g_msg'],
					array(
						'off'      => __( 'Χωρίς αφιέρωση', 'checkout-extras' ),
						'optional' => __( 'Προαιρετική', 'checkout-extras' ),
						'required' => __( 'Υποχρεωτική όταν είναι δώρο', 'checkout-extras' ),
					),
					__( 'Πεδίο αφιέρωσης', 'checkout-extras' )
				);
				self::input( 'cke_g_max', (string) $s['g_max'], __( 'Μέγιστο μήκος', 'checkout-extras' ), __( 'χαρακτήρες (20–1000)', 'checkout-extras' ), 'number' );
				?>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Για το κατάστημα', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php
				self::check( 'cke_g_admin_line', $s['g_admin_line'], __( 'Γραμμή «🎁 Δώρο» και η αφιέρωση στην κορυφή του email «Νέα παραγγελία» του διαχειριστή', 'checkout-extras' ) );
				self::check( 'cke_g_column', $s['g_column'], __( 'Στήλη 🎁 στη λίστα παραγγελιών (με φίλτρο «Μόνο δώρα»)', 'checkout-extras' ) );
				?>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Κείμενα για τον πελάτη', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php
				self::texts_table(
					array(
						'g_label'        => __( 'Το κουτάκι στο ταμείο', 'checkout-extras' ),
						'g_help'         => __( 'Βοήθεια κάτω από το κουτάκι (προαιρετικό, π.χ. «Δεν βάζουμε απόδειξη στο δέμα»)', 'checkout-extras' ),
						'g_fee_name'     => __( 'Όνομα της χρέωσης στην παραγγελία', 'checkout-extras' ),
						'g_msg_label'    => __( 'Ετικέτα της αφιέρωσης', 'checkout-extras' ),
						'g_msg_required' => __( 'Σφάλμα: λείπει η αφιέρωση', 'checkout-extras' ),
						'g_msg_long'     => __( 'Σφάλμα: η αφιέρωση είναι μεγάλη ({max} = το όριο)', 'checkout-extras' ),
						'g_row'          => __( 'Γραμμή στην παραγγελία και στα emails', 'checkout-extras' ),
						'g_yes'          => __( 'Τιμή της γραμμής', 'checkout-extras' ),
					)
				);
				?>
			</div>
			<p class="cke-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'checkout-extras' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: thank-you page
	 * ------------------------------------------------------------------- */

	private static function render_thankyou(): void {
		$s = CKE_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="cke_action" value="save_thankyou" />

			<h2 class="cke-h2"><?php esc_html_e( 'Λειτουργία', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php self::mode_field( 'cke_t_mode', $s['t_mode'], __( 'Λειτουργία δοκιμής: το μήνυμα και τα προϊόντα φαίνονται μόνο στους διαχειριστές του καταστήματος', 'checkout-extras' ) ); ?>
				<p class="description"><?php esc_html_e( 'Το μήνυμα και τα προϊόντα μπαίνουν μέσα στη σελίδα ευχαριστίας του θέματος και του WooCommerce, χωρίς αλλαγή στο layout. Δουλεύουν και στο Order confirmation των block themes.', 'checkout-extras' ); ?></p>
				<?php self::check( 'cke_t_replace', $s['t_replace'], __( 'Το μήνυμα στη θέση του «Ευχαριστούμε. Η παραγγελία σας ελήφθη.» του WooCommerce (αλλιώς σε δικό του κουτί από πάνω)', 'checkout-extras' ) ); ?>
				<?php
				$order = wc_get_orders(
					array(
						'limit'   => 1,
						'type'    => 'shop_order',
						'status'  => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
						'orderby' => 'date',
						'order'   => 'DESC',
					)
				);
				if ( $order && $order[0] instanceof WC_Order ) {
					echo '<p class="cke-mt-8"><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $order[0]->get_checkout_order_received_url() ) . '">' . esc_html(
						sprintf(
							/* translators: %s: order number */
							__( 'Προεπισκόπηση με την παραγγελία #%s', 'checkout-extras' ),
							$order[0]->get_order_number()
						)
					) . '</a></p>';
					echo '<p class="description">' . esc_html__( 'Η προεπισκόπηση δεν αλλάζει τίποτα και δεν δημιουργεί κουπόνι.', 'checkout-extras' ) . '</p>';
				}
				?>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Μήνυμα', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<p class="description"><?php esc_html_e( 'Placeholders: {first_name}, {order_number}, {order_total}, {shop_name}, {email}. Η πρόταση με placeholder χωρίς τιμή φεύγει ολόκληρη. Αλλαγές γραμμής κρατιούνται. Το «αναμένεται πληρωμή» ισχύει για εκκρεμείς παραγγελίες και παραγγελίες σε αναμονή (π.χ. τραπεζική κατάθεση)· κενό = το μήνυμα της πληρωμένης.', 'checkout-extras' ); ?></p>
				<?php
				self::texts_table(
					array(
						't_paid_title' => __( 'Πληρωμένη παραγγελία: τίτλος', 'checkout-extras' ),
						't_paid_body'  => __( 'Πληρωμένη παραγγελία: κείμενο', 'checkout-extras' ),
						't_hold_title' => __( 'Αναμένεται πληρωμή: τίτλος', 'checkout-extras' ),
						't_hold_body'  => __( 'Αναμένεται πληρωμή: κείμενο', 'checkout-extras' ),
					)
				);
				?>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Προϊόντα', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php
				self::select(
					'cke_t_products',
					$s['t_products'],
					array(
						'none'    => __( 'Κανένα', 'checkout-extras' ),
						'cross'   => __( 'Cross-sells των προϊόντων της παραγγελίας', 'checkout-extras' ),
						'related' => __( 'Σχετικά προϊόντα', 'checkout-extras' ),
						'sale'    => __( 'Προϊόντα σε προσφορά', 'checkout-extras' ),
					),
					__( 'Μετά τα στοιχεία της παραγγελίας', 'checkout-extras' )
				);
				self::input( 'cke_t_limit', (string) $s['t_limit'], __( 'Πλήθος', 'checkout-extras' ), '1–8', 'number' );
				?>
				<p class="description"><?php esc_html_e( 'Με τις κάρτες προϊόντων του θέματος. Δεν δείχνει όσα αγόρασε ήδη ο πελάτης ούτε εξαντλημένα.', 'checkout-extras' ); ?></p>
				<?php self::texts_table( array( 't_products_title' => __( 'Τίτλος της ενότητας', 'checkout-extras' ) ) ); ?>
			</div>
			<p class="cke-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'checkout-extras' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: coupon
	 * ------------------------------------------------------------------- */

	private static function render_coupon(): void {
		$s = CKE_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="cke_action" value="save_coupon" />

			<h2 class="cke-h2"><?php esc_html_e( 'Λειτουργία', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php self::mode_field( 'cke_c_mode', $s['c_mode'], __( 'Λειτουργία δοκιμής: κουπόνια μόνο για παραγγελίες λογαριασμών διαχειριστή του καταστήματος', 'checkout-extras' ) ); ?>
				<p class="description"><?php esc_html_e( 'Ο πελάτης παίρνει ένα μοναδικό κουπόνι για την επόμενη παραγγελία του, όταν η παραγγελία του φτάσει σε μία από τις καταστάσεις παρακάτω. Ποτέ σε απλήρωτες, αποτυχημένες ή ακυρωμένες παραγγελίες.', 'checkout-extras' ); ?></p>
				<p class="cke-label"><?php esc_html_e( 'Δημιουργία όταν η παραγγελία γίνει', 'checkout-extras' ); ?></p>
				<div class="cke-statuses">
				<?php
				$labels = wc_get_order_statuses();
				foreach ( CKE_Settings::STATUSES as $st ) {
					$l = isset( $labels[ 'wc-' . $st ] ) ? $labels[ 'wc-' . $st ] : $st;
					echo '<label class="cke-check"><input type="checkbox" name="cke_c_statuses[]" value="' . esc_attr( $st ) . '" ' . checked( in_array( $st, $s['c_statuses'], true ), true, false ) . ' /> ' . esc_html( $l ) . '</label>';
				}
				?>
				</div>
				<p class="description"><?php esc_html_e( 'Το «Σε αναμονή» (π.χ. τραπεζική κατάθεση, αντικαταβολή) σημαίνει κουπόνι πριν από την πληρωμή: άφησέ το κλειστό, εκτός αν το θέλεις.', 'checkout-extras' ); ?></p>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Ποιος παίρνει κουπόνι', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php
				self::input( 'cke_c_min_total', '' === $s['c_min_total'] ? '' : wc_format_localized_decimal( $s['c_min_total'] ), __( 'Ελάχιστο σύνολο παραγγελίας', 'checkout-extras' ), get_woocommerce_currency_symbol() . ' · ' . __( 'κενό = χωρίς όριο', 'checkout-extras' ) );
				self::check( 'cke_c_skip_used', $s['c_skip_used'], __( 'Όχι σε παραγγελία που χρησιμοποίησε ήδη κουπόνι ευχαριστίας', 'checkout-extras' ) );
				self::check( 'cke_c_one_active', $s['c_one_active'], __( 'Ένα ενεργό κουπόνι ανά email: όχι νέο όσο το προηγούμενο δεν έχει χρησιμοποιηθεί ή λήξει', 'checkout-extras' ) );
				self::input( 'cke_c_days_between', (string) $s['c_days_between'], __( 'Ημέρες ανάμεσα σε δύο κουπόνια', 'checkout-extras' ), __( 'ανά email · 0 = χωρίς όριο', 'checkout-extras' ), 'number' );
				self::check( 'cke_c_first_only', $s['c_first_only'], __( 'Μόνο στην πρώτη παραγγελία ενός email', 'checkout-extras' ) );
				?>
				<p class="cke-label"><?php esc_html_e( 'Όχι για παραγγελίες που έχουν μόνο προϊόντα από αυτές τις κατηγορίες', 'checkout-extras' ); ?></p>
				<?php self::cats_field( 'cke_c_skip_cats', $s['c_skip_cats'] ); ?>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Το κουπόνι', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php
				self::select(
					'cke_c_type',
					$s['c_type'],
					array(
						'percent'    => __( 'Ποσοστό στο καλάθι', 'checkout-extras' ),
						'fixed_cart' => __( 'Σταθερό ποσό στο καλάθι', 'checkout-extras' ),
					),
					__( 'Τύπος έκπτωσης', 'checkout-extras' )
				);
				self::input( 'cke_c_amount', wc_format_localized_decimal( $s['c_amount'] ), __( 'Έκπτωση', 'checkout-extras' ), __( '% ή ποσό', 'checkout-extras' ) );
				self::input( 'cke_c_expiry', (string) $s['c_expiry'], __( 'Λήξη', 'checkout-extras' ), __( 'ημέρες μετά τη δημιουργία', 'checkout-extras' ), 'number' );
				self::input( 'cke_c_min_spend', '' === $s['c_min_spend'] ? '' : wc_format_localized_decimal( $s['c_min_spend'] ), __( 'Ελάχιστη δαπάνη', 'checkout-extras' ), get_woocommerce_currency_symbol() );
				self::input( 'cke_c_max_spend', '' === $s['c_max_spend'] ? '' : wc_format_localized_decimal( $s['c_max_spend'] ), __( 'Μέγιστη δαπάνη', 'checkout-extras' ), get_woocommerce_currency_symbol() );
				self::input( 'cke_c_limit_items', (string) $s['c_limit_items'], __( 'Μόνο σε τόσα τεμάχια', 'checkout-extras' ), __( '0 = σε όλο το καλάθι', 'checkout-extras' ), 'number' );
				self::check( 'cke_c_free_ship', $s['c_free_ship'], __( 'Δίνει και δωρεάν αποστολή', 'checkout-extras' ) );
				self::check( 'cke_c_individual', $s['c_individual'], __( 'Μόνο του: δεν συνδυάζεται με άλλα κουπόνια', 'checkout-extras' ) );
				self::check( 'cke_c_exclude_sale', $s['c_exclude_sale'], __( 'Όχι σε προϊόντα σε προσφορά', 'checkout-extras' ) );
				self::check( 'cke_c_email_restrict', $s['c_email_restrict'], __( 'Μόνο για το email χρέωσης της παραγγελίας', 'checkout-extras' ) );
				self::input( 'cke_c_prefix', $s['c_prefix'], __( 'Πρόθεμα κωδικού', 'checkout-extras' ), __( 'έως 10 πεζά λατινικά, ψηφία ή «-», και 8 τυχαίοι χαρακτήρες', 'checkout-extras' ) );
				?>
				<p class="description"><?php esc_html_e( 'Κάθε κουπόνι είναι ένα κανονικό κουπόνι του WooCommerce, μίας χρήσης. Οι αλλαγές εδώ ισχύουν για τα νέα κουπόνια, όχι για όσα έχουν ήδη δοθεί.', 'checkout-extras' ); ?></p>
				<details class="cke-details">
					<summary><?php esc_html_e( 'Περιορισμοί σε προϊόντα και κατηγορίες', 'checkout-extras' ); ?></summary>
					<?php
					self::ids_field( 'cke_c_products', $s['c_products'], __( 'Μόνο για αυτά τα προϊόντα (ids, με κόμμα)', 'checkout-extras' ) );
					self::ids_field( 'cke_c_ex_products', $s['c_ex_products'], __( 'Όχι για αυτά τα προϊόντα (ids, με κόμμα)', 'checkout-extras' ) );
					?>
					<p class="cke-label"><?php esc_html_e( 'Μόνο για αυτές τις κατηγορίες', 'checkout-extras' ); ?></p>
					<?php self::cats_field( 'cke_c_cats', $s['c_cats'] ); ?>
					<p class="cke-label"><?php esc_html_e( 'Όχι για αυτές τις κατηγορίες', 'checkout-extras' ); ?></p>
					<?php self::cats_field( 'cke_c_ex_cats', $s['c_ex_cats'] ); ?>
				</details>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Πώς φτάνει στον πελάτη', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<p class="description"><?php esc_html_e( 'Στη σελίδα ευχαριστίας φαίνεται πάντα, με κουμπί αντιγραφής. Για το email:', 'checkout-extras' ); ?></p>
				<?php
				foreach ( array(
					'order'    => __( 'Μέσα στο email της παραγγελίας που στέλνει ήδη το WooCommerce (προτείνεται)', 'checkout-extras' ),
					'separate' => __( 'Χωριστό email «Κουπόνι ευχαριστίας» (WooCommerce › Ρυθμίσεις › Emails)', 'checkout-extras' ),
					'none'     => __( 'Χωρίς email', 'checkout-extras' ),
				) as $k => $label ) {
					echo '<label class="cke-check"><input type="radio" name="cke_c_email" value="' . esc_attr( $k ) . '" ' . checked( $s['c_email'], $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
				}
				?>
				<p class="cke-label"><?php esc_html_e( 'Emails της παραγγελίας που το δείχνουν (όσο είναι ενεργό)', 'checkout-extras' ); ?></p>
				<div class="cke-statuses">
				<?php
				$names = array(
					'customer_processing_order' => __( 'Παραγγελία σε επεξεργασία', 'checkout-extras' ),
					'customer_completed_order'  => __( 'Ολοκληρωμένη παραγγελία', 'checkout-extras' ),
					'customer_on_hold_order'    => __( 'Παραγγελία σε αναμονή', 'checkout-extras' ),
				);
				foreach ( CKE_Settings::EMAIL_IDS as $id ) {
					echo '<label class="cke-check"><input type="checkbox" name="cke_c_email_ids[]" value="' . esc_attr( $id ) . '" ' . checked( in_array( $id, $s['c_email_ids'], true ), true, false ) . ' /> ' . esc_html( $names[ $id ] ) . '</label>';
				}
				?>
				</div>
				<?php
				self::check( 'cke_c_remind', $s['c_remind'], __( 'Υπενθύμιση με email πριν λήξει ένα κουπόνι που δεν χρησιμοποιήθηκε', 'checkout-extras' ) );
				self::input( 'cke_c_remind_days', (string) $s['c_remind_days'], __( 'Πόσες ημέρες πριν', 'checkout-extras' ), '1–60', 'number' );
				?>
				<p class="description"><?php esc_html_e( 'Η υπενθύμιση προγραμματίζεται όταν δημιουργείται το κουπόνι (Action Scheduler του WooCommerce) και στέλνεται μόνο αν το κουπόνι είναι ακόμα αχρησιμοποίητο.', 'checkout-extras' ); ?></p>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Καθαρισμός', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php
				self::check( 'cke_c_cleanup', $s['c_cleanup'], __( 'Διαγραφή των ληγμένων κουπονιών ευχαριστίας που δεν χρησιμοποιήθηκαν', 'checkout-extras' ) );
				self::input( 'cke_c_cleanup_days', (string) $s['c_cleanup_days'], __( 'Μετά από', 'checkout-extras' ), __( 'ημέρες από τη λήξη', 'checkout-extras' ), 'number' );
				?>
				<p class="description"><?php esc_html_e( 'Μία φορά την ημέρα. Μόνο κουπόνια που έφτιαξε αυτό το plugin· κανένα άλλο κουπόνι δεν αγγίζεται. Τα στατιστικά μένουν, γιατί διαβάζονται από τις παραγγελίες.', 'checkout-extras' ); ?></p>
			</div>

			<h2 class="cke-h2"><?php esc_html_e( 'Κείμενα για τον πελάτη', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<p class="description"><?php esc_html_e( 'Placeholders: {amount} (π.χ. 10% ή 5,00 €), {code}, {expiry}, {min_spend}, {first_name}, {shop_name}. Η πρόταση με placeholder χωρίς τιμή φεύγει ολόκληρη (π.χ. «Για αγορές από {min_spend}.» χωρίς ελάχιστη δαπάνη).', 'checkout-extras' ); ?></p>
				<?php
				self::texts_table(
					array(
						'c_title'       => __( 'Τίτλος (σελίδα ευχαριστίας και email)', 'checkout-extras' ),
						'c_body'        => __( 'Κείμενο (σελίδα ευχαριστίας και email)', 'checkout-extras' ),
						'c_pending'     => __( 'Αναμένεται πληρωμή: το κουπόνι έρχεται με email', 'checkout-extras' ),
						'c_copy'        => __( 'Κουμπί αντιγραφής', 'checkout-extras' ),
						'c_copied'      => __( 'Μετά την αντιγραφή', 'checkout-extras' ),
						'c_remind_body' => __( 'Κείμενο της υπενθύμισης', 'checkout-extras' ),
					),
					__( 'Θέμα και επικεφαλίδα των emails αλλάζουν στο WooCommerce › Ρυθμίσεις › Emails.', 'checkout-extras' )
				);
				?>
			</div>
			<p class="cke-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'checkout-extras' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: stats
	 * ------------------------------------------------------------------- */

	private static function render_stats(): void {
		$tz   = wp_timezone();
		$to   = self::date_q( 'to', ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' ) );
		$from = self::date_q( 'from', ( new DateTimeImmutable( 'now', $tz ) )->modify( '-89 days' )->format( 'Y-m-d' ) );
		if ( $from > $to ) {
			$tmp  = $from;
			$from = $to;
			$to   = $tmp;
		}
		$st   = CKE_Coupon::stats( $from, $to );
		$rate = $st['issued'] > 0 ? round( 100 * $st['used'] / $st['issued'], 1 ) : 0;
		?>
		<h2 class="cke-h2"><?php esc_html_e( 'Κουπόνια ευχαριστίας', 'checkout-extras' ); ?></h2>
		<form method="get" class="cke-period">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="stats" />
			<label><?php esc_html_e( 'Από', 'checkout-extras' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /></label>
			<label><?php esc_html_e( 'Έως', 'checkout-extras' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" /></label>
			<button type="submit" class="button"><?php esc_html_e( 'Εμφάνιση', 'checkout-extras' ); ?></button>
		</form>
		<p class="description"><?php esc_html_e( 'Ανά ημερομηνία της παραγγελίας που έδωσε το κουπόνι. «Χρησιμοποιήθηκαν» = κουπόνια που μπήκαν σε πληρωμένη παραγγελία.', 'checkout-extras' ); ?></p>

		<div class="cke-grid-kpi">
			<div class="cke-card"><div class="cke-card-label"><?php esc_html_e( 'Εκδόθηκαν', 'checkout-extras' ); ?></div><div class="cke-card-value"><?php echo esc_html( number_format_i18n( $st['issued'] ) ); ?></div></div>
			<div class="cke-card"><div class="cke-card-label"><?php esc_html_e( 'Χρησιμοποιήθηκαν', 'checkout-extras' ); ?></div><div class="cke-card-value"><?php echo esc_html( number_format_i18n( $st['used'] ) ); ?></div><div class="cke-card-sub"><?php echo esc_html( number_format_i18n( $rate, 1 ) . '%' ); ?></div></div>
			<div class="cke-card"><div class="cke-card-label"><?php esc_html_e( 'Τζίρος παραγγελιών με κουπόνι', 'checkout-extras' ); ?></div><div class="cke-card-value"><?php echo wp_kses_post( wc_price( $st['revenue'] ) ); ?></div></div>
			<div class="cke-card"><div class="cke-card-label"><?php esc_html_e( 'Συνολική έκπτωση', 'checkout-extras' ); ?></div><div class="cke-card-value"><?php echo wp_kses_post( wc_price( $st['discount'] ) ); ?></div></div>
		</div>

		<?php if ( ! $st['rows'] ) : ?>
			<p class="cke-empty"><?php esc_html_e( 'Κανένα κουπόνι ευχαριστίας σε αυτή την περίοδο.', 'checkout-extras' ); ?></p>
		<?php else : ?>
			<h2 class="cke-h2"><?php esc_html_e( 'Τελευταία κουπόνια', 'checkout-extras' ); ?></h2>
			<table class="widefat cke-table">
				<thead><tr>
					<th><?php esc_html_e( 'Κωδικός', 'checkout-extras' ); ?></th>
					<th><?php esc_html_e( 'Από την παραγγελία', 'checkout-extras' ); ?></th>
					<th><?php esc_html_e( 'Email', 'checkout-extras' ); ?></th>
					<th><?php esc_html_e( 'Λήξη', 'checkout-extras' ); ?></th>
					<th><?php esc_html_e( 'Κατάσταση', 'checkout-extras' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $st['rows'] as $r ) {
					$o = $r['order'];
					switch ( $r['state'] ) {
						case 'used':
							$badge = '<span class="cke-badge cke-badge--ok">' . esc_html__( 'χρησιμοποιήθηκε', 'checkout-extras' ) . '</span>';
							if ( $r['used_in'] instanceof WC_Order ) {
								$badge .= ' <a href="' . esc_url( $r['used_in']->get_edit_order_url() ) . '">#' . esc_html( $r['used_in']->get_order_number() ) . '</a>';
							}
							break;
						case 'expired':
							$badge = '<span class="cke-badge cke-badge--muted">' . esc_html__( 'έληξε', 'checkout-extras' ) . '</span>';
							break;
						case 'deleted':
							$badge = '<span class="cke-badge cke-badge--muted">' . esc_html__( 'διαγράφηκε', 'checkout-extras' ) . '</span>';
							break;
						default:
							$badge = '<span class="cke-badge cke-badge--warn">' . esc_html__( 'ενεργό', 'checkout-extras' ) . '</span>';
					}
					echo '<tr><td><code>' . esc_html( strtoupper( $r['code'] ) ) . '</code></td><td><a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a></td><td>' . esc_html( $r['email'] ) . '</td><td>' . esc_html( $r['expiry'] ) . '</td><td>' . $badge . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge escaped above.
				}
				?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/** A Y-m-d GET parameter, else the default. */
	private static function date_q( string $key, string $default ): string {
		$v = self::q( $key );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && checkdate( (int) substr( $v, 5, 2 ), (int) substr( $v, 8, 2 ), (int) substr( $v, 0, 4 ) ) ? $v : $default;
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		$s = CKE_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="cke_action" value="save_tools" />
			<h2 class="cke-h2"><?php esc_html_e( 'Γλώσσα και uninstall', 'checkout-extras' ); ?></h2>
			<div class="cke-zone">
				<?php
				self::select(
					'cke_lang',
					$s['lang'],
					array(
						'auto' => __( 'Αυτόματα (γλώσσα του site)', 'checkout-extras' ),
						'el'   => __( 'Ελληνικά', 'checkout-extras' ),
						'en'   => __( 'English', 'checkout-extras' ),
					),
					__( 'Γλώσσα κειμένων για τον πελάτη', 'checkout-extras' )
				);
				self::check( 'cke_c_delete_on_uninstall', $s['c_delete_on_uninstall'], __( 'Διαγραφή των κουπονιών ευχαριστίας (και των ενεργών) όταν διαγραφεί το plugin', 'checkout-extras' ) );
				?>
				<p class="description"><?php esc_html_e( 'Χωρίς αυτή την επιλογή τα κουπόνια μένουν, γιατί οι πελάτες κρατούν τους κωδικούς. Το «δώρο» και ο κωδικός πάνω στις παραγγελίες μένουν πάντα: είναι μέρος της παραγγελίας.', 'checkout-extras' ); ?></p>
			</div>
			<p class="cke-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'checkout-extras' ); ?></button></p>
		</form>

		<h2 class="cke-h2"><?php esc_html_e( 'Καθαρισμός τώρα', 'checkout-extras' ); ?></h2>
		<div class="cke-zone">
			<form method="post" class="cke-inline-form">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="cke_action" value="cleanup" />
				<button type="submit" class="button"><?php esc_html_e( 'Διαγραφή ληγμένων αχρησιμοποίητων κουπονιών ευχαριστίας', 'checkout-extras' ); ?></button>
			</form>
			<p class="description cke-mt-8"><?php esc_html_e( 'Με τον κανόνα της καρτέλας Κουπόνι (ημέρες μετά τη λήξη), έως 50 κάθε φορά.', 'checkout-extras' ); ?></p>
		</div>

		<h2 class="cke-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'checkout-extras' ); ?></h2>
		<div class="cke-zone">
			<form method="post" enctype="multipart/form-data" class="cke-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'cke_backup_nonce' ); ?>
				<input type="file" name="cke_import_file" accept=".json,application/json" />
				<button type="submit" name="cke_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'checkout-extras' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&cke_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'checkout-extras' ); ?></a>
			<p class="description cke-mt-8"><?php esc_html_e( 'Το backup έχει όλες τις ρυθμίσεις και τα κείμενα. Τα θέματα των emails μένουν στο WooCommerce.', 'checkout-extras' ); ?></p>
		</div>

		<h2 class="cke-h2"><?php esc_html_e( 'Για προγραμματιστές', 'checkout-extras' ); ?></h2>
		<div class="cke-zone">
			<table class="widefat cke-table">
				<tbody>
					<tr><td><code>cke_get_gift( $order )</code></td><td><?php esc_html_e( 'Το δώρο μιας παραγγελίας (gift, message, fee), ή null αν δεν είναι δώρο.', 'checkout-extras' ); ?></td></tr>
					<tr><td><code>cke_coupon_args( $args, $order )</code></td><td><?php esc_html_e( 'Φίλτρο: οι ιδιότητες του κουπονιού πριν δημιουργηθεί· false = κανένα κουπόνι.', 'checkout-extras' ); ?></td></tr>
					<tr><td><code>cke_coupon_created( $coupon, $order )</code></td><td><?php esc_html_e( 'Action μετά τη δημιουργία ενός κουπονιού ευχαριστίας.', 'checkout-extras' ); ?></td></tr>
					<tr><td><code>cke_text</code></td><td><?php esc_html_e( 'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).', 'checkout-extras' ); ?></td></tr>
					<tr><td><code>_cke_gift</code>, <code>_cke_gift_message</code>, <code>_cke_coupon_code</code></td><td><?php esc_html_e( 'Order meta: δώρο, αφιέρωση και ο κωδικός που πήρε η παραγγελία.', 'checkout-extras' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		// Import (own nonce, multipart form).
		if ( isset( $_POST['cke_import'] ) ) {
			if ( ! isset( $_POST['cke_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['cke_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'checkout-extras' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['cke_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'checkout-extras' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['cke_action'] ) ) ) {
			case 'save_gift':
				self::redirect( 'gift', self::do_save_gift() );
				break;
			case 'save_thankyou':
				self::redirect( 'thankyou', self::do_save_thankyou() );
				break;
			case 'save_coupon':
				self::redirect( 'coupon', self::do_save_coupon() );
				break;
			case 'save_tools':
				self::redirect( 'tools', self::do_save_tools() );
				break;
			case 'cleanup':
				$n = CKE_Coupon::cleanup();
				self::redirect(
					'tools',
					array(
						self::msg(
							'success',
							sprintf(
								/* translators: %d: number of coupons */
								__( 'Διαγράφηκαν %d κουπόνια.', 'checkout-extras' ),
								$n
							)
						),
					)
				);
				break;
			default:
				self::redirect( 'gift', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'checkout-extras' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'cke_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by CKE_Settings::sanitize()). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by CKE_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	/**
	 * Copies the posted values of a tab into the settings.
	 *
	 * @param array $raw    Current settings.
	 * @param array $plain  Setting keys read as scalars.
	 * @param array $flags  Setting keys read as checkboxes.
	 * @param array $lists  Setting keys read as arrays (missing = empty).
	 * @param array $texts  Text keys of the tab.
	 */
	private static function collect( array $raw, array $plain, array $flags, array $lists, array $texts ): array {
		foreach ( $plain as $k ) {
			$raw[ $k ] = self::post( 'cke_' . $k );
		}
		foreach ( $flags as $k ) {
			$raw[ $k ] = '1' === self::post( 'cke_' . $k );
		}
		foreach ( $lists as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
			$raw[ $k ] = isset( $_POST[ 'cke_' . $k ] ) && is_array( $_POST[ 'cke_' . $k ] ) ? self::post_array( 'cke_' . $k ) : array();
		}
		$t = self::post_array( 'cke_t' );
		foreach ( $texts as $k ) {
			if ( isset( $t[ $k ] ) && is_array( $t[ $k ] ) ) {
				$raw[ $k ] = array(
					'el' => isset( $t[ $k ]['el'] ) && is_string( $t[ $k ]['el'] ) ? $t[ $k ]['el'] : '',
					'en' => isset( $t[ $k ]['en'] ) && is_string( $t[ $k ]['en'] ) ? $t[ $k ]['en'] : '',
				);
			}
		}
		return $raw;
	}

	/** The success message plus a warning for test mode / off. */
	private static function saved( string $mode ): array {
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'checkout-extras' ) ) );
		if ( 'test' === $mode ) {
			$msgs[] = self::msg( 'info', __( 'Λειτουργία δοκιμής: οι πελάτες δεν βλέπουν ακόμα τίποτα.', 'checkout-extras' ) );
		}
		return $msgs;
	}

	private static function do_save_gift(): array {
		$raw = self::collect(
			CKE_Settings::get(),
			array( 'g_mode', 'g_position', 'g_fee', 'g_tax', 'g_tax_class', 'g_msg', 'g_max' ),
			array( 'g_shippable_only', 'g_admin_line', 'g_column' ),
			array(),
			array( 'g_label', 'g_help', 'g_fee_name', 'g_msg_label', 'g_msg_required', 'g_msg_long', 'g_row', 'g_yes' )
		);
		$fee = $raw['g_fee'];
		CKE_Settings::save( $raw );
		$s    = CKE_Settings::get();
		$msgs = self::saved( $s['g_mode'] );
		if ( '' !== trim( $fee ) && wc_format_decimal( str_replace( ',', '.', $fee ) ) !== wc_format_decimal( $s['g_fee'] ) ) {
			$msgs[] = self::msg( 'warning', __( 'Το κόστος δεν ήταν έγκυρο ποσό και κρατήθηκε το προηγούμενο.', 'checkout-extras' ) );
		}
		if ( 'off' !== $s['g_mode'] && CKE_Settings::fee() > 0 && self::checkout_is_block() ) {
			$msgs[] = self::msg( 'warning', __( 'Το ταμείο σου είναι το Checkout block: με κόστος το δώρο δεν εμφανίζεται εκεί.', 'checkout-extras' ) );
		}
		return $msgs;
	}

	private static function do_save_thankyou(): array {
		$raw = self::collect(
			CKE_Settings::get(),
			array( 't_mode', 't_products', 't_limit' ),
			array( 't_replace' ),
			array(),
			array( 't_paid_title', 't_paid_body', 't_hold_title', 't_hold_body', 't_products_title' )
		);
		CKE_Settings::save( $raw );
		return self::saved( CKE_Settings::get()['t_mode'] );
	}

	private static function do_save_coupon(): array {
		$raw              = self::collect(
			CKE_Settings::get(),
			array( 'c_mode', 'c_min_total', 'c_days_between', 'c_type', 'c_amount', 'c_expiry', 'c_min_spend', 'c_max_spend', 'c_limit_items', 'c_prefix', 'c_email', 'c_remind_days', 'c_cleanup_days', 'c_products', 'c_ex_products' ),
			array( 'c_skip_used', 'c_one_active', 'c_first_only', 'c_free_ship', 'c_individual', 'c_exclude_sale', 'c_email_restrict', 'c_remind', 'c_cleanup' ),
			array( 'c_statuses', 'c_skip_cats', 'c_cats', 'c_ex_cats', 'c_email_ids' ),
			array( 'c_title', 'c_body', 'c_pending', 'c_copy', 'c_copied', 'c_remind_body' )
		);
		$prefix           = $raw['c_prefix'];
		$raw['c_prefix']  = strtolower( trim( $prefix ) );
		CKE_Settings::save( $raw );
		$s    = CKE_Settings::get();
		$msgs = self::saved( $s['c_mode'] );
		if ( $s['c_prefix'] !== strtolower( trim( $prefix ) ) ) {
			$msgs[] = self::msg( 'warning', __( 'Το πρόθεμα κρατήθηκε ως είχε: έως 10 πεζά λατινικά, ψηφία ή «-».', 'checkout-extras' ) );
		}
		if ( 'off' !== $s['c_mode'] && ! $s['c_statuses'] ) {
			$msgs[] = self::msg( 'warning', __( 'Δεν διάλεξες καμία κατάσταση παραγγελίας: δεν θα δημιουργηθεί κανένα κουπόνι.', 'checkout-extras' ) );
		}
		if ( 'off' !== $s['c_mode'] && 'order' === $s['c_email'] && ! $s['c_email_ids'] ) {
			$msgs[] = self::msg( 'warning', __( 'Δεν διάλεξες κανένα email της παραγγελίας: το κουπόνι θα φαίνεται μόνο στη σελίδα ευχαριστίας.', 'checkout-extras' ) );
		}
		CKE_Coupon::schedule();
		return $msgs;
	}

	private static function do_save_tools(): array {
		$raw = self::collect( CKE_Settings::get(), array( 'lang' ), array( 'c_delete_on_uninstall' ), array(), array() );
		CKE_Settings::save( $raw );
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'checkout-extras' ) ) );
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'cke_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'checkout-extras' ) );
		}
		$state = array(
			'plugin'  => 'checkout-extras',
			'version' => CKE_VERSION,
			'options' => array(
				CKE_Settings::OPT => CKE_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="checkout-extras-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/**
	 * Strict import: the blob must be an object; it goes through
	 * CKE_Settings::sanitize() (the same validation as the forms). An invalid
	 * file leaves the current settings untouched and says so.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['cke_import_file']['error'] ) ? (int) $_FILES['cke_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['cke_import_file']['size'] ) ? (int) $_FILES['cke_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'checkout-extras' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['cke_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['cke_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'checkout-extras' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['cke_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'checkout-extras' ) ) );
		}
		$blob = isset( $state['options'][ CKE_Settings::OPT ] ) ? $state['options'][ CKE_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Checkout Extras).', 'checkout-extras' ) ) );
		}
		CKE_Settings::save( $blob );
		CKE_Coupon::schedule();
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'checkout-extras' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'cke_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'cke_aui_msg_' . get_current_user_id() );
			return $raw;
		}
		return array();
	}

	private static function print_notices( array $notices ): void {
		foreach ( $notices as $n ) {
			if ( ! is_array( $n ) || ! isset( $n['type'], $n['text'] ) ) {
				continue;
			}
			$type = in_array( $n['type'], array( 'success', 'warning', 'error', 'info' ), true ) ? $n['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible inline"><p>' . esc_html( (string) $n['text'] ) . '</p></div>';
		}
	}

	/* =====================================================================
	 * Footer (Bible §7 — identical pattern)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="cke-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'checkout-extras' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="cke-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="cke-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'checkout-extras' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="cke-footer-cta">
			<a class="cke-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'checkout-extras' ); ?>
			</a>
			<a class="cke-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'checkout-extras' ); ?>
			</a>
		</p>
		<?php
	}
}
