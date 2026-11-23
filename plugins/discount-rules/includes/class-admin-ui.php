<?php
/**
 * DSR_Admin_UI — menu, plugin page, PRG routes, backup (Bible §3–§8, §11).
 *
 * One page, submenu «Κανόνες εκπτώσεων» of the shared "Noxpress" menu
 * (admin_menu priority 480), slug dsr-rules, with tabs:
 *  - rules     the list: status now, dates, what it gives, orders that
 *              got it (the latest 300 paid orders), switch, copy, delete
 *  - edit      the rule form, with a live example
 *  - test      «Δοκιμή καλαθιού»: products and quantities → what the cart
 *              would get (the cart's own calculation, nothing stored)
 *  - history   the last 200 changes: who, when, what (old → new)
 *  - settings  mode, order cap, category limits, exclusions, display,
 *              lowest 30-day price
 *  - texts     visitor text language and texts
 *  - tools     export / import, filters and API
 *
 * Every state change: POST on admin_init, slug + nonce + capability
 * (manage_woocommerce), strict validation (DSR_Rules::sanitize,
 * DSR_Settings::sanitize), PRG with the message in the transient
 * dsr_aui_msg_{uid}. Export and the test cart: privileged GET (slug +
 * nonce + capability); the test cart writes nothing.
 */

defined( 'ABSPATH' ) || exit;

final class DSR_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'dsr-rules';
	const NONCE       = 'dsr_admin';
	const NONCE_FIELD = 'dsr_nonce';
	const NONCE_BAK   = 'dsr_backup';
	const NONCE_TEST  = 'dsr_test';
	const MAX_IMPORT  = 524288;
	const STAT_ORDERS = 300;
	const CAT_ROWS    = 3;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 480 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DSR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label msgid. */
	private static function tabs(): array {
		return array(
			'rules'    => __( 'Κανόνες', 'discount-rules' ),
			'edit'     => __( 'Νέος κανόνας', 'discount-rules' ),
			'test'     => __( 'Δοκιμή καλαθιού', 'discount-rules' ),
			'history'  => __( 'Ιστορικό', 'discount-rules' ),
			'settings' => __( 'Ρυθμίσεις', 'discount-rules' ),
			'texts'    => __( 'Κείμενα', 'discount-rules' ),
			'tools'    => __( 'Εργαλεία', 'discount-rules' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Discount Rules', 'discount-rules' ),
			__( 'Κανόνες εκπτώσεων', 'discount-rules' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( self::url( 'rules' ) ) . '">' . esc_html__( 'Κανόνες', 'discount-rules' ) . '</a>'
		);
		return $links;
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'rules';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'dsr-admin', DSR_URL . 'assets/admin.css', array(), DSR_VERSION );
		$tab = self::current_tab();
		if ( 'edit' === $tab || 'test' === $tab || 'settings' === $tab ) {
			if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
				wp_enqueue_script( 'wc-enhanced-select' );
			}
			if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
			}
			wp_enqueue_script( 'dsr-admin', DSR_URL . 'assets/admin.js', array( 'jquery' ), DSR_VERSION, true );
			wp_localize_script(
				'dsr-admin',
				'dsrAdmin',
				array(
					'decimals' => wc_get_price_decimals(),
					'dec'      => wc_get_price_decimal_separator(),
					'sample'   => __( 'Παράδειγμα με τιμή 10,00: ', 'discount-rules' ),
					'items'    => __( 'τεμάχια', 'discount-rules' ),
					'pay'      => __( 'πληρώνεις', 'discount-rules' ),
					'each'     => __( 'το ένα', 'discount-rules' ),
					'cart'     => __( 'καλάθι', 'discount-rules' ),
					'confirm'  => __( 'Διαγραφή του κανόνα; Δεν αναιρείται (μένει στο ιστορικό).', 'discount-rules' ),
				)
			);
		}
		if ( 'rules' === $tab ) {
			wp_enqueue_script( 'dsr-admin', DSR_URL . 'assets/admin.js', array( 'jquery' ), DSR_VERSION, true );
			wp_localize_script(
				'dsr-admin',
				'dsrAdmin',
				array(
					'confirm' => __( 'Διαγραφή του κανόνα; Δεν αναιρείται (μένει στο ιστορικό).', 'discount-rules' ),
				)
			);
		}
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'discount-rules' ) );
		}
		$tab   = self::current_tab();
		$editn = 'edit' === $tab && (int) self::q( 'rule' ) > 0;
		?>
		<div class="wrap dsr-wrap">
			<h1><?php esc_html_e( 'Discount Rules', 'discount-rules' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Κανόνες εκπτώσεων με ημερομηνίες: προσφορά σε προϊόντα, κλιμακωτή έκπτωση ποσότητας, «πάρε Χ πλήρωσε Υ» και έκπτωση στο σύνολο του καλαθιού. Κάθε γραμμή του καλαθιού παίρνει έναν κανόνα, αυτόν που συμφέρει περισσότερο τον πελάτη.', 'discount-rules' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>
			<?php self::mode_line(); ?>

			<nav class="nav-tab-wrapper dsr-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					if ( 'edit' === $slug && $editn ) {
						$label = __( 'Επεξεργασία κανόνα', 'discount-rules' );
					}
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( self::url( $slug ) ),
						$slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'edit':
					self::render_edit();
					break;
				case 'test':
					self::render_test();
					break;
				case 'history':
					self::render_history();
					break;
				case 'settings':
					self::render_settings();
					break;
				case 'texts':
					self::render_texts();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_rules();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/** One line on the mode in effect. */
	private static function mode_line(): void {
		if ( Discount_Rules::killed() ) {
			echo '<p class="dsr-warn">' . esc_html__( 'Το DSR_DISABLE είναι ενεργό στο wp-config.php: κανένας κανόνας δεν εφαρμόζεται και τα καλάθια έχουν τις κανονικές τιμές.', 'discount-rules' ) . '</p>';
			return;
		}
		$mode = DSR_Settings::get()['mode'];
		if ( 'off' === $mode ) {
			echo '<p class="dsr-warn">' . esc_html__( 'Η λειτουργία είναι ανενεργή: κανένας κανόνας δεν εφαρμόζεται. Άλλαξέ τη στην καρτέλα Ρυθμίσεις.', 'discount-rules' ) . '</p>';
		} elseif ( 'test' === $mode ) {
			echo '<p class="dsr-warn">' . esc_html__( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος βλέπουν και παίρνουν τις εκπτώσεις.', 'discount-rules' ) . '</p>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Tab: rules
	 * ------------------------------------------------------------------- */

	/** Status => [ label, badge class ]. */
	private static function status_badge( string $status ): string {
		$map = array(
			'active'    => array( __( 'Ενεργός', 'discount-rules' ), 'ok' ),
			'scheduled' => array( __( 'Προγραμματισμένος', 'discount-rules' ), 'warn' ),
			'expired'   => array( __( 'Έληξε', 'discount-rules' ), 'muted' ),
			'off'       => array( __( 'Ανενεργός', 'discount-rules' ), 'muted' ),
		);
		$row = isset( $map[ $status ] ) ? $map[ $status ] : $map['off'];
		return '<span class="dsr-badge dsr-badge--' . esc_attr( $row[1] ) . '">' . esc_html( $row[0] ) . '</span>';
	}

	/** Type => label. */
	private static function type_label( string $type ): string {
		$map = array(
			'sale'  => __( 'Προσφορά σε προϊόντα', 'discount-rules' ),
			'tiers' => __( 'Κλιμακωτή ποσότητα', 'discount-rules' ),
			'xy'    => __( 'Πάρε Χ πλήρωσε Υ', 'discount-rules' ),
			'cart'  => __( 'Σύνολο καλαθιού', 'discount-rules' ),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}

	/** «−20%», «3+: −10% · 5+: −15%», «3 για 2», «από 40 €: −5%». */
	private static function what( array $r ): string {
		$amt = static function ( string $kind, float $amount ): string {
			return 'percent' === $kind ? '−' . wc_format_localized_decimal( round( $amount, 2 ) ) . '%' : '−' . wp_strip_all_tags( wc_price( $amount ) );
		};
		switch ( $r['type'] ) {
			case 'tiers':
				$parts = array();
				foreach ( $r['tiers'] as $t ) {
					$parts[] = $t['min'] . '+: ' . $amt( $t['kind'], $t['amount'] );
				}
				return implode( ' · ', $parts );
			case 'xy':
				/* translators: 1: buy, 2: pay */
				return sprintf( __( '%1$d για %2$d', 'discount-rules' ), $r['buy'], $r['pay'] );
			case 'cart':
				return ( $r['min'] > 0 ? sprintf( /* translators: %s: amount */ __( 'από %s', 'discount-rules' ), wp_strip_all_tags( wc_price( $r['min'] ) ) ) . ': ' : '' ) . $amt( $r['kind'], $r['amount'] );
			default:
				return $amt( $r['kind'], $r['amount'] );
		}
	}

	/** «Όλα τα προϊόντα» or «3 προϊόντα, 2 κατηγορίες». */
	private static function scope( array $r ): string {
		if ( 'yes' === $r['all'] ) {
			$out = __( 'Όλα τα προϊόντα', 'discount-rules' );
		} else {
			$parts = array();
			if ( $r['products'] ) {
				/* translators: %d: number */
				$parts[] = sprintf( _n( '%d προϊόν', '%d προϊόντα', count( $r['products'] ), 'discount-rules' ), count( $r['products'] ) );
			}
			if ( $r['cats'] ) {
				/* translators: %d: number */
				$parts[] = sprintf( _n( '%d κατηγορία', '%d κατηγορίες', count( $r['cats'] ), 'discount-rules' ), count( $r['cats'] ) );
			}
			if ( $r['tags'] ) {
				/* translators: %d: number */
				$parts[] = sprintf( _n( '%d ετικέτα', '%d ετικέτες', count( $r['tags'] ), 'discount-rules' ), count( $r['tags'] ) );
			}
			if ( $r['brands'] ) {
				/* translators: %d: number */
				$parts[] = sprintf( _n( '%d brand', '%d brands', count( $r['brands'] ), 'discount-rules' ), count( $r['brands'] ) );
			}
			$out = implode( ', ', $parts );
		}
		if ( $r['ex_products'] || $r['ex_cats'] ) {
			$out .= ' ' . __( '(με εξαιρέσεις)', 'discount-rules' );
		}
		return $out;
	}

	/** Rule id => { orders, amount } from the latest paid orders. */
	private static function stats(): array {
		$out = array();
		try {
			$orders = wc_get_orders(
				array(
					'limit'        => self::STAT_ORDERS,
					'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
					'meta_key'     => DSR_Order::APPLIED, // phpcs:ignore WordPress.DB.SlowDBQuery -- admin only, limited.
					'meta_compare' => 'EXISTS',
					'orderby'      => 'date',
					'order'        => 'DESC',
				)
			);
			foreach ( (array) $orders as $o ) {
				if ( ! $o instanceof WC_Order ) {
					continue;
				}
				$applied = $o->get_meta( DSR_Order::APPLIED, true );
				foreach ( is_array( $applied ) ? $applied : array() as $a ) {
					if ( ! is_array( $a ) || ! isset( $a['r'] ) ) {
						continue;
					}
					$id = (int) $a['r'];
					if ( ! isset( $out[ $id ] ) ) {
						$out[ $id ] = array(
							'orders' => 0,
							'amount' => 0.0,
						);
					}
					++$out[ $id ]['orders'];
					$out[ $id ]['amount'] += isset( $a['amount'] ) ? (float) $a['amount'] : 0.0;
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return $out;
	}

	private static function dt( string $v ): string {
		$ts = DSR_Rules::ts( $v );
		return $ts > 0 ? (string) wp_date( get_option( 'date_format', 'j/n/Y' ) . ' H:i', $ts ) : '—';
	}

	private static function render_rules(): void {
		$rules = DSR_Rules::all();
		$stats = self::stats();
		?>
		<p><a class="button button-primary" href="<?php echo esc_url( self::url( 'edit' ) ); ?>"><?php esc_html_e( 'Νέος κανόνας', 'discount-rules' ); ?></a></p>
		<?php if ( empty( $rules ) ) : ?>
			<p class="dsr-muted"><?php esc_html_e( 'Δεν υπάρχει ακόμα κανένας κανόνας. Φτιάξε τον πρώτο με το «Νέος κανόνας».', 'discount-rules' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat dsr-table">
			<thead><tr>
				<th><?php esc_html_e( 'Κανόνας', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Τύπος', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Έκπτωση', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Προϊόντα', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Από', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Έως', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Κατάσταση', 'discount-rules' ); ?></th>
				<th class="dsr-num-col"><?php esc_html_e( 'Παραγγελίες', 'discount-rules' ); ?></th>
				<th class="dsr-num-col"><?php esc_html_e( 'Έκπτωση που δόθηκε', 'discount-rules' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rules as $r ) : ?>
				<?php
				$st   = DSR_Rules::status( $r );
				$s    = isset( $stats[ $r['id'] ] ) ? $stats[ $r['id'] ] : array(
					'orders' => 0,
					'amount' => 0.0,
				);
				$warn = DSR_Rules::warnings( $r );
				?>
				<tr>
					<td><a href="<?php echo esc_url( self::url( 'edit', array( 'rule' => $r['id'] ) ) ); ?>"><strong><?php echo esc_html( $r['name'] ); ?></strong></a>
						<?php
						if ( 'all' !== $r['who'] ) {
							echo '<br><span class="dsr-hint">' . esc_html( 'guests' === $r['who'] ? __( 'μόνο επισκέπτες', 'discount-rules' ) : __( 'μόνο συνδεδεμένοι πελάτες', 'discount-rules' ) ) . '</span>';
						}
						foreach ( $warn as $w ) {
							echo '<br><span class="dsr-hint dsr-warn">' . esc_html( $w ) . '</span>';
						}
						?>
					</td>
					<td><?php echo esc_html( self::type_label( $r['type'] ) ); ?></td>
					<td><?php echo esc_html( self::what( $r ) ); ?></td>
					<td><?php echo esc_html( self::scope( $r ) ); ?></td>
					<td><?php echo esc_html( self::dt( $r['from'] ) ); ?></td>
					<td><?php echo esc_html( self::dt( $r['to'] ) ); ?></td>
					<td><?php echo self::status_badge( $st ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></td>
					<td class="dsr-num-col"><?php echo esc_html( (string) $s['orders'] ); ?></td>
					<td class="dsr-num-col"><?php echo esc_html( wp_strip_all_tags( wc_price( $s['amount'] ) ) ); ?></td>
					<td class="dsr-actions">
						<?php
						self::row_action( 'yes' === $r['on'] ? 'disable' : 'enable', $r['id'], 'yes' === $r['on'] ? __( 'Απενεργοποίηση', 'discount-rules' ) : __( 'Ενεργοποίηση', 'discount-rules' ) );
						self::row_action( 'copy', $r['id'], __( 'Αντιγραφή', 'discount-rules' ) );
						self::row_action( 'delete', $r['id'], __( 'Διαγραφή', 'discount-rules' ) );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number of orders */ __( 'Οι παραγγελίες και η έκπτωση μετρούν από τις %d πιο πρόσφατες πληρωμένες παραγγελίες με κανόνα (Σε επεξεργασία, Ολοκληρωμένη, Σε αναμονή), χωρίς ΦΠΑ.', 'discount-rules' ), self::STAT_ORDERS ) ); ?></p>
		<?php
	}

	private static function row_action( string $act, int $id, string $label ): void {
		?>
		<form method="post" class="dsr-inline-form">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="dsr_action" value="<?php echo esc_attr( $act ); ?>" />
			<input type="hidden" name="dsr_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<button type="submit" class="button<?php echo 'delete' === $act ? ' dsr-delete' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: edit
	 * ------------------------------------------------------------------- */

	/** Products as <option>s for a product search select. */
	private static function product_options( array $ids ): void {
		foreach ( $ids as $id ) {
			$p = wc_get_product( (int) $id );
			if ( $p instanceof WC_Product ) {
				echo '<option value="' . esc_attr( (string) $id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
	}

	/** A multi-select of a taxonomy's terms. */
	private static function term_select( string $name, string $taxonomy, array $selected ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1000,
				'orderby'    => 'name',
			)
		);
		echo '<select name="' . esc_attr( $name ) . '[]" multiple="multiple" class="wc-enhanced-select dsr-wide" data-placeholder="' . esc_attr__( 'Διάλεξε…', 'discount-rules' ) . '">';
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( $t instanceof WP_Term ) {
					$label = $t->name;
					if ( $t->parent ) {
						$parent = get_term( (int) $t->parent, $taxonomy );
						if ( $parent instanceof WP_Term ) {
							$label = $parent->name . ' › ' . $t->name;
						}
					}
					echo '<option value="' . esc_attr( (string) $t->term_id ) . '"' . selected( in_array( (int) $t->term_id, $selected, true ), true, false ) . '>' . esc_html( $label ) . '</option>';
				}
			}
		}
		echo '</select>';
	}

	private static function kind_select( string $name, string $value ): void {
		echo '<select name="' . esc_attr( $name ) . '" class="dsr-kind">'
			. '<option value="percent"' . selected( $value, 'percent', false ) . '>%</option>'
			. '<option value="fixed"' . selected( $value, 'fixed', false ) . '>' . esc_html( get_woocommerce_currency_symbol() ) . '</option>'
			. '</select>';
	}

	private static function num( float $v ): string {
		return $v > 0 ? wc_format_localized_decimal( rtrim( rtrim( number_format( $v, 6, '.', '' ), '0' ), '.' ) ) : '';
	}

	private static function render_edit(): void {
		$id   = (int) self::q( 'rule' );
		$r    = $id > 0 ? DSR_Rules::get( $id ) : null;
		$back = get_transient( 'dsr_form_' . get_current_user_id() );
		if ( is_array( $back ) ) {
			delete_transient( 'dsr_form_' . get_current_user_id() );
			$r = array_merge( DSR_Rules::blank(), DSR_Rules::sanitize( $back )['rule'] );
		}
		if ( null === $r ) {
			if ( $id > 0 ) {
				echo '<p class="dsr-warn">' . esc_html__( 'Ο κανόνας δεν βρέθηκε.', 'discount-rules' ) . '</p>';
				return;
			}
			$r = DSR_Rules::blank();
		}
		$f     = static function ( string $k ): string {
			return 'dsr[' . $k . ']';
		};
		$tiers = $r['tiers'];
		while ( count( $tiers ) < DSR_Rules::MAX_TIER ) {
			$tiers[] = array(
				'min'    => '',
				'kind'   => 'percent',
				'amount' => 0.0,
			);
		}
		$has_brands = taxonomy_exists( 'product_brand' );
		?>
		<form method="post" class="dsr-rule-form">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="dsr_action" value="save_rule" />
			<input type="hidden" name="<?php echo esc_attr( $f( 'id' ) ); ?>" value="<?php echo esc_attr( (string) $r['id'] ); ?>" />

			<h2 class="dsr-h2"><?php esc_html_e( 'Κανόνας', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<p class="dsr-field"><span><?php esc_html_e( 'Όνομα', 'discount-rules' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $f( 'name' ) ); ?>" value="<?php echo esc_attr( $r['name'] ); ?>" maxlength="80" class="dsr-wide" required placeholder="<?php esc_attr_e( 'π.χ. Black Friday 2026', 'discount-rules' ); ?>" /></p>
				<p class="dsr-field"><span><?php esc_html_e( 'Ενεργός', 'discount-rules' ); ?></span>
					<label class="dsr-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'on' ) ); ?>" value="yes" <?php checked( $r['on'], 'yes' ); ?> /> <?php esc_html_e( 'Ισχύει μέσα στις ημερομηνίες του', 'discount-rules' ); ?></label></p>
				<div class="dsr-row">
					<?php
					foreach ( DSR_Rules::TYPES as $t ) {
						echo '<label class="dsr-check dsr-block"><input type="radio" class="dsr-type" name="' . esc_attr( $f( 'type' ) ) . '" value="' . esc_attr( $t ) . '" ' . checked( $r['type'], $t, false ) . ' /> <strong>' . esc_html( self::type_label( $t ) ) . '</strong> — ';
						switch ( $t ) {
							case 'sale':
								esc_html_e( 'χαμηλότερη τιμή σε προϊόντα ή κατηγορίες, με διαγραμμένη την κανονική τιμή στο κατάστημα.', 'discount-rules' );
								break;
							case 'tiers':
								esc_html_e( 'έκπτωση ανάλογα με το πόσα τεμάχια μπαίνουν στο καλάθι, με πίνακα στη σελίδα του προϊόντος.', 'discount-rules' );
								break;
							case 'xy':
								esc_html_e( 'π.χ. 3 για 2: για κάθε 3 τεμάχια το φθηνότερο είναι δώρο.', 'discount-rules' );
								break;
							default:
								esc_html_e( 'μία γραμμή «Έκπτωση» στα σύνολα όταν το καλάθι φτάνει ένα ποσό.', 'discount-rules' );
						}
						echo '</label>';
					}
					?>
				</div>
			</div>

			<h2 class="dsr-h2"><?php esc_html_e( 'Έκπτωση', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<div class="dsr-type-box" data-types="sale cart">
					<p class="dsr-field"><span><?php esc_html_e( 'Πόση', 'discount-rules' ); ?></span>
						<input type="text" inputmode="decimal" name="<?php echo esc_attr( $f( 'amount' ) ); ?>" value="<?php echo esc_attr( self::num( $r['amount'] ) ); ?>" class="dsr-num dsr-amount" />
						<?php self::kind_select( $f( 'kind' ), $r['kind'] ); ?>
						<span class="description dsr-type-box" data-types="sale"><?php esc_html_e( 'Σταθερό ποσό = τόσο λιγότερο ανά τεμάχιο.', 'discount-rules' ); ?></span>
						<span class="description dsr-type-box" data-types="cart"><?php esc_html_e( 'Σταθερό ποσό = τόσο λιγότερο σε όλο το καλάθι.', 'discount-rules' ); ?></span>
					</p>
				</div>
				<div class="dsr-type-box" data-types="tiers">
					<table class="widefat dsr-table dsr-tiers-edit">
						<thead><tr><th><?php esc_html_e( 'Από τεμάχια', 'discount-rules' ); ?></th><th><?php esc_html_e( 'Έκπτωση ανά τεμάχιο', 'discount-rules' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $tiers as $i => $t ) : ?>
							<tr>
								<td><input type="number" min="2" max="9999" step="1" name="<?php echo esc_attr( $f( 'tiers' ) . '[' . $i . '][min]' ); ?>" value="<?php echo esc_attr( (string) $t['min'] ); ?>" class="dsr-num dsr-tmin" /></td>
								<td><input type="text" inputmode="decimal" name="<?php echo esc_attr( $f( 'tiers' ) . '[' . $i . '][amount]' ); ?>" value="<?php echo esc_attr( self::num( (float) $t['amount'] ) ); ?>" class="dsr-num dsr-tamount" />
									<?php self::kind_select( $f( 'tiers' ) . '[' . $i . '][kind]', (string) $t['kind'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Κενές γραμμές αγνοούνται. Ισχύει η μεγαλύτερη κλίμακα που φτάνει το καλάθι.', 'discount-rules' ); ?></p>
				</div>
				<div class="dsr-type-box" data-types="xy">
					<p class="dsr-field"><span><?php esc_html_e( 'Πάρε', 'discount-rules' ); ?></span>
						<input type="number" min="2" max="100" name="<?php echo esc_attr( $f( 'buy' ) ); ?>" value="<?php echo esc_attr( (string) $r['buy'] ); ?>" class="dsr-num dsr-buy" />
						<span><?php esc_html_e( 'πλήρωσε', 'discount-rules' ); ?></span>
						<input type="number" min="1" max="99" name="<?php echo esc_attr( $f( 'pay' ) ); ?>" value="<?php echo esc_attr( (string) $r['pay'] ); ?>" class="dsr-num dsr-paynum" />
					</p>
					<p class="description"><?php esc_html_e( 'Για κάθε ομάδα τεμαχίων, τα φθηνότερα είναι δώρο. Επαναλαμβάνεται: 6 τεμάχια με «3 για 2» = 2 δώρο.', 'discount-rules' ); ?></p>
				</div>
				<div class="dsr-type-box" data-types="tiers xy">
					<p class="dsr-field"><span><?php esc_html_e( 'Μετρούν', 'discount-rules' ); ?></span>
						<select name="<?php echo esc_attr( $f( 'count' ) ); ?>">
							<option value="product" <?php selected( $r['count'], 'product' ); ?>><?php esc_html_e( 'Τα τεμάχια κάθε προϊόντος χωριστά (οι παραλλαγές μαζί)', 'discount-rules' ); ?></option>
							<option value="set" <?php selected( $r['count'], 'set' ); ?>><?php esc_html_e( 'Όλα τα προϊόντα του κανόνα μαζί (π.χ. οποιαδήποτε 3 βιβλία της κατηγορίας)', 'discount-rules' ); ?></option>
						</select></p>
				</div>
				<p class="dsr-example description" aria-live="polite"></p>
			</div>

			<h2 class="dsr-h2"><?php esc_html_e( 'Σε ποια προϊόντα', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<p><label class="dsr-check"><input type="checkbox" class="dsr-all" name="<?php echo esc_attr( $f( 'all' ) ); ?>" value="yes" <?php checked( $r['all'], 'yes' ); ?> /> <?php esc_html_e( 'Όλα τα προϊόντα', 'discount-rules' ); ?></label></p>
				<div class="dsr-scope">
					<p class="dsr-label"><?php esc_html_e( 'Προϊόντα', 'discount-rules' ); ?></p>
					<select class="wc-product-search dsr-wide" multiple="multiple" name="<?php echo esc_attr( $f( 'products' ) ); ?>[]" data-placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'discount-rules' ); ?>" data-action="woocommerce_json_search_products_and_variations"><?php self::product_options( $r['products'] ); ?></select>
					<p class="dsr-label"><?php esc_html_e( 'Κατηγορίες (μαζί με τις υποκατηγορίες τους)', 'discount-rules' ); ?></p>
					<?php self::term_select( $f( 'cats' ), 'product_cat', $r['cats'] ); ?>
					<p class="dsr-label"><?php esc_html_e( 'Ετικέτες', 'discount-rules' ); ?></p>
					<?php self::term_select( $f( 'tags' ), 'product_tag', $r['tags'] ); ?>
					<?php if ( $has_brands ) : ?>
						<p class="dsr-label"><?php esc_html_e( 'Brands', 'discount-rules' ); ?></p>
						<?php self::term_select( $f( 'brands' ), 'product_brand', $r['brands'] ); ?>
					<?php endif; ?>
				</div>
				<p class="dsr-label"><?php esc_html_e( 'Εκτός από αυτά τα προϊόντα', 'discount-rules' ); ?></p>
				<select class="wc-product-search dsr-wide" multiple="multiple" name="<?php echo esc_attr( $f( 'ex_products' ) ); ?>[]" data-placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'discount-rules' ); ?>" data-action="woocommerce_json_search_products_and_variations"><?php self::product_options( $r['ex_products'] ); ?></select>
				<p class="dsr-label"><?php esc_html_e( 'Εκτός από αυτές τις κατηγορίες', 'discount-rules' ); ?></p>
				<?php self::term_select( $f( 'ex_cats' ), 'product_cat', $r['ex_cats'] ); ?>
				<p class="description"><?php esc_html_e( 'Οι γραμμές πακέτων (Product Bundles) και οι λογαριασμοί χονδρικής (Trade Accounts) δεν παίρνουν ποτέ κανόνα. Οι εξαιρέσεις όλου του καταστήματος είναι στις Ρυθμίσεις.', 'discount-rules' ); ?></p>
			</div>

			<h2 class="dsr-h2"><?php esc_html_e( 'Πότε και για ποιους', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<p class="dsr-field"><span><?php esc_html_e( 'Από', 'discount-rules' ); ?></span>
					<input type="datetime-local" name="<?php echo esc_attr( $f( 'from' ) ); ?>" value="<?php echo esc_attr( str_replace( ' ', 'T', $r['from'] ) ); ?>" />
					<span class="description"><?php esc_html_e( 'Κενό = από τώρα.', 'discount-rules' ); ?></span></p>
				<p class="dsr-field"><span><?php esc_html_e( 'Έως', 'discount-rules' ); ?></span>
					<input type="datetime-local" name="<?php echo esc_attr( $f( 'to' ) ); ?>" value="<?php echo esc_attr( str_replace( ' ', 'T', $r['to'] ) ); ?>" />
					<span class="description"><?php echo esc_html( sprintf( /* translators: %s: time zone */ __( 'Κενό = χωρίς λήξη. Ώρα του site (%s).', 'discount-rules' ), wp_timezone_string() ) ); ?></span></p>
				<p class="dsr-field"><span><?php esc_html_e( 'Για', 'discount-rules' ); ?></span>
					<select name="<?php echo esc_attr( $f( 'who' ) ); ?>">
						<option value="all" <?php selected( $r['who'], 'all' ); ?>><?php esc_html_e( 'Όλους', 'discount-rules' ); ?></option>
						<option value="guests" <?php selected( $r['who'], 'guests' ); ?>><?php esc_html_e( 'Μόνο επισκέπτες (χωρίς σύνδεση)', 'discount-rules' ); ?></option>
						<option value="customers" <?php selected( $r['who'], 'customers' ); ?>><?php esc_html_e( 'Μόνο συνδεδεμένους πελάτες', 'discount-rules' ); ?></option>
					</select></p>
				<p class="dsr-field"><span><?php esc_html_e( 'Ελάχιστο καλάθι', 'discount-rules' ); ?></span>
					<input type="text" inputmode="decimal" name="<?php echo esc_attr( $f( 'min' ) ); ?>" value="<?php echo esc_attr( self::num( $r['min'] ) ); ?>" class="dsr-num" /> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
					<span class="description"><?php esc_html_e( 'Κενό = χωρίς ελάχιστο. Κανόνας με ελάχιστο δεν δείχνει διαγραμμένη τιμή στο κατάστημα· φαίνεται στο καλάθι.', 'discount-rules' ); ?></span></p>
			</div>

			<h2 class="dsr-h2"><?php esc_html_e( 'Τι βλέπει ο πελάτης', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<p class="dsr-field"><span><?php esc_html_e( 'Ετικέτα (ελληνικά)', 'discount-rules' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $f( 'label' ) ); ?>[el]" value="<?php echo esc_attr( $r['label']['el'] ); ?>" maxlength="40" class="dsr-wide" /></p>
				<p class="dsr-field"><span><?php esc_html_e( 'Ετικέτα (English)', 'discount-rules' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $f( 'label' ) ); ?>[en]" value="<?php echo esc_attr( $r['label']['en'] ); ?>" maxlength="40" class="dsr-wide" /></p>
				<p class="description"><?php esc_html_e( 'Φαίνεται στο καλάθι («Προσφορά: …»), στην παραγγελία, στα emails και κάτω από την τιμή. Κενό = φτιάχνεται από τον κανόνα («3 για 2», «−20%») ή το όνομά του.', 'discount-rules' ); ?></p>
			</div>

			<p class="dsr-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση κανόνα', 'discount-rules' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::url( 'rules' ) ); ?>"><?php esc_html_e( 'Πίσω στους κανόνες', 'discount-rules' ); ?></a></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: test
	 * ------------------------------------------------------------------- */

	private static function render_test(): void {
		$run   = '' !== self::q( 'dsr_run' ) && wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_TEST );
		$items = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above; read-only.
		$pids = isset( $_GET['dsr_p'] ) && is_array( $_GET['dsr_p'] ) ? array_map( 'absint', wp_unslash( $_GET['dsr_p'] ) ) : array();
		$qtys = isset( $_GET['dsr_q'] ) && is_array( $_GET['dsr_q'] ) ? array_map( 'absint', wp_unslash( $_GET['dsr_q'] ) ) : array();
		// phpcs:enable
		for ( $i = 0; $i < 6; $i++ ) {
			$items[] = array(
				'product_id' => isset( $pids[ $i ] ) ? (int) $pids[ $i ] : 0,
				'qty'        => isset( $qtys[ $i ] ) && $qtys[ $i ] > 0 ? min( 9999, (int) $qtys[ $i ] ) : 1,
			);
		}
		$customer = 'yes' === self::q( 'dsr_c' );
		$when     = self::q( 'dsr_when' );
		$when     = str_replace( 'T', ' ', $when );
		$ts       = '' !== $when ? DSR_Rules::ts( $when ) : 0;
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="test" />
			<input type="hidden" name="dsr_run" value="1" />
			<?php wp_nonce_field( self::NONCE_TEST, '_wpnonce', false ); ?>
			<p class="description"><?php esc_html_e( 'Διάλεξε προϊόντα και ποσότητες: βλέπεις τι θα έπαιρνε το καλάθι με τον ίδιο υπολογισμό, χωρίς να φτιαχτεί καλάθι ή παραγγελία. Μετρούν και οι ανενεργοί από τη λειτουργία κανόνες, όχι όμως οι απενεργοποιημένοι.', 'discount-rules' ); ?></p>
			<table class="widefat dsr-table">
				<thead><tr><th><?php esc_html_e( 'Προϊόν', 'discount-rules' ); ?></th><th><?php esc_html_e( 'Τεμάχια', 'discount-rules' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $items as $it ) : ?>
					<tr>
						<td><select class="wc-product-search dsr-wide" name="dsr_p[]" data-allow_clear="true" data-placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'discount-rules' ); ?>" data-action="woocommerce_json_search_products_and_variations"><option value=""></option><?php $it['product_id'] ? self::product_options( array( $it['product_id'] ) ) : null; ?></select></td>
						<td><input type="number" min="1" max="9999" name="dsr_q[]" value="<?php echo esc_attr( (string) $it['qty'] ); ?>" class="dsr-num" /></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="dsr-field"><label class="dsr-check"><input type="checkbox" name="dsr_c" value="yes" <?php checked( $customer ); ?> /> <?php esc_html_e( 'Ως συνδεδεμένος πελάτης', 'discount-rules' ); ?></label></p>
			<p class="dsr-field"><span><?php esc_html_e( 'Ημερομηνία και ώρα', 'discount-rules' ); ?></span>
				<input type="datetime-local" name="dsr_when" value="<?php echo esc_attr( str_replace( ' ', 'T', $when ) ); ?>" /> <span class="description"><?php esc_html_e( 'Κενό = τώρα.', 'discount-rules' ); ?></span></p>
			<p class="dsr-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Υπολογισμός', 'discount-rules' ); ?></button></p>
		</form>
		<?php
		if ( ! $run ) {
			return;
		}
		$use = array_values(
			array_filter(
				$items,
				static function ( $it ) {
					return $it['product_id'] > 0;
				}
			)
		);
		if ( empty( $use ) ) {
			echo '<p class="dsr-warn">' . esc_html__( 'Διάλεξε τουλάχιστον ένα προϊόν (απλό ή παραλλαγή).', 'discount-rules' ) . '</p>';
			return;
		}
		DSR_Engine::flush();
		$res = DSR_Abilities::run( $use, $customer, $ts > 0 ? $ts : time() );
		?>
		<h2 class="dsr-h2"><?php esc_html_e( 'Αποτέλεσμα', 'discount-rules' ); ?></h2>
		<table class="widefat dsr-table">
			<thead><tr>
				<th><?php esc_html_e( 'Προϊόν', 'discount-rules' ); ?></th>
				<th class="dsr-num-col"><?php esc_html_e( 'Τεμάχια', 'discount-rules' ); ?></th>
				<th class="dsr-num-col"><?php esc_html_e( 'Τιμή', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Κανόνας', 'discount-rules' ); ?></th>
				<th class="dsr-num-col"><?php esc_html_e( 'Νέα τιμή', 'discount-rules' ); ?></th>
				<th class="dsr-num-col"><?php esc_html_e( 'Έκπτωση', 'discount-rules' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $res['lines'] as $l ) : ?>
				<tr>
					<td><?php echo esc_html( $l['name'] ); ?></td>
					<td class="dsr-num-col"><?php echo esc_html( (string) $l['qty'] ); ?></td>
					<td class="dsr-num-col"><?php echo esc_html( wp_strip_all_tags( wc_price( $l['price'] ) ) ); ?></td>
					<td><?php echo $l['rule'] ? esc_html( $l['rule_name'] . ' (#' . $l['rule'] . ')' ) : '<span class="dsr-muted">—</span>'; ?></td>
					<td class="dsr-num-col"><?php echo esc_html( wp_strip_all_tags( wc_price( $l['unit'] ) ) ); ?></td>
					<td class="dsr-num-col"><?php echo esc_html( wp_strip_all_tags( wc_price( $l['discount'] ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="dsr-zone dsr-mt-8">
			<p><?php echo esc_html( __( 'Υποσύνολο:', 'discount-rules' ) . ' ' . wp_strip_all_tags( wc_price( $res['subtotal'] ) ) ); ?></p>
			<?php if ( $res['cart'] ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: 1: rule name, 2: amount */ __( 'Έκπτωση καλαθιού «%1$s»: −%2$s', 'discount-rules' ), $res['cart']['name'], wp_strip_all_tags( wc_price( $res['cart']['amount'] ) ) ) ); ?></p>
			<?php endif; ?>
			<p><strong><?php echo esc_html( __( 'Σύνολο εκπτώσεων:', 'discount-rules' ) . ' −' . wp_strip_all_tags( wc_price( $res['discount'] ) ) . ' · ' . __( 'Πληρώνει:', 'discount-rules' ) . ' ' . wp_strip_all_tags( wc_price( $res['total'] ) ) ); ?></strong></p>
			<?php if ( $res['capped'] ) : ?>
				<p class="dsr-warn"><?php echo esc_html( sprintf( /* translators: %d: cap percent */ __( 'Η έκπτωση κόπηκε στο ανώτατο όριο ανά παραγγελία (%d%%).', 'discount-rules' ), (int) DSR_Settings::get()['cap'] ) ); ?></p>
			<?php endif; ?>
			<?php foreach ( $res['hints'] as $h ) : ?>
				<p class="dsr-muted"><?php echo esc_html( DSR_Settings::fill( 't_hint', array( 'n' => (int) $h['n'], 'rule' => (string) $h['name'] ) ) ); ?></p>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Τιμές όπως είναι αποθηκευμένες (με ή χωρίς ΦΠΑ, όπως τα προϊόντα). Οι λογαριασμοί χονδρικής δεν παίρνουν ποτέ κανόνα.', 'discount-rules' ); ?></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: history
	 * ------------------------------------------------------------------- */

	private static function render_history(): void {
		$log  = DSR_Rules::history();
		$acts = array(
			'created'  => __( 'Δημιουργία', 'discount-rules' ),
			'updated'  => __( 'Αλλαγή', 'discount-rules' ),
			'enabled'  => __( 'Ενεργοποίηση', 'discount-rules' ),
			'disabled' => __( 'Απενεργοποίηση', 'discount-rules' ),
			'deleted'  => __( 'Διαγραφή', 'discount-rules' ),
			'imported' => __( 'Εισαγωγή', 'discount-rules' ),
			'settings' => __( 'Ρυθμίσεις', 'discount-rules' ),
		);
		if ( empty( $log ) ) {
			echo '<p class="dsr-muted">' . esc_html__( 'Καμία αλλαγή ακόμα.', 'discount-rules' ) . '</p>';
			return;
		}
		?>
		<table class="widefat dsr-table">
			<thead><tr>
				<th><?php esc_html_e( 'Πότε', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Ποιος', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Τι', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Κανόνας', 'discount-rules' ); ?></th>
				<th><?php esc_html_e( 'Αλλαγές', 'discount-rules' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $log as $row ) : ?>
				<?php
				if ( ! is_array( $row ) ) {
					continue;
				}
				$act = isset( $row['act'] ) ? (string) $row['act'] : '';
				?>
				<tr>
					<td><?php echo esc_html( wp_date( get_option( 'date_format', 'j/n/Y' ) . ' H:i', (int) ( $row['t'] ?? 0 ) ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['user'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( isset( $acts[ $act ] ) ? $acts[ $act ] : $act ); ?></td>
					<td><?php echo esc_html( ( ! empty( $row['id'] ) ? '#' . (int) $row['id'] . ' ' : '' ) . (string) ( $row['name'] ?? '' ) ); ?></td>
					<td><code class="dsr-diff"><?php echo esc_html( (string) ( $row['diff'] ?? '' ) ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Οι τελευταίες %d αλλαγές κανόνων και ρυθμίσεων.', 'discount-rules' ), DSR_Rules::MAX_LOG ) ); ?></p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: settings
	 * ------------------------------------------------------------------- */

	private static function radios( string $name, string $value, array $opts ): void {
		foreach ( $opts as $k => $label ) {
			echo '<label class="dsr-check dsr-block"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
	}

	private static function checkbox( string $name, string $value, string $label ): void {
		echo '<label class="dsr-check dsr-block"><input type="checkbox" name="' . esc_attr( $name ) . '" value="yes" ' . checked( $value, 'yes', false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	private static function render_settings(): void {
		$s    = DSR_Settings::get();
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1000,
				'orderby'    => 'name',
			)
		);
		$rows = $s['cat_max'];
		for ( $i = 0; $i < self::CAT_ROWS; $i++ ) {
			$rows[ 'new' . $i ] = '';
		}
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="dsr_action" value="save_settings" />

			<h2 class="dsr-h2"><?php esc_html_e( 'Λειτουργία', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<?php
				self::radios(
					'dsr_mode',
					$s['mode'],
					array(
						'off'  => __( 'Ανενεργό', 'discount-rules' ),
						'test' => __( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος βλέπουν και παίρνουν τις εκπτώσεις', 'discount-rules' ),
						'live' => __( 'Ενεργό για όλους', 'discount-rules' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'discount-rules' ); ?> <code>define( 'DSR_DISABLE', true );</code></p>
			</div>

			<h2 class="dsr-h2"><?php esc_html_e( 'Όρια', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<p class="dsr-field"><span><?php esc_html_e( 'Ανώτατη έκπτωση ανά παραγγελία', 'discount-rules' ); ?></span>
					<input type="number" min="1" max="100" name="dsr_cap" value="<?php echo esc_attr( (string) $s['cap'] ); ?>" class="dsr-num" /> % <?php esc_html_e( 'του υποσυνόλου', 'discount-rules' ); ?></p>
				<p class="description"><?php esc_html_e( 'Όλες οι εκπτώσεις των κανόνων μαζί δεν περνούν ποτέ αυτό το ποσοστό. Πιάνει λάθη πληκτρολόγησης (π.χ. 100% αντί για 10%).', 'discount-rules' ); ?></p>

				<p class="dsr-label"><?php esc_html_e( 'Μέγιστη έκπτωση ανά κατηγορία', 'discount-rules' ); ?></p>
				<table class="widefat dsr-table">
					<thead><tr><th><?php esc_html_e( 'Κατηγορία (και οι υποκατηγορίες της)', 'discount-rules' ); ?></th><th><?php esc_html_e( 'Έως', 'discount-rules' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $term => $pct ) : ?>
						<tr>
							<td><select name="dsr_cm_t[]"><option value=""><?php esc_html_e( '— Καμία —', 'discount-rules' ); ?></option>
								<?php
								if ( is_array( $cats ) ) {
									foreach ( $cats as $c ) {
										if ( $c instanceof WP_Term ) {
											echo '<option value="' . esc_attr( (string) $c->term_id ) . '"' . selected( (int) $term, (int) $c->term_id, false ) . '>' . esc_html( $c->name ) . '</option>';
										}
									}
								}
								?>
							</select></td>
							<td><input type="text" inputmode="decimal" name="dsr_cm_p[]" value="<?php echo esc_attr( '' === $pct ? '' : wc_format_localized_decimal( (string) $pct ) ); ?>" class="dsr-num" /> %</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Καμία γραμμή της κατηγορίας δεν πληρώνει λιγότερο από την κανονική τιμή μείον αυτό το ποσοστό, όποιοι κανόνες κι αν ισχύουν· οι γραμμές αυτές δεν παίρνουν έκπτωση καλαθιού. Χρήσιμο για την ενιαία τιμή βιβλίου (π.χ. νέα βιβλία έως 10%): ρώτα τον λογιστή σου για τα ακριβή όρια.', 'discount-rules' ); ?></p>
			</div>

			<h2 class="dsr-h2"><?php esc_html_e( 'Εξαιρέσεις όλου του καταστήματος', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<?php
				self::checkbox( 'dsr_ex_others', $s['ex_others'], __( 'Τίτλοι άλλων εκδοτών (Book Feed, Trade Feed Import) δεν παίρνουν ποτέ κανόνα', 'discount-rules' ) );
				self::checkbox( 'dsr_ex_preorder', $s['ex_preorder'], __( 'Προϊόντα σε προπαραγγελία (Pre-Orders) δεν παίρνουν κανόνα', 'discount-rules' ) );
				?>
				<p class="description"><?php esc_html_e( 'Πάντα εκτός: γραμμές πακέτων (Product Bundles) και λογαριασμοί χονδρικής (Trade Accounts). Τα κουπόνια των πελατών δεν ισχύουν σε γραμμές με κανόνα, εκτός από κουπόνι 100%.', 'discount-rules' ); ?></p>
			</div>

			<h2 class="dsr-h2"><?php esc_html_e( 'Εμφάνιση', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<?php
				self::checkbox( 'dsr_table', $s['table'], __( 'Πίνακας κλιμάκων στη σελίδα του προϊόντος', 'discount-rules' ) );
				self::checkbox( 'dsr_loop_label', $s['loop_label'], __( 'Κανόνες ποσότητας στις λίστες προϊόντων: ribbon πάνω στην εικόνα, εξήγηση και σύνδεσμος στη σελίδα της προσφοράς', 'discount-rules' ) );
				self::checkbox( 'dsr_hint', $s['hint'], __( '«Πρόσθεσε ακόμη 1…» στο καλάθι όταν λείπει ένα τεμάχιο για την επόμενη κλίμακα ή το δώρο', 'discount-rules' ) );
				?>
				<p class="description"><?php esc_html_e( 'Η υπόδειξη φαίνεται στο κλασικό καλάθι. Στα blocks Καλάθι και Ταμείο οι εκπτώσεις και τα ονόματα των κανόνων φαίνονται κανονικά.', 'discount-rules' ); ?></p>
			</div>

			<h2 class="dsr-h2"><?php esc_html_e( 'Χαμηλότερη τιμή 30 ημερών', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<?php
				self::radios(
					'dsr_omnibus',
					$s['omnibus'],
					array(
						'rules' => __( 'Κάτω από κάθε τιμή που χαμηλώνει ένας κανόνας «Προσφορά»', 'discount-rules' ),
						'all'   => __( 'Και κάτω από τις τιμές προσφοράς του ίδιου του WooCommerce', 'discount-rules' ),
						'off'   => __( 'Ανενεργό', 'discount-rules' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Άρθρο 9ι ν. 2251/1994: για προϊόν που κυκλοφορεί λιγότερο από 30 ημέρες η αναφορά είναι η χαμηλότερη τιμή από την κυκλοφορία του, και σε σταδιακές μειώσεις μέσα σε 60 ημέρες είναι η τιμή πριν από την πρώτη μείωση. Το plugin τα εφαρμόζει και τα δύο αυτόματα από το ιστορικό τιμών. Ο νόμος αφορά κάθε ανακοίνωση μείωσης, οπότε η επιλογή «Και κάτω από τις τιμές προσφοράς του ίδιου του WooCommerce» είναι η ασφαλέστερη.', 'discount-rules' ); ?></p>
				<p class="description"><?php esc_html_e( 'Οδηγία Omnibus (ΕΕ 2019/2161): όταν ανακοινώνεις μείωση τιμής, δείχνεις τη χαμηλότερη τιμή των 30 ημερών πριν από τη μείωση. Το plugin κρατά ιστορικό τιμών από τη μέρα που ενεργοποιήθηκε (μία εργασία κάθε βράδυ και σε κάθε αποθήκευση προϊόντος)· όσο δεν έχει ιστορικό για ένα προϊόν, η γραμμή δεν φαίνεται. Οι προσφορές ποσότητας και το «3 για 2» συνήθως δεν θεωρούνται μείωση τιμής: επιβεβαίωσέ το με τον λογιστή σου.', 'discount-rules' ); ?></p>
			</div>

			<p class="dsr-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'discount-rules' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: texts
	 * ------------------------------------------------------------------- */

	private static function render_texts(): void {
		$s    = DSR_Settings::get();
		$rows = array(
			't_line'    => __( 'Γραμμή στο καλάθι, στην παραγγελία και στα emails («Προσφορά: 3 για 2»)', 'discount-rules' ),
			't_cart'    => __( 'Γραμμή της έκπτωσης καλαθιού στα σύνολα', 'discount-rules' ),
			't_xy'      => __( 'Ετικέτα «πάρε Χ πλήρωσε Υ» ({buy}, {pay})', 'discount-rules' ),
			't_badge'   => __( 'Ένδειξη έκπτωσης ({percent})', 'discount-rules' ),
			't_tiers'   => __( 'Τίτλος του πίνακα κλιμάκων', 'discount-rules' ),
			't_tier'    => __( 'Γραμμή του πίνακα ({min}, {price})', 'discount-rules' ),
			't_until'   => __( 'Λήξη ({date})', 'discount-rules' ),
			't_hint'    => __( 'Υπόδειξη στο καλάθι ({n}, {rule})', 'discount-rules' ),
			't_omnibus' => __( 'Χαμηλότερη τιμή 30 ημερών ({price})', 'discount-rules' ),
			't_xy_help' => __( 'Εξήγηση «πάρε Χ πλήρωσε Υ» στις λίστες, στο προϊόν και στη σελίδα της προσφοράς ({buy}, {pay})', 'discount-rules' ),
			't_xy_mix'  => __( 'Δεύτερη γραμμή όταν μετράνε μαζί όλα τα προϊόντα του κανόνα', 'discount-rules' ),
			't_more'    => __( 'Σύνδεσμος στη σελίδα με όλα τα προϊόντα της προσφοράς ({label})', 'discount-rules' ),
		);
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="dsr_action" value="save_texts" />
			<h2 class="dsr-h2"><?php esc_html_e( 'Γλώσσα κειμένων', 'discount-rules' ); ?></h2>
			<div class="dsr-zone">
				<?php
				self::radios(
					'dsr_lang',
					$s['lang'],
					array(
						'auto' => __( 'Αυτόματα, από τη γλώσσα του site', 'discount-rules' ),
						'el'   => __( 'Ελληνικά', 'discount-rules' ),
						'en'   => __( 'English', 'discount-rules' ),
					)
				);
				?>
			</div>
			<h2 class="dsr-h2"><?php esc_html_e( 'Κείμενα', 'discount-rules' ); ?></h2>
			<table class="widefat dsr-table dsr-texts">
				<thead><tr><th></th><th><?php esc_html_e( 'Ελληνικά', 'discount-rules' ); ?></th><th><?php esc_html_e( 'English', 'discount-rules' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $k => $label ) : ?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td><input type="text" name="dsr_t[<?php echo esc_attr( $k ); ?>][el]" value="<?php echo esc_attr( $s[ $k ]['el'] ); ?>" maxlength="<?php echo esc_attr( (string) DSR_Settings::TEXTS[ $k ] ); ?>" /></td>
						<td><input type="text" name="dsr_t[<?php echo esc_attr( $k ); ?>][en]" value="<?php echo esc_attr( $s[ $k ]['en'] ); ?>" maxlength="<?php echo esc_attr( (string) DSR_Settings::TEXTS[ $k ] ); ?>" /></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Απλό κείμενο. Κενό ελληνικό = το αρχικό κείμενο· κενό αγγλικό = δείχνει το ελληνικό.', 'discount-rules' ); ?></p>
			<p class="dsr-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'discount-rules' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		?>
		<h2 class="dsr-h2"><?php esc_html_e( 'Αντίγραφο ασφαλείας', 'discount-rules' ); ?></h2>
		<div class="dsr-zone">
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'tools', array( 'dsr_export' => 1 ) ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή κανόνων και ρυθμίσεων (JSON)', 'discount-rules' ); ?></a></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_BAK, 'dsr_backup_nonce' ); ?>
				<input type="hidden" name="dsr_import" value="1" />
				<p><input type="file" name="dsr_import_file" accept="application/json,.json" />
					<button type="submit" class="button"><?php esc_html_e( 'Εισαγωγή', 'discount-rules' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'Η εισαγωγή αντικαθιστά όλους τους κανόνες και τις ρυθμίσεις, με τον ίδιο έλεγχο με τις φόρμες. Η λειτουργία γίνεται «Ανενεργό» μέχρι να την αλλάξεις, ώστε να δεις πρώτα τους κανόνες. Ένα μη έγκυρο αρχείο δεν αλλάζει τίποτα.', 'discount-rules' ); ?></p>
			</form>
		</div>

		<h2 class="dsr-h2"><?php esc_html_e( 'Για προγραμματιστές', 'discount-rules' ); ?></h2>
		<table class="widefat dsr-table">
			<tbody>
				<tr><td><code>dsr_get_price( $product )</code></td><td><?php esc_html_e( 'Η τιμή ενός προϊόντος από κανόνα «Προσφορά» τώρα, για επισκέπτες (για feeds και schema), ή null.', 'discount-rules' ); ?></td></tr>
				<tr><td><code>dsr_get_item_rule( $item )</code></td><td><?php esc_html_e( 'Ο κανόνας μιας γραμμής παραγγελίας (κανόνας, τιμή πριν και μετά, έκπτωση), ή null.', 'discount-rules' ); ?></td></tr>
				<tr><td><code>dsr_rules_changed( $rule_ids )</code></td><td><?php esc_html_e( 'Action: μετά από κάθε αλλαγή κανόνα και όταν ένας κανόνας αρχίζει ή λήγει.', 'discount-rules' ); ?></td></tr>
				<tr><td><code>dsr_eligible( $ok, $product )</code></td><td><?php esc_html_e( 'Φίλτρο: false = το προϊόν δεν παίρνει ποτέ κανόνα (π.χ. δωροκάρτες).', 'discount-rules' ); ?></td></tr>
				<tr><td><code>dsr_line_discount( $discount, $rule_id, $product, $qty, $price )</code></td><td><?php esc_html_e( 'Φίλτρο: η έκπτωση μιας γραμμής του καλαθιού, η τελευταία λέξη (0 = καμία).', 'discount-rules' ); ?></td></tr>
				<tr><td><code>dsr_text( $text, $key, $lang )</code></td><td><?php esc_html_e( 'Φίλτρο: τα κείμενα του επισκέπτη.', 'discount-rules' ); ?></td></tr>
				<tr><td><code>discount-rules/rules</code>, <code>discount-rules/preview</code></td><td><?php esc_html_e( 'Abilities μόνο ανάγνωσης (WordPress 6.9+): οι κανόνες και τι θα έπαιρνε ένα καλάθι.', 'discount-rules' ); ?></td></tr>
			</tbody>
		</table>
		<?php
	}

	/* =====================================================================
	 * Routes (POST, PRG)
	 * =================================================================== */

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		// Import (own nonce, multipart form).
		if ( isset( $_POST['dsr_import'] ) ) {
			if ( ! isset( $_POST['dsr_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dsr_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'discount-rules' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['dsr_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'discount-rules' ) );
		}

		$id = (int) self::post( 'dsr_id' );
		switch ( sanitize_key( wp_unslash( $_POST['dsr_action'] ) ) ) {
			case 'save_rule':
				self::do_save_rule();
				break;
			case 'enable':
			case 'disable':
				$on = 'enable' === sanitize_key( wp_unslash( $_POST['dsr_action'] ) );
				self::redirect(
					'rules',
					array( DSR_Rules::set_on( $id, $on ) ? self::msg( 'success', $on ? __( 'Ο κανόνας ενεργοποιήθηκε.', 'discount-rules' ) : __( 'Ο κανόνας απενεργοποιήθηκε.', 'discount-rules' ) ) : self::msg( 'error', __( 'Ο κανόνας δεν βρέθηκε.', 'discount-rules' ) ) )
				);
				break;
			case 'copy':
				$new = DSR_Rules::copy( $id );
				self::redirect( 'rules', array( $new ? self::msg( 'success', __( 'Ο κανόνας αντιγράφηκε, ανενεργός. Άλλαξε ημερομηνίες και ενεργοποίησέ τον.', 'discount-rules' ) ) : self::msg( 'error', __( 'Ο κανόνας δεν βρέθηκε.', 'discount-rules' ) ) ) );
				break;
			case 'delete':
				self::redirect( 'rules', array( DSR_Rules::delete( $id ) ? self::msg( 'success', __( 'Ο κανόνας διαγράφηκε.', 'discount-rules' ) ) : self::msg( 'error', __( 'Ο κανόνας δεν βρέθηκε.', 'discount-rules' ) ) ) );
				break;
			case 'save_settings':
				self::redirect( 'settings', self::do_save_settings() );
				break;
			case 'save_texts':
				self::redirect( 'texts', self::do_save_texts() );
				break;
			default:
				self::redirect( 'rules', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'discount-rules' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs, array $args = array() ): void {
		set_transient( 'dsr_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by the validators). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by DSR_Rules::sanitize() / DSR_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	private static function do_save_rule(): void {
		$raw = self::post_array( 'dsr' );
		if ( ! isset( $raw['on'] ) ) {
			$raw['on'] = 'no';
		}
		if ( ! isset( $raw['all'] ) ) {
			$raw['all'] = 'no';
		}
		$res = DSR_Rules::save( $raw );
		if ( ! empty( $res['errors'] ) ) {
			set_transient( 'dsr_form_' . get_current_user_id(), $raw, 300 );
			$msgs = array();
			foreach ( $res['errors'] as $e ) {
				$msgs[] = self::msg( 'error', $e );
			}
			$id = isset( $raw['id'] ) ? (int) $raw['id'] : 0;
			self::redirect( 'edit', $msgs, $id > 0 ? array( 'rule' => $id ) : array() );
		}
		DSR_Engine::flush();
		$msgs = array( self::msg( 'success', __( 'Ο κανόνας αποθηκεύτηκε.', 'discount-rules' ) ) );
		foreach ( $res['warnings'] as $w ) {
			$msgs[] = self::msg( 'warning', $w );
		}
		if ( 'off' === DSR_Settings::get()['mode'] ) {
			$msgs[] = self::msg( 'info', __( 'Η λειτουργία είναι ανενεργή: ο κανόνας θα ισχύσει όταν τη βάλεις σε δοκιμή ή ενεργό, στις Ρυθμίσεις.', 'discount-rules' ) );
		}
		self::redirect( 'edit', $msgs, array( 'rule' => $res['id'] ) );
	}

	private static function do_save_settings(): array {
		$old                = DSR_Settings::get();
		$raw                = $old;
		$raw['mode']        = sanitize_key( self::post( 'dsr_mode' ) );
		$raw['cap']         = self::post( 'dsr_cap' );
		$raw['omnibus']     = sanitize_key( self::post( 'dsr_omnibus' ) );
		$raw['ex_others']   = 'yes' === self::post( 'dsr_ex_others' ) ? 'yes' : 'no';
		$raw['ex_preorder'] = 'yes' === self::post( 'dsr_ex_preorder' ) ? 'yes' : 'no';
		$raw['table']       = 'yes' === self::post( 'dsr_table' ) ? 'yes' : 'no';
		$raw['loop_label']  = 'yes' === self::post( 'dsr_loop_label' ) ? 'yes' : 'no';
		$raw['hint']        = 'yes' === self::post( 'dsr_hint' ) ? 'yes' : 'no';
		$terms              = self::post_array( 'dsr_cm_t' );
		$pcts               = self::post_array( 'dsr_cm_p' );
		$raw['cat_max']     = array();
		$bad                = false;
		foreach ( $terms as $i => $t ) {
			$t = (int) $t;
			$p = isset( $pcts[ $i ] ) && is_scalar( $pcts[ $i ] ) ? str_replace( ',', '.', trim( (string) $pcts[ $i ] ) ) : '';
			if ( $t <= 0 || '' === $p ) {
				continue;
			}
			if ( ! is_numeric( $p ) || (float) $p < 0 || (float) $p >= 100 ) {
				$bad = true;
				continue;
			}
			$raw['cat_max'][ $t ] = (float) $p;
		}
		DSR_Settings::save( $raw );
		$new = DSR_Settings::get();
		DSR_Engine::flush();

		$diff = array();
		foreach ( array( 'mode', 'cap', 'omnibus', 'ex_others', 'ex_preorder', 'table', 'loop_label', 'hint', 'cat_max' ) as $k ) {
			$a = is_array( $old[ $k ] ) ? (string) wp_json_encode( $old[ $k ] ) : (string) $old[ $k ];
			$b = is_array( $new[ $k ] ) ? (string) wp_json_encode( $new[ $k ] ) : (string) $new[ $k ];
			if ( $a !== $b ) {
				$diff[] = $k . ': ' . $a . ' → ' . $b;
			}
		}
		if ( $diff ) {
			DSR_Rules::log(
				'settings',
				array(
					'id'   => 0,
					'name' => '',
				),
				implode( '; ', $diff )
			);
			DSR_Rules::changed( array() );
		}
		if ( 'off' !== $new['omnibus'] ) {
			DSR_History::schedule();
		}
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'discount-rules' ) ) );
		if ( $bad ) {
			$msgs[] = self::msg( 'warning', __( 'Ένα όριο κατηγορίας δεν ήταν έγκυρο ποσοστό (0 έως 99,99) και δεν κρατήθηκε.', 'discount-rules' ) );
		}
		return $msgs;
	}

	private static function do_save_texts(): array {
		$raw         = DSR_Settings::get();
		$raw['lang'] = sanitize_key( self::post( 'dsr_lang' ) );
		$t           = self::post_array( 'dsr_t' );
		foreach ( array_keys( DSR_Settings::TEXTS ) as $k ) {
			if ( isset( $t[ $k ] ) && is_array( $t[ $k ] ) ) {
				$raw[ $k ] = array(
					'el' => isset( $t[ $k ]['el'] ) && is_string( $t[ $k ]['el'] ) ? $t[ $k ]['el'] : '',
					'en' => isset( $t[ $k ]['en'] ) && is_string( $t[ $k ]['en'] ) ? $t[ $k ]['en'] : '',
				);
			}
		}
		DSR_Settings::save( $raw );
		DSR_Rules::changed( array() );
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'discount-rules' ) ) );
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'dsr_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'discount-rules' ) );
		}
		$state = array(
			'plugin'  => 'discount-rules',
			'version' => DSR_VERSION,
			'options' => array(
				DSR_Settings::OPT => DSR_Settings::get(),
				DSR_Rules::OPT    => array_values( DSR_Rules::all() ),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="discount-rules-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/**
	 * Strict import: the blob goes through the same validation as the
	 * forms; the mode becomes «off» so the shop manager looks first. An
	 * invalid file changes nothing.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['dsr_import_file']['error'] ) ? (int) $_FILES['dsr_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['dsr_import_file']['size'] ) ? (int) $_FILES['dsr_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'discount-rules' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['dsr_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['dsr_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'discount-rules' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['dsr_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) || ! isset( $state['options'] ) || ! is_array( $state['options'] ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο αντίγραφο του Discount Rules.', 'discount-rules' ) ) );
		}
		$settings = isset( $state['options'][ DSR_Settings::OPT ] ) && is_array( $state['options'][ DSR_Settings::OPT ] ) ? $state['options'][ DSR_Settings::OPT ] : null;
		$rules    = isset( $state['options'][ DSR_Rules::OPT ] ) && is_array( $state['options'][ DSR_Rules::OPT ] ) ? $state['options'][ DSR_Rules::OPT ] : null;
		if ( null === $settings && null === $rules ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο αντίγραφο του Discount Rules.', 'discount-rules' ) ) );
		}
		$msgs = array();
		if ( null !== $rules ) {
			$n = empty( $rules ) ? 0 : DSR_Rules::replace_all( $rules );
			if ( $n <= 0 && ! empty( $rules ) ) {
				return array( self::msg( 'error', __( 'Κανένας έγκυρος κανόνας στο αρχείο: δεν άλλαξε τίποτα.', 'discount-rules' ) ) );
			}
			/* translators: %d: number of rules */
			$msgs[] = self::msg( 'success', sprintf( __( 'Εισαγωγή %d κανόνων.', 'discount-rules' ), $n ) );
		}
		$keep = DSR_Settings::get();
		$next = null !== $settings ? $settings : $keep;
		$next['mode'] = 'off';
		DSR_Settings::save( $next );
		DSR_Engine::flush();
		$msgs[] = self::msg( 'warning', __( 'Η λειτουργία έγινε «Ανενεργό»: έλεγξε τους κανόνες και άλλαξέ τη στις Ρυθμίσεις.', 'discount-rules' ) );
		return $msgs;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'dsr_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'dsr_aui_msg_' . get_current_user_id() );
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
		<p class="dsr-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'discount-rules' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="dsr-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="dsr-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'discount-rules' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="dsr-footer-cta">
			<a class="dsr-kofi" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '♡ Στήριξε το Noxpress', 'discount-rules' ); ?>
			</a>
			<a class="dsr-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'discount-rules' ); ?>
			</a>
		</p>
		<?php
	}
}
