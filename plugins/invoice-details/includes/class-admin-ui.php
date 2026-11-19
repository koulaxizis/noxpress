<?php
/**
 * IVD_Admin_UI — menu, «Τιμολόγια» list with CSV, settings (Bible §3–§8, §11).
 *
 * One page, submenu «Τιμολόγια» of the shared "Noxpress" menu (admin_menu
 * priority 380), slug ivd-invoices, with tabs:
 *  - list      invoice orders of a period (old Timologia / OBS ones
 *              included), CSV export («Excel ελληνικά» or standard CSV)
 *  - settings  mode, fields, VIES, compatible meta, orders list, uninstall,
 *              customer texts (el / en)
 *
 * State change: POST on admin_init, slug + nonce + capability, strict
 * validation (IVD_Settings::sanitize), PRG with the message in the
 * transient ivd_aui_msg_{uid}. CSV: privileged GET (slug + nonce +
 * capability), Noxpress_Safe::csv_row (formula guard).
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'ivd-invoices';
	const NONCE       = 'ivd_admin';
	const NONCE_FIELD = 'ivd_nonce';
	const NONCE_CSV   = 'ivd_csv';
	const PER_PAGE    = 50;
	const MAX_CSV     = 5000;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 380 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_csv' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IVD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'list'     => __( 'Τιμολόγια', 'invoice-details' ),
			'settings' => __( 'Ρυθμίσεις', 'invoice-details' ),
		);
	}

	public static function admin_menu(): void {
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Invoice Details', 'invoice-details' ),
			__( 'Τιμολόγια', 'invoice-details' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'invoice-details' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/** A sanitized GET parameter (read-only). */
	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing and list filters.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'list';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'ivd-admin', IVD_URL . 'assets/admin.css', array(), IVD_VERSION );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'invoice-details' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap ivd-wrap">
			<h1><?php esc_html_e( 'Invoice Details', 'invoice-details' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Απόδειξη ή τιμολόγιο στο ταμείο, με επωνυμία, ΑΦΜ, ΔΟΥ και δραστηριότητα, έλεγχο του ΑΦΜ και τα στοιχεία στην παραγγελία. Δεν κόβει παραστατικά: τα δίνει στον πάροχο τιμολόγησης.', 'invoice-details' ); ?></p>
			<?php
			if ( Invoice_Details::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το IVD_DISABLE είναι ενεργό στο wp-config.php: τίποτα δεν εμφανίζεται στο ταμείο. Οι παραγγελίες δείχνουν κανονικά τα στοιχεία τους.', 'invoice-details' ) . '</p></div>';
			} elseif ( 'test' === IVD_Settings::get()['mode'] ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Λειτουργία δοκιμής: την επιλογή «Απόδειξη / Τιμολόγιο» τη βλέπουν μόνο οι διαχειριστές του καταστήματος. Άλλαξέ τη στις Ρυθμίσεις όταν είσαι έτοιμος.', 'invoice-details' ) . '</p></div>';
			}
			self::print_notices( self::take_msgs() );
			?>
			<nav class="nav-tab-wrapper ivd-tabs">
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
			if ( 'settings' === $tab ) {
				self::render_settings();
			} else {
				self::render_list();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * List
	 * ------------------------------------------------------------------- */

	/** Period and statuses of the list (GET), with defaults: this month, paid orders. */
	private static function filters(): array {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'now', $tz );
		$from  = self::q( 'from' );
		$to    = self::q( 'to' );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = $today->format( 'Y-m-01' );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$to = $today->format( 'Y-m-d' );
		}
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		$all      = wc_get_order_statuses();
		$statuses = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$raw = isset( $_GET['st'] ) && is_array( $_GET['st'] ) ? array_map( 'sanitize_key', wp_unslash( $_GET['st'] ) ) : array();
		foreach ( $raw as $st ) {
			if ( isset( $all[ 'wc-' . $st ] ) ) {
				$statuses[] = $st;
			}
		}
		if ( ! $statuses ) {
			$statuses = array( 'processing', 'completed', 'on-hold' );
		}
		$fmt = self::q( 'fmt' );
		return array(
			'from'     => $from,
			'to'       => $to,
			'statuses' => $statuses,
			'fmt'      => 'csv' === $fmt ? 'csv' : 'excel',
		);
	}

	/**
	 * Invoice orders of the filters.
	 *
	 * @param array $f     Filters.
	 * @param int   $limit Most orders.
	 * @param int   $page  Page (1-based).
	 * @return array { orders: WC_Order[], total: int }
	 */
	private static function query( array $f, int $limit, int $page = 1 ): array {
		$tz   = wp_timezone();
		$from = ( new DateTimeImmutable( $f['from'] . ' 00:00:00', $tz ) )->getTimestamp();
		$to   = ( new DateTimeImmutable( $f['to'] . ' 23:59:59', $tz ) )->getTimestamp();
		$r    = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array_map(
					static function ( $s ) {
						return 'wc-' . $s;
					},
					$f['statuses']
				),
				'date_created' => $from . '...' . $to,
				'meta_query'   => array( IVD_Order::meta_query() ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the list is filtered by the invoice marker.
				'limit'        => $limit,
				'paged'        => max( 1, $page ),
				'orderby'      => 'date',
				'order'        => 'ASC',
				'paginate'     => true,
			)
		);
		return array(
			'orders' => is_object( $r ) && isset( $r->orders ) ? $r->orders : array(),
			'total'  => is_object( $r ) && isset( $r->total ) ? (int) $r->total : 0,
		);
	}

	/** One row of the list / CSV. */
	private static function row( WC_Order $o ): ?array {
		$d = IVD_Data::get( $o );
		if ( null === $d ) {
			return null;
		}
		$total = (float) $o->get_total() - (float) $o->get_total_refunded();
		$tax   = (float) $o->get_total_tax() - (float) $o->get_total_tax_refunded();
		$date  = $o->get_date_created();
		return array(
			'id'         => (int) $o->get_id(),
			'number'     => (string) $o->get_order_number(),
			'date'       => $date ? $date->date_i18n( 'Y-m-d' ) : '',
			'status'     => wc_get_order_status_name( $o->get_status() ),
			'company'    => $d['company'],
			'vat'        => IVD_Order::number( $d ),
			'country'    => '' !== $d['country'] ? $d['country'] : (string) $o->get_billing_country(),
			'tax_office' => $d['tax_office'],
			'activity'   => $d['activity'],
			'net'        => $total - $tax,
			'tax'        => $tax,
			'total'      => $total,
			'currency'   => (string) $o->get_currency(),
			'check'      => ! empty( $d['check']['status'] ) ? IVD_Vat::status_label( (string) $d['check']['status'] ) : '',
			'source'     => $d['source'],
		);
	}

	private static function render_list(): void {
		$f    = self::filters();
		$page = max( 1, absint( self::q( 'paged' ) ) );
		$r    = self::query( $f, self::PER_PAGE, $page );
		$all  = wc_get_order_statuses();
		?>
		<div class="ivd-zone">
			<form method="get" class="ivd-period">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="tab" value="list" />
				<label><?php esc_html_e( 'Από', 'invoice-details' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" /></label>
				<label><?php esc_html_e( 'Έως', 'invoice-details' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" /></label>
				<span class="ivd-statuses">
					<?php
					foreach ( $all as $key => $label ) {
						$st = substr( $key, 3 );
						echo '<label class="ivd-check"><input type="checkbox" name="st[]" value="' . esc_attr( $st ) . '" ' . checked( in_array( $st, $f['statuses'], true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
					}
					?>
				</span>
				<button type="submit" class="button"><?php esc_html_e( 'Εμφάνιση', 'invoice-details' ); ?></button>
			</form>
			<form method="get" class="ivd-period">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="ivd_csv" value="1" />
				<input type="hidden" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" />
				<input type="hidden" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" />
				<?php
				foreach ( $f['statuses'] as $st ) {
					echo '<input type="hidden" name="st[]" value="' . esc_attr( $st ) . '" />';
				}
				wp_nonce_field( self::NONCE_CSV, '_ivdcsv', false );
				?>
				<select name="fmt">
					<option value="excel"><?php esc_html_e( 'Excel ελληνικά (; και υποδιαστολή κόμμα)', 'invoice-details' ); ?></option>
					<option value="csv"><?php esc_html_e( 'CSV (, και τελεία)', 'invoice-details' ); ?></option>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Εξαγωγή CSV', 'invoice-details' ); ?></button>
			</form>
			<p class="description">
				<?php
				/* translators: %d: number of orders */
				echo esc_html( sprintf( _n( '%d παραγγελία με τιμολόγιο.', '%d παραγγελίες με τιμολόγιο.', $r['total'], 'invoice-details' ), $r['total'] ) );
				echo ' ';
				esc_html_e( 'Ποσά μετά τις επιστροφές. Περιλαμβάνονται και τα τιμολόγια από το Timologia ή το OBS πριν από την αλλαγή.', 'invoice-details' );
				?>
			</p>
			<table class="ivd-table widefat">
				<thead><tr>
					<th><?php esc_html_e( 'Παραγγελία', 'invoice-details' ); ?></th>
					<th><?php esc_html_e( 'Ημερομηνία', 'invoice-details' ); ?></th>
					<th><?php esc_html_e( 'Επωνυμία', 'invoice-details' ); ?></th>
					<th><?php esc_html_e( 'ΑΦΜ', 'invoice-details' ); ?></th>
					<th><?php esc_html_e( 'ΔΟΥ', 'invoice-details' ); ?></th>
					<th><?php esc_html_e( 'Σύνολο', 'invoice-details' ); ?></th>
					<th><?php esc_html_e( 'Έλεγχος', 'invoice-details' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$shown = 0;
				foreach ( $r['orders'] as $o ) {
					$row = $o instanceof WC_Order ? self::row( $o ) : null;
					if ( null === $row ) {
						continue;
					}
					++$shown;
					echo '<tr>';
					echo '<td><a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $row['number'] ) . '</a> <span class="ivd-muted">' . esc_html( $row['status'] ) . '</span></td>';
					echo '<td>' . esc_html( $row['date'] ) . '</td>';
					echo '<td>' . esc_html( $row['company'] ) . '</td>';
					echo '<td><code>' . esc_html( $row['vat'] ) . '</code></td>';
					echo '<td>' . esc_html( $row['tax_office'] ) . '</td>';
					echo '<td>' . wp_kses_post( wc_price( $row['total'], array( 'currency' => $row['currency'] ) ) ) . '</td>';
					echo '<td>' . esc_html( $row['check'] ) . ( 'ivd' !== $row['source'] ? ' <span class="ivd-badge ivd-badge--muted">' . esc_html( 'obs' === $row['source'] ? 'OBS' : 'Timologia' ) . '</span>' : '' ) . '</td>';
					echo '</tr>';
				}
				if ( 0 === $shown ) {
					echo '<tr><td colspan="7" class="ivd-empty">' . esc_html__( 'Κανένα τιμολόγιο σε αυτή την περίοδο.', 'invoice-details' ) . '</td></tr>';
				}
				?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $r['total'] / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<p class="ivd-mt-8">';
				for ( $i = 1; $i <= $pages; $i++ ) {
					$url = add_query_arg(
						array(
							'page'  => self::SLUG,
							'tab'   => 'list',
							'from'  => $f['from'],
							'to'    => $f['to'],
							'st'    => $f['statuses'],
							'paged' => $i,
						),
						admin_url( 'admin.php' )
					);
					echo $i === $page ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( $url ) . '">' . (int) $i . '</a> ';
				}
				echo '</p>';
			}
			?>
		</div>
		<?php
	}

	/** admin_init: the CSV download (privileged GET: slug + nonce + capability). */
	public static function route_csv(): void {
		if ( self::SLUG !== self::current_page() || '1' !== self::q( 'ivd_csv' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( sanitize_key( self::q( '_ivdcsv' ) ), self::NONCE_CSV ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'invoice-details' ) );
		}
		$f     = self::filters();
		$r     = self::query( $f, self::MAX_CSV );
		$excel = 'excel' === $f['fmt'];
		$sep   = $excel ? ';' : ',';
		$num   = static function ( float $v ) use ( $excel ): string {
			return number_format( $v, 2, $excel ? ',' : '.', '' );
		};

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="invoices-' . $f['from'] . '-' . $f['to'] . '.csv"' );
		$fh = fopen( 'php://output', 'w' );
		if ( false === $fh ) {
			exit;
		}
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		Noxpress_Safe::csv_row(
			$fh,
			array(
				__( 'Παραγγελία', 'invoice-details' ),
				__( 'Ημερομηνία', 'invoice-details' ),
				__( 'Κατάσταση', 'invoice-details' ),
				__( 'Επωνυμία', 'invoice-details' ),
				__( 'ΑΦΜ', 'invoice-details' ),
				__( 'Χώρα', 'invoice-details' ),
				__( 'ΔΟΥ', 'invoice-details' ),
				__( 'Δραστηριότητα', 'invoice-details' ),
				__( 'Καθαρό', 'invoice-details' ),
				__( 'ΦΠΑ', 'invoice-details' ),
				__( 'Σύνολο', 'invoice-details' ),
				__( 'Νόμισμα', 'invoice-details' ),
				__( 'Έλεγχος ΑΦΜ', 'invoice-details' ),
			),
			$sep
		);
		foreach ( $r['orders'] as $o ) {
			$row = $o instanceof WC_Order ? self::row( $o ) : null;
			if ( null === $row ) {
				continue;
			}
			Noxpress_Safe::csv_row(
				$fh,
				array(
					$row['number'],
					$row['date'],
					$row['status'],
					$row['company'],
					$row['vat'],
					$row['country'],
					$row['tax_office'],
					$row['activity'],
					$num( $row['net'] ),
					$num( $row['tax'] ),
					$num( $row['total'] ),
					$row['currency'],
					$row['check'],
				),
				$sep
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output.
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private static function radio_row( string $name, string $value, array $opts, string $label ): void {
		echo '<span class="ivd-label">' . esc_html( $label ) . '</span><span class="ivd-inline">';
		foreach ( $opts as $k => $l ) {
			echo '<label class="ivd-check"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . ' /> ' . esc_html( $l ) . '</label>';
		}
		echo '</span>';
	}

	private static function check( string $name, bool $on, string $label ): void {
		echo '<label class="ivd-check ivd-block"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	private static function render_settings(): void {
		$s     = IVD_Settings::get();
		$field = array(
			'required' => __( 'Υποχρεωτικό', 'invoice-details' ),
			'optional' => __( 'Προαιρετικό', 'invoice-details' ),
			'hidden'   => __( 'Κρυφό', 'invoice-details' ),
		);
		$texts = array(
			'choice'       => __( 'Τίτλος της επιλογής', 'invoice-details' ),
			'receipt'      => __( 'Απόδειξη', 'invoice-details' ),
			'invoice'      => __( 'Τιμολόγιο', 'invoice-details' ),
			'want'         => __( 'Κουτάκι στο block ταμείο', 'invoice-details' ),
			'company'      => __( 'Επωνυμία', 'invoice-details' ),
			'vat'          => __( 'ΑΦΜ', 'invoice-details' ),
			'tax_office'   => __( 'ΔΟΥ', 'invoice-details' ),
			'activity'     => __( 'Δραστηριότητα', 'invoice-details' ),
			'hq'           => __( 'Διεύθυνση έδρας', 'invoice-details' ),
			'help'         => __( 'Βοήθεια κάτω από την επιλογή (κλασικό ταμείο)', 'invoice-details' ),
			'required'     => __( 'Μήνυμα: λείπει πεδίο ({field})', 'invoice-details' ),
			'vat_bad'      => __( 'Μήνυμα: λάθος ΑΦΜ', 'invoice-details' ),
			'vies_bad'     => __( 'Μήνυμα: ο ΑΦΜ δεν βρέθηκε στο VIES', 'invoice-details' ),
			'need_invoice' => __( 'Μήνυμα: η παραγγελία θέλει τιμολόγιο', 'invoice-details' ),
			'aade_found'   => __( 'Μήνυμα: στοιχεία από την ΑΑΔΕ', 'invoice-details' ),
			'aade_inactive' => __( 'Μήνυμα: ανενεργός ΑΦΜ στην ΑΑΔΕ', 'invoice-details' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) ); ?>">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="ivd_action" value="save" />

			<h2 class="ivd-h2"><?php esc_html_e( 'Λειτουργία', 'invoice-details' ); ?></h2>
			<div class="ivd-zone">
				<?php
				self::radio_row(
					'mode',
					$s['mode'],
					array(
						'off'  => __( 'Ανενεργό', 'invoice-details' ),
						'test' => __( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν την επιλογή', 'invoice-details' ),
						'live' => __( 'Ενεργό για όλους', 'invoice-details' ),
					),
					__( 'Επιλογή «Απόδειξη / Τιμολόγιο» στο ταμείο', 'invoice-details' )
				);
				?>
				<p class="description"><?php esc_html_e( 'Λειτουργεί στο κλασικό ταμείο και στο block ταμείο (WooCommerce 9.9 και νεότερο). Οι παραγγελίες που έχουν ήδη στοιχεία τα δείχνουν σε κάθε λειτουργία.', 'invoice-details' ); ?></p>
			</div>

			<h2 class="ivd-h2"><?php esc_html_e( 'Πεδία τιμολογίου', 'invoice-details' ); ?></h2>
			<div class="ivd-zone">
				<p class="description"><?php esc_html_e( 'Επωνυμία και ΑΦΜ είναι πάντα υποχρεωτικά. Η επωνυμία είναι το πεδίο «Εταιρεία» του WooCommerce· αν το έχεις κρυφό, το plugin δείχνει δικό του πεδίο μόνο για τιμολόγιο. ΔΟΥ και δραστηριότητα ζητούνται μόνο για διεύθυνση χρέωσης στην Ελλάδα.', 'invoice-details' ); ?></p>
				<div class="ivd-grid">
					<div><?php self::radio_row( 'f_tax_office', $s['f_tax_office'], $field, __( 'ΔΟΥ', 'invoice-details' ) ); ?></div>
					<div><?php self::radio_row( 'f_activity', $s['f_activity'], $field, __( 'Δραστηριότητα', 'invoice-details' ) ); ?></div>
					<div>
						<?php
						self::radio_row(
							'f_hq',
							$s['f_hq'],
							array(
								'off'      => __( 'Όχι', 'invoice-details' ),
								'optional' => __( 'Προαιρετικό', 'invoice-details' ),
							),
							__( 'Διεύθυνση έδρας (αν διαφέρει από τη χρέωση)', 'invoice-details' )
						);
						?>
					</div>
				</div>
			</div>

			<h2 class="ivd-h2"><?php esc_html_e( 'Έλεγχος ΑΦΜ', 'invoice-details' ); ?></h2>
			<div class="ivd-zone">
				<p class="description"><?php esc_html_e( 'Ο ελληνικός ΑΦΜ ελέγχεται πάντα τοπικά με το ψηφίο ελέγχου, χωρίς δίκτυο. Το VIES δεν χρησιμοποιείται για ελληνικούς ΑΦΜ, γιατί δεν γνωρίζει όσες επιχειρήσεις δεν κάνουν ενδοκοινοτικές συναλλαγές.', 'invoice-details' ); ?></p>
				<?php self::check( 'vies', $s['vies'], __( 'Έλεγχος στο VIES της ΕΕ για ΑΦΜ άλλων χωρών της ΕΕ, όταν γίνεται η παραγγελία', 'invoice-details' ) ); ?>
				<?php
				self::radio_row(
					'vies_invalid',
					$s['vies_invalid'],
					array(
						'accept' => __( 'Η παραγγελία περνά, με σημείωση στην παραγγελία', 'invoice-details' ),
						'reject' => __( 'Ο πελάτης διορθώνει τον αριθμό', 'invoice-details' ),
					),
					__( 'Όταν το VIES λέει ότι ο αριθμός δεν υπάρχει', 'invoice-details' )
				);
				?>
				<p class="description"><?php esc_html_e( 'Όταν το VIES δεν απαντά, η παραγγελία περνά πάντα και ο έλεγχος γίνεται αργότερα με το κουμπί «Έλεγχος VIES» στην παραγγελία. Το VIES παίρνει μόνο τη χώρα και τον αριθμό. Γράψε στην πολιτική απορρήτου ότι ο ΑΦΜ επιχειρήσεων της ΕΕ ελέγχεται στην υπηρεσία VIES της Ευρωπαϊκής Επιτροπής.', 'invoice-details' ); ?></p>
			</div>

			<h2 class="ivd-h2"><?php esc_html_e( 'Στοιχεία από την ΑΑΔΕ', 'invoice-details' ); ?></h2>
			<div class="ivd-zone">
				<?php if ( IVD_Aade::configured() ) : ?>
					<?php self::check( 'aade', $s['aade'], __( 'Συμπλήρωση επωνυμίας, ΔΟΥ, δραστηριότητας και έδρας από την ΑΑΔΕ όταν ο πελάτης γράφει ελληνικό ΑΦΜ', 'invoice-details' ) ); ?>
					<?php
					$ivd_last = IVD_Aade::last_error();
					if ( ! empty( $ivd_last['code'] ) ) {
						echo '<p class="description">' . esc_html__( 'Τελευταίο σφάλμα της ΑΑΔΕ:', 'invoice-details' ) . ' <code>' . esc_html( (string) $ivd_last['code'] ) . '</code>' . ( ! empty( $ivd_last['at'] ) ? ' · ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ivd_last['at'] ) ) : '' ) . '</p>';
					}
					?>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Για να συμπληρώνονται τα στοιχεία από την ΑΑΔΕ, βγάλε «ειδικούς κωδικούς» για την υπηρεσία «Αναζήτηση Βασικών Στοιχείων Μητρώου Επιχειρήσεων» στο Taxisnet και γράψε τους στο wp-config.php:', 'invoice-details' ); ?></p>
					<pre class="ivd-code">define( 'IVD_AADE_USER', '…' );
define( 'IVD_AADE_PASS', '…' );</pre>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Οι κωδικοί μένουν μόνο στο wp-config.php: δεν αποθηκεύονται στη βάση και δεν φαίνονται εδώ. Η ΑΑΔΕ παίρνει μόνο τον ΑΦΜ. Μόνο αριθμοί με σωστό ψηφίο ελέγχου, μία ερώτηση ανά ΑΦΜ για 7 ημέρες και όριο ανά επισκέπτη. Όταν η ΑΑΔΕ δεν απαντά, ο πελάτης γράφει τα στοιχεία ο ίδιος.', 'invoice-details' ); ?></p>
			</div>

			<h2 class="ivd-h2"><?php esc_html_e( 'Πάροχος τιμολόγησης', 'invoice-details' ); ?></h2>
			<div class="ivd-zone">
				<?php
				self::radio_row(
					'compat',
					$s['compat'],
					array(
						'none'      => __( 'Μόνο τα δικά μας πεδία', 'invoice-details' ),
						'timologia' => __( 'Και με τα ονόματα του Timologia for WooCommerce', 'invoice-details' ),
						'obs'       => __( 'Και με τα ονόματα του Timologia OBS', 'invoice-details' ),
					),
					__( 'Ονόματα στοιχείων στην παραγγελία', 'invoice-details' )
				);
				?>
				<p class="description"><?php esc_html_e( 'Διάλεξε τα ονόματα του παλιού plugin όταν ο πάροχος τιμολόγησης ή η αποθήκη σου διαβάζει εκείνα. Τα στοιχεία φαίνονται πάντα και στο REST API του WooCommerce (meta _ivd_*, και η επωνυμία στο billing company).', 'invoice-details' ); ?></p>
			</div>

			<h2 class="ivd-h2"><?php esc_html_e( 'Διαχείριση', 'invoice-details' ); ?></h2>
			<div class="ivd-zone">
				<?php self::check( 'column', $s['column'], __( 'Στήλη «Τ» και φίλτρο «Μόνο τιμολόγια» στη λίστα παραγγελιών', 'invoice-details' ) ); ?>
				<?php self::check( 'delete_customers', $s['delete_customers'], __( 'Διαγραφή των αποθηκευμένων στοιχείων των πελατών όταν διαγραφεί το plugin (τα στοιχεία των παραγγελιών μένουν πάντα)', 'invoice-details' ) ); ?>
				<?php
				self::radio_row(
					'lang',
					$s['lang'],
					array(
						'auto' => __( 'Από τη γλώσσα του site', 'invoice-details' ),
						'el'   => __( 'Ελληνικά', 'invoice-details' ),
						'en'   => 'English',
					),
					__( 'Γλώσσα των κειμένων για τον πελάτη', 'invoice-details' )
				);
				?>
			</div>

			<h2 class="ivd-h2"><?php esc_html_e( 'Κείμενα για τον πελάτη', 'invoice-details' ); ?></h2>
			<div class="ivd-zone ivd-texts">
				<p class="description"><?php esc_html_e( 'Κενό αγγλικό κείμενο = δείχνει το ελληνικό.', 'invoice-details' ); ?></p>
				<table class="ivd-table widefat">
					<thead><tr><th><?php esc_html_e( 'Κείμενο', 'invoice-details' ); ?></th><th><?php esc_html_e( 'Ελληνικά', 'invoice-details' ); ?></th><th>English</th></tr></thead>
					<tbody>
					<?php
					foreach ( $texts as $key => $label ) {
						$max = IVD_Settings::TEXTS[ $key ][0];
						echo '<tr><td>' . esc_html( $label ) . '</td>';
						foreach ( array( 'el', 'en' ) as $l ) {
							echo '<td><input type="text" name="' . esc_attr( $key . '[' . $l . ']' ) . '" maxlength="' . (int) $max . '" value="' . esc_attr( (string) $s[ $key ][ $l ] ) . '" /></td>';
						}
						echo '</tr>';
					}
					?>
					</tbody>
				</table>
			</div>

			<p class="ivd-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'invoice-details' ); ?></button></p>
		</form>

		<h2 class="ivd-h2"><?php esc_html_e( 'Για προγραμματιστές', 'invoice-details' ); ?></h2>
		<div class="ivd-zone">
			<p class="description"><code>ivd_get( $order )</code> · <code>ivd_is_invoice( $order )</code> · <code>apply_filters( 'ivd_require_invoice', false, $cart )</code> · <code>ivd_offered()</code> · <code>ivd_text</code> · <code>ivd_aade_response</code> · <code>IVD_DISABLE</code> · <code>IVD_AADE_USER</code> / <code>IVD_AADE_PASS</code></p>
		</div>
		<?php
	}

	/* =====================================================================
	 * Routes
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['ivd_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'invoice-details' ) );
		}
		if ( 'save' !== sanitize_key( wp_unslash( $_POST['ivd_action'] ) ) ) {
			return;
		}
		$raw = array();
		foreach ( array( 'mode', 'f_tax_office', 'f_activity', 'f_hq', 'vies_invalid', 'compat', 'lang' ) as $k ) {
			if ( isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ) {
				$raw[ $k ] = sanitize_key( wp_unslash( $_POST[ $k ] ) );
			}
		}
		foreach ( array( 'vies', 'aade', 'column', 'delete_customers' ) as $k ) {
			$raw[ $k ] = isset( $_POST[ $k ] );
		}
		foreach ( array_keys( IVD_Settings::TEXTS ) as $k ) {
			if ( isset( $_POST[ $k ] ) && is_array( $_POST[ $k ] ) ) {
				$raw[ $k ] = wp_unslash( $_POST[ $k ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IVD_Settings::sanitize cleans every value.
			}
		}
		IVD_Settings::save( $raw );
		set_transient(
			'ivd_aui_msg_' . get_current_user_id(),
			array(
				array(
					'type' => 'success',
					'text' => __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'invoice-details' ),
				),
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) );
		exit;
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'ivd_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'ivd_aui_msg_' . get_current_user_id() );
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
		<p class="ivd-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'invoice-details' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="ivd-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="ivd-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'invoice-details' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="ivd-footer-cta">
			<a class="ivd-kofi" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '♡ Στήριξε το Noxpress', 'invoice-details' ); ?>
			</a>
			<a class="ivd-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'invoice-details' ); ?>
			</a>
		</p>
		<?php
	}
}
