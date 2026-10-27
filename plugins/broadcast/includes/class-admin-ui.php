<?php
/**
 * BRC_Admin_UI — menu, queue, history, modules, sources, Viber, templates,
 * tools (Bible §3–§8, §11).
 *
 * Submenu «Broadcast» of the shared "Noxpress" menu (admin_menu priority
 * 280), slug brc-settings, capability Broadcast::cap(). Tabs:
 *  - queue      items waiting (approve / «Δημοσιεύτηκε», edit, reject), a
 *               new post by hand; works on a phone (cards, big buttons)
 *  - history    every item with filters, retry, CSV export (§5)
 *  - modules    module switches, mode, approval per source, brake, retention
 *  - sources    settings of the products, newsletter, YouTube and digest modules
 *  - viber      token, connect / disconnect, channel status
 *  - templates  one template per source, with its placeholders
 *  - tools      settings backup (no secrets), clear the history, developers
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient brc_aui_msg_{uid}). Exports: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'brc-settings';
	const NONCE       = 'brc_admin';
	const NONCE_FIELD = 'brc_nonce';
	const NONCE_BAK   = 'brc_backup';
	const NONCE_CSV   = 'brc_csv';
	const MAX_IMPORT  = 262144;
	const PER_PAGE    = 50;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 280 );
		add_action( 'admin_init', array( 'BRC_Queue', 'maybe_install' ) );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BRC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'queue'     => __( 'Ουρά', 'broadcast' ),
			'history'   => __( 'Ιστορικό', 'broadcast' ),
			'modules'   => __( 'Modules', 'broadcast' ),
			'sources'   => __( 'Πηγές', 'broadcast' ),
			'viber'     => __( 'Viber', 'broadcast' ),
			'templates' => __( 'Πρότυπα', 'broadcast' ),
			'tools'     => __( 'Εργαλεία', 'broadcast' ),
		);
	}

	/** Source / destination module => label. */
	public static function module_names(): array {
		return array(
			'products'   => __( 'Νέα προϊόντα', 'broadcast' ),
			'newsletter' => __( 'Newsletter', 'broadcast' ),
			'youtube'    => __( 'YouTube', 'broadcast' ),
			'queue'      => __( 'Ουρά αναρτήσεων', 'broadcast' ),
			'digest'     => __( 'Εβδομαδιαίο digest Book Feed', 'broadcast' ),
			'viber'      => __( 'Viber Channel', 'broadcast' ),
		);
	}

	private static function status_names(): array {
		return array(
			'pending'  => __( 'Περιμένει', 'broadcast' ),
			'queued'   => __( 'Στην ουρά', 'broadcast' ),
			'sending'  => __( 'Αποστολή', 'broadcast' ),
			'sent'     => __( 'Στάλθηκε', 'broadcast' ),
			'test'     => __( 'Δοκιμή', 'broadcast' ),
			'failed'   => __( 'Απέτυχε', 'broadcast' ),
			'skipped'  => __( 'Παραλείφθηκε', 'broadcast' ),
			'rejected' => __( 'Απορρίφθηκε', 'broadcast' ),
		);
	}

	private static function reason_names(): array {
		return array(
			'approval' => __( 'χρειάζεται έγκριση', 'broadcast' ),
			'brake'    => __( 'φρένο: πολλές αναρτήσεις', 'broadcast' ),
			'publish'  => __( 'περιμένει «Δημοσιεύτηκε»', 'broadcast' ),
			'unsure'   => __( 'ίσως Short', 'broadcast' ),
			'dup_url'  => __( 'ίδιος σύνδεσμος μέσα σε 7 ημέρες', 'broadcast' ),
			'off'      => __( 'ο προορισμός είναι κλειστός', 'broadcast' ),
			'connect'  => __( 'χρειάζεται νέα σύνδεση', 'broadcast' ),
			'network'  => __( 'δίκτυο', 'broadcast' ),
			'rate'     => __( 'πολλά αιτήματα', 'broadcast' ),
			'refused'  => __( 'το απέρριψε ο προορισμός', 'broadcast' ),
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
			__( 'Broadcast', 'broadcast' ),
			__( 'Broadcast', 'broadcast' ),
			Broadcast::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'broadcast' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'queue';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'brc-admin', BRC_URL . 'assets/admin.css', array(), BRC_VERSION );
		wp_enqueue_script( 'brc-admin', BRC_URL . 'assets/admin.js', array(), BRC_VERSION, true );
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Broadcast::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'broadcast' ) );
		}
		$tab     = self::current_tab();
		$pending = BRC_Queue::count( array( 'status' => 'pending' ) );
		?>
		<div class="wrap brc-wrap">
			<h1><?php esc_html_e( 'Broadcast', 'broadcast' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ανακοινώνει γεγονότα του site σε κανάλια: πηγές → ουρά → προορισμοί. Κάθε module ενεργοποιείται χωριστά· όσα είναι κλειστά δεν φορτώνουν τίποτα.', 'broadcast' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			self::state_notices();
			?>

			<nav class="nav-tab-wrapper brc-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s%4$s</a>',
						esc_url( self::url( $slug ) ),
						$slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label ),
						'queue' === $slug && $pending ? ' <span class="brc-count">' . (int) $pending . '</span>' : ''
					);
				}
				?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'history':
					self::render_history();
					break;
				case 'modules':
					self::render_modules();
					break;
				case 'sources':
					self::render_sources();
					break;
				case 'viber':
					self::render_viber();
					break;
				case 'templates':
					self::render_templates();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_queue();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/** Notices about the whole plugin: kill switch, mode, trial, brake, no destination. */
	private static function state_notices(): void {
		$s = BRC_Settings::get();
		if ( Broadcast::killed() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το BRC_DISABLE είναι ενεργό στο wp-config.php: καμία πηγή, καμία ανάρτηση και κανένα webhook.', 'broadcast' ) . '</p></div>';
		}
		if ( 'test' === $s['mode'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Λειτουργία δοκιμής: οι πηγές γεμίζουν την ουρά κανονικά, αλλά τίποτα δεν φεύγει από το site. Στο Ιστορικό βλέπεις τι θα στελνόταν.', 'broadcast' ) . '</p></div>';
		} elseif ( 'off' === $s['mode'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Ανενεργό: οι πηγές δεν προσθέτουν τίποτα και τίποτα δεν στέλνεται.', 'broadcast' ) . '</p></div>';
		}
		$src_on = false;
		foreach ( array_keys( Broadcast::SOURCES ) as $src ) {
			$src_on = $src_on || $s['mods'][ $src ];
		}
		if ( $src_on && ! BRC_Settings::destinations() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Καμία πηγή δεν έχει πού να στείλει: ενεργοποίησε έναν προορισμό (Viber Channel) στα Modules.', 'broadcast' ) . '</p></div>';
		}
		if ( BRC_Settings::trial_due() ) {
			?>
			<div class="notice notice-info inline brc-trial">
				<p><?php esc_html_e( 'Πέρασαν 14 ημέρες δοκιμής με έγκριση. Να γίνουν όλες οι πηγές αυτόματες; Η ουρά αναρτήσεων περιμένει πάντα το «Δημοσιεύτηκε».', 'broadcast' ); ?></p>
				<form method="post" class="brc-inline-form">
					<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
					<button type="submit" name="brc_action" value="trial_auto" class="button button-primary"><?php esc_html_e( 'Να γίνουν αυτόματες', 'broadcast' ); ?></button>
					<button type="submit" name="brc_action" value="trial_later" class="button"><?php esc_html_e( 'Ρώτα με σε 7 ημέρες', 'broadcast' ); ?></button>
					<button type="submit" name="brc_action" value="trial_keep" class="button"><?php esc_html_e( 'Να μείνει η έγκριση', 'broadcast' ); ?></button>
				</form>
			</div>
			<?php
		}
		$notes = BRC_Queue::notes();
		if ( ! empty( $notes['brake'] ) && (int) $notes['brake'] > time() - DAY_IN_SECONDS ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: posts per hour, 2: posts per day */
					__( 'Το φρένο σταμάτησε αναρτήσεις το τελευταίο 24ωρο (όριο %1$d την ώρα και %2$d την ημέρα ανά πηγή). Περιμένουν στην Ουρά για έγκριση.', 'broadcast' ),
					$s['lim_hour'],
					$s['lim_day']
				)
			) . '</p></div>';
		}
		if ( ! empty( $notes['error'] ) && is_array( $notes['error'] ) && (int) $notes['error'][0] > time() - DAY_IN_SECONDS ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: error message */
					__( 'Σφάλμα στην τελευταία εκτέλεση στο παρασκήνιο: %s', 'broadcast' ),
					(string) $notes['error'][1]
				)
			) . '</p></div>';
		}
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="brc-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="brc-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function number( string $name, int $value, int $min, int $max, string $label, string $unit = '' ): void {
		printf(
			'<label class="brc-field"><span class="brc-label">%1$s</span><input type="number" name="%2$s" value="%3$d" min="%4$d" max="%5$d" class="small-text" /> %6$s</label>',
			esc_html( $label ),
			esc_attr( $name ),
			(int) $value,
			(int) $min,
			(int) $max,
			esc_html( $unit )
		);
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="brc-badge brc-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function status_badge( string $status ): string {
		$names = self::status_names();
		$kind  = array(
			'pending'  => 'warn',
			'queued'   => 'muted',
			'sending'  => 'muted',
			'sent'     => 'ok',
			'test'     => 'muted',
			'failed'   => 'danger',
			'skipped'  => 'muted',
			'rejected' => 'muted',
		);
		return self::badge( isset( $names[ $status ] ) ? $names[ $status ] : $status, isset( $kind[ $status ] ) ? $kind[ $status ] : 'muted' );
	}

	private static function when( int $t ): string {
		return $t ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $t ) : '—';
	}

	private static function off_notice( string $module ): void {
		if ( ! BRC_Settings::module( $module ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: module name */
					__( 'Το module «%s» είναι κλειστό. Οι ρυθμίσεις αποθηκεύονται, αλλά δεν κάνει τίποτα μέχρι να το ενεργοποιήσεις στα Modules.', 'broadcast' ),
					self::module_names()[ $module ]
				)
			) . '</p></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------- */

	private static function render_queue(): void {
		$items   = BRC_Queue::list( array( 'status' => 'pending' ), 100 );
		$names   = self::module_names();
		$reasons = self::reason_names();
		?>
		<h2 class="brc-h2"><?php esc_html_e( 'Περιμένουν', 'broadcast' ); ?></h2>
		<?php if ( ! $items ) : ?>
			<p class="brc-empty"><?php esc_html_e( 'Τίποτα δεν περιμένει.', 'broadcast' ); ?></p>
		<?php endif; ?>
		<div class="brc-items">
			<?php foreach ( $items as $it ) : ?>
				<form method="post" class="brc-item">
					<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
					<input type="hidden" name="brc_id" value="<?php echo (int) $it['id']; ?>" />
					<div class="brc-item-head">
						<?php echo self::badge( isset( $names[ $it['source'] ] ) ? $names[ $it['source'] ] : $it['source'], 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
						<?php echo self::badge( isset( $reasons[ $it['reason'] ] ) ? $reasons[ $it['reason'] ] : $it['reason'], 'brake' === $it['reason'] ? 'danger' : 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
						<span class="brc-muted"><?php echo esc_html( self::when( $it['created'] ) . ' → ' . ( isset( $names[ $it['dest'] ] ) ? $names[ $it['dest'] ] : $it['dest'] ) ); ?></span>
					</div>
					<div class="brc-item-body">
						<?php if ( '' !== $it['msg']['image'] ) : ?>
							<img class="brc-thumb" src="<?php echo esc_url( $it['msg']['image'] ); ?>" alt="" loading="lazy" />
						<?php endif; ?>
						<div class="brc-item-text">
							<strong><?php echo esc_html( $it['title'] ); ?></strong>
							<textarea name="brc_text" rows="5" class="brc-wide"><?php echo esc_textarea( $it['msg']['text'] ); ?></textarea>
							<?php if ( '' !== $it['msg']['url'] ) : ?>
								<a class="brc-link" href="<?php echo esc_url( $it['msg']['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $it['msg']['url'] ); ?></a>
							<?php endif; ?>
						</div>
					</div>
					<div class="brc-item-actions">
						<button type="submit" name="brc_action" value="approve" class="button button-primary brc-big">
							<?php echo 'publish' === $it['reason'] ? esc_html__( 'Δημοσιεύτηκε', 'broadcast' ) : esc_html__( 'Έγκριση και αποστολή', 'broadcast' ); ?>
						</button>
						<button type="submit" name="brc_action" value="edit" class="button brc-big"><?php esc_html_e( 'Αποθήκευση κειμένου', 'broadcast' ); ?></button>
						<button type="submit" name="brc_action" value="reject" class="button brc-big brc-danger" data-brc-confirm="<?php esc_attr_e( 'Απόρριψη αυτής της ανάρτησης;', 'broadcast' ); ?>"><?php esc_html_e( 'Απόρριψη', 'broadcast' ); ?></button>
					</div>
				</form>
			<?php endforeach; ?>
		</div>

		<?php if ( BRC_Settings::module( 'queue' ) ) : ?>
			<h2 class="brc-h2"><?php esc_html_e( 'Νέα ανάρτηση', 'broadcast' ); ?></h2>
			<form method="post" class="brc-zone">
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<input type="hidden" name="brc_action" value="add" />
				<p class="description"><?php esc_html_e( 'Μπαίνει στην ουρά και περιμένει το «Δημοσιεύτηκε», όπως όσα στέλνει μια ρουτίνα.', 'broadcast' ); ?></p>
				<label class="brc-field"><span class="brc-label"><?php esc_html_e( 'Κείμενο', 'broadcast' ); ?></span>
					<textarea name="brc_new_text" rows="4" class="brc-wide" maxlength="2000" required></textarea></label>
				<label class="brc-field"><span class="brc-label"><?php esc_html_e( 'Σύνδεσμος (προαιρετικό)', 'broadcast' ); ?></span>
					<input type="url" name="brc_new_url" class="brc-wide" placeholder="https://…" /></label>
				<label class="brc-field"><span class="brc-label"><?php esc_html_e( 'Εικόνα (URL https, προαιρετικό)', 'broadcast' ); ?></span>
					<input type="url" name="brc_new_image" class="brc-wide" placeholder="https://…" /></label>
				<p class="brc-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Προσθήκη στην ουρά', 'broadcast' ); ?></button></p>
			</form>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * History
	 * ------------------------------------------------------------------- */

	/** History filters from the query string (the screen and the CSV share them). */
	private static function history_filters(): array {
		$f      = array();
		$status = sanitize_key( self::q( 'st' ) );
		$source = sanitize_key( self::q( 'src' ) );
		if ( isset( self::status_names()[ $status ] ) ) {
			$f['status'] = $status;
		}
		if ( isset( Broadcast::SOURCES[ $source ] ) ) {
			$f['source'] = $source;
		}
		foreach ( array( 'from' => '00:00:00', 'to' => '23:59:59' ) as $k => $time ) {
			$v = self::q( $k );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				$d = date_create_immutable( $v . ' ' . $time, wp_timezone() );
				if ( $d ) {
					$f[ $k ] = $d->getTimestamp();
					$f[ $k . '_s' ] = $v;
				}
			}
		}
		$q = self::q( 'q' );
		if ( '' !== $q ) {
			$f['q'] = mb_substr( $q, 0, 100 );
		}
		return $f;
	}

	private static function render_history(): void {
		$f       = self::history_filters();
		$total   = BRC_Queue::count( $f );
		$page    = max( 1, (int) self::q( 'p' ) );
		$rows    = BRC_Queue::list( $f, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$names   = self::module_names();
		$reasons = self::reason_names();
		$pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$lang    = BRC_Lang::lang();
		?>
		<form method="get" class="brc-zone brc-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="history" />
			<select name="st">
				<option value=""><?php esc_html_e( 'Όλες οι καταστάσεις', 'broadcast' ); ?></option>
				<?php foreach ( self::status_names() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>"<?php selected( isset( $f['status'] ) ? $f['status'] : '', $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="src">
				<option value=""><?php esc_html_e( 'Όλες οι πηγές', 'broadcast' ); ?></option>
				<?php foreach ( array_keys( Broadcast::SOURCES ) as $k ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>"<?php selected( isset( $f['source'] ) ? $f['source'] : '', $k ); ?>><?php echo esc_html( $names[ $k ] ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" name="from" value="<?php echo esc_attr( isset( $f['from_s'] ) ? $f['from_s'] : '' ); ?>" aria-label="<?php esc_attr_e( 'Από', 'broadcast' ); ?>" />
			<input type="date" name="to" value="<?php echo esc_attr( isset( $f['to_s'] ) ? $f['to_s'] : '' ); ?>" aria-label="<?php esc_attr_e( 'Έως', 'broadcast' ); ?>" />
			<input type="search" name="q" value="<?php echo esc_attr( isset( $f['q'] ) ? $f['q'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Τίτλος', 'broadcast' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Φίλτρο', 'broadcast' ); ?></button>
		</form>

		<div class="brc-zone brc-inline">
			<?php
			$csv = array(
				'page'     => self::SLUG,
				'tab'      => 'history',
				'brc_csv'  => 1,
				'st'       => isset( $f['status'] ) ? $f['status'] : '',
				'src'      => isset( $f['source'] ) ? $f['source'] : '',
				'from'     => isset( $f['from_s'] ) ? $f['from_s'] : '',
				'to'       => isset( $f['to_s'] ) ? $f['to_s'] : '',
				'q'        => isset( $f['q'] ) ? $f['q'] : '',
				'_wpnonce' => wp_create_nonce( self::NONCE_CSV ),
			);
			$fmt = (string) get_user_option( 'brc_csv' );
			$fmt = in_array( $fmt, array( 'excel', 'std' ), true ) ? $fmt : ( 'el' === $lang ? 'excel' : 'std' );
			?>
			<a class="button" href="<?php echo esc_url( add_query_arg( $csv + array( 'fmt' => 'excel' ), admin_url( 'admin.php' ) ) ); ?>"><?php echo 'excel' === $fmt ? '★ ' : ''; ?><?php esc_html_e( 'CSV (Excel ελληνικά)', 'broadcast' ); ?></a>
			<a class="button" href="<?php echo esc_url( add_query_arg( $csv + array( 'fmt' => 'std' ), admin_url( 'admin.php' ) ) ); ?>"><?php echo 'std' === $fmt ? '★ ' : ''; ?><?php esc_html_e( 'CSV (Τυπικό)', 'broadcast' ); ?></a>
			<span class="brc-muted">
				<?php
				/* translators: %d: number of items */
				echo esc_html( sprintf( __( '%d εγγραφές', 'broadcast' ), $total ) );
				?>
			</span>
		</div>

		<div class="brc-xscroll">
		<table class="widefat brc-table">
			<thead><tr>
				<th><?php esc_html_e( 'Πότε', 'broadcast' ); ?></th>
				<th><?php esc_html_e( 'Πηγή', 'broadcast' ); ?></th>
				<th><?php esc_html_e( 'Τίτλος', 'broadcast' ); ?></th>
				<th><?php esc_html_e( 'Κατάσταση', 'broadcast' ); ?></th>
				<th><?php esc_html_e( 'Απάντηση', 'broadcast' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6" class="brc-empty"><?php esc_html_e( 'Καμία εγγραφή.', 'broadcast' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( self::when( $r['sent_at'] ? $r['sent_at'] : $r['created'] ) ); ?></td>
					<td><?php echo esc_html( isset( $names[ $r['source'] ] ) ? $names[ $r['source'] ] : $r['source'] ); ?></td>
					<td>
						<?php if ( '' !== $r['msg']['url'] ) : ?>
							<a href="<?php echo esc_url( $r['msg']['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $r['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $r['title'] ); ?>
						<?php endif; ?>
						<details class="brc-details"><summary><?php esc_html_e( 'Κείμενο', 'broadcast' ); ?></summary><pre><?php echo esc_html( $r['msg']['text'] ); ?></pre></details>
					</td>
					<td>
						<?php echo self::status_badge( $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
						<?php if ( '' !== $r['reason'] ) : ?>
							<span class="brc-muted"><?php echo esc_html( isset( $reasons[ $r['reason'] ] ) ? $reasons[ $r['reason'] ] : $r['reason'] ); ?></span>
						<?php endif; ?>
					</td>
					<td class="brc-result"><?php echo esc_html( mb_substr( $r['result'], 0, 300 ) ); ?></td>
					<td>
						<?php if ( 'failed' === $r['status'] ) : ?>
							<form method="post">
								<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
								<input type="hidden" name="brc_id" value="<?php echo (int) $r['id']; ?>" />
								<button type="submit" name="brc_action" value="retry" class="button"><?php esc_html_e( 'Ξανά', 'broadcast' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php if ( $pages > 1 ) : ?>
			<p class="brc-pages">
				<?php
				for ( $i = 1; $i <= $pages; $i++ ) {
					if ( $i === $page ) {
						echo '<span class="brc-page is-current">' . (int) $i . '</span> ';
						continue;
					}
					$args = array(
						'st'   => isset( $f['status'] ) ? $f['status'] : '',
						'src'  => isset( $f['source'] ) ? $f['source'] : '',
						'from' => isset( $f['from_s'] ) ? $f['from_s'] : '',
						'to'   => isset( $f['to_s'] ) ? $f['to_s'] : '',
						'q'    => isset( $f['q'] ) ? $f['q'] : '',
						'p'    => $i,
					);
					echo '<a class="brc-page" href="' . esc_url( self::url( 'history', array_filter( $args ) ) ) . '">' . (int) $i . '</a> ';
				}
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Modules
	 * ------------------------------------------------------------------- */

	private static function module_desc(): array {
		return array(
			'products'   => __( 'Κάθε νέο προϊόν που δημοσιεύεται, με φίλτρο κατηγοριών. Οι τίτλοι του Book Feed μένουν έξω από προεπιλογή.', 'broadcast' ),
			'newsletter' => __( 'Όταν σταλεί μια καμπάνια στο MailerLite (webhook campaign.sent), ανάρτηση με τον τίτλο και σύνδεσμο. Το orOS Newsletter θα έρθει από το ίδιο σημείο.', 'broadcast' ),
			'youtube'    => __( 'Νέα βίντεο από τα κανάλια YouTube που ορίζεις, χωρίς κλειδί API. Τα Shorts μένουν έξω από προεπιλογή.', 'broadcast' ),
			'queue'      => __( 'Αναρτήσεις που ετοιμάζει μια ρουτίνα (π.χ. μέσω Site Bridge) ή εσύ, και φεύγουν όταν πατήσεις «Δημοσιεύτηκε».', 'broadcast' ),
			'digest'     => __( 'Ένα μήνυμα την εβδομάδα με τους νέους τίτλους άλλων εκδοτών από το Book Feed, αντί για ένα ανά τίτλο.', 'broadcast' ),
			'viber'      => __( 'Αναρτήσεις σε ένα Viber Channel μέσω του Channels Post API, με το token του καναλιού.', 'broadcast' ),
		);
	}

	private static function render_modules(): void {
		$s     = BRC_Settings::get();
		$names = self::module_names();
		$desc  = self::module_desc();
		$woo   = Broadcast::woo();
		$bf    = function_exists( 'bkf_get_source' );
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="brc_action" value="save_modules" />

			<h2 class="brc-h2"><?php esc_html_e( 'Πηγές', 'broadcast' ); ?></h2>
			<div class="brc-mods">
				<?php foreach ( Broadcast::SOURCES as $m => $needs_wc ) : ?>
					<div class="brc-mod">
						<h3>
							<?php echo esc_html( $names[ $m ] ); ?>
							<?php echo $s['mods'][ $m ] ? self::badge( __( 'Ενεργό', 'broadcast' ), 'ok' ) : self::badge( __( 'Κλειστό', 'broadcast' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
						</h3>
						<p><?php echo esc_html( $desc[ $m ] ); ?></p>
						<?php if ( $needs_wc && ! $woo ) : ?>
							<p class="brc-warn"><?php esc_html_e( 'Θέλει WooCommerce.', 'broadcast' ); ?></p>
						<?php endif; ?>
						<?php if ( 'digest' === $m && ! $bf ) : ?>
							<p class="brc-warn"><?php esc_html_e( 'Θέλει το Book Feed.', 'broadcast' ); ?></p>
						<?php endif; ?>
						<?php self::checkbox( 'brc_mod_' . $m, $s['mods'][ $m ], __( 'Ενεργοποίηση', 'broadcast' ) ); ?>
						<?php if ( in_array( $m, BRC_Settings::APPROVABLE, true ) ) : ?>
							<?php self::checkbox( 'brc_ap_' . $m, $s['approve'][ $m ], __( 'Χρειάζεται έγκριση', 'broadcast' ) ); ?>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<div class="brc-mod is-soon">
					<h3><?php esc_html_e( 'Instagram', 'broadcast' ); ?> <?php echo self::badge( __( 'Σύντομα', 'broadcast' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></h3>
					<p><?php esc_html_e( 'Νέες αναρτήσεις του λογαριασμού Instagram σου (Business), μέσω δικής σου εφαρμογής Meta.', 'broadcast' ); ?></p>
				</div>
			</div>

			<h2 class="brc-h2"><?php esc_html_e( 'Προορισμοί', 'broadcast' ); ?></h2>
			<div class="brc-mods">
				<?php foreach ( Broadcast::DESTS as $m ) : ?>
					<div class="brc-mod">
						<h3>
							<?php echo esc_html( $names[ $m ] ); ?>
							<?php echo $s['mods'][ $m ] ? self::badge( __( 'Ενεργό', 'broadcast' ), 'ok' ) : self::badge( __( 'Κλειστό', 'broadcast' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
						</h3>
						<p><?php echo esc_html( $desc[ $m ] ); ?></p>
						<?php self::checkbox( 'brc_mod_' . $m, $s['mods'][ $m ], __( 'Ενεργοποίηση', 'broadcast' ) ); ?>
					</div>
				<?php endforeach; ?>
				<?php
				$soon = array(
					__( 'Telegram Channel', 'broadcast' ) => __( 'Οι ίδιες αναρτήσεις σε ένα κανάλι Telegram, με bot διαχειριστή.', 'broadcast' ),
					__( 'Facebook Page', 'broadcast' )    => __( 'Οι ίδιες αναρτήσεις στη σελίδα σου στο Facebook.', 'broadcast' ),
				);
				foreach ( $soon as $name => $text ) {
					?>
					<div class="brc-mod is-soon">
						<h3><?php echo esc_html( $name ); ?> <?php echo self::badge( __( 'Σύντομα', 'broadcast' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></h3>
						<p><?php echo esc_html( $text ); ?></p>
					</div>
					<?php
				}
				?>
			</div>

			<h2 class="brc-h2"><?php esc_html_e( 'Λειτουργία', 'broadcast' ); ?></h2>
			<div class="brc-zone">
				<?php
				self::radio( 'brc_mode', 'test', $s['mode'], __( 'Δοκιμή: η ουρά γεμίζει, αλλά τίποτα δεν φεύγει από το site', 'broadcast' ) );
				self::radio( 'brc_mode', 'live', $s['mode'], __( 'Ενεργό: οι αναρτήσεις στέλνονται', 'broadcast' ) );
				self::radio( 'brc_mode', 'off', $s['mode'], __( 'Ανενεργό: οι πηγές δεν προσθέτουν τίποτα', 'broadcast' ) );
				?>
			</div>

			<h2 class="brc-h2"><?php esc_html_e( 'Φρένο και ιστορικό', 'broadcast' ); ?></h2>
			<div class="brc-zone">
				<?php
				self::number( 'brc_lim_hour', $s['lim_hour'], 1, 100, __( 'Έως τόσες αναρτήσεις ανά πηγή την ώρα', 'broadcast' ) );
				self::number( 'brc_lim_day', $s['lim_day'], 1, 500, __( 'Έως τόσες αναρτήσεις ανά πηγή την ημέρα', 'broadcast' ) );
				?>
				<p class="description"><?php esc_html_e( 'Ό,τι περισσεύει (π.χ. μαζική δημοσίευση προϊόντων) δεν χάνεται: περιμένει στην Ουρά για έγκριση.', 'broadcast' ); ?></p>
				<?php self::number( 'brc_keep_days', $s['keep_days'], 7, 730, __( 'Το ιστορικό κρατά', 'broadcast' ), __( 'ημέρες', 'broadcast' ) ); ?>
			</div>

			<p class="brc-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'broadcast' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Sources
	 * ------------------------------------------------------------------- */

	private static function cat_options( array $selected ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1000,
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}
		$by_parent = array();
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = $t;
		}
		$walk = static function ( int $parent, int $depth ) use ( &$walk, $by_parent, $selected ) {
			foreach ( isset( $by_parent[ $parent ] ) ? $by_parent[ $parent ] : array() as $t ) {
				printf(
					'<option value="%1$d"%2$s>%3$s%4$s</option>',
					(int) $t->term_id,
					in_array( (int) $t->term_id, $selected, true ) ? ' selected' : '',
					esc_html( str_repeat( '— ', $depth ) ),
					esc_html( $t->name )
				);
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
	}

	private static function secret_state( string $name ): void {
		$st = BRC_Crypt::state( $name );
		if ( 'constant' === $st ) {
			echo '<p class="description">' . self::badge( __( 'Από το wp-config.php', 'broadcast' ), 'ok' ) . ' ' . esc_html( BRC_Crypt::SECRETS[ $name ] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
		} elseif ( 'set' === $st ) {
			echo '<p class="description">' . self::badge( __( 'Αποθηκευμένο', 'broadcast' ), 'ok' ) . ' ' . esc_html__( 'Κενό πεδίο = μένει το τωρινό.', 'broadcast' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
		} elseif ( 'unreadable' === $st ) {
			echo '<p class="brc-warn">' . esc_html__( 'Το αποθηκευμένο μυστικό δεν διαβάζεται πια (άλλαξαν τα salts του wp-config.php). Γράψ\' το ξανά.', 'broadcast' ) . '</p>';
		}
	}

	private static function copy_field( string $value ): void {
		printf(
			'<input type="text" class="brc-wide brc-copy" readonly value="%1$s" />',
			esc_attr( $value )
		);
	}

	private static function render_sources(): void {
		$s     = BRC_Settings::get();
		$notes = BRC_Queue::notes();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="brc_action" value="save_sources" />

			<?php if ( Broadcast::woo() ) : ?>
				<h2 class="brc-h2"><?php esc_html_e( 'Νέα προϊόντα', 'broadcast' ); ?></h2>
				<?php self::off_notice( 'products' ); ?>
				<div class="brc-zone">
					<?php self::number( 'brc_p_delay', $s['p_delay'], 0, 60, __( 'Αναμονή μετά τη δημοσίευση', 'broadcast' ), __( 'λεπτά', 'broadcast' ) ); ?>
					<p class="description"><?php esc_html_e( 'Ώστε διορθώσεις τίτλου ή εικόνας αμέσως μετά τη δημοσίευση να μπαίνουν στην ανάρτηση. Μόνο νέα προϊόντα, ποτέ ενημερώσεις.', 'broadcast' ); ?></p>
					<div class="brc-grid">
						<label class="brc-field"><span class="brc-label"><?php esc_html_e( 'Μόνο από αυτές τις κατηγορίες (καμία = όλες)', 'broadcast' ); ?></span>
							<select name="brc_p_cats_in[]" multiple size="8" class="brc-wide"><?php self::cat_options( $s['p_cats_in'] ); ?></select></label>
						<label class="brc-field"><span class="brc-label"><?php esc_html_e( 'Ποτέ από αυτές τις κατηγορίες', 'broadcast' ); ?></span>
							<select name="brc_p_cats_ex[]" multiple size="8" class="brc-wide"><?php self::cat_options( $s['p_cats_ex'] ); ?></select></label>
					</div>
					<p class="description"><?php esc_html_e( 'Οι υποκατηγορίες μετρούν μαζί με τη γονική. Ctrl / Cmd για πολλές.', 'broadcast' ); ?></p>
					<span class="brc-label"><?php esc_html_e( 'Τύποι προϊόντων (κανένας = όλοι)', 'broadcast' ); ?></span>
					<div class="brc-inline">
						<?php
						foreach ( wc_get_product_types() as $type => $label ) {
							self::checkbox( 'brc_p_types[' . $type . ']', in_array( $type, $s['p_types'], true ), (string) $label );
						}
						?>
					</div>
					<?php
					self::checkbox( 'brc_p_image', $s['p_image'], __( 'Μόνο προϊόντα με εικόνα', 'broadcast' ) );
					self::checkbox( 'brc_p_skip_bf', $s['p_skip_bf'], __( 'Χωρίς τους τίτλους που έφερε το Book Feed (άλλοι εκδότες)', 'broadcast' ) );
					self::checkbox( 'brc_p_skip_hidden', $s['p_skip_hidden'], __( 'Χωρίς τις κατηγορίες που κρύβει το Hide Catalog', 'broadcast' ) );
					?>
				</div>
			<?php endif; ?>

			<h2 class="brc-h2"><?php esc_html_e( 'Newsletter (MailerLite)', 'broadcast' ); ?></h2>
			<?php self::off_notice( 'newsletter' ); ?>
			<div class="brc-zone">
				<ol>
					<li><?php esc_html_e( 'Στο MailerLite: Integrations → Webhooks → Create webhook, γεγονός «Campaign sent».', 'broadcast' ); ?></li>
					<li><?php esc_html_e( 'Διεύθυνση (URL) του webhook:', 'broadcast' ); ?> <?php self::copy_field( rest_url( 'broadcast/v1/hook/newsletter/mailerlite' ) ); ?></li>
					<li><?php esc_html_e( 'Αντέγραψε το Secret του webhook εδώ:', 'broadcast' ); ?></li>
				</ol>
				<input type="password" name="brc_ml_secret" class="brc-wide" autocomplete="new-password" spellcheck="false" placeholder="<?php esc_attr_e( 'Secret του webhook', 'broadcast' ); ?>" />
				<?php self::secret_state( 'mailerlite' ); ?>
				<?php if ( ! empty( $notes['nl_hook'] ) ) : ?>
					<p class="description">
						<?php
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'Τελευταίο υπογεγραμμένο αίτημα από το MailerLite: %s', 'broadcast' ), self::when( (int) $notes['nl_hook'] ) ) );
						?>
					</p>
				<?php endif; ?>
				<label class="brc-field"><span class="brc-label"><?php esc_html_e( 'Σύνδεσμος της ανάρτησης', 'broadcast' ); ?></span>
					<input type="url" name="brc_nl_link" class="brc-wide" value="<?php echo esc_attr( $s['nl_link'] ); ?>" placeholder="https://…" /></label>
				<p class="description"><?php esc_html_e( 'Το MailerLite δεν στέλνει θέμα ούτε δημόσιο σύνδεσμο της καμπάνιας. Βάλε έναν σταθερό σύνδεσμο (π.χ. αρχείο newsletter ή σελίδα εγγραφής). Κενό = η ανάρτηση έχει μόνο τον τίτλο.', 'broadcast' ); ?></p>
				<?php self::checkbox( 'brc_nl_preview', $s['nl_preview'], __( 'Χωρίς σταθερό σύνδεσμο: χρήση του preview_url της καμπάνιας (μόνο αν ανοίγει χωρίς σύνδεση στο MailerLite)', 'broadcast' ) ); ?>
			</div>

			<h2 class="brc-h2"><?php esc_html_e( 'YouTube', 'broadcast' ); ?></h2>
			<?php self::off_notice( 'youtube' ); ?>
			<div class="brc-zone">
				<label class="brc-field"><span class="brc-label"><?php esc_html_e( 'Κανάλια (ένα ανά γραμμή, έως 5): @handle, διεύθυνση καναλιού ή id UC…', 'broadcast' ); ?></span>
					<textarea name="brc_yt_channels" rows="3" class="brc-wide" spellcheck="false"><?php echo esc_textarea( implode( "\n", $s['yt_channels'] ) ); ?></textarea></label>
				<?php
				self::checkbox( 'brc_yt_shorts', $s['yt_shorts'], __( 'Μαζί με τα Shorts', 'broadcast' ) );
				self::checkbox( 'brc_yt_live', $s['yt_live'], __( 'Μαζί με τα live', 'broadcast' ) );
				self::number( 'brc_yt_every', $s['yt_every'], 15, 120, __( 'Έλεγχος κάθε', 'broadcast' ), __( 'λεπτά', 'broadcast' ) );
				?>
				<p class="description"><?php esc_html_e( 'Χωρίς κλειδί API: διαβάζεται το δημόσιο feed της λίστας «μεγάλα βίντεο» του καναλιού, όπου τα Shorts δεν εμφανίζονται. Στον πρώτο έλεγχο τα υπάρχοντα βίντεο απλώς σημειώνονται· αναρτώνται μόνο τα νέα.', 'broadcast' ); ?></p>
				<?php
				$state = isset( $notes['yt_state'] ) && is_array( $notes['yt_state'] ) ? $notes['yt_state'] : array();
				if ( $s['yt_channels'] ) {
					echo '<ul class="brc-list">';
					foreach ( $s['yt_channels'] as $ch ) {
						$st = isset( $state[ $ch ] ) ? (string) $state[ $ch ] : '';
						if ( 'long' === $st ) {
							$txt = __( 'μόνο μεγάλα βίντεο (UULF)', 'broadcast' );
						} elseif ( 'all' === $st ) {
							$txt = __( 'όλα τα βίντεο', 'broadcast' );
						} elseif ( 'fallback' === $st ) {
							$txt = __( 'όλα τα βίντεο + έλεγχος Short ανά βίντεο', 'broadcast' );
						} elseif ( '' === $st ) {
							$txt = __( 'δεν ελέγχθηκε ακόμα', 'broadcast' );
						} else {
							$txt = $st;
						}
						echo '<li><code>' . esc_html( $ch ) . '</code> — ' . esc_html( $txt ) . '</li>';
					}
					echo '</ul>';
					if ( ! empty( $notes['yt_poll'] ) ) {
						/* translators: %s: date and time */
						echo '<p class="description">' . esc_html( sprintf( __( 'Τελευταίος έλεγχος: %s', 'broadcast' ), self::when( (int) $notes['yt_poll'] ) ) ) . '</p>';
					}
					if ( BRC_Settings::module( 'youtube' ) ) {
						echo '<button type="submit" name="brc_action" value="yt_now" class="button">' . esc_html__( 'Έλεγχος τώρα', 'broadcast' ) . '</button>';
					}
				}
				?>
			</div>

			<?php if ( Broadcast::woo() ) : ?>
				<h2 class="brc-h2"><?php esc_html_e( 'Εβδομαδιαίο digest Book Feed', 'broadcast' ); ?></h2>
				<?php self::off_notice( 'digest' ); ?>
				<div class="brc-zone">
					<label class="brc-field"><span class="brc-label"><?php esc_html_e( 'Ημέρα', 'broadcast' ); ?></span>
						<select name="brc_dg_day">
							<?php
							global $wp_locale;
							for ( $d = 0; $d < 7; $d++ ) {
								printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $d, selected( $s['dg_day'], $d, false ), esc_html( $wp_locale->get_weekday( $d ) ) );
							}
							?>
						</select></label>
					<?php
					self::number( 'brc_dg_hour', $s['dg_hour'], 0, 23, __( 'Ώρα (του site)', 'broadcast' ), ':00' );
					self::number( 'brc_dg_max', $s['dg_max'], 1, 20, __( 'Τίτλοι στη λίστα', 'broadcast' ) );
					?>
					<label class="brc-field"><span class="brc-label"><?php esc_html_e( 'Σύνδεσμος του μηνύματος (κενό = το κατάστημα)', 'broadcast' ); ?></span>
						<input type="url" name="brc_dg_url" class="brc-wide" value="<?php echo esc_attr( $s['dg_url'] ); ?>" placeholder="https://…" /></label>
					<?php if ( function_exists( 'bkf_get_source' ) ) : ?>
						<?php
						Broadcast::load_source( 'digest' );
						$ids = class_exists( 'BRC_Src_Digest' ) ? BRC_Src_Digest::titles( time() - 7 * DAY_IN_SECONDS, time() ) : array();
						?>
						<p class="description">
							<?php
							/* translators: %d: number of titles */
							echo esc_html( sprintf( __( 'Τις τελευταίες 7 ημέρες δημοσιεύτηκαν %d τίτλοι άλλων εκδοτών.', 'broadcast' ), count( $ids ) ) );
							?>
						</p>
					<?php else : ?>
						<p class="brc-warn"><?php esc_html_e( 'Το Book Feed δεν είναι ενεργό.', 'broadcast' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p class="brc-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'broadcast' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Viber
	 * ------------------------------------------------------------------- */

	private static function render_viber(): void {
		$acc   = get_option( 'brc_viber', array() );
		$acc   = is_array( $acc ) ? $acc : array();
		$notes = BRC_Queue::notes();
		self::off_notice( 'viber' );
		?>
		<h2 class="brc-h2"><?php esc_html_e( 'Σύνδεση', 'broadcast' ); ?></h2>
		<div class="brc-zone">
			<?php if ( ! empty( $acc['from'] ) ) : ?>
				<p>
					<?php echo self::badge( __( 'Συνδέθηκε', 'broadcast' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
					<strong><?php echo esc_html( (string) $acc['channel'] ); ?></strong>
					<?php
					/* translators: %d: number of subscribers */
					echo esc_html( sprintf( __( '· %d συνδρομητές', 'broadcast' ), (int) $acc['subs'] ) );
					?>
				</p>
				<p class="description">
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Συνδέθηκε: %s', 'broadcast' ), self::when( (int) $acc['at'] ) ) );
					if ( ! empty( $notes['viber_cb'] ) ) {
						echo ' · ';
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'τελευταίο υπογεγραμμένο αίτημα από το Viber: %s', 'broadcast' ), self::when( (int) $notes['viber_cb'] ) ) );
					}
					?>
				</p>
				<?php if ( isset( $acc['hook'] ) && rest_url( 'broadcast/v1/viber' ) !== $acc['hook'] ) : ?>
					<p class="brc-warn"><?php esc_html_e( 'Η διεύθυνση του site άλλαξε από τη σύνδεση: πάτησε ξανά «Σύνδεση με το Viber».', 'broadcast' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p><?php echo self::badge( __( 'Δεν συνδέθηκε', 'broadcast' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></p>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<ol>
					<li><?php esc_html_e( 'Στο Viber (ως superadmin του καναλιού): Πληροφορίες καναλιού → Ρυθμίσεις → Developer Tools → αντιγραφή token.', 'broadcast' ); ?></li>
					<li><?php esc_html_e( 'Επικόλλησέ το εδώ και πάτησε «Σύνδεση με το Viber». Το site δηλώνει το webhook του και διαβάζει το κανάλι.', 'broadcast' ); ?></li>
				</ol>
				<input type="password" name="brc_vb_token" class="brc-wide" autocomplete="new-password" spellcheck="false" placeholder="<?php esc_attr_e( 'Token του καναλιού', 'broadcast' ); ?>" />
				<?php self::secret_state( 'viber' ); ?>
				<p class="description"><?php esc_html_e( 'Webhook του site (το δηλώνει η σύνδεση):', 'broadcast' ); ?></p>
				<?php self::copy_field( rest_url( 'broadcast/v1/viber' ) ); ?>
				<p class="brc-submit">
					<button type="submit" name="brc_action" value="vb_connect" class="button button-primary"<?php disabled( ! BRC_Settings::module( 'viber' ) ); ?>><?php esc_html_e( 'Σύνδεση με το Viber', 'broadcast' ); ?></button>
					<button type="submit" name="brc_action" value="vb_save" class="button"><?php esc_html_e( 'Αποθήκευση token', 'broadcast' ); ?></button>
					<?php if ( ! empty( $acc['from'] ) || 'none' !== BRC_Crypt::state( 'viber' ) ) : ?>
						<button type="submit" name="brc_action" value="vb_disconnect" class="button brc-danger" data-brc-confirm="<?php esc_attr_e( 'Αποσύνδεση από το Viber; Το token θα σβηστεί.', 'broadcast' ); ?>"><?php esc_html_e( 'Αποσύνδεση', 'broadcast' ); ?></button>
					<?php endif; ?>
				</p>
				<p class="description"><?php esc_html_e( 'Η Αποσύνδεση σβήνει το webhook στο Viber και το token από το site. Στη λειτουργία δοκιμής η σύνδεση γίνεται κανονικά, αλλά καμία ανάρτηση δεν φεύγει.', 'broadcast' ); ?></p>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------- */

	private static function render_templates(): void {
		$s     = BRC_Settings::get();
		$names = self::module_names();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="brc_action" value="save_templates" />
			<p class="description"><?php esc_html_e( 'Ένα κείμενο ανά πηγή, στη γλώσσα του καναλιού. Μια γραμμή που μένει κενή επειδή η πηγή δεν έχει την τιμή (π.χ. τιμή) παραλείπεται. Κενό πρότυπο = το αρχικό.', 'broadcast' ); ?></p>
			<?php foreach ( array_keys( BRC_Settings::default_templates() ) as $src ) : ?>
				<h2 class="brc-h2"><?php echo esc_html( $names[ $src ] ); ?></h2>
				<div class="brc-zone">
					<textarea name="brc_tpl_<?php echo esc_attr( $src ); ?>" rows="5" class="brc-wide" maxlength="2000"><?php echo esc_textarea( $s[ 'tpl_' . $src ] ); ?></textarea>
					<p class="description">
						<?php
						echo esc_html__( 'Placeholders:', 'broadcast' ) . ' ';
						echo esc_html(
							implode(
								' ',
								array_map(
									static function ( $k ) {
										return '{' . $k . '}';
									},
									BRC_Template::keys_for( $src )
								)
							)
						);
						?>
					</p>
				</div>
			<?php endforeach; ?>
			<p class="brc-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'broadcast' ); ?></button>
				<button type="submit" name="brc_action" value="reset_templates" class="button"><?php esc_html_e( 'Επαναφορά αρχικών', 'broadcast' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		?>
		<h2 class="brc-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'broadcast' ); ?></h2>
		<div class="brc-zone">
			<form method="post" enctype="multipart/form-data" class="brc-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'brc_backup_nonce' ); ?>
				<input type="file" name="brc_import_file" accept=".json,application/json" />
				<button type="submit" name="brc_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'broadcast' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'tools', array( 'brc_export' => 1 ) ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'broadcast' ); ?></a>
			<p class="description brc-mt-8"><?php esc_html_e( 'Χωρίς το token του Viber και το secret του newsletter: μετά την εισαγωγή μένουν όσα υπάρχουν. Οι διακόπτες των modules μένουν όπως είναι, για να τους ανοίξεις εσύ.', 'broadcast' ); ?></p>
		</div>

		<h2 class="brc-h2"><?php esc_html_e( 'Ιστορικό', 'broadcast' ); ?></h2>
		<div class="brc-zone">
			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<input type="hidden" name="brc_action" value="clear_history" />
				<p><?php esc_html_e( 'Σβήνει όσα στάλθηκαν, απέτυχαν, παραλείφθηκαν ή απορρίφθηκαν. Όσα περιμένουν μένουν. Η προστασία από διπλές αναρτήσεις για αυτά χάνεται.', 'broadcast' ); ?></p>
				<button type="submit" class="button brc-danger" data-brc-confirm="<?php esc_attr_e( 'Να σβηστεί το ιστορικό;', 'broadcast' ); ?>"><?php esc_html_e( 'Καθαρισμός ιστορικού', 'broadcast' ); ?></button>
			</form>
		</div>

		<h2 class="brc-h2"><?php esc_html_e( 'Για developers', 'broadcast' ); ?></h2>
		<div class="brc-zone">
			<table class="widefat striped brc-table">
				<tbody>
					<tr><td><code>broadcast/queue-add</code> <?php esc_html_e( '(ability)', 'broadcast' ); ?></td><td><?php esc_html_e( 'Νέα ανάρτηση στην ουρά: { text, url, image_url | attachment_id, ref }. Περιμένει το «Δημοσιεύτηκε».', 'broadcast' ); ?></td></tr>
					<tr><td><code>broadcast/queue-list</code>, <code>broadcast/queue-send</code>, <code>broadcast/status</code></td><td><?php esc_html_e( 'Τι περιμένει, αποστολή ενός ( { id, text } ), κατάσταση.', 'broadcast' ); ?></td></tr>
					<tr><td><code>brc_message</code> <?php esc_html_e( '(φίλτρο)', 'broadcast' ); ?></td><td><?php esc_html_e( 'Αλλάζει ένα μήνυμα πριν μπει στην ουρά: ( $message, $source, $key ). Κενός πίνακας = δεν μπαίνει.', 'broadcast' ); ?></td></tr>
					<tr><td><code>brc_item_sent</code> <?php esc_html_e( '(action)', 'broadcast' ); ?></td><td><?php esc_html_e( 'Μετά από κάθε ανάρτηση: ( $id, $item ).', 'broadcast' ); ?></td></tr>
					<tr><td><code>POST /wp-json/broadcast/v1/hook/newsletter/mailerlite</code></td><td><?php esc_html_e( 'Webhook «campaign.sent» του MailerLite, μόνο με σωστή υπογραφή.', 'broadcast' ); ?></td></tr>
					<tr><td><code>BRC_VIBER_TOKEN</code>, <code>BRC_MAILERLITE_SECRET</code></td><td><?php esc_html_e( 'Στο wp-config.php: τα μυστικά από εκεί, με προτεραιότητα.', 'broadcast' ); ?></td></tr>
					<tr><td><code>BRC_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: σταματά κάθε πηγή, κάθε ανάρτηση και κάθε webhook.', 'broadcast' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		if ( isset( $_POST['brc_import'] ) ) {
			if ( ! isset( $_POST['brc_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['brc_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( Broadcast::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'broadcast' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['brc_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Broadcast::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'broadcast' ) );
		}

		$saved = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'broadcast' ) ) );
		$id    = (int) self::post( 'brc_id' );
		switch ( sanitize_key( wp_unslash( $_POST['brc_action'] ) ) ) {
			case 'approve':
				$ok = BRC_Queue::approve( $id, self::post( 'brc_text' ) );
				self::redirect( 'queue', array( $ok ? self::msg( 'success', 'test' === BRC_Settings::get()['mode'] ? __( 'Εγκρίθηκε. Λειτουργία δοκιμής: θα σημειωθεί «Δοκιμή» και τίποτα δεν θα φύγει.', 'broadcast' ) : __( 'Εγκρίθηκε: φεύγει μέσα σε ένα λεπτό.', 'broadcast' ) ) : self::msg( 'error', __( 'Το στοιχείο δεν περιμένει πια.', 'broadcast' ) ) ) );
				break;
			case 'edit':
				$ok = BRC_Queue::edit( $id, self::post( 'brc_text' ) );
				self::redirect( 'queue', array( $ok ? self::msg( 'success', __( 'Το κείμενο αποθηκεύτηκε.', 'broadcast' ) ) : self::msg( 'error', __( 'Το στοιχείο δεν περιμένει πια.', 'broadcast' ) ) ) );
				break;
			case 'reject':
				$ok = BRC_Queue::reject( $id );
				self::redirect( 'queue', array( $ok ? self::msg( 'success', __( 'Απορρίφθηκε.', 'broadcast' ) ) : self::msg( 'error', __( 'Το στοιχείο δεν περιμένει πια.', 'broadcast' ) ) ) );
				break;
			case 'retry':
				$ok = BRC_Queue::retry( $id );
				self::redirect( 'history', array( $ok ? self::msg( 'success', __( 'Ξανά στην ουρά.', 'broadcast' ) ) : self::msg( 'error', __( 'Το στοιχείο δεν έχει αποτύχει.', 'broadcast' ) ) ) );
				break;
			case 'add':
				self::redirect( 'queue', self::do_add() );
				break;
			case 'save_modules':
				self::do_save_modules();
				self::redirect( 'modules', $saved );
				break;
			case 'save_sources':
				self::redirect( 'sources', self::do_save_sources() );
				break;
			case 'yt_now':
				$msgs = self::do_save_sources();
				if ( BRC_Settings::module( 'youtube' ) && Broadcast::load_source( 'youtube' ) ) {
					BRC_Src_Youtube::maybe_poll( true );
					$msgs[] = self::msg( 'success', __( 'Ο έλεγχος του YouTube έγινε.', 'broadcast' ) );
				}
				self::redirect( 'sources', $msgs );
				break;
			case 'save_templates':
				$s = BRC_Settings::get();
				foreach ( array_keys( BRC_Settings::default_templates() ) as $src ) {
					$s[ 'tpl_' . $src ] = self::post( 'brc_tpl_' . $src );
				}
				BRC_Settings::save( $s );
				self::redirect( 'templates', $saved );
				break;
			case 'reset_templates':
				$s = BRC_Settings::get();
				foreach ( BRC_Settings::default_templates() as $src => $tpl ) {
					$s[ 'tpl_' . $src ] = $tpl;
				}
				BRC_Settings::save( $s );
				self::redirect( 'templates', array( self::msg( 'success', __( 'Τα πρότυπα επανήλθαν στα αρχικά.', 'broadcast' ) ) ) );
				break;
			case 'vb_save':
				self::redirect( 'viber', self::do_token() );
				break;
			case 'vb_connect':
				$msgs = self::do_token();
				$msgs[] = self::do_connect();
				self::redirect( 'viber', $msgs );
				break;
			case 'vb_disconnect':
				require_once BRC_PATH . 'includes/class-viber.php';
				BRC_Viber::disconnect();
				BRC_Crypt::put( 'viber', '' );
				self::redirect( 'viber', array( self::msg( 'success', __( 'Αποσυνδέθηκε. Το token σβήστηκε από το site.', 'broadcast' ) ) ) );
				break;
			case 'trial_auto':
				$s = BRC_Settings::get();
				foreach ( BRC_Settings::APPROVABLE as $src ) {
					$s['approve'][ $src ] = false;
				}
				$s['trial_done'] = true;
				BRC_Settings::save( $s );
				self::redirect( 'modules', array( self::msg( 'success', __( 'Όλες οι πηγές στέλνουν πια αυτόματα. Η έγκριση ανοίγει ξανά ανά πηγή στα Modules.', 'broadcast' ) ) ) );
				break;
			case 'trial_later':
				$s             = BRC_Settings::get();
				$s['trial_at'] = time() - 7 * DAY_IN_SECONDS;
				BRC_Settings::save( $s );
				self::redirect( 'queue', array( self::msg( 'success', __( 'Θα σε ρωτήσει ξανά σε 7 ημέρες.', 'broadcast' ) ) ) );
				break;
			case 'trial_keep':
				$s               = BRC_Settings::get();
				$s['trial_done'] = true;
				BRC_Settings::save( $s );
				self::redirect( 'queue', array( self::msg( 'success', __( 'Η έγκριση μένει. Αλλάζει ανά πηγή στα Modules.', 'broadcast' ) ) ) );
				break;
			case 'clear_history':
				$n = BRC_Queue::clear_history();
				/* translators: %d: number of rows */
				self::redirect( 'tools', array( self::msg( 'success', sprintf( __( 'Σβήστηκαν %d εγγραφές.', 'broadcast' ), $n ) ) ) );
				break;
			default:
				self::redirect( 'queue', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'broadcast' ) ) ) );
		}
	}

	private static function do_add(): array {
		if ( ! BRC_Settings::module( 'queue' ) || ! Broadcast::load_source( 'queue' ) ) {
			return array( self::msg( 'error', __( 'Το module «Ουρά αναρτήσεων» είναι κλειστό.', 'broadcast' ) ) );
		}
		$r = BRC_Src_Queue::add(
			array(
				'text'      => self::post( 'brc_new_text' ),
				'url'       => self::post( 'brc_new_url' ),
				'image_url' => self::post( 'brc_new_image' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return array( self::msg( 'error', __( 'Δεν προστέθηκε:', 'broadcast' ) . ' ' . $r->get_error_message() ) );
		}
		return array( self::msg( 'success', __( 'Προστέθηκε στην ουρά.', 'broadcast' ) ) );
	}

	private static function do_save_modules(): void {
		$s = BRC_Settings::get();
		foreach ( BRC_Settings::MODULES as $m ) {
			$s['mods'][ $m ] = '1' === self::post( 'brc_mod_' . $m );
		}
		foreach ( BRC_Settings::APPROVABLE as $src ) {
			$s['approve'][ $src ] = '1' === self::post( 'brc_ap_' . $src );
		}
		$s['mode']      = sanitize_key( self::post( 'brc_mode' ) );
		$s['lim_hour']  = (int) self::post( 'brc_lim_hour' );
		$s['lim_day']   = (int) self::post( 'brc_lim_day' );
		$s['keep_days'] = (int) self::post( 'brc_keep_days' );
		BRC_Settings::save( $s );
		BRC_Jobs::ensure();
	}

	/** Posted array of ids or keys (callers sanitize). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); sanitized by BRC_Settings.
		$v = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		return is_array( $v ) ? $v : array();
	}

	private static function do_save_sources(): array {
		$s    = BRC_Settings::get();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'broadcast' ) ) );

		if ( Broadcast::woo() ) {
			$s['p_delay']       = (int) self::post( 'brc_p_delay' );
			$s['p_cats_in']     = self::post_array( 'brc_p_cats_in' );
			$s['p_cats_ex']     = self::post_array( 'brc_p_cats_ex' );
			$s['p_types']       = array_keys( self::post_array( 'brc_p_types' ) );
			$s['p_image']       = '1' === self::post( 'brc_p_image' );
			$s['p_skip_bf']     = '1' === self::post( 'brc_p_skip_bf' );
			$s['p_skip_hidden'] = '1' === self::post( 'brc_p_skip_hidden' );
			$s['dg_day']        = (int) self::post( 'brc_dg_day' );
			$s['dg_hour']       = (int) self::post( 'brc_dg_hour' );
			$s['dg_max']        = (int) self::post( 'brc_dg_max' );
			$s['dg_url']        = self::post( 'brc_dg_url' );
		}

		$s['nl_link']    = self::post( 'brc_nl_link' );
		$s['nl_preview'] = '1' === self::post( 'brc_nl_preview' );
		if ( '' !== trim( self::post( 'brc_nl_link' ) ) && '' === BRC_Settings::clean_url( self::post( 'brc_nl_link' ) ) ) {
			$msgs[] = self::msg( 'error', __( 'Ο σύνδεσμος του newsletter θέλει https://. Δεν κρατήθηκε.', 'broadcast' ) );
		}
		$secret = trim( self::post( 'brc_ml_secret' ) );
		if ( '' !== $secret ) {
			$msgs[] = BRC_Crypt::put( 'mailerlite', mb_substr( $secret, 0, 200 ) )
				? self::msg( 'success', __( 'Το secret του newsletter αποθηκεύτηκε σφραγισμένο.', 'broadcast' ) )
				: self::msg( 'error', __( 'Το secret δεν αποθηκεύτηκε: λείπει το OpenSSL (aes-256-gcm). Βάλ\' το ως σταθερά στο wp-config.php.', 'broadcast' ) );
		}

		$channels = array();
		$bad      = array();
		$lines    = preg_split( '/\r\n|\r|\n/', self::post( 'brc_yt_channels' ) );
		if ( count( array_filter( array_map( 'trim', $lines ) ) ) ) {
			Broadcast::load_source( 'youtube' );
		}
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$id = BRC_Src_Youtube::resolve( $line );
			if ( '' === $id ) {
				$bad[] = $line;
			} else {
				$channels[] = $id;
			}
		}
		$s['yt_channels'] = $channels;
		$s['yt_shorts']   = '1' === self::post( 'brc_yt_shorts' );
		$s['yt_live']     = '1' === self::post( 'brc_yt_live' );
		$s['yt_every']    = (int) self::post( 'brc_yt_every' );
		if ( $bad ) {
			/* translators: %s: list of channels */
			$msgs[] = self::msg( 'error', sprintf( __( 'Δεν βρέθηκε κανάλι YouTube για: %s', 'broadcast' ), implode( ', ', array_slice( $bad, 0, 5 ) ) ) );
		}
		if ( count( $channels ) > 5 ) {
			$msgs[] = self::msg( 'warning', __( 'Κρατήθηκαν τα πρώτα 5 κανάλια.', 'broadcast' ) );
		}

		BRC_Settings::save( $s );
		return $msgs;
	}

	private static function do_token(): array {
		$token = trim( self::post( 'brc_vb_token' ) );
		if ( '' === $token ) {
			return array();
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $token ) ) {
			return array( self::msg( 'error', __( 'Το token δεν μοιάζει με token του Viber (γράμματα, αριθμοί και -). Δεν κρατήθηκε.', 'broadcast' ) ) );
		}
		return array(
			BRC_Crypt::put( 'viber', $token )
				? self::msg( 'success', __( 'Το token αποθηκεύτηκε σφραγισμένο.', 'broadcast' ) )
				: self::msg( 'error', __( 'Το token δεν αποθηκεύτηκε: λείπει το OpenSSL (aes-256-gcm). Βάλ\' το ως σταθερά στο wp-config.php.', 'broadcast' ) ),
		);
	}

	private static function do_connect(): array {
		if ( ! BRC_Settings::module( 'viber' ) ) {
			return self::msg( 'error', __( 'Ενεργοποίησε πρώτα το module Viber Channel στα Modules.', 'broadcast' ) );
		}
		require_once BRC_PATH . 'includes/class-viber.php';
		$r = BRC_Viber::connect();
		if ( $r['ok'] ) {
			$acc = BRC_Viber::account();
			/* translators: 1: channel name, 2: number of subscribers */
			return self::msg( 'success', sprintf( __( '✓ Συνδέθηκε με το κανάλι «%1$s» (%2$d συνδρομητές).', 'broadcast' ), (string) $acc['channel'], (int) $acc['subs'] ) );
		}
		/* translators: %s: error from Viber */
		return self::msg( 'error', sprintf( __( '✗ Η σύνδεση απέτυχε: %s', 'broadcast' ), $r['error'] ) );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'brc_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/* =====================================================================
	 * Exports (privileged GET): settings backup, history CSV
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		if ( '' !== self::q( 'brc_export' ) ) {
			if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( Broadcast::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'broadcast' ) );
			}
			$state = array(
				'plugin'  => 'broadcast',
				'version' => BRC_VERSION,
				'options' => array(
					BRC_Settings::OPT => BRC_Settings::get(),
				),
			);
			nocache_headers();
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="broadcast-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			exit;
		}
		if ( '' !== self::q( 'brc_csv' ) ) {
			if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_CSV ) || ! current_user_can( Broadcast::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'broadcast' ) );
			}
			self::csv();
		}
	}

	/** Formula guard for text cells (Bible §5). */
	private static function cell( string $v ): string {
		return '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ? "'" . $v : $v;
	}

	private static function csv(): void {
		$fmt = 'std' === self::q( 'fmt' ) ? 'std' : 'excel';
		update_user_option( get_current_user_id(), 'brc_csv', $fmt );
		$sep  = 'excel' === $fmt ? ';' : ',';
		$f    = self::history_filters();
		$rows = BRC_Queue::list( $f, 5000 );
		$from = isset( $f['from_s'] ) ? $f['from_s'] : ( $rows ? wp_date( 'Y-m-d', end( $rows )['created'] ) : wp_date( 'Y-m-d' ) );
		$to   = isset( $f['to_s'] ) ? $f['to_s'] : wp_date( 'Y-m-d' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="broadcast-history-' . $from . '_' . $to . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output.
		fputcsv(
			$out,
			array(
				__( 'Πότε', 'broadcast' ),
				__( 'Πηγή', 'broadcast' ),
				__( 'Προορισμός', 'broadcast' ),
				__( 'Τίτλος', 'broadcast' ),
				__( 'Σύνδεσμος', 'broadcast' ),
				__( 'Κατάσταση', 'broadcast' ),
				__( 'Αιτία', 'broadcast' ),
				__( 'Προσπάθειες', 'broadcast' ),
				__( 'Απάντηση', 'broadcast' ),
			),
			$sep
		);
		$names    = self::module_names();
		$statuses = self::status_names();
		$reasons  = self::reason_names();
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					wp_date( 'Y-m-d H:i', $r['sent_at'] ? $r['sent_at'] : $r['created'] ),
					self::cell( isset( $names[ $r['source'] ] ) ? $names[ $r['source'] ] : $r['source'] ),
					self::cell( isset( $names[ $r['dest'] ] ) ? $names[ $r['dest'] ] : $r['dest'] ),
					self::cell( $r['title'] ),
					self::cell( $r['msg']['url'] ),
					self::cell( isset( $statuses[ $r['status'] ] ) ? $statuses[ $r['status'] ] : $r['status'] ),
					self::cell( isset( $reasons[ $r['reason'] ] ) ? $reasons[ $r['reason'] ] : $r['reason'] ),
					$r['tries'],
					self::cell( $r['result'] ),
				),
				$sep
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output.
		exit;
	}

	/** Strict import through BRC_Settings::sanitize(); an invalid file changes nothing. */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['brc_import_file']['error'] ) ? (int) $_FILES['brc_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['brc_import_file']['size'] ) ? (int) $_FILES['brc_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'broadcast' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['brc_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['brc_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'broadcast' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['brc_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'broadcast' ) ) );
		}
		$blob = isset( $state['options'][ BRC_Settings::OPT ] ) ? $state['options'][ BRC_Settings::OPT ] : null;
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Broadcast).', 'broadcast' ) ) );
		}
		// Modules come in off and the trial state stays: nothing starts posting
		// because a file was imported.
		$cur                = BRC_Settings::get();
		$blob['mods']       = $cur['mods'];
		$blob['trial_at']   = $cur['trial_at'];
		$blob['trial_done'] = $cur['trial_done'];
		BRC_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε. Τα modules έμειναν όπως ήταν.', 'broadcast' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'brc_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'brc_aui_msg_' . get_current_user_id() );
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
		<p class="brc-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'broadcast' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="brc-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="brc-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'broadcast' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="brc-footer-cta">
			<a class="brc-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'broadcast' ); ?>
			</a>
			<a class="brc-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'broadcast' ); ?>
			</a>
		</p>
		<?php
	}
}
