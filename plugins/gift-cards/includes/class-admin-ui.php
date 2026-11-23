<?php
/**
 * GFC_Admin_UI — menu, pages, PRG routes, order meta box, backup
 * (Bible §3–§8, §11).
 *
 * Submenu «Δωροκάρτες» of the shared "Noxpress" menu (admin_menu priority
 * 400), slug gfc-cards, with tabs:
 *  - cards     list (status filter, search by last 4 / email / order) and
 *              the card page (?card=ID): details, history, actions
 *  - new       a card issued by hand (to an email, now or on a date)
 *  - summary   what the shop owes (open balances), issued / used / expired
 *              in a period, cards whose check failed
 *  - settings  mode, statuses, delivery, approval, expiry, limits, PDF
 *  - texts     customer texts (el / en)
 *  - tools     text language, backup, uninstall choice, filters and API
 *
 * Every change: POST on admin_init, slug + nonce + capability, strict
 * validation, PRG with the message in the transient gfc_aui_msg_{uid}.
 * Money actions carry a one-time token (uniq in the ledger), so a double
 * submit writes nothing the second time. Codes are never shown: "New code
 * and send" makes a new one and emails it (E3).
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'gfc-cards';
	const NONCE       = 'gfc_admin';
	const NONCE_FIELD = 'gfc_nonce';
	const NONCE_BAK   = 'gfc_backup';
	const MAX_IMPORT  = 262144;
	const PER_PAGE    = 50;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 400 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'order_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GFC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'cards'    => __( 'Κάρτες', 'gift-cards' ),
			'new'      => __( 'Νέα κάρτα', 'gift-cards' ),
			'summary'  => __( 'Σύνοψη', 'gift-cards' ),
			'settings' => __( 'Ρυθμίσεις', 'gift-cards' ),
			'texts'    => __( 'Κείμενα', 'gift-cards' ),
			'tools'    => __( 'Εργαλεία', 'gift-cards' ),
		);
	}

	/** Status => admin label. */
	public static function statuses(): array {
		return array(
			'pending'  => __( 'Προγραμματισμένη', 'gift-cards' ),
			'review'   => __( 'Σε αναμονή έγκρισης', 'gift-cards' ),
			'active'   => __( 'Ενεργή', 'gift-cards' ),
			'disabled' => __( 'Απενεργοποιημένη', 'gift-cards' ),
			'void'     => __( 'Ακυρωμένη', 'gift-cards' ),
			'expired'  => __( 'Έληξε', 'gift-cards' ),
			'locked'   => __( 'Κλειδωμένη (έλεγχος)', 'gift-cards' ),
		);
	}

	/** Ledger type => admin label. */
	private static function types(): array {
		return array(
			'issue'   => __( 'Έκδοση', 'gift-cards' ),
			'redeem'  => __( 'Χρήση', 'gift-cards' ),
			'restore' => __( 'Επαναφορά', 'gift-cards' ),
			'refund'  => __( 'Επιστροφή', 'gift-cards' ),
			'adjust'  => __( 'Διόρθωση', 'gift-cards' ),
			'void'    => __( 'Ακύρωση', 'gift-cards' ),
			'expire'  => __( 'Λήξη', 'gift-cards' ),
			'status'  => __( 'Κατάσταση', 'gift-cards' ),
			'recode'  => __( 'Νέος κωδικός', 'gift-cards' ),
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
			__( 'Gift Cards', 'gift-cards' ),
			__( 'Δωροκάρτες', 'gift-cards' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Δωροκάρτες', 'gift-cards' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'cards';
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'gfc-admin', GFC_URL . 'assets/admin.css', array(), GFC_VERSION );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'gift-cards' ) );
		}
		// Refund page from the order meta box (GET with nonce).
		if ( '' !== self::q( 'gfc_refund_order' ) ) {
			if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'gift-cards' ) );
			}
			$order = wc_get_order( absint( self::q( 'gfc_refund_order' ) ) );
			$card  = absint( self::q( 'gfc_card' ) );
			if ( $order instanceof WC_Order && isset( GFC_Redeem::stored( $order )[ $card ] ) ) {
				self::render_refund_page( $order, $card );
				return;
			}
		}
		$tab = self::current_tab();
		?>
		<div class="wrap gfc-wrap">
			<h1><?php esc_html_e( 'Gift Cards', 'gift-cards' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Δωροκάρτες με υπόλοιπο: πωλούνται ως προϊόν, φεύγουν με email και PDF, και πληρώνουν μέρος ή όλη την παραγγελία στο ταμείο. Κάθε κίνηση γράφεται στο ημερολόγιο της κάρτας.', 'gift-cards' ); ?></p>
			<?php
			if ( Gift_Cards::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το GFC_DISABLE είναι ενεργό στο wp-config.php: δωροκάρτες δεν πωλούνται, δεν εξαργυρώνονται και δεν στέλνονται. Τα υπόλοιπα και το ημερολόγιο μένουν όπως είναι.', 'gift-cards' ) . '</p></div>';
			} elseif ( 'test' === GFC_Settings::get()['mode'] ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος αγοράζουν και εξαργυρώνουν δωροκάρτες. Αλλαγή στις Ρυθμίσεις.', 'gift-cards' ) . '</p></div>';
			}
			$broken = GFC_Store::broken();
			if ( $broken ) {
				/* translators: %d: number of cards */
				echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Σε %d κάρτες το υπόλοιπο δεν συμφωνεί με το ημερολόγιο. Δες τη Σύνοψη.', 'gift-cards' ), count( $broken ) ) ) . '</p></div>';
			}
			self::print_notices( self::take_msgs() );
			?>
			<nav class="nav-tab-wrapper gfc-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( self::url( array( 'tab' => $slug ) ) ),
						$slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'new':
					self::render_new();
					break;
				case 'summary':
					self::render_summary();
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
					$id = absint( self::q( 'card' ) );
					if ( $id ) {
						self::render_card( $id );
					} else {
						self::render_list();
					}
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	/** One-time token for a money action (ledger uniq). */
	private static function token_field(): void {
		echo '<input type="hidden" name="gfc_token" value="' . esc_attr( bin2hex( random_bytes( 12 ) ) ) . '" />';
	}

	private static function check( string $name, bool $on, string $label ): void {
		echo '<label class="gfc-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	private static function select( string $name, string $value, array $opts, string $label ): void {
		echo '<p class="gfc-field"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '">';
		foreach ( $opts as $k => $l ) {
			echo '<option value="' . esc_attr( (string) $k ) . '" ' . selected( $value, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p>';
	}

	private static function input( string $name, string $value, string $label, string $after = '', string $type = 'text', string $class = 'gfc-num' ): void {
		echo '<p class="gfc-field"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" class="' . esc_attr( $class ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $type ? ' min="0"' : '' ) . ' />' . ( '' !== $after ? ' <span class="gfc-muted">' . esc_html( $after ) . '</span>' : '' ) . '</p>';
	}

	private static function decimal( int $cents ): string {
		return wc_format_localized_price( wc_format_decimal( $cents / 100, 2 ) );
	}

	private static function date( ?string $gmt, bool $time = true ): string {
		if ( ! $gmt ) {
			return '—';
		}
		$fmt = (string) get_option( 'date_format' ) . ( $time ? ' ' . (string) get_option( 'time_format' ) : '' );
		return wp_date( $fmt, (int) strtotime( $gmt . ' UTC' ) );
	}

	private static function badge( string $status ): string {
		$map = array(
			'active'   => 'ok',
			'pending'  => 'warn',
			'review'   => 'warn',
			'locked'   => 'danger',
			'disabled' => 'muted',
			'void'     => 'muted',
			'expired'  => 'muted',
		);
		$lab = self::statuses()[ $status ] ?? $status;
		return '<span class="gfc-badge gfc-badge--' . esc_attr( $map[ $status ] ?? 'muted' ) . '">' . esc_html( $lab ) . '</span>';
	}

	private static function order_link( int $order_id ): string {
		if ( ! $order_id ) {
			return '—';
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '#' . (int) $order_id;
		}
		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
	}

	/* ---------------------------------------------------------------------
	 * Cards list
	 * ------------------------------------------------------------------- */

	private static function render_list(): void {
		global $wpdb;
		$t      = GFC_Schema::cards();
		$status = sanitize_key( self::q( 'status' ) );
		$search = trim( self::q( 's' ) );
		$paged  = max( 1, absint( self::q( 'paged' ) ) );
		$where  = array( '1=1' );
		$args   = array();
		if ( isset( self::statuses()[ $status ] ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $search ) {
			$s4 = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', $search ) );
			$or = array( 'recipient_email LIKE %s', 'buyer_email LIKE %s', 'recipient_name LIKE %s' );
			$lk = '%' . $wpdb->esc_like( $search ) . '%';
			array_push( $args, $lk, $lk, $lk );
			if ( 4 === strlen( $s4 ) ) {
				$or[]   = 'last4 = %s';
				$args[] = $s4;
			}
			if ( ctype_digit( ltrim( $search, '#' ) ) ) {
				$or[]   = 'order_id = %d';
				$args[] = (int) ltrim( $search, '#' );
				$or[]   = 'id = %d';
				$args[] = (int) ltrim( $search, '#' );
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}
		$w = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- own table, placeholders built above.
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $w", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $w" ) );
		$q     = array_merge( $args, array( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $w ORDER BY id DESC LIMIT %d OFFSET %d", $q ), ARRAY_A );
		// phpcs:enable
		$rows = array_map( array( 'GFC_Store', 'cast' ), is_array( $rows ) ? $rows : array() );
		?>
		<form method="get" class="gfc-inline-form gfc-mt-8">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<select name="status">
				<option value=""><?php esc_html_e( 'Όλες οι καταστάσεις', 'gift-cards' ); ?></option>
				<?php foreach ( self::statuses() as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Τελευταία 4, email, όνομα ή παραγγελία', 'gift-cards' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Αναζήτηση', 'gift-cards' ); ?></button>
		</form>
		<?php if ( ! $rows ) : ?>
			<p class="gfc-empty"><?php esc_html_e( 'Δεν υπάρχουν δωροκάρτες ακόμα.', 'gift-cards' ); ?></p>
			<?php
			return;
		endif;
		?>
		<div class="gfc-scroll">
		<table class="widefat gfc-table">
			<thead><tr>
				<th>#</th>
				<th><?php esc_html_e( 'Κάρτα', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Αρχικό', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Υπόλοιπο', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Κατάσταση', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Παραλήπτης', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Παραγγελία', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Αποστολή', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Λήξη', 'gift-cards' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $c ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( self::url( array( 'card' => $c['id'] ) ) ); ?>"><?php echo (int) $c['id']; ?></a></td>
					<td><a href="<?php echo esc_url( self::url( array( 'card' => $c['id'] ) ) ); ?>"><?php echo esc_html( GFC_Codes::mask( (string) $c['last4'] ) ); ?></a></td>
					<td><?php echo esc_html( GFC_Settings::money( $c['initial'], $c['currency'] ) ); ?></td>
					<td><?php echo esc_html( GFC_Settings::money( $c['balance'], $c['currency'] ) ); ?></td>
					<td><?php echo self::badge( $c['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
					<td><?php echo esc_html( $c['for_me'] ? __( 'ο αγοραστής', 'gift-cards' ) : trim( $c['recipient_name'] . ' ' . ( $c['recipient_email'] ? '<' . $c['recipient_email'] . '>' : '' ) ) ); ?></td>
					<td><?php echo self::order_link( $c['order_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in order_link(). ?></td>
					<td><?php echo esc_html( $c['delivered_at'] ? self::date( $c['delivered_at'] ) : self::date( $c['deliver_at'] ) ); ?></td>
					<td><?php echo esc_html( self::date( $c['expires_at'], false ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="gfc-mt-8">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</p>';
		}
	}

	/* ---------------------------------------------------------------------
	 * One card
	 * ------------------------------------------------------------------- */

	private static function action_form( int $card_id, string $action, string $label, string $class = 'button', string $confirm = '' ): void {
		echo '<form method="post" class="gfc-inline-form">';
		self::nonce_field();
		self::token_field();
		echo '<input type="hidden" name="gfc_action" value="' . esc_attr( $action ) . '" /><input type="hidden" name="gfc_card" value="' . (int) $card_id . '" />';
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . ( '' !== $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}

	private static function render_card( int $id ): void {
		$c = GFC_Store::get( $id );
		echo '<p class="gfc-mt-8"><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Όλες οι κάρτες', 'gift-cards' ) . '</a></p>';
		if ( ! $c ) {
			echo '<p class="gfc-empty">' . esc_html__( 'Η κάρτα δεν βρέθηκε.', 'gift-cards' ) . '</p>';
			return;
		}
		$cur = $c['currency'];
		?>
		<h2 class="gfc-h2">
			<?php
			/* translators: 1: id, 2: masked code */
			echo esc_html( sprintf( __( 'Δωροκάρτα #%1$d · %2$s', 'gift-cards' ), $c['id'], GFC_Codes::mask( (string) $c['last4'] ) ) );
			echo ' ' . self::badge( $c['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			?>
		</h2>
		<div class="gfc-grid gfc-grid-kpi">
			<div class="gfc-card"><div class="gfc-card-label"><?php esc_html_e( 'Υπόλοιπο', 'gift-cards' ); ?></div><div class="gfc-card-value"><?php echo esc_html( GFC_Settings::money( $c['balance'], $cur ) ); ?></div><div class="gfc-card-sub"><?php /* translators: %s: amount */ echo esc_html( sprintf( __( 'από %s', 'gift-cards' ), GFC_Settings::money( $c['initial'], $cur ) ) ); ?></div></div>
			<div class="gfc-card"><div class="gfc-card-label"><?php esc_html_e( 'Παραλήπτης', 'gift-cards' ); ?></div><div class="gfc-card-value"><?php echo esc_html( $c['recipient_name'] ? $c['recipient_name'] : '—' ); ?></div><div class="gfc-card-sub"><?php echo esc_html( $c['recipient_email'] ); ?></div></div>
			<div class="gfc-card"><div class="gfc-card-label"><?php esc_html_e( 'Αποστολή', 'gift-cards' ); ?></div><div class="gfc-card-value"><?php echo esc_html( $c['delivered_at'] ? self::date( $c['delivered_at'] ) : self::date( $c['deliver_at'] ) ); ?></div><div class="gfc-card-sub"><?php echo esc_html( $c['delivered_at'] ? __( 'στάλθηκε', 'gift-cards' ) : __( 'προγραμματισμένη', 'gift-cards' ) ); ?></div></div>
			<div class="gfc-card"><div class="gfc-card-label"><?php esc_html_e( 'Λήξη', 'gift-cards' ); ?></div><div class="gfc-card-value"><?php echo esc_html( $c['expires_at'] ? self::date( $c['expires_at'], false ) : __( 'Χωρίς λήξη', 'gift-cards' ) ); ?></div></div>
		</div>
		<table class="widefat gfc-table gfc-mt-8"><tbody>
			<tr><td><?php esc_html_e( 'Προέλευση', 'gift-cards' ); ?></td><td><?php echo 'order' === $c['source'] ? self::order_link( $c['order_id'] ) : esc_html__( 'Έκδοση από το admin', 'gift-cards' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in order_link(). ?></td></tr>
			<tr><td><?php esc_html_e( 'Αγοραστής', 'gift-cards' ); ?></td><td><?php echo esc_html( $c['buyer_email'] ? $c['buyer_email'] : '—' ); ?><?php echo $c['for_me'] ? ' · ' . esc_html__( 'για τον ίδιο', 'gift-cards' ) : ''; ?></td></tr>
			<tr><td><?php esc_html_e( 'Από', 'gift-cards' ); ?></td><td><?php echo esc_html( $c['sender_name'] ? $c['sender_name'] : '—' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Μήνυμα', 'gift-cards' ); ?></td><td><?php echo nl2br( esc_html( $c['message'] ? (string) $c['message'] : '—' ) ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Γλώσσα', 'gift-cards' ); ?></td><td><?php echo esc_html( 'en' === $c['lang'] ? 'English' : 'Ελληνικά' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Δημιουργήθηκε', 'gift-cards' ); ?></td><td><?php echo esc_html( self::date( $c['created_at'] ) ); ?></td></tr>
		</tbody></table>

		<h3 class="gfc-h3"><?php esc_html_e( 'Ενέργειες', 'gift-cards' ); ?></h3>
		<div class="gfc-zone">
			<?php
			$s = $c['status'];
			if ( 'review' === $s ) {
				self::action_form( $id, 'approve', __( 'Έγκριση και αποστολή', 'gift-cards' ), 'button button-primary' );
			}
			if ( 'pending' === $s && ! $c['delivered_at'] ) {
				self::action_form( $id, 'send_now', __( 'Αποστολή τώρα', 'gift-cards' ), 'button button-primary' );
			}
			if ( 'active' === $s ) {
				self::action_form( $id, 'disable', __( 'Απενεργοποίηση', 'gift-cards' ) );
			}
			if ( 'disabled' === $s ) {
				self::action_form( $id, 'enable', __( 'Ενεργοποίηση', 'gift-cards' ) );
			}
			if ( in_array( $s, array( 'active', 'disabled' ), true ) ) {
				self::action_form( $id, 'recode', __( 'Νέος κωδικός και αποστολή', 'gift-cards' ), 'button', __( 'Ο τωρινός κωδικός θα σταματήσει να ισχύει και θα σταλεί νέος στον κάτοχο. Συνέχεια;', 'gift-cards' ) );
			}
			if ( 'locked' === $s ) {
				self::action_form( $id, 'unlock', __( 'Ξεκλείδωμα (αν το υπόλοιπο συμφωνεί με το ημερολόγιο)', 'gift-cards' ) );
			}
			$unused = in_array( $s, array( 'pending', 'review' ), true ) || ( in_array( $s, array( 'active', 'disabled', 'expired' ), true ) && $c['balance'] === $c['initial'] );
			if ( $unused ) {
				self::action_form( $id, 'void', __( 'Ακύρωση κάρτας', 'gift-cards' ), 'button', __( 'Η κάρτα ακυρώνεται και το υπόλοιπό της μηδενίζεται. Δεν αναιρείται. Συνέχεια;', 'gift-cards' ) );
			}
			?>
			<p class="description gfc-mt-8"><?php esc_html_e( 'Ο κωδικός δεν φυλάγεται πουθενά, ούτε εδώ. Αν ο πελάτης τον χάσει, «Νέος κωδικός και αποστολή» στέλνει καινούργιο με το ίδιο υπόλοιπο.', 'gift-cards' ); ?></p>
		</div>

		<?php if ( in_array( $s, array( 'active', 'disabled', 'expired' ), true ) ) : ?>
		<h3 class="gfc-h3"><?php esc_html_e( 'Διόρθωση υπολοίπου', 'gift-cards' ); ?></h3>
		<div class="gfc-zone">
			<form method="post">
				<?php self::nonce_field(); ?>
				<?php self::token_field(); ?>
				<input type="hidden" name="gfc_action" value="adjust" />
				<input type="hidden" name="gfc_card" value="<?php echo (int) $id; ?>" />
				<?php
				self::select(
					'gfc_dir',
					'add',
					array(
						'add' => __( 'Προσθήκη', 'gift-cards' ),
						'sub' => __( 'Αφαίρεση', 'gift-cards' ),
					),
					__( 'Κίνηση', 'gift-cards' )
				);
				self::input( 'gfc_amount', '', __( 'Ποσό', 'gift-cards' ), get_woocommerce_currency_symbol( $cur ) );
				self::input( 'gfc_note', '', __( 'Αιτία (υποχρεωτική)', 'gift-cards' ), '', 'text', 'regular-text' );
				?>
				<p class="gfc-submit"><button type="submit" class="button"><?php esc_html_e( 'Καταχώριση', 'gift-cards' ); ?></button></p>
			</form>
		</div>
		<?php endif; ?>

		<?php if ( in_array( $s, array( 'pending', 'review' ), true ) ) : ?>
		<h3 class="gfc-h3"><?php esc_html_e( 'Στοιχεία αποστολής', 'gift-cards' ); ?></h3>
		<div class="gfc-zone">
			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="gfc_action" value="delivery" />
				<input type="hidden" name="gfc_card" value="<?php echo (int) $id; ?>" />
				<?php
				self::input( 'gfc_rname', (string) $c['recipient_name'], __( 'Όνομα παραλήπτη', 'gift-cards' ), '', 'text', 'regular-text' );
				self::input( 'gfc_remail', (string) $c['recipient_email'], __( 'Email παραλήπτη', 'gift-cards' ), '', 'email', 'regular-text' );
				self::input( 'gfc_date', $c['deliver_at'] ? wp_date( 'Y-m-d', (int) strtotime( $c['deliver_at'] . ' UTC' ) ) : '', __( 'Ημερομηνία αποστολής', 'gift-cards' ), __( 'κενό = αμέσως', 'gift-cards' ), 'date', '' );
				?>
				<p class="gfc-submit"><button type="submit" class="button"><?php esc_html_e( 'Αποθήκευση', 'gift-cards' ); ?></button></p>
			</form>
		</div>
		<?php endif; ?>

		<h3 class="gfc-h3"><?php esc_html_e( 'Ημερολόγιο', 'gift-cards' ); ?></h3>
		<div class="gfc-scroll">
		<table class="widefat gfc-table">
			<thead><tr>
				<th><?php esc_html_e( 'Ημερομηνία', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Κίνηση', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Ποσό', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Υπόλοιπο μετά', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Παραγγελία', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Χρήστης', 'gift-cards' ); ?></th>
				<th><?php esc_html_e( 'Σημείωση', 'gift-cards' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( GFC_Store::ledger( $id ) as $r ) :
				$u     = (int) $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : null;
				$delta = (int) $r['delta'];
				?>
				<tr>
					<td><?php echo esc_html( self::date( $r['created_at'] ) ); ?></td>
					<td><?php echo esc_html( self::types()[ $r['type'] ] ?? $r['type'] ); ?></td>
					<td><?php echo esc_html( 0 === $delta ? '—' : ( $delta > 0 ? '+' : '−' ) . GFC_Settings::money( abs( $delta ), $cur ) ); ?></td>
					<td><?php echo esc_html( GFC_Settings::money( (int) $r['balance_after'], $cur ) ); ?></td>
					<td><?php echo self::order_link( (int) $r['order_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in order_link(). ?></td>
					<td><?php echo esc_html( $u ? $u->display_name : '—' ); ?></td>
					<td><?php echo esc_html( (string) $r['note'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * New card
	 * ------------------------------------------------------------------- */

	private static function render_new(): void {
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<?php self::token_field(); ?>
			<input type="hidden" name="gfc_action" value="new_card" />
			<h2 class="gfc-h2"><?php esc_html_e( 'Νέα δωροκάρτα από το admin', 'gift-cards' ); ?></h2>
			<div class="gfc-zone">
				<?php
				self::input( 'gfc_amount', '', __( 'Ποσό', 'gift-cards' ), get_woocommerce_currency_symbol() );
				self::input( 'gfc_rname', '', __( 'Όνομα παραλήπτη', 'gift-cards' ), '', 'text', 'regular-text' );
				self::input( 'gfc_remail', '', __( 'Email παραλήπτη', 'gift-cards' ), '', 'email', 'regular-text' );
				self::input( 'gfc_sender', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), __( 'Από', 'gift-cards' ), '', 'text', 'regular-text' );
				?>
				<p class="gfc-field"><span><?php esc_html_e( 'Μήνυμα', 'gift-cards' ); ?></span><textarea name="gfc_message" rows="3" class="large-text" maxlength="<?php echo (int) GFC_Product::MSG_MAX; ?>"></textarea></p>
				<?php
				self::input( 'gfc_date', '', __( 'Ημερομηνία αποστολής', 'gift-cards' ), __( 'κενό = αμέσως', 'gift-cards' ), 'date', '' );
				self::select(
					'gfc_lang',
					GFC_Settings::visitor_lang(),
					array(
						'el' => 'Ελληνικά',
						'en' => 'English',
					),
					__( 'Γλώσσα του email', 'gift-cards' )
				);
				self::input( 'gfc_note', '', __( 'Αιτία (για το ημερολόγιο)', 'gift-cards' ), __( 'π.χ. δώρο σε διαγωνισμό, αποζημίωση', 'gift-cards' ), 'text', 'regular-text' );
				?>
				<p class="description"><?php esc_html_e( 'Η κάρτα στέλνεται με email και PDF στον παραλήπτη. Δεν συνδέεται με παραγγελία και μετράει στο υπόλοιπο που χρωστά το κατάστημα.', 'gift-cards' ); ?></p>
			</div>
			<p class="gfc-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Δημιουργία και αποστολή', 'gift-cards' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Summary
	 * ------------------------------------------------------------------- */

	private static function date_q( string $key, string $default ): string {
		$v = self::q( $key );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $default;
	}

	private static function render_summary(): void {
		global $wpdb;
		$c    = GFC_Schema::cards();
		$l    = GFC_Schema::ledger();
		$from = self::date_q( 'from', wp_date( 'Y-m-01' ) );
		$to   = self::date_q( 'to', wp_date( 'Y-m-d' ) );
		$tz   = wp_timezone();
		$f    = ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$t    = ( new DateTimeImmutable( $to . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$owed = $wpdb->get_results( "SELECT currency, SUM(balance) AS s, COUNT(*) AS n FROM $c WHERE status IN ('pending','review','active','disabled','locked') AND balance > 0 GROUP BY currency", ARRAY_A );
		$mov  = $wpdb->get_results( $wpdb->prepare( "SELECT c.currency, l.type, SUM(l.delta) AS s, COUNT(*) AS n FROM $l l JOIN $c c ON c.id = l.card_id WHERE l.created_at BETWEEN %s AND %s AND l.delta <> 0 GROUP BY c.currency, l.type", $f, $t ), ARRAY_A );
		$byst = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $c GROUP BY status", ARRAY_A );
		// phpcs:enable
		?>
		<h2 class="gfc-h2"><?php esc_html_e( 'Τι χρωστά το κατάστημα', 'gift-cards' ); ?></h2>
		<div class="gfc-grid gfc-grid-kpi">
			<?php if ( ! $owed ) : ?>
				<div class="gfc-card"><div class="gfc-card-label"><?php esc_html_e( 'Ανοιχτά υπόλοιπα', 'gift-cards' ); ?></div><div class="gfc-card-value"><?php echo esc_html( GFC_Settings::money( 0 ) ); ?></div></div>
			<?php endif; ?>
			<?php foreach ( (array) $owed as $o ) : ?>
				<div class="gfc-card"><div class="gfc-card-label"><?php esc_html_e( 'Ανοιχτά υπόλοιπα', 'gift-cards' ); ?></div><div class="gfc-card-value"><?php echo esc_html( GFC_Settings::money( (int) $o['s'], (string) $o['currency'] ) ); ?></div><div class="gfc-card-sub"><?php /* translators: %d: number of cards */ echo esc_html( sprintf( _n( '%d κάρτα', '%d κάρτες', (int) $o['n'], 'gift-cards' ), (int) $o['n'] ) ); ?></div></div>
			<?php endforeach; ?>
		</div>
		<p class="description gfc-mt-8"><?php esc_html_e( 'Το υπόλοιπο των καρτών είναι χρήματα που πήρε ήδη το κατάστημα και θα δώσει σε προϊόντα. Ο λογιστής σου ξέρει πώς καταχωρούνται (προκαταβολή ή έσοδο, ΦΠΑ στην πώληση ή στη χρήση).', 'gift-cards' ); ?></p>

		<h2 class="gfc-h2"><?php esc_html_e( 'Κινήσεις περιόδου', 'gift-cards' ); ?></h2>
		<form method="get" class="gfc-inline-form gfc-period">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="summary" />
			<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /> – <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Εμφάνιση', 'gift-cards' ); ?></button>
		</form>
		<?php if ( ! $mov ) : ?>
			<p class="gfc-empty"><?php esc_html_e( 'Καμία κίνηση στην περίοδο.', 'gift-cards' ); ?></p>
		<?php else : ?>
			<table class="widefat gfc-table gfc-mt-8">
				<thead><tr><th><?php esc_html_e( 'Κίνηση', 'gift-cards' ); ?></th><th><?php esc_html_e( 'Πλήθος', 'gift-cards' ); ?></th><th><?php esc_html_e( 'Ποσό', 'gift-cards' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $mov as $m ) : ?>
					<tr>
						<td><?php echo esc_html( self::types()[ $m['type'] ] ?? $m['type'] ); ?></td>
						<td><?php echo (int) $m['n']; ?></td>
						<td><?php echo esc_html( ( (int) $m['s'] > 0 ? '+' : '−' ) . GFC_Settings::money( abs( (int) $m['s'] ), (string) $m['currency'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 class="gfc-h2"><?php esc_html_e( 'Κάρτες ανά κατάσταση', 'gift-cards' ); ?></h2>
		<p class="gfc-statuses">
			<?php
			foreach ( (array) $byst as $b ) {
				echo '<a href="' . esc_url( self::url( array( 'status' => $b['status'] ) ) ) . '">' . self::badge( (string) $b['status'] ) . ' ' . (int) $b['n'] . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			}
			?>
		</p>

		<h2 class="gfc-h2"><?php esc_html_e( 'Έλεγχος ημερολογίου', 'gift-cards' ); ?></h2>
		<?php
		$broken = GFC_Store::broken();
		if ( ! $broken ) {
			echo '<p>' . esc_html__( 'Σε όλες τις κάρτες το υπόλοιπο ισούται με το άθροισμα των κινήσεών τους.', 'gift-cards' ) . '</p>';
			return;
		}
		echo '<table class="widefat gfc-table"><thead><tr><th>#</th><th>' . esc_html__( 'Υπόλοιπο', 'gift-cards' ) . '</th><th>' . esc_html__( 'Άθροισμα κινήσεων', 'gift-cards' ) . '</th></tr></thead><tbody>';
		foreach ( $broken as $b ) {
			echo '<tr><td><a href="' . esc_url( self::url( array( 'card' => $b['id'] ) ) ) . '">' . (int) $b['id'] . '</a></td><td>' . esc_html( self::decimal( $b['balance'] ) ) . '</td><td>' . esc_html( self::decimal( $b['ledger'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description gfc-mt-8">' . esc_html__( 'Ο ημερήσιος έλεγχος κλειδώνει αυτές τις κάρτες. Δεν διορθώνονται αυτόματα: χρειάζεται έλεγχος στη βάση από τον προγραμματιστή.', 'gift-cards' ) . '</p>';
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s = GFC_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="gfc_action" value="save_settings" />

			<h2 class="gfc-h2"><?php esc_html_e( 'Λειτουργία', 'gift-cards' ); ?></h2>
			<div class="gfc-zone">
				<label class="gfc-check"><input type="radio" name="gfc_mode" value="test" <?php checked( $s['mode'], 'test' ); ?> /> <?php esc_html_e( 'Δοκιμή: μόνο οι διαχειριστές του καταστήματος αγοράζουν και εξαργυρώνουν', 'gift-cards' ); ?></label>
				<label class="gfc-check"><input type="radio" name="gfc_mode" value="live" <?php checked( $s['mode'], 'live' ); ?> /> <?php esc_html_e( 'Ενεργό για όλους', 'gift-cards' ); ?></label>
				<p class="description"><?php esc_html_e( 'Για προϊόν δωροκάρτας: Προϊόντα › Προσθήκη, τύπος «Δωροκάρτα». Τα ποσά ορίζονται στο προϊόν.', 'gift-cards' ); ?></p>
			</div>

			<h2 class="gfc-h2"><?php esc_html_e( 'Έκδοση και αποστολή', 'gift-cards' ); ?></h2>
			<div class="gfc-zone">
				<p class="gfc-label"><?php esc_html_e( 'Η κάρτα δημιουργείται όταν η παραγγελία γίνει:', 'gift-cards' ); ?></p>
				<?php
				$labels = array(
					'processing' => __( 'Σε επεξεργασία', 'gift-cards' ),
					'completed'  => __( 'Ολοκληρωμένη', 'gift-cards' ),
					'on-hold'    => __( 'Σε αναμονή (όχι προτεινόμενο)', 'gift-cards' ),
				);
				foreach ( $labels as $k => $lab ) {
					echo '<label class="gfc-check"><input type="checkbox" name="gfc_paid_statuses[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, $s['paid_statuses'], true ), true, false ) . ' /> ' . esc_html( $lab ) . '</label>';
				}
				echo '<p class="description">' . esc_html__( 'Αντικαταβολή: η κάρτα δημιουργείται μόνο όταν η παραγγελία γίνει «Ολοκληρωμένη», γιατί η αντικαταβολή τη βάζει «Σε επεξεργασία» πριν πληρωθεί. Τραπεζική κατάθεση και επιταγή: μόνο όταν την περάσεις εσύ σε «Σε επεξεργασία» ή «Ολοκληρωμένη».', 'gift-cards' ) . '</p>';
				self::input( 'gfc_delay_hours', (string) $s['delay_hours'], __( 'Καθυστέρηση αποστολής', 'gift-cards' ), __( 'ώρες μετά την πληρωμή (0 = αμέσως)', 'gift-cards' ), 'number' );
				self::input( 'gfc_review_over', $s['review_over'] ? self::decimal( $s['review_over'] ) : '', __( 'Έγκριση με το χέρι πάνω από', 'gift-cards' ), __( 'σύνολο δωροκαρτών μιας παραγγελίας· κενό = ποτέ', 'gift-cards' ) );
				?>
				<p class="description"><?php esc_html_e( 'Η καθυστέρηση και η έγκριση βοηθούν απέναντι σε κλεμμένες κάρτες πληρωμής: η δωροκάρτα δεν φεύγει πριν ελέγξεις την παραγγελία.', 'gift-cards' ); ?></p>
			</div>

			<h2 class="gfc-h2"><?php esc_html_e( 'Λήξη', 'gift-cards' ); ?></h2>
			<div class="gfc-zone">
				<?php
				self::input( 'gfc_expiry_months', (string) $s['expiry_months'], __( 'Ισχύς', 'gift-cards' ), __( 'μήνες από την αποστολή (0 = χωρίς λήξη)', 'gift-cards' ), 'number' );
				self::input( 'gfc_remind_days', (string) $s['remind_days'], __( 'Υπενθύμιση', 'gift-cards' ), __( 'ημέρες πριν τη λήξη (0 = καμία)', 'gift-cards' ), 'number' );
				?>
				<p class="description"><?php esc_html_e( 'Η λήξη ισχύει για κάρτες που στέλνονται μετά την αλλαγή· όσες στάλθηκαν κρατούν τη δική τους.', 'gift-cards' ); ?></p>
			</div>

			<h2 class="gfc-h2"><?php esc_html_e( 'Απόρρητο', 'gift-cards' ); ?></h2>
			<div class="gfc-zone">
				<?php
				self::input( 'gfc_keep_days', (string) $s['keep_days'], __( 'Ονόματα και μήνυμα', 'gift-cards' ), __( 'σβήνονται τόσες ημέρες αφού η κάρτα εξαντληθεί, λήξει ή ακυρωθεί (0 = ποτέ)', 'gift-cards' ), 'number' );
				?>
				<p class="description"><?php esc_html_e( 'Τα ποσά και οι κινήσεις μένουν ως λογιστικό αρχείο. Τα email σβήνονται όταν ο πελάτης ζητήσει διαγραφή από τα Εργαλεία → Διαγραφή προσωπικών δεδομένων.', 'gift-cards' ); ?></p>
			</div>

			<h2 class="gfc-h2"><?php esc_html_e( 'Ταμείο', 'gift-cards' ); ?></h2>
			<div class="gfc-zone">
				<?php
				self::check( 'gfc_no_card_for_gc', $s['no_card_for_gc'], __( 'Δωροκάρτα δεν αγοράζεται με δωροκάρτα', 'gift-cards' ) );
				self::check( 'gfc_restore_refund', $s['restore_refund'], __( 'Όταν η παραγγελία επιστραφεί ολόκληρη, το ποσό της δωροκάρτας γυρνά στην κάρτα', 'gift-cards' ) );
				self::input( 'gfc_max_cards', (string) $s['max_cards'], __( 'Κάρτες ανά παραγγελία', 'gift-cards' ), '', 'number' );
				self::input( 'gfc_pending_hours', (string) $s['pending_hours'], __( 'Απλήρωτες παραγγελίες με κάρτα', 'gift-cards' ), __( 'ακυρώνονται μετά από τόσες ώρες και το ποσό γυρνά στην κάρτα', 'gift-cards' ), 'number' );
				?>
			</div>

			<h2 class="gfc-h2"><?php esc_html_e( 'Προστασία από δοκιμές κωδικών', 'gift-cards' ); ?></h2>
			<div class="gfc-zone">
				<?php
				self::input( 'gfc_limit_n', (string) $s['limit_n'], __( 'Προσπάθειες', 'gift-cards' ), __( 'ανά IP και ανά επισκέπτη', 'gift-cards' ), 'number' );
				self::input( 'gfc_limit_min', (string) $s['limit_min'], __( 'Μέσα σε', 'gift-cards' ), __( 'λεπτά', 'gift-cards' ), 'number' );
				self::input( 'gfc_site_fail_hour', (string) $s['site_fail_hour'], __( 'Λάθος κωδικοί σε όλο το site', 'gift-cards' ), __( 'την ώρα· πάνω από αυτό το πεδίο κλείνει για μία ώρα και έρχεται email', 'gift-cards' ), 'number' );
				?>
			</div>

			<h2 class="gfc-h2"><?php esc_html_e( 'PDF', 'gift-cards' ); ?></h2>
			<div class="gfc-zone">
				<?php
				self::check( 'gfc_pdf', $s['pdf'], __( 'Εκτυπώσιμη κάρτα PDF συνημμένη στο email', 'gift-cards' ) );
				self::select(
					'gfc_pdf_size',
					$s['pdf_size'],
					array(
						'a5' => __( 'A5 οριζόντιο', 'gift-cards' ),
						'a4' => __( 'A4 όρθιο', 'gift-cards' ),
					),
					__( 'Μέγεθος', 'gift-cards' )
				);
				if ( ! GFC_PDF::available() ) {
					echo '<p class="gfc-warn">' . esc_html__( 'Η γραμματοσειρά του PDF λείπει ή δεν διαβάζεται: τα emails φεύγουν χωρίς PDF.', 'gift-cards' ) . '</p>';
				}
				?>
				<p class="description"><?php esc_html_e( 'Το PDF έχει το λογότυπο του site ή την εικόνα του προϊόντος, το ποσό, το μήνυμα και τον κωδικό. Φτιάχνεται τη στιγμή της αποστολής και δεν μένει στον server.', 'gift-cards' ); ?></p>
			</div>

			<p class="gfc-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'gift-cards' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_texts(): void {
		$s = GFC_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="gfc_action" value="save_texts" />
			<table class="widefat gfc-table gfc-texts gfc-mt-8">
				<thead><tr><th><?php esc_html_e( 'Κείμενο', 'gift-cards' ); ?></th><th><?php esc_html_e( 'Ελληνικά', 'gift-cards' ); ?></th><th><?php esc_html_e( 'English', 'gift-cards' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( GFC_Settings::text_defs() as $key => $def ) : ?>
					<tr>
						<td><?php echo esc_html( $def[0] ); ?></td>
						<?php
						foreach ( array( 'el' => 1, 'en' => 2 ) as $l => $i ) {
							$val = (string) ( $s['texts'][ $key ][ $l ] ?? '' );
							echo '<td><input type="text" name="' . esc_attr( 'gfc_t[' . $key . '][' . $l . ']' ) . '" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $def[ $i ] ) . '" maxlength="400" /></td>';
						}
						?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description gfc-mt-8"><?php esc_html_e( 'Κενό = το κείμενο που φαίνεται αχνό. Κενό αγγλικό = δείχνει το ελληνικό. Οι λέξεις σε άγκιστρα, όπως {amount}, συμπληρώνονται μόνες τους. Τα θέματα των emails αλλάζουν και στο WooCommerce › Ρυθμίσεις › Emails.', 'gift-cards' ); ?></p>
			<p class="gfc-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'gift-cards' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_tools(): void {
		$s = GFC_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="gfc_action" value="save_tools" />
			<h2 class="gfc-h2"><?php esc_html_e( 'Γλώσσα και uninstall', 'gift-cards' ); ?></h2>
			<div class="gfc-zone">
				<?php
				self::select(
					'gfc_lang',
					$s['lang'],
					array(
						'auto' => __( 'Αυτόματα (γλώσσα του site)', 'gift-cards' ),
						'el'   => __( 'Ελληνικά', 'gift-cards' ),
						'en'   => __( 'English', 'gift-cards' ),
					),
					__( 'Γλώσσα κειμένων για τον πελάτη', 'gift-cards' )
				);
				self::check( 'gfc_delete_tick', 'ΔΙΑΓΡΑΦΗ' === $s['delete_data'], __( 'Διαγραφή όλων των δωροκαρτών και του ημερολογίου τους όταν διαγραφεί το plugin', 'gift-cards' ) );
				self::input( 'gfc_delete_word', '', __( 'Γράψε ΔΙΑΓΡΑΦΗ για επιβεβαίωση', 'gift-cards' ), '', 'text', 'regular-text' );
				?>
				<p class="description"><?php esc_html_e( 'Χωρίς αυτή την επιλογή οι κάρτες μένουν μετά τη διαγραφή του plugin: είναι χρήματα που χρωστά το κατάστημα στους πελάτες, και ξαναδουλεύουν αν το plugin εγκατασταθεί πάλι.', 'gift-cards' ); ?></p>
			</div>
			<p class="gfc-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'gift-cards' ); ?></button></p>
		</form>

		<h2 class="gfc-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'gift-cards' ); ?></h2>
		<div class="gfc-zone">
			<form method="post" enctype="multipart/form-data" class="gfc-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'gfc_backup_nonce' ); ?>
				<input type="file" name="gfc_import_file" accept=".json,application/json" />
				<button type="submit" name="gfc_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'gift-cards' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( array( 'tab' => 'tools', 'gfc_export' => 1 ) ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'gift-cards' ); ?></a>
			<p class="description gfc-mt-8"><?php esc_html_e( 'Το backup έχει τις ρυθμίσεις και τα κείμενα, όχι τις κάρτες ούτε την επιλογή διαγραφής. Οι κάρτες είναι στους πίνακες της βάσης και μπαίνουν στο backup της βάσης.', 'gift-cards' ); ?></p>
		</div>

		<h2 class="gfc-h2"><?php esc_html_e( 'Για προγραμματιστές', 'gift-cards' ); ?></h2>
		<div class="gfc-zone">
			<table class="widefat gfc-table">
				<tbody>
					<tr><td><code>gfc_is_gift_card_product( $product )</code></td><td><?php esc_html_e( 'Αν ένα προϊόν είναι δωροκάρτα. Plugins εκπτώσεων και τιμών τα παραλείπουν.', 'gift-cards' ); ?></td></tr>
					<tr><td><code>gfc_order_cards( $order )</code></td><td><?php esc_html_e( 'Οι δωροκάρτες που πλήρωσαν μια παραγγελία, με ποσό και επιστροφές.', 'gift-cards' ); ?></td></tr>
					<tr><td><code>noxpress_is_stored_value_item</code></td><td><?php esc_html_e( 'Φίλτρο της σουίτας: true για γραμμή παραγγελίας που είναι δωροκάρτα (δεν είναι πώληση προϊόντος).', 'gift-cards' ); ?></td></tr>
					<tr><td><code>gfc_text</code></td><td><?php esc_html_e( 'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).', 'gift-cards' ); ?></td></tr>
					<tr><td><code>GFC_DISABLE</code></td><td><?php esc_html_e( 'Σταθερά στο wp-config.php: σταματά πώληση, εξαργύρωση και αποστολή χωρίς να αγγίξει τα υπόλοιπα.', 'gift-cards' ); ?></td></tr>
					<tr><td><code>wp gift-cards</code></td><td><?php esc_html_e( 'WP-CLI: λίστα, σύνοψη και έλεγχος ημερολογίου (μόνο ανάγνωση).', 'gift-cards' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * Order meta box (refund to a card)
	 * =================================================================== */

	public static function meta_box( $post_type, $post_or_order ): void {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && function_exists( 'wc_get_container' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		if ( $post_type !== $screen && 'shop_order' !== $post_type && 'woocommerce_page_wc-orders' !== $post_type ) {
			return;
		}
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order instanceof WP_Post ? $post_or_order->ID : 0 );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! GFC_Redeem::stored( $order ) && ! GFC_Store::by_order( $order->get_id() ) ) {
			return;
		}
		add_meta_box( 'gfc-order', __( 'Δωροκάρτες', 'gift-cards' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	public static function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order instanceof WP_Post ? $post_or_order->ID : 0 );
		if ( ! $order instanceof WC_Order || ! current_user_can( self::CAP ) ) {
			return;
		}
		$cur = $order->get_currency();
		foreach ( GFC_Redeem::stored( $order ) as $id => $c ) {
			$card = GFC_Store::get( $id );
			$net  = GFC_Store::order_net( $id, $order->get_id() );
			echo '<p><a href="' . esc_url( self::url( array( 'card' => $id ) ) ) . '">' . esc_html( GFC_Codes::mask( $card ? (string) $card['last4'] : '' ) ) . '</a>: '
				/* translators: %s: amount */
				. esc_html( sprintf( __( 'πλήρωσε %s', 'gift-cards' ), GFC_Settings::money( $net, $cur ) ) ) . '</p>';
			if ( $net > 0 ) {
				$url = wp_nonce_url( self::url( array( 'gfc_refund_order' => $order->get_id(), 'gfc_card' => $id ) ), self::NONCE );
				// A GET form inside the order form would submit the order; the box links to a small page instead.
				echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Επιστροφή στην κάρτα…', 'gift-cards' ) . '</a></p>';
			}
		}
		foreach ( GFC_Store::by_order( $order->get_id() ) as $card ) {
			echo '<p>' . esc_html__( 'Αγοράστηκε:', 'gift-cards' ) . ' <a href="' . esc_url( self::url( array( 'card' => $card['id'] ) ) ) . '">#' . (int) $card['id'] . ' · ' . esc_html( GFC_Settings::money( $card['initial'], $card['currency'] ) ) . '</a> ' . self::badge( $card['status'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
		}
		echo '<p class="description">' . esc_html__( 'Η επιστροφή χρημάτων του WooCommerce φτάνει μόνο ως το ποσό που πληρώθηκε με άλλο τρόπο. Το μέρος της δωροκάρτας επιστρέφεται εδώ, στην ίδια κάρτα.', 'gift-cards' ) . '</p>';
	}

	/** The refund page (from the order meta box): GET shows the form, POST does it. */
	private static function render_refund_page( WC_Order $order, int $card_id ): void {
		$net = GFC_Store::order_net( $card_id, $order->get_id() );
		$c   = GFC_Store::get( $card_id );
		?>
		<div class="wrap gfc-wrap">
			<h1><?php esc_html_e( 'Επιστροφή στη δωροκάρτα', 'gift-cards' ); ?></h1>
			<p>
				<?php
				/* translators: 1: card, 2: order number, 3: amount */
				echo esc_html( sprintf( __( 'Κάρτα %1$s, παραγγελία #%2$s: πλήρωσε %3$s.', 'gift-cards' ), GFC_Codes::mask( $c ? (string) $c['last4'] : '' ), $order->get_order_number(), GFC_Settings::money( $net, $order->get_currency() ) ) );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( self::url() ); ?>">
				<?php self::nonce_field(); ?>
				<?php self::token_field(); ?>
				<input type="hidden" name="gfc_action" value="refund_card" />
				<input type="hidden" name="gfc_order" value="<?php echo (int) $order->get_id(); ?>" />
				<input type="hidden" name="gfc_card" value="<?php echo (int) $card_id; ?>" />
				<?php self::input( 'gfc_amount', self::decimal( $net ), __( 'Ποσό', 'gift-cards' ), get_woocommerce_currency_symbol( $order->get_currency() ) ); ?>
				<p class="gfc-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Επιστροφή στην κάρτα', 'gift-cards' ); ?></button>
					<a class="button" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php esc_html_e( 'Πίσω στην παραγγελία', 'gift-cards' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/* =====================================================================
	 * Routes
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		if ( isset( $_POST['gfc_import'] ) ) {
			if ( ! isset( $_POST['gfc_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['gfc_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'gift-cards' ) );
			}
			self::redirect( array( 'tab' => 'tools' ), self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['gfc_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'gift-cards' ) );
		}
		$uid  = get_current_user_id();
		$card = absint( self::post( 'gfc_card' ) );
		$back = array( 'card' => $card );

		switch ( sanitize_key( wp_unslash( $_POST['gfc_action'] ) ) ) {
			case 'save_settings':
				self::redirect( array( 'tab' => 'settings' ), self::do_save_settings() );
				break;
			case 'save_texts':
				$raw          = GFC_Settings::get();
				$raw['texts'] = self::post_array( 'gfc_t' );
				GFC_Settings::save( $raw );
				self::redirect( array( 'tab' => 'texts' ), array( self::msg( 'success', __( 'Τα κείμενα αποθηκεύτηκαν.', 'gift-cards' ) ) ) );
				break;
			case 'save_tools':
				self::redirect( array( 'tab' => 'tools' ), self::do_save_tools() );
				break;
			case 'new_card':
				self::do_new_card( $uid );
				break;
			case 'approve':
				$ok = GFC_Issue::approve( $card, $uid );
				self::redirect( $back, array( $ok ? self::msg( 'success', __( 'Εγκρίθηκε: η κάρτα φεύγει στην ώρα της.', 'gift-cards' ) ) : self::msg( 'error', __( 'Η κάρτα δεν είναι σε αναμονή έγκρισης.', 'gift-cards' ) ) ) );
				break;
			case 'send_now':
				$c = GFC_Store::get( $card );
				if ( $c && 'pending' === $c['status'] ) {
					GFC_Store::update_delivery( $card, array( 'deliver_at' => current_time( 'mysql', true ) ) );
					GFC_Issue::deliver( $card );
				}
				$c = GFC_Store::get( $card );
				self::redirect( $back, array( $c && 'active' === $c['status'] ? self::msg( 'success', __( 'Η κάρτα στάλθηκε.', 'gift-cards' ) ) : self::msg( 'error', __( 'Η κάρτα δεν στάλθηκε. Δες το ημερολόγιο.', 'gift-cards' ) ) ) );
				break;
			case 'disable':
				$ok = GFC_Store::set_status( $card, 'disabled', __( 'Απενεργοποιήθηκε από το admin.', 'gift-cards' ), $uid, 'active' );
				self::redirect( $back, array( $ok ? self::msg( 'success', __( 'Η κάρτα απενεργοποιήθηκε.', 'gift-cards' ) ) : self::msg( 'error', __( 'Η κατάσταση της κάρτας άλλαξε στο μεταξύ.', 'gift-cards' ) ) ) );
				break;
			case 'enable':
				$ok = GFC_Store::set_status( $card, 'active', __( 'Ενεργοποιήθηκε από το admin.', 'gift-cards' ), $uid, 'disabled' );
				self::redirect( $back, array( $ok ? self::msg( 'success', __( 'Η κάρτα ενεργοποιήθηκε.', 'gift-cards' ) ) : self::msg( 'error', __( 'Η κατάσταση της κάρτας άλλαξε στο μεταξύ.', 'gift-cards' ) ) ) );
				break;
			case 'unlock':
				$c  = GFC_Store::get( $card );
				$ok = $c && 'locked' === $c['status'] && GFC_Store::invariant_holds( $card, $c['balance'] )
					&& GFC_Store::set_status( $card, 'active', __( 'Ξεκλειδώθηκε από το admin (το υπόλοιπο συμφωνεί με το ημερολόγιο).', 'gift-cards' ), $uid, 'locked' );
				self::redirect( $back, array( $ok ? self::msg( 'success', __( 'Η κάρτα ξεκλειδώθηκε.', 'gift-cards' ) ) : self::msg( 'error', __( 'Το υπόλοιπο δεν συμφωνεί με το ημερολόγιο: η κάρτα μένει κλειδωμένη.', 'gift-cards' ) ) ) );
				break;
			case 'void':
				$c  = GFC_Store::get( $card );
				$ok = $c && GFC_Issue::void_card( $c, __( 'Ακυρώθηκε από το admin.', 'gift-cards' ), $uid );
				self::redirect( $back, array( $ok ? self::msg( 'success', __( 'Η κάρτα ακυρώθηκε.', 'gift-cards' ) ) : self::msg( 'error', __( 'Η κάρτα έχει χρησιμοποιηθεί και δεν ακυρώνεται. Μπορείς να την απενεργοποιήσεις.', 'gift-cards' ) ) ) );
				break;
			case 'recode':
				self::redirect( $back, self::do_recode( $card, $uid ) );
				break;
			case 'adjust':
				self::redirect( $back, self::do_adjust( $card, $uid ) );
				break;
			case 'delivery':
				self::redirect( $back, self::do_delivery( $card ) );
				break;
			case 'refund_card':
				self::do_refund( $card, $uid );
				break;
			default:
				self::redirect( array(), array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'gift-cards' ) ) ) );
		}
	}

	private static function token(): string {
		$t = strtolower( preg_replace( '/[^a-f0-9]/i', '', self::post( 'gfc_token' ) ) );
		return strlen( $t ) >= 16 ? substr( $t, 0, 40 ) : '';
	}

	private static function do_save_settings(): array {
		$raw = GFC_Settings::get();
		foreach ( array( 'mode', 'delay_hours', 'expiry_months', 'remind_days', 'keep_days', 'max_cards', 'pending_hours', 'limit_n', 'limit_min', 'site_fail_hour', 'pdf_size' ) as $k ) {
			$raw[ $k ] = self::post( 'gfc_' . $k );
		}
		$raw['paid_statuses'] = array_map( 'sanitize_key', self::post_array( 'gfc_paid_statuses' ) );
		foreach ( array( 'no_card_for_gc', 'restore_refund', 'pdf' ) as $k ) {
			$raw[ $k ] = '1' === self::post( 'gfc_' . $k );
		}
		$msgs   = array();
		$review = trim( self::post( 'gfc_review_over' ) );
		if ( '' === $review ) {
			$raw['review_over'] = 0;
		} else {
			$c = GFC_Settings::to_cents( $review );
			if ( null === $c ) {
				$msgs[] = self::msg( 'warning', __( 'Το ποσό έγκρισης δεν είναι έγκυρο και δεν άλλαξε.', 'gift-cards' ) );
			} else {
				$raw['review_over'] = $c;
			}
		}
		GFC_Settings::save( $raw );
		array_unshift( $msgs, self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'gift-cards' ) ) );
		return $msgs;
	}

	private static function do_save_tools(): array {
		$raw         = GFC_Settings::get();
		$raw['lang'] = self::post( 'gfc_lang' );
		$tick        = '1' === self::post( 'gfc_delete_tick' );
		$word        = trim( self::post( 'gfc_delete_word' ) );
		$msgs        = array();
		if ( ! $tick ) {
			$raw['delete_data'] = '';
		} elseif ( 'ΔΙΑΓΡΑΦΗ' === $word ) {
			$raw['delete_data'] = 'ΔΙΑΓΡΑΦΗ';
		} elseif ( 'ΔΙΑΓΡΑΦΗ' !== $raw['delete_data'] ) {
			$msgs[] = self::msg( 'warning', __( 'Για να σβηστούν οι κάρτες στο uninstall, τσέκαρε την επιλογή και γράψε ΔΙΑΓΡΑΦΗ. Η επιλογή δεν άλλαξε.', 'gift-cards' ) );
		}
		GFC_Settings::save( $raw );
		array_unshift( $msgs, self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'gift-cards' ) ) );
		return $msgs;
	}

	private static function do_new_card( int $uid ): void {
		$amount = GFC_Settings::to_cents( self::post( 'gfc_amount' ) );
		$email  = sanitize_email( self::post( 'gfc_remail' ) );
		$name   = mb_substr( sanitize_text_field( self::post( 'gfc_rname' ) ), 0, 100 );
		$token  = self::token();
		if ( null === $amount || $amount <= 0 || $amount > 10000000 ) {
			self::redirect( array( 'tab' => 'new' ), array( self::msg( 'error', __( 'Γράψε ένα έγκυρο ποσό.', 'gift-cards' ) ) ) );
		}
		if ( ! is_email( $email ) || '' === $name ) {
			self::redirect( array( 'tab' => 'new' ), array( self::msg( 'error', __( 'Συμπλήρωσε όνομα και σωστό email παραλήπτη.', 'gift-cards' ) ) ) );
		}
		if ( '' === $token ) {
			self::redirect( array( 'tab' => 'new' ), array( self::msg( 'error', __( 'Η φόρμα έληξε. Δοκίμασε ξανά.', 'gift-cards' ) ) ) );
		}
		$date = self::post( 'gfc_date' );
		$at   = null;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date > wp_date( 'Y-m-d' ) ) {
			$at = ( new DateTimeImmutable( $date . ' 09:00:00', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		$note = sanitize_text_field( self::post( 'gfc_note' ) );
		$res  = GFC_Store::create(
			array(
				'issue_key'       => 'm:' . $token,
				'status'          => 'pending',
				'source'          => 'manual',
				'buyer_email'     => '',
				'for_me'          => 0,
				'recipient_name'  => $name,
				'recipient_email' => mb_substr( $email, 0, 100 ),
				'sender_name'     => mb_substr( sanitize_text_field( self::post( 'gfc_sender' ) ), 0, 100 ),
				'message'         => mb_substr( sanitize_textarea_field( self::post( 'gfc_message' ) ), 0, GFC_Product::MSG_MAX ),
				'lang'            => 'en' === self::post( 'gfc_lang' ) ? 'en' : 'el',
				'deliver_at'      => $at,
			),
			$amount,
			'' !== $note ? $note : __( 'Έκδοση από το admin.', 'gift-cards' ),
			$uid
		);
		if ( ! $res ) {
			self::redirect( array( 'tab' => 'new' ), array( self::msg( 'error', __( 'Η κάρτα δεν δημιουργήθηκε.', 'gift-cards' ) ) ) );
		}
		if ( $res['created'] ) {
			if ( $at ) {
				GFC_Issue::schedule_delivery( $res['id'] );
			} else {
				GFC_Issue::deliver( $res['id'] );
			}
		}
		$c = GFC_Store::get( $res['id'] );
		self::redirect(
			array( 'card' => $res['id'] ),
			array(
				$c && 'active' === $c['status']
					? self::msg( 'success', __( 'Η κάρτα δημιουργήθηκε και στάλθηκε.', 'gift-cards' ) )
					: self::msg( 'success', __( 'Η κάρτα δημιουργήθηκε και θα σταλεί στην ημερομηνία της.', 'gift-cards' ) ),
			)
		);
	}

	private static function do_recode( int $card_id, int $uid ): array {
		$code = GFC_Store::replace_code( $card_id, $uid );
		if ( null === $code ) {
			return array( self::msg( 'error', __( 'Δεν δόθηκε νέος κωδικός: η κάρτα δεν είναι ενεργή ή απενεργοποιημένη.', 'gift-cards' ) ) );
		}
		$card = GFC_Store::get( $card_id );
		$sent = $card && GFC_Emails::card( $card, $code );
		unset( $code );
		if ( ! $sent ) {
			GFC_Store::note( $card_id, 'status', __( 'Το email με τον νέο κωδικό δεν στάλθηκε.', 'gift-cards' ), $uid );
			return array( self::msg( 'error', __( 'Ο παλιός κωδικός ακυρώθηκε, αλλά το email δεν στάλθηκε. Διόρθωσε την αποστολή emails και ξαναπάτα «Νέος κωδικός και αποστολή».', 'gift-cards' ) ) );
		}
		return array( self::msg( 'success', __( 'Νέος κωδικός στάλθηκε στον κάτοχο. Ο παλιός δεν ισχύει πια.', 'gift-cards' ) ) );
	}

	private static function do_adjust( int $card_id, int $uid ): array {
		$cents = GFC_Settings::to_cents( self::post( 'gfc_amount' ) );
		$note  = mb_substr( sanitize_text_field( self::post( 'gfc_note' ) ), 0, 190 );
		$token = self::token();
		if ( null === $cents || $cents <= 0 ) {
			return array( self::msg( 'error', __( 'Γράψε ένα έγκυρο ποσό.', 'gift-cards' ) ) );
		}
		if ( '' === $note ) {
			return array( self::msg( 'error', __( 'Γράψε την αιτία της διόρθωσης.', 'gift-cards' ) ) );
		}
		if ( '' === $token ) {
			return array( self::msg( 'error', __( 'Η φόρμα έληξε. Δοκίμασε ξανά.', 'gift-cards' ) ) );
		}
		$c = GFC_Store::get( $card_id );
		if ( ! $c || ! in_array( $c['status'], array( 'active', 'disabled', 'expired' ), true ) ) {
			return array( self::msg( 'error', __( 'Η κάρτα δεν δέχεται διόρθωση σε αυτή την κατάσταση.', 'gift-cards' ) ) );
		}
		$delta = 'sub' === self::post( 'gfc_dir' ) ? -$cents : $cents;
		$res   = GFC_Store::move(
			$card_id,
			$delta,
			'adjust',
			array(
				'user_id' => $uid,
				'note'    => $note,
				'uniq'    => 'adj:' . $token,
			)
		);
		if ( ! $res['ok'] ) {
			return array( self::msg( 'error', 'balance' === $res['error'] ? __( 'Το υπόλοιπο δεν φτάνει για αυτή την αφαίρεση.', 'gift-cards' ) : __( 'Η διόρθωση δεν έγινε.', 'gift-cards' ) ) );
		}
		/* translators: %s: new balance */
		return array( self::msg( 'success', sprintf( __( 'Καταχωρίστηκε. Νέο υπόλοιπο: %s.', 'gift-cards' ), GFC_Settings::money( $res['balance'], $c['currency'] ) ) ) );
	}

	private static function do_delivery( int $card_id ): array {
		$email = sanitize_email( self::post( 'gfc_remail' ) );
		$name  = mb_substr( sanitize_text_field( self::post( 'gfc_rname' ) ), 0, 100 );
		$c     = GFC_Store::get( $card_id );
		if ( ! $c || ! is_email( $email ) ) {
			return array( self::msg( 'error', __( 'Συμπλήρωσε σωστό email παραλήπτη.', 'gift-cards' ) ) );
		}
		$date = self::post( 'gfc_date' );
		$at   = null;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$at = ( new DateTimeImmutable( $date . ' 09:00:00', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		$fields = array(
			'recipient_name'  => $name,
			'recipient_email' => mb_substr( $email, 0, 100 ),
			'deliver_at'      => $at,
		);
		if ( $c['for_me'] ) {
			unset( $fields['recipient_name'], $fields['recipient_email'] );
		}
		if ( ! GFC_Store::update_delivery( $card_id, $fields ) ) {
			return array( self::msg( 'error', __( 'Τα στοιχεία δεν άλλαξαν: η κάρτα έχει ήδη σταλεί.', 'gift-cards' ) ) );
		}
		GFC_Store::note( $card_id, 'status', __( 'Άλλαξαν τα στοιχεία αποστολής.', 'gift-cards' ), get_current_user_id() );
		GFC_Issue::schedule_delivery( $card_id );
		return array( self::msg( 'success', __( 'Τα στοιχεία αποστολής αποθηκεύτηκαν.', 'gift-cards' ) ) );
	}

	private static function do_refund( int $card_id, int $uid ): void {
		$order = wc_get_order( absint( self::post( 'gfc_order' ) ) );
		$cents = GFC_Settings::to_cents( self::post( 'gfc_amount' ) );
		$token = self::token();
		if ( ! $order instanceof WC_Order ) {
			self::redirect( array(), array( self::msg( 'error', __( 'Η παραγγελία δεν βρέθηκε.', 'gift-cards' ) ) ) );
		}
		$back = $order->get_edit_order_url();
		if ( null === $cents || $cents <= 0 || '' === $token ) {
			self::redirect_to( $back, array( self::msg( 'error', __( 'Γράψε ένα έγκυρο ποσό.', 'gift-cards' ) ) ) );
		}
		$res = GFC_Redeem::refund_to_card( $order, $card_id, $cents, $uid, $token );
		if ( $res['ok'] ) {
			$msg = 'duplicate' === $res['error'] ? __( 'Η επιστροφή είχε ήδη γίνει.', 'gift-cards' ) : __( 'Το ποσό επιστράφηκε στη δωροκάρτα.', 'gift-cards' );
			self::redirect_to( $back, array( self::msg( 'success', $msg ) ) );
		}
		self::redirect_to( $back, array( self::msg( 'error', 'over' === $res['error'] ? __( 'Το ποσό είναι μεγαλύτερο από όσο πλήρωσε η κάρτα σε αυτή την παραγγελία.', 'gift-cards' ) : __( 'Η επιστροφή δεν έγινε.', 'gift-cards' ) ) ) );
	}

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'gfc_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'gift-cards' ) );
		}
		$opts = GFC_Settings::get();
		unset( $opts['delete_data'] );
		$state = array(
			'plugin'  => 'gift-cards',
			'version' => GFC_VERSION,
			'options' => array( GFC_Settings::OPT => $opts ),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="gift-cards-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['gfc_import_file']['error'] ) ? (int) $_FILES['gfc_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['gfc_import_file']['size'] ) ? (int) $_FILES['gfc_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'gift-cards' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['gfc_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['gfc_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'gift-cards' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['gfc_import_file']['tmp_name'] ), true );
		// phpcs:enable
		$blob = is_array( $state ) && isset( $state['options'][ GFC_Settings::OPT ] ) ? $state['options'][ GFC_Settings::OPT ] : null;
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Gift Cards).', 'gift-cards' ) ) );
		}
		// The uninstall choice never comes from a file.
		$blob['delete_data'] = GFC_Settings::get()['delete_data'];
		GFC_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'gift-cards' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * PRG
	 * ------------------------------------------------------------------- */

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by GFC_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( array $args, array $msgs ): void {
		self::redirect_to( self::url( $args ), $msgs );
	}

	private static function redirect_to( string $url, array $msgs ): void {
		set_transient( 'gfc_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( $url );
		exit;
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'gfc_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'gfc_aui_msg_' . get_current_user_id() );
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

	/** Order screen notices after a refund to a card (the PRG lands there). */
	public static function order_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		self::print_notices( self::take_msgs() );
	}

	private static function footer(): void {
		?>
		<p class="gfc-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'gift-cards' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="gfc-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="gfc-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'gift-cards' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="gfc-footer-cta">
			<a class="gfc-kofi" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '♡ Στήριξε το Noxpress', 'gift-cards' ); ?>
			</a>
			<a class="gfc-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'gift-cards' ); ?>
			</a>
		</p>
		<?php
	}
}
