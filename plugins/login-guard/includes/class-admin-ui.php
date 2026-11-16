<?php
/**
 * LGD_Admin_UI — menu, tabs, forms (Bible §3–§8, §11).
 *
 * Submenu «Login Guard» of the shared "Noxpress" menu, slug lgd-settings,
 * capability manage_options. Tabs:
 *  - overview  mode and warnings, counts, active lockouts with «Unlock»,
 *              «trust my address»
 *  - settings  mode, limits, trusted addresses, XML-RPC, enumeration,
 *              e-mails, visitor texts, language, uninstall
 *  - log       the newest 200 security events, «clear»
 *  - tools     settings export / import (JSON), recovery doors,
 *              developers, privacy text
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient lgd_aui_msg_{uid}). Export: privileged GET
 * (slug + nonce + capability).
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'lgd-settings';
	const NONCE       = 'lgd_admin';
	const NONCE_FIELD = 'lgd_nonce';
	const NONCE_EXP   = 'lgd_export';
	const MAX_JSON    = 262144;

	/** Menu priority (Bible §8): taken at merge time from the next free one. */
	const MENU_PRIORITY = 430;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), self::MENU_PRIORITY );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LGD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'overview' => __( 'Επισκόπηση', 'login-guard' ),
			'settings' => __( 'Ρυθμίσεις', 'login-guard' ),
			'log'      => __( 'Ημερολόγιο', 'login-guard' ),
			'tools'    => __( 'Εργαλεία', 'login-guard' ),
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
			__( 'Login Guard', 'login-guard' ),
			__( 'Login Guard', 'login-guard' ),
			Login_Guard::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'login-guard' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'lgd-admin', LGD_URL . 'assets/admin.css', array(), LGD_VERSION );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Login_Guard::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'login-guard' ) );
		}
		$tab = self::current_tab();
		$s   = LGD_Settings::get();
		?>
		<div class="wrap lgd-wrap">
			<h1><?php esc_html_e( 'Login Guard', 'login-guard' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Όριο αποτυχημένων συνδέσεων ανά IP και ανά λογαριασμό, κλείσιμο των συνδέσεων μέσω XML-RPC και προστασία από απαρίθμηση χρηστών. Ό,τι γράφεται κρατά μόνο hash ή κομμένη IP, για 30 ημέρες.', 'login-guard' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Login_Guard::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το LGD_DISABLE είναι ενεργό στο wp-config.php: τίποτα δεν μετριέται και κανείς δεν κλειδώνεται. Σβήσε τη γραμμή όταν τελειώσεις.', 'login-guard' ) . '</p></div>';
			} elseif ( 'off' === $s['mode'] ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Το Login Guard είναι κλειστό: τίποτα δεν μετριέται ή κλειδώνεται. Ξεκίνα με «Δοκιμή» στις Ρυθμίσεις.', 'login-guard' ) . '</p></div>';
			} elseif ( 'test' === $s['mode'] ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Λειτουργία δοκιμής: οι αποτυχίες μετριούνται και το ημερολόγιο γράφει τι θα κλείδωνε, αλλά κανείς δεν κλειδώνεται. XML-RPC και απαρίθμηση αλλάζουν μόνο στο «Ενεργό».', 'login-guard' ) . '</p></div>';
			}
			if ( ! Noxpress_Safe::has_salts() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το wp-config.php δεν έχει δικά του κλειδιά (salts). Τα hashes και οι σύνδεσμοι ξεκλειδώματος είναι πιο αδύναμα. Πρόσθεσε κλειδιά από το api.wordpress.org/secret-key/1.1/salt/.', 'login-guard' ) . '</p></div>';
			}
			?>

			<nav class="nav-tab-wrapper lgd-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( self::url( (string) $slug ) ),
						(string) $slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'settings':
					self::render_settings();
					break;
				case 'log':
					self::render_log();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_overview();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="lgd-badge lgd-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	private static function checkbox( string $name, bool $on, string $label, string $value = '1' ): void {
		printf(
			'<label class="lgd-check"><input type="checkbox" name="%1$s" value="%4$s"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="lgd-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function number( string $name, int $value, int $min, int $max, string $label ): void {
		printf(
			'<label class="lgd-field"><span class="lgd-label">%1$s</span><input type="number" class="lgd-num" name="%2$s" value="%3$d" min="%4$d" max="%5$d" /></label>',
			esc_html( $label ),
			esc_attr( $name ),
			(int) $value,
			(int) $min,
			(int) $max
		);
	}

	/** Hidden fields of a POST form. */
	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="lgd_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function button_form( string $action, array $fields, string $label, string $class = 'button' ): void {
		echo '<form method="post" class="lgd-inline-form">';
		self::form_head( $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Who a log or lock row is about: a linked login, or the truncated IP. */
	private static function who( int $uid, string $ip ): string {
		if ( $uid > 0 ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				return '<a href="' . esc_url( get_edit_user_link( $uid ) ) . '">' . esc_html( $u->user_login ) . '</a>';
			}
		}
		return '' !== $ip ? '<code>' . esc_html( $ip ) . '</code>' : '<span class="lgd-muted">—</span>';
	}

	/* =====================================================================
	 * Overview
	 * =================================================================== */

	private static function render_overview(): void {
		$s     = LGD_Settings::get();
		$day   = LGD_Store::counts( time() - DAY_IN_SECONDS );
		$month = LGD_Store::counts( time() - 30 * DAY_IN_SECONDS );
		$locks = LGD_Store::locks();
		$ip    = LGD_Store::ip();

		$modes = array(
			'off'  => array( __( 'Κλειστό', 'login-guard' ), 'warn' ),
			'test' => array( __( 'Δοκιμή', 'login-guard' ), 'warn' ),
			'live' => array( __( 'Ενεργό', 'login-guard' ), 'ok' ),
		);
		$m     = $modes[ $s['mode'] ];
		$lk    = static function ( array $c ): int {
			return (int) $c['lock_ip'] + (int) $c['lock_long'] + (int) $c['lock_user'];
		};
		?>
		<h2 class="lgd-h2"><?php esc_html_e( 'Κατάσταση', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<p><?php echo self::badge( $m[0], Login_Guard::killed() ? 'danger' : $m[1] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></p>
			<div class="lgd-stats">
				<div class="lgd-stat"><span class="lgd-stat-n"><?php echo esc_html( number_format_i18n( LGD_Store::failures( 24 ) ) ); ?></span><span class="lgd-stat-l"><?php esc_html_e( 'αποτυχημένες συνδέσεις, 24 ώρες', 'login-guard' ); ?></span></div>
				<div class="lgd-stat"><span class="lgd-stat-n"><?php echo esc_html( number_format_i18n( LGD_Store::failures( 24 * 30 ) ) ); ?></span><span class="lgd-stat-l"><?php esc_html_e( 'αποτυχημένες συνδέσεις, 30 ημέρες', 'login-guard' ); ?></span></div>
				<div class="lgd-stat"><span class="lgd-stat-n"><?php echo esc_html( number_format_i18n( $lk( $day ) ) ); ?></span><span class="lgd-stat-l"><?php esc_html_e( 'κλειδώματα, 24 ώρες', 'login-guard' ); ?></span></div>
				<div class="lgd-stat"><span class="lgd-stat-n"><?php echo esc_html( number_format_i18n( $lk( $month ) ) ); ?></span><span class="lgd-stat-l"><?php esc_html_e( 'κλειδώματα, 30 ημέρες', 'login-guard' ); ?></span></div>
			</div>
			<?php if ( 'test' === $s['mode'] ) : ?>
				<p class="description">
					<?php
					/* translators: %d: number of would-be lockouts */
					echo esc_html( sprintf( __( 'Στη δοκιμή θα είχαν γίνει %d κλειδώματα τις τελευταίες 30 ημέρες.', 'login-guard' ), (int) $month['test_ip'] + (int) $month['test_user'] ) );
					?>
				</p>
			<?php endif; ?>
		</div>

		<h2 class="lgd-h2"><?php esc_html_e( 'Ενεργά κλειδώματα', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<?php if ( ! $locks ) : ?>
				<p class="lgd-muted"><?php esc_html_e( 'Κανένα κλείδωμα αυτή τη στιγμή.', 'login-guard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped lgd-table">
					<thead><tr><th><?php esc_html_e( 'Τι', 'login-guard' ); ?></th><th><?php esc_html_e( 'Ποιος', 'login-guard' ); ?></th><th><?php esc_html_e( 'Ως', 'login-guard' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $locks as $key => $l ) : ?>
						<tr>
							<td><?php echo esc_html( 'user' === $l['k'] ? __( 'Λογαριασμός', 'login-guard' ) : __( 'IP', 'login-guard' ) ); ?></td>
							<td><?php echo 'user' === $l['k'] && empty( $l['uid'] ) ? '<span class="lgd-muted">' . esc_html__( 'όνομα χωρίς λογαριασμό', 'login-guard' ) . '</span>' : self::who( (int) $l['uid'], (string) $l['ip'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in who(). ?></td>
							<td><?php echo esc_html( wp_date( self::fmt(), (int) $l['until'] ) ); ?></td>
							<td><?php self::button_form( 'unlock', array( 'lgd_key' => (string) $key ), __( 'Ξεκλείδωμα', 'login-guard' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><?php self::button_form( 'unlock_all', array(), __( 'Ξεκλείδωμα όλων', 'login-guard' ) ); ?></p>
			<?php endif; ?>
		</div>

		<?php self::render_twofa_overview(); ?>

		<h2 class="lgd-h2"><?php esc_html_e( 'Η διεύθυνσή σου', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<?php
			if ( '' === $ip ) {
				echo '<p>' . self::badge( __( 'Άγνωστη', 'login-guard' ), 'danger' ) . ' ' . esc_html__( 'Ο server δεν δίνει διεύθυνση IP: τα όρια ανά IP δεν δουλεύουν, μόνο τα όρια ανά λογαριασμό.', 'login-guard' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			} else {
				$trusted = LGD_Store::trusted( $ip );
				echo '<p><code>' . esc_html( $ip ) . '</code> ' . ( $trusted ? self::badge( __( 'Έμπιστη', 'login-guard' ), 'ok' ) : '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				if ( ! in_array( $ip, $s['allow'], true ) && Noxpress_Safe::public_ip( $ip ) ) {
					self::button_form( 'trust_me', array(), __( 'Πρόσθεσέ τη στις έμπιστες διευθύνσεις', 'login-guard' ) );
				}
				if ( ! Noxpress_Safe::public_ip( $ip ) ) {
					echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Ο server βλέπει ιδιωτική διεύθυνση για αυτό το αίτημα, άρα το site είναι πιθανώς πίσω από reverse proxy που δεν είναι η Cloudflare. Τότε όλοι οι επισκέπτες φαίνονται με την ίδια IP και ένα κλείδωμα IP κλειδώνει όλους. Ζήτησε από τον host να δίνει την πραγματική IP στο REMOTE_ADDR· μην τη βάλεις στις έμπιστες, γιατί θα ήταν έμπιστος κάθε επισκέπτης.', 'login-guard' ) . '</p></div>';
				}
			}
			?>
			<p class="description"><?php esc_html_e( 'Η διεύθυνση από την οποία αποθηκεύεις τις ρυθμίσεις δεν κλειδώνεται για 24 ώρες, ώστε να μην κλειδωθείς έξω μόνος σου.', 'login-guard' ); ?></p>
		</div>
		<?php
	}

	/** Overview: who has 2FA, who still needs it, Application Passwords. */
	private static function render_twofa_overview(): void {
		$sum  = LGD_TwoFA::summary();
		$apps = LGD_TwoFA::app_passwords();
		?>
		<h2 class="lgd-h2"><?php esc_html_e( 'Επαλήθευση σε δύο βήματα', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<?php if ( ! LGD_TwoFA::active() ) : ?>
				<p><?php echo self::badge( __( 'Ανενεργή', 'login-guard' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></p>
			<?php endif; ?>
			<div class="lgd-stats">
				<div class="lgd-stat"><span class="lgd-stat-n"><?php echo esc_html( number_format_i18n( (int) $sum['on'] ) ); ?></span><span class="lgd-stat-l"><?php esc_html_e( 'χρήστες με 2FA', 'login-guard' ); ?></span></div>
				<div class="lgd-stat"><span class="lgd-stat-n"><?php echo esc_html( number_format_i18n( (int) $sum['missing'] ) ); ?></span><span class="lgd-stat-l"><?php esc_html_e( 'τη χρειάζονται και δεν την έχουν', 'login-guard' ); ?></span></div>
			</div>
			<?php if ( $sum['list'] ) : ?>
				<p>
					<?php
					$links = array();
					foreach ( $sum['list'] as $u ) {
						$links[] = '<a href="' . esc_url( get_edit_user_link( (int) $u->ID ) ) . '">' . esc_html( $u->user_login ) . '</a>';
					}
					echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
					?>
				</p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Κάθε χρήστης τη ρυθμίζει στο προφίλ του, με μια εφαρμογή επαλήθευσης και 10 κωδικούς ανάκτησης.', 'login-guard' ); ?> <a href="<?php echo esc_url( admin_url( 'profile.php#lgd-2fa' ) ); ?>"><?php esc_html_e( 'Το προφίλ μου', 'login-guard' ); ?></a></p>
		</div>

		<h2 class="lgd-h2"><?php esc_html_e( 'Application Passwords', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<?php if ( ! $apps ) : ?>
				<p class="lgd-muted"><?php esc_html_e( 'Κανένας χρήστης δεν έχει Application Password.', 'login-guard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped lgd-table">
					<thead><tr><th><?php esc_html_e( 'Λογαριασμός', 'login-guard' ); ?></th><th><?php esc_html_e( 'Πλήθος', 'login-guard' ); ?></th><th><?php esc_html_e( 'Τελευταία χρήση', 'login-guard' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $apps as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_user_link( (int) $row[0]->ID ) . '#application-passwords-section' ); ?>"><?php echo esc_html( $row[0]->user_login ); ?></a></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row[1] ) ); ?></td>
							<td><?php echo $row[2] ? esc_html( wp_date( self::fmt(), (int) $row[2] ) ) : '<span class="lgd-muted">' . esc_html__( 'ποτέ', 'login-guard' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped here. ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Τα Application Passwords συνδέονται χωρίς δεύτερο βήμα, από τον σχεδιασμό του WordPress (τα χρησιμοποιούν orOS, Trade Feed Import και άλλα). Ανακαλείς όσα δεν χρειάζεσαι από το προφίλ του χρήστη.', 'login-guard' ); ?></p>
		</div>
		<?php
	}

	/* =====================================================================
	 * Settings
	 * =================================================================== */

	private static function render_settings(): void {
		$s = LGD_Settings::get();
		?>
		<form method="post">
			<?php self::form_head( 'save_settings' ); ?>

			<h2 class="lgd-h2"><?php esc_html_e( 'Λειτουργία', 'login-guard' ); ?></h2>
			<div class="lgd-zone">
				<?php
				self::radio( 'lgd_mode', 'off', $s['mode'], __( 'Κλειστό: τίποτα δεν μετριέται ή κλειδώνεται', 'login-guard' ) );
				self::radio( 'lgd_mode', 'test', $s['mode'], __( 'Δοκιμή: μετρά και γράφει τι θα κλείδωνε, χωρίς να κλειδώνει κανέναν', 'login-guard' ) );
				self::radio( 'lgd_mode', 'live', $s['mode'], __( 'Ενεργό: όρια, XML-RPC και απαρίθμηση ισχύουν για όλους', 'login-guard' ) );
				?>
			</div>

			<h2 class="lgd-h2"><?php esc_html_e( 'Όρια συνδέσεων', 'login-guard' ); ?></h2>
			<div class="lgd-zone">
				<h3 class="lgd-h3"><?php esc_html_e( 'Ανά IP', 'login-guard' ); ?></h3>
				<div class="lgd-grid">
					<?php
					self::number( 'lgd_ip_fails', (int) $s['ip_fails'], 2, 50, __( 'Αποτυχίες (2–50)', 'login-guard' ) );
					self::number( 'lgd_ip_window', (int) $s['ip_window'], 1, 1440, __( 'μέσα σε λεπτά (1–1440)', 'login-guard' ) );
					self::number( 'lgd_ip_lock', (int) $s['ip_lock'], 1, 1440, __( 'κλείδωμα σε λεπτά (1–1440)', 'login-guard' ) );
					self::number( 'lgd_esc_count', (int) $s['esc_count'], 2, 20, __( 'Μακρύ κλείδωμα μετά από κλειδώματα σε 24 ώρες (2–20)', 'login-guard' ) );
					self::number( 'lgd_esc_hours', (int) $s['esc_hours'], 1, 168, __( 'διάρκεια σε ώρες (1–168)', 'login-guard' ) );
					?>
				</div>
				<h3 class="lgd-h3"><?php esc_html_e( 'Ανά λογαριασμό, από όλες τις IP', 'login-guard' ); ?></h3>
				<div class="lgd-grid">
					<?php
					self::number( 'lgd_acct_fails', (int) $s['acct_fails'], 5, 500, __( 'Αποτυχίες (5–500)', 'login-guard' ) );
					self::number( 'lgd_acct_window', (int) $s['acct_window'], 5, 1440, __( 'μέσα σε λεπτά (5–1440)', 'login-guard' ) );
					self::number( 'lgd_acct_lock', (int) $s['acct_lock'], 1, 1440, __( 'κλείδωμα σε λεπτά (1–1440)', 'login-guard' ) );
					?>
				</div>
				<?php self::checkbox( 'lgd_unlock_mail', $s['unlock_mail'], __( 'Σύνδεσμος ξεκλειδώματος με email για κλειδωμένο λογαριασμό (δεν παρακάμπτει τον κωδικό)', 'login-guard' ) ); ?>
				<p class="description"><?php esc_html_e( 'Μετρούν οι φόρμες σύνδεσης του WordPress και του WooCommerce, το XML-RPC και τα Application Passwords. Μια κλειδωμένη IP ή ένας κλειδωμένος λογαριασμός παίρνει το ίδιο μήνυμα είτε ο κωδικός είναι σωστός είτε όχι.', 'login-guard' ); ?></p>
			</div>

			<h2 class="lgd-h2"><?php esc_html_e( 'Έμπιστες διευθύνσεις', 'login-guard' ); ?></h2>
			<div class="lgd-zone">
				<span class="lgd-label"><?php esc_html_e( 'Μία IP ή ένα εύρος CIDR ανά γραμμή (π.χ. 203.0.113.7 ή 203.0.113.0/24). Δεν μετριούνται και δεν κλειδώνουν ποτέ.', 'login-guard' ); ?></span>
				<textarea name="lgd_allow" class="lgd-area" rows="5"><?php echo esc_textarea( implode( "\n", $s['allow'] ) ); ?></textarea>
			</div>

			<h2 class="lgd-h2"><?php esc_html_e( 'XML-RPC', 'login-guard' ); ?></h2>
			<div class="lgd-zone">
				<?php
				self::radio( 'lgd_xmlrpc', 'auth_off', $s['xmlrpc'], __( 'Χωρίς συνδέσεις: καμία μέθοδος που θέλει κωδικό και κανένα system.multicall (προτείνεται)', 'login-guard' ) );
				self::radio( 'lgd_xmlrpc', 'closed', $s['xmlrpc'], __( 'Κλειστό εντελώς: το xmlrpc.php απαντά 403', 'login-guard' ) );
				self::radio( 'lgd_xmlrpc', 'open', $s['xmlrpc'], __( 'Όπως το WordPress', 'login-guard' ) );
				self::checkbox( 'lgd_pingbacks', $s['pingbacks'], __( 'Χωρίς pingbacks (και χωρίς το header X-Pingback)', 'login-guard' ) );
				if ( class_exists( 'Jetpack' ) ) {
					echo '<p class="description">' . esc_html__( 'Το Jetpack είναι ενεργό και χρειάζεται το XML-RPC με συνδέσεις: διάλεξε «Όπως το WordPress».', 'login-guard' ) . '</p>';
				}
				?>
			</div>

			<h2 class="lgd-h2"><?php esc_html_e( 'Απαρίθμηση χρηστών', 'login-guard' ); ?></h2>
			<div class="lgd-zone">
				<?php
				self::checkbox( 'lgd_enum_errors', $s['enum_errors'], __( 'Ουδέτερα μηνύματα: ίδιο μήνυμα για λάθος όνομα και λάθος κωδικό, ίδια σελίδα στο «Ξέχασα τον κωδικό» είτε υπάρχει λογαριασμός είτε όχι', 'login-guard' ) );
				self::checkbox( 'lgd_lostpw', $s['lostpw'], __( 'Όριο στο «Ξέχασα τον κωδικό»: 5 αιτήματα την ώρα ανά IP και 3 ανά λογαριασμό', 'login-guard' ) );
				self::checkbox( 'lgd_enum_rest', $s['enum_rest'], __( 'Το /wp-json/wp/v2/users μόνο για συνδεδεμένους χρήστες', 'login-guard' ) );
				self::checkbox( 'lgd_enum_author', $s['enum_author'], __( 'Το /?author=1 απαντά 404 στους επισκέπτες', 'login-guard' ) );
				self::checkbox( 'lgd_enum_sitemap', $s['enum_sitemap'], __( 'Χωρίς χρήστες στο wp-sitemap.xml', 'login-guard' ) );
				self::checkbox( 'lgd_enum_oembed', $s['enum_oembed'], __( 'Χωρίς όνομα συγγραφέα στις απαντήσεις oEmbed', 'login-guard' ) );
				self::checkbox( 'lgd_enum_archives', $s['enum_archives'], __( 'Οι σελίδες /author/όνομα/ απαντούν 404 στους επισκέπτες (όχι σε blog με συγγραφείς)', 'login-guard' ) );
				?>
			</div>

			<h2 class="lgd-h2"><?php esc_html_e( 'Επαλήθευση σε δύο βήματα', 'login-guard' ); ?></h2>
			<div class="lgd-zone">
				<?php
				if ( ! LGD_TwoFA::possible() ) {
					echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Η επαλήθευση σε δύο βήματα χρειάζεται δικά σου κλειδιά (salts) στο wp-config.php και OpenSSL, για να κρατιούνται τα μυστικά κρυπτογραφημένα.', 'login-guard' ) . '</p></div>';
				}
				self::checkbox( 'lgd_twofa', $s['twofa'], __( 'Επαλήθευση σε δύο βήματα με εφαρμογή (TOTP) για όσους τη ρυθμίσουν στο προφίλ τους', 'login-guard' ) );
				?>
				<p class="lgd-label"><?php esc_html_e( 'Υποχρεωτική για τους ρόλους (μόνο στο «Ενεργό»)', 'login-guard' ); ?></p>
				<?php
				foreach ( wp_roles()->get_names() as $role => $name ) {
					self::checkbox( 'lgd_tfa_roles[]', in_array( $role, (array) $s['tfa_roles'], true ), translate_user_role( $name ), $role );
				}
				?>
				<p class="description"><?php esc_html_e( 'Ένας ρόλος που έχεις κι εσύ γίνεται υποχρεωτικός μόνο αφού ρυθμίσεις τη δική σου επαλήθευση, ώστε να μην κλειδωθείς έξω. Οι πελάτες δεν τη χρειάζονται.', 'login-guard' ); ?></p>
				<div class="lgd-grid">
					<?php
					self::number( 'lgd_tfa_grace_days', (int) $s['tfa_grace_days'], 0, 30, __( 'Περίοδος χάριτος σε ημέρες (0–30)', 'login-guard' ) );
					self::number( 'lgd_tfa_grace_logins', (int) $s['tfa_grace_logins'], 0, 20, __( 'ή σε συνδέσεις (0–20)', 'login-guard' ) );
					self::number( 'lgd_tfa_trust_days', (int) $s['tfa_trust_days'], 0, 90, __( '«Να μη ζητηθεί ξανά σε αυτόν τον browser» για ημέρες (0 = ποτέ, 0–90)', 'login-guard' ) );
					?>
				</div>
				<?php self::checkbox( 'lgd_tfa_strict', $s['tfa_strict'], __( 'Αυστηρός φύλακας: κλείνει κάθε σύνδεση χρήστη με 2FA που δεν πέρασε από το δεύτερο βήμα (π.χ. «σύνδεση ως» άλλων plugins). Εκτός WP-CLI και cron.', 'login-guard' ) ); ?>
			</div>

			<h2 class="lgd-h2"><?php esc_html_e( 'Ειδοποιήσεις', 'login-guard' ); ?></h2>
			<div class="lgd-zone">
				<?php
				self::checkbox( 'lgd_notify_device', $s['notify_device'], __( 'Email στον διαχειριστή όταν συνδέεται από συσκευή που δεν είχε χρησιμοποιήσει 30 ημέρες', 'login-guard' ) );
				self::checkbox( 'lgd_notify_surge', $s['notify_surge'], __( 'Email στη διεύθυνση του site σε κύμα επιθέσεων, το πολύ ένα την ώρα', 'login-guard' ) );
				?>
				<div class="lgd-grid">
					<?php self::number( 'lgd_surge_at', (int) $s['surge_at'], 3, 500, __( 'Κλειδώματα σε μία ώρα για το email (3–500)', 'login-guard' ) ); ?>
				</div>
			</div>

			<h2 class="lgd-h2"><?php esc_html_e( 'Κείμενα για τους επισκέπτες', 'login-guard' ); ?></h2>
			<div class="lgd-zone">
				<?php
				self::radio( 'lgd_lang', 'auto', $s['lang'], __( 'Γλώσσα: αυτόματα, από τη γλώσσα του site', 'login-guard' ) );
				self::radio( 'lgd_lang', 'el', $s['lang'], 'Ελληνικά' );
				self::radio( 'lgd_lang', 'en', $s['lang'], 'English' );
				?>
				<p class="description"><?php esc_html_e( 'Τα {minutes}, {days}, {site} και {link} συμπληρώνονται μόνα τους. Κενό αγγλικό κείμενο δείχνει το ελληνικό.', 'login-guard' ); ?></p>
				<table class="widefat lgd-table lgd-texts">
					<thead><tr><th><?php esc_html_e( 'Κείμενο', 'login-guard' ); ?></th><th>Ελληνικά</th><th>English</th></tr></thead>
					<tbody>
					<?php foreach ( LGD_Settings::texts() as $k => $t ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $k ); ?></code></td>
							<?php foreach ( array( 'el', 'en' ) as $l ) : ?>
								<td>
								<?php
								$name = 't_' . $k . '_' . $l;
								if ( $t[2] > 120 ) {
									echo '<textarea class="lgd-area" rows="3" name="lgd_' . esc_attr( $name ) . '" maxlength="' . (int) $t[2] . '">' . esc_textarea( (string) $s[ $name ] ) . '</textarea>';
								} else {
									echo '<input type="text" class="lgd-text" name="lgd_' . esc_attr( $name ) . '" value="' . esc_attr( (string) $s[ $name ] ) . '" maxlength="' . (int) $t[2] . '" />';
								}
								?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( ! LGD_Lang::rs_active() ) : ?>
				<h2 class="lgd-h2"><?php esc_html_e( 'Γλώσσα', 'login-guard' ); ?></h2>
				<div class="lgd-zone">
					<?php
					$cur = (string) get_user_meta( get_current_user_id(), 'rs_lang', true );
					$cur = in_array( $cur, array( 'el', 'en' ), true ) ? $cur : '';
					self::radio( 'lgd_admin_lang', '', $cur, __( 'Όπως η γλώσσα του λογαριασμού', 'login-guard' ) );
					self::radio( 'lgd_admin_lang', 'el', $cur, 'Ελληνικά' );
					self::radio( 'lgd_admin_lang', 'en', $cur, 'English' );
					?>
					<p class="description"><?php esc_html_e( 'Ισχύει για όλα τα plugins της σουίτας Noxpress.', 'login-guard' ); ?></p>
				</div>
			<?php endif; ?>

			<h2 class="lgd-h2"><?php esc_html_e( 'Απεγκατάσταση', 'login-guard' ); ?></h2>
			<div class="lgd-zone">
				<?php self::checkbox( 'lgd_keep', $s['keep'], __( 'Να μείνουν οι ρυθμίσεις και το ημερολόγιο όταν σβήσω το plugin', 'login-guard' ) ); ?>
			</div>

			<p class="lgd-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'login-guard' ); ?></button></p>
		</form>
		<?php
	}

	/* =====================================================================
	 * Log
	 * =================================================================== */

	private static function render_log(): void {
		$rows   = LGD_Store::rows( 200 );
		$labels = LGD_Store::events();
		?>
		<h2 class="lgd-h2"><?php esc_html_e( 'Γεγονότα ασφαλείας', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<p class="description"><?php esc_html_e( 'Τα 200 νεότερα: κλειδώματα, ξεκλειδώματα, όρια, email. Κρατιούνται 30 ημέρες, με κομμένη IP. Οι κανονικές συνδέσεις δεν γράφονται εδώ.', 'login-guard' ); ?></p>
			<?php if ( ! $rows ) : ?>
				<p class="lgd-muted"><?php esc_html_e( 'Κανένα γεγονός ακόμα.', 'login-guard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped lgd-table">
					<thead><tr><th><?php esc_html_e( 'Ώρα', 'login-guard' ); ?></th><th><?php esc_html_e( 'Γεγονός', 'login-guard' ); ?></th><th><?php esc_html_e( 'IP', 'login-guard' ); ?></th><th><?php esc_html_e( 'Λογαριασμός', 'login-guard' ); ?></th><th><?php esc_html_e( 'Λεπτομέρειες', 'login-guard' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( self::fmt(), $r['t'] ) ); ?></td>
							<td><?php echo esc_html( isset( $labels[ $r['ev'] ] ) ? $labels[ $r['ev'] ] : $r['ev'] ); ?></td>
							<td><?php echo '' !== $r['ip'] ? '<code>' . esc_html( $r['ip'] ) . '</code>' : '<span class="lgd-muted">—</span>'; ?></td>
							<td><?php echo $r['uid'] ? self::who( $r['uid'], '' ) : '<span class="lgd-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in who(). ?></td>
							<td><?php echo esc_html( $r['info'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><?php self::button_form( 'clear_log', array(), __( 'Καθαρισμός ημερολογίου', 'login-guard' ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Tools
	 * =================================================================== */

	private static function render_tools(): void {
		?>
		<h2 class="lgd-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'tools', array( 'lgd_export' => 'json' ) ), self::NONCE_EXP ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'login-guard' ); ?></a></p>
			<form method="post" enctype="multipart/form-data">
				<?php self::form_head( 'import_json' ); ?>
				<input type="file" name="lgd_json" accept=".json,application/json" />
				<button type="submit" class="button"><?php esc_html_e( 'Επαναφορά', 'login-guard' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'Η επαναφορά δεν αλλάζει τη λειτουργία: μένει όπως είναι τώρα, ώστε ένα backup να μην κλειδώσει κανέναν χωρίς δοκιμή.', 'login-guard' ); ?></p>
		</div>

		<h2 class="lgd-h2"><?php esc_html_e( 'Αν κλειδωθείς έξω', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<table class="widefat striped lgd-table">
				<tbody>
					<tr><td><?php esc_html_e( 'Περίμενε', 'login-guard' ); ?></td><td><?php esc_html_e( 'Κάθε κλείδωμα λήγει μόνο του (προεπιλογή 15 λεπτά).', 'login-guard' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Σύνδεσμος με email', 'login-guard' ); ?></td><td><?php esc_html_e( 'Για κλειδωμένο λογαριασμό, από τον σύνδεσμο στο μήνυμα της φόρμας σύνδεσης.', 'login-guard' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Άλλος διαχειριστής', 'login-guard' ); ?></td><td><?php esc_html_e( 'Ξεκλειδώνει από την Επισκόπηση.', 'login-guard' ); ?></td></tr>
					<tr><td><code>define( 'LGD_DISABLE', true );</code></td><td><?php esc_html_e( 'Στο wp-config.php: το Login Guard σταματά εντελώς.', 'login-guard' ); ?></td></tr>
					<tr><td><code>wp login-guard unlock --all</code></td><td><?php esc_html_e( 'WP-CLI: ξεκλειδώνει τα πάντα. Επίσης unlock --ip=…, unlock --user=…, mode off, status.', 'login-guard' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Κωδικός ανάκτησης', 'login-guard' ); ?></td><td><?php esc_html_e( 'Χωρίς το κινητό: ένας από τους 10 κωδικούς ανάκτησης, στο ίδιο πεδίο με τον κωδικό της εφαρμογής.', 'login-guard' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Άλλος διαχειριστής με 2FA', 'login-guard' ); ?></td><td><?php esc_html_e( 'Μηδενίζει την επαλήθευση από το προφίλ του χρήστη.', 'login-guard' ); ?></td></tr>
					<tr><td><code>define( 'LGD_DISABLE_2FA', true );</code></td><td><?php esc_html_e( 'Στο wp-config.php: σταματά μόνο η επαλήθευση σε δύο βήματα· τα όρια συνεχίζουν.', 'login-guard' ); ?></td></tr>
					<tr><td><code>wp login-guard 2fa reset &lt;user&gt;</code></td><td><?php esc_html_e( 'WP-CLI: μηδενίζει την επαλήθευση ενός χρήστη.', 'login-guard' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'FTP ή File Manager', 'login-guard' ); ?></td><td><?php esc_html_e( 'Μετονόμασε τον φάκελο wp-content/plugins/login-guard: το plugin απενεργοποιείται.', 'login-guard' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<h2 class="lgd-h2"><?php esc_html_e( 'Για developers', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<table class="widefat striped lgd-table">
				<tbody>
					<tr><td><code>lgd_locked</code> <?php esc_html_e( '(action)', 'login-guard' ); ?></td><td><?php esc_html_e( 'Ένα κλείδωμα ξεκίνησε: ( $kind \'ip\' | \'user\', $ident_hash, $until ). Ποτέ IP ή όνομα.', 'login-guard' ); ?></td></tr>
					<tr><td><code>lgd_unlocked</code> <?php esc_html_e( '(action)', 'login-guard' ); ?></td><td><?php esc_html_e( 'Ένα κλείδωμα τελείωσε νωρίς: ( $kind, $ident_hash, $by \'admin\' | \'mail\' | \'cli\' ).', 'login-guard' ); ?></td></tr>
					<tr><td><code>lgd_2fa_passed</code>, <code>lgd_2fa_failed</code>, <code>lgd_session_blocked</code> <?php esc_html_e( '(actions)', 'login-guard' ); ?></td><td><?php esc_html_e( '( int $user_id ): το δεύτερο βήμα πέρασε, ένας λάθος κωδικός, ο φύλακας έκλεισε ένα session.', 'login-guard' ); ?></td></tr>
					<tr><td><code>lgd_requires_2fa</code> <?php esc_html_e( '(φίλτρο)', 'login-guard' ); ?></td><td><?php esc_html_e( '( bool $required, WP_User $user ): κάνει υποχρεωτική την επαλήθευση για έναν χρήστη. Μόνο προσθέτει, ποτέ δεν αφαιρεί.', 'login-guard' ); ?></td></tr>
					<tr><td><code>lgd_trusted_ip</code> <?php esc_html_e( '(φίλτρο)', 'login-guard' ); ?></td><td><?php esc_html_e( '( bool $ok, string $ip ): μια διεύθυνση που δεν μετριέται ποτέ.', 'login-guard' ); ?></td></tr>
					<tr><td><code>lgd_text</code> <?php esc_html_e( '(φίλτρο)', 'login-guard' ); ?></td><td><?php esc_html_e( '( $text, $key, $lang ): τα κείμενα των επισκεπτών, π.χ. για WPML.', 'login-guard' ); ?></td></tr>
					<tr><td><code>login-guard/status</code></td><td><?php esc_html_e( 'Ability (WordPress 6.9+) για το Site Bridge, μόνο ανάγνωση, με το ίδιο δικαίωμα (manage_options). Ποτέ IP, όνομα ή email.', 'login-guard' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<h2 class="lgd-h2"><?php esc_html_e( 'Πολιτική απορρήτου', 'login-guard' ); ?></h2>
		<div class="lgd-zone">
			<p class="description"><?php esc_html_e( 'Ένα προτεινόμενο κείμενο είναι στις Ρυθμίσεις → Απόρρητο → Οδηγός πολιτικής, στην ενότητα «Login Guard».', 'login-guard' ); ?></p>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['lgd_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Login_Guard::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'login-guard' ) );
		}
		LGD_Store::install();

		switch ( sanitize_key( wp_unslash( $_POST['lgd_action'] ) ) ) {
			case 'save_settings':
				self::do_save_settings();
				break;
			case 'unlock':
				$key = self::post( 'lgd_key' );
				$ok  = preg_match( '/^[iu][a-f0-9]{32}$/', $key ) && LGD_Store::unlock( $key, 'admin' );
				self::redirect( 'overview', array( $ok ? self::msg( 'success', __( 'Ξεκλειδώθηκε.', 'login-guard' ) ) : self::msg( 'warning', __( 'Το κλείδωμα είχε ήδη λήξει.', 'login-guard' ) ) ) );
				break;
			case 'unlock_all':
				$n = LGD_Store::unlock_all( 'admin' );
				/* translators: %d: number of lockouts */
				self::redirect( 'overview', array( self::msg( 'success', sprintf( _n( 'Τελείωσε %d κλείδωμα.', 'Τελείωσαν %d κλειδώματα.', $n, 'login-guard' ), $n ) ) ) );
				break;
			case 'trust_me':
				$ip = LGD_Store::ip();
				if ( '' !== $ip && Noxpress_Safe::public_ip( $ip ) ) {
					$s          = LGD_Settings::get();
					$s['allow'] = array_merge( $s['allow'], array( $ip ) );
					LGD_Settings::save( $s );
					LGD_Store::unlock( LGD_Store::key_ip( $ip ), 'admin' );
				}
				self::redirect( 'overview', array( self::msg( 'success', __( 'Η διεύθυνσή σου είναι τώρα έμπιστη.', 'login-guard' ) ) ) );
				break;
			case 'clear_log':
				LGD_Store::clear_log();
				self::redirect( 'log', array( self::msg( 'success', __( 'Το ημερολόγιο καθάρισε.', 'login-guard' ) ) ) );
				break;
			case 'import_json':
				self::redirect( 'tools', self::do_import_json() );
				break;
			default:
				self::redirect( 'overview', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'login-guard' ) ) ) );
		}
	}

	/**
	 * Multisite: a super admin's 2FA must not depend on one subsite's
	 * administrator, so only a super admin changes the mode and the 2FA
	 * switches there.
	 */
	private static function network_policy( array $s ): array {
		if ( ! is_multisite() || is_super_admin() ) {
			return $s;
		}
		$cur = LGD_Settings::get();
		foreach ( array( 'mode', 'twofa', 'tfa_strict' ) as $k ) {
			$s[ $k ] = $cur[ $k ];
		}
		return $s;
	}

	private static function do_save_settings(): void {
		$s    = LGD_Settings::get();
		$prev = $s['mode'];
		foreach ( array( 'mode', 'xmlrpc', 'lang' ) as $k ) {
			$s[ $k ] = sanitize_key( self::post( 'lgd_' . $k ) );
		}
		foreach ( array( 'unlock_mail', 'pingbacks', 'enum_rest', 'enum_author', 'enum_sitemap', 'enum_oembed', 'enum_errors', 'enum_archives', 'lostpw', 'notify_device', 'notify_surge', 'keep', 'twofa', 'tfa_strict' ) as $k ) {
			$s[ $k ] = '1' === self::post( 'lgd_' . $k );
		}
		foreach ( array( 'ip_fails', 'ip_window', 'ip_lock', 'esc_count', 'esc_hours', 'acct_fails', 'acct_window', 'acct_lock', 'surge_at', 'tfa_grace_days', 'tfa_grace_logins', 'tfa_trust_days' ) as $k ) {
			$s[ $k ] = (int) self::post( 'lgd_' . $k );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in route().
		$roles   = isset( $_POST['lgd_tfa_roles'] ) && is_array( $_POST['lgd_tfa_roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['lgd_tfa_roles'] ) ) : array();
		$roles   = array_values( array_intersect( $roles, array_keys( wp_roles()->get_names() ) ) );
		$me      = wp_get_current_user();
		$dropped = array();
		if ( ! LGD_TwoFA::is_on( (int) $me->ID ) ) {
			// Anti self-lockout: a role of the saver becomes required only after the saver has 2FA.
			$dropped = array_values( array_intersect( $roles, (array) $me->roles ) );
			$roles   = array_values( array_diff( $roles, $dropped ) );
		}
		$s['tfa_roles'] = $roles;
		$s['allow'] = sanitize_textarea_field( self::post( 'lgd_allow' ) );
		foreach ( array_keys( LGD_Settings::texts() ) as $k ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$s[ 't_' . $k . '_' . $l ] = self::post( 'lgd_t_' . $k . '_' . $l );
			}
		}
		$saved = LGD_Settings::save( self::network_policy( $s ) );

		// Anti self-lockout: the address that saves stays trusted for 24 hours.
		LGD_Store::remember_saver( LGD_Store::ip() );

		if ( ! LGD_Lang::rs_active() ) {
			$lang = self::post( 'lgd_admin_lang' );
			if ( in_array( $lang, array( 'el', 'en' ), true ) ) {
				update_user_meta( get_current_user_id(), 'rs_lang', $lang );
			} else {
				delete_user_meta( get_current_user_id(), 'rs_lang' );
			}
			LGD_Lang::reset();
		}

		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'login-guard' ) ) );
		$bad  = count( array_filter( preg_split( '/[\s,]+/', self::post( 'lgd_allow' ) ) ) ) - count( $saved['allow'] );
		if ( $bad > 0 ) {
			/* translators: %d: number of entries */
			$msgs[] = self::msg( 'warning', sprintf( _n( '%d γραμμή στις έμπιστες διευθύνσεις δεν είναι έγκυρη IP ή εύρος (ή είναι ευρύτερο από /8) και αγνοήθηκε.', '%d γραμμές στις έμπιστες διευθύνσεις δεν είναι έγκυρες IP ή εύρη (ή είναι ευρύτερα από /8) και αγνοήθηκαν.', $bad, 'login-guard' ), $bad ) );
		}
		if ( $dropped ) {
			$msgs[] = self::msg( 'warning', __( 'Ο ρόλος σου δεν έγινε υποχρεωτικός για επαλήθευση σε δύο βήματα: ρύθμισε πρώτα τη δική σου στο προφίλ και αποθήκευσε ξανά.', 'login-guard' ) );
		}
		if ( 'live' === $saved['mode'] && 'live' !== $prev ) {
			$msgs[] = self::msg( 'info', __( 'Ενεργό. Δοκίμασε μια σύνδεση από ιδιωτικό παράθυρο. Η δική σου διεύθυνση δεν κλειδώνεται για 24 ώρες.', 'login-guard' ) );
		}
		self::redirect( 'settings', $msgs );
	}

	/* =====================================================================
	 * Export (GET) and JSON restore
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || 'json' !== self::q( 'lgd_export' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nonce.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_EXP ) || ! current_user_can( Login_Guard::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'login-guard' ) );
		}
		$out = array(
			'plugin'  => 'login-guard',
			'version' => LGD_VERSION,
			'options' => array( LGD_Settings::OPT => LGD_Settings::get() ),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="login-guard-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private static function do_import_json(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['lgd_json']['error'] ) ? (int) $_FILES['lgd_json']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['lgd_json']['size'] ) ? (int) $_FILES['lgd_json']['size'] : 0;
		$tmp  = isset( $_FILES['lgd_json']['tmp_name'] ) ? (string) $_FILES['lgd_json']['tmp_name'] : '';
		// phpcs:enable
		if ( $size > self::MAX_JSON ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'login-guard' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο.', 'login-guard' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $tmp ), true );
		if ( ! is_array( $state ) || ! isset( $state['plugin'] ) || 'login-guard' !== $state['plugin'] || ! isset( $state['options'][ LGD_Settings::OPT ] ) || ! is_array( $state['options'][ LGD_Settings::OPT ] ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Login Guard).', 'login-guard' ) ) );
		}
		$in         = $state['options'][ LGD_Settings::OPT ];
		$in['mode'] = LGD_Settings::mode(); // A backup never switches the mode.
		LGD_Settings::save( self::network_policy( $in ) );
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις επανήλθαν. Η λειτουργία έμεινε όπως ήταν.', 'login-guard' ) ) );
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		if ( $msgs ) {
			set_transient( 'lgd_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'lgd_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'lgd_aui_msg_' . get_current_user_id() );
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
		<p class="lgd-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'login-guard' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="lgd-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="lgd-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'login-guard' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="lgd-footer-cta">
			<a class="lgd-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'login-guard' ); ?>
			</a>
			<a class="lgd-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'login-guard' ); ?>
			</a>
		</p>
		<?php
	}
}
