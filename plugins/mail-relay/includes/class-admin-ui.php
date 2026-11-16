<?php
/**
 * MRL_Admin_UI — menu, tabs, forms (Bible §3–§8, §11).
 *
 * Submenu «Mail Relay» of the shared "Noxpress" menu (admin_menu priority
 * 420), slug mrl-settings, capability manage_options. Tabs:
 *  - log         the sent log: filters, a mail's content when it was kept,
 *                resend, CSV, empty the log
 *  - connection  mode (on / off), sender, primary and backup connections
 *                with their secrets
 *  - tools       test email, staging guard, SPF / DKIM / DMARC, import from
 *                FluentSMTP, settings backup
 *  - settings    log content and retention, alerts, uninstall, developers
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient mrl_aui_msg_{uid}). Exports: privileged GET.
 * Secret fields are always empty: «empty = keep the current one».
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'mrl-settings';
	const NONCE       = 'mrl_admin';
	const NONCE_FIELD = 'mrl_nonce';
	const NONCE_EXP   = 'mrl_export';
	const MAX_JSON    = 1048576;
	const TEST_LIMIT  = 5;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 420 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MRL_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'log'        => __( 'Αρχείο αποστολών', 'mail-relay' ),
			'connection' => __( 'Σύνδεση', 'mail-relay' ),
			'tools'      => __( 'Εργαλεία', 'mail-relay' ),
			'settings'   => __( 'Ρυθμίσεις', 'mail-relay' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Mail Relay', 'mail-relay' ),
			__( 'Mail Relay', 'mail-relay' ),
			Mail_Relay::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'mail-relay' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'log';
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'mrl-admin', MRL_URL . 'assets/admin.css', array(), MRL_VERSION );
		wp_enqueue_script( 'mrl-admin', MRL_URL . 'assets/admin.js', array(), MRL_VERSION, true );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Mail_Relay::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'mail-relay' ) );
		}
		$tab = self::current_tab();
		$s   = MRL_Settings::get();
		?>
		<div class="wrap mrl-wrap">
			<h1><?php esc_html_e( 'Mail Relay', 'mail-relay' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Όλα τα email του site μέσω του λογαριασμού που ορίζεις (SMTP ή API παρόχου), με εφεδρική σύνδεση και αρχείο αποστολών. Οι κωδικοί δεν εμφανίζονται ποτέ και δεν μπαίνουν σε αρχεία ή αντίγραφα ρυθμίσεων.', 'mail-relay' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Mail_Relay::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το MRL_DISABLE είναι ενεργό στο wp-config.php: το WordPress στέλνει όπως χωρίς το plugin και τίποτα δεν γράφεται στο αρχείο.', 'mail-relay' ) . '</p></div>';
			}
			if ( 'on' !== $s['mode'] ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Η αποστολή μέσω Mail Relay είναι κλειστή: το WordPress στέλνει όπως χωρίς το plugin. Ρύθμισε τη σύνδεση, στείλε δοκιμαστικό και ενεργοποίησέ την στην καρτέλα «Σύνδεση».', 'mail-relay' ) . '</p></div>';
			}
			?>

			<nav class="nav-tab-wrapper mrl-tabs">
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
				case 'connection':
					self::render_connection();
					break;
				case 'tools':
					self::render_tools();
					break;
				case 'settings':
					self::render_settings();
					break;
				default:
					self::render_log();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="mrl-badge mrl-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="mrl-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="mrl_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function button_form( string $action, array $fields, string $label, string $class = 'button', string $confirm = '' ): void {
		echo '<form method="post" class="mrl-inline-form"' . ( '' !== $confirm ? ' data-mrl-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		self::form_head( $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function status_label( string $st ): string {
		$map = array(
			'sent'    => array( __( 'Στάλθηκε', 'mail-relay' ), 'ok' ),
			'failed'  => array( __( 'Απέτυχε', 'mail-relay' ), 'danger' ),
			'held'    => array( __( 'Σε παύση', 'mail-relay' ), 'warn' ),
			'sending' => array( __( 'Σε εξέλιξη', 'mail-relay' ), 'muted' ),
		);
		$x   = isset( $map[ $st ] ) ? $map[ $st ] : array( $st, 'muted' );
		return self::badge( $x[0], $x[1] );
	}

	private static function conn_label( string $slot ): string {
		$map = array(
			'primary' => __( 'κύρια', 'mail-relay' ),
			'backup'  => __( 'εφεδρική', 'mail-relay' ),
			'php'     => __( 'mail() του server', 'mail-relay' ),
		);
		return isset( $map[ $slot ] ) ? $map[ $slot ] : '';
	}

	/* ---------------------------------------------------------------------
	 * Log tab
	 * ------------------------------------------------------------------- */

	private static function render_log(): void {
		MRL_Alerts::seen();
		$status = sanitize_key( self::q( 'mrl_status' ) );
		$search = self::q( 'mrl_s' );
		$paged  = max( 1, (int) self::q( 'paged' ) );
		$per    = 50;
		$view   = (int) self::q( 'mrl_view' );
		$c24    = MRL_Log::counts( time() - DAY_IN_SECONDS );
		$s      = MRL_Settings::get();

		echo '<p class="mrl-counts">';
		/* translators: 1: sent, 2: failed, 3: held, 4: saved by the backup */
		echo esc_html( sprintf( __( 'Τελευταίες 24 ώρες: %1$d στάλθηκαν, %2$d απέτυχαν, %3$d σε παύση, %4$d από την εφεδρική σύνδεση.', 'mail-relay' ), $c24['sent'], $c24['failed'], $c24['held'], $c24['backup'] ) );
		echo '</p>';

		if ( $view > 0 ) {
			self::render_view( $view );
		}
		?>
		<form method="get" class="mrl-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="log" />
			<select name="mrl_status">
				<option value=""><?php esc_html_e( 'Όλα', 'mail-relay' ); ?></option>
				<option value="sent" <?php selected( $status, 'sent' ); ?>><?php esc_html_e( 'Στάλθηκε', 'mail-relay' ); ?></option>
				<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Απέτυχε', 'mail-relay' ); ?></option>
				<option value="held" <?php selected( $status, 'held' ); ?>><?php esc_html_e( 'Σε παύση', 'mail-relay' ); ?></option>
			</select>
			<input type="search" name="mrl_s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Παραλήπτης, θέμα ή πηγή', 'mail-relay' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Φιλτράρισμα', 'mail-relay' ); ?></button>
		</form>
		<?php
		$q = MRL_Log::query(
			array(
				'status' => $status,
				'search' => $search,
				'limit'  => $per,
				'offset' => ( $paged - 1 ) * $per,
			)
		);
		if ( ! $q['rows'] ) {
			echo '<p class="mrl-empty">' . esc_html__( 'Κανένα email στο αρχείο.', 'mail-relay' ) . '</p>';
		} else {
			?>
			<div class="mrl-table-wrap">
			<table class="widefat striped mrl-table">
				<thead><tr>
					<th><?php esc_html_e( 'Ημερομηνία', 'mail-relay' ); ?></th>
					<th><?php esc_html_e( 'Κατάσταση', 'mail-relay' ); ?></th>
					<th><?php esc_html_e( 'Παραλήπτες', 'mail-relay' ); ?></th>
					<th><?php esc_html_e( 'Θέμα', 'mail-relay' ); ?></th>
					<th><?php esc_html_e( 'Πηγή', 'mail-relay' ); ?></th>
					<th><?php esc_html_e( 'Σφάλμα', 'mail-relay' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $q['rows'] as $r ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( self::fmt(), $r['created'] ) ); ?></td>
						<td><?php echo self::status_label( $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
							<?php if ( '' !== $r['conn'] ) : ?><br /><span class="mrl-muted"><?php echo esc_html( self::conn_label( $r['conn'] ) ); ?></span><?php endif; ?></td>
						<td class="mrl-wide"><?php echo esc_html( $r['to'] ); ?></td>
						<td class="mrl-wide"><?php echo esc_html( $r['subject'] ); ?>
							<?php if ( $r['attach'] ) : ?><br /><span class="mrl-muted"><?php /* translators: %d: number of attachments */ echo esc_html( sprintf( _n( '%d συνημμένο', '%d συνημμένα', count( $r['attach'] ), 'mail-relay' ), count( $r['attach'] ) ) ); ?></span><?php endif; ?>
							<?php if ( $r['sensitive'] ) : ?><br /><?php echo self::badge( __( 'ευαίσθητο: χωρίς περιεχόμενο', 'mail-relay' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $r['source'] ); ?></code></td>
						<td class="mrl-wide mrl-error"><?php echo esc_html( $r['error'] ); ?></td>
						<td class="mrl-row-actions">
							<?php if ( $r['has_body'] ) : ?>
								<a class="button button-small" href="<?php echo esc_url( self::url( 'log', array( 'mrl_view' => $r['id'], 'mrl_status' => $status, 'mrl_s' => $search, 'paged' => $paged ) ) ); ?>"><?php esc_html_e( 'Προβολή', 'mail-relay' ); ?></a>
								<?php self::button_form( 'resend', array( 'mrl_id' => $r['id'] ), __( 'Ξανά', 'mail-relay' ), 'button button-small', __( 'Να σταλεί ξανά αυτό το email;', 'mail-relay' ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php
			$pages = (int) ceil( $q['total'] / $per );
			if ( $pages > 1 ) {
				echo '<p class="mrl-pages">';
				for ( $i = 1; $i <= min( $pages, 50 ); $i++ ) {
					if ( $i === $paged ) {
						echo '<strong>' . (int) $i . '</strong> ';
					} else {
						echo '<a href="' . esc_url( self::url( 'log', array( 'mrl_status' => $status, 'mrl_s' => $search, 'paged' => $i ) ) ) . '">' . (int) $i . '</a> ';
					}
				}
				echo '</p>';
			}
		}
		?>
		<div class="mrl-actions">
			<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'log', array( 'mrl_export' => 'csv', 'mrl_status' => $status, 'mrl_s' => $search ) ), self::NONCE_EXP ) ); ?>"><?php esc_html_e( 'Εξαγωγή CSV (χωρίς περιεχόμενο)', 'mail-relay' ); ?></a>
			<?php self::button_form( 'clear_log', array(), __( 'Άδειασμα αρχείου', 'mail-relay' ), 'button', __( 'Να σβηστεί όλο το αρχείο αποστολών;', 'mail-relay' ) ); ?>
		</div>
		<p class="description">
			<?php
			/* translators: %d: days */
			echo esc_html( sprintf( __( 'Οι εγγραφές σβήνονται μετά από %d ημέρες.', 'mail-relay' ), $s['days'] ) );
			echo ' ';
			echo esc_html( $s['bodies'] ? __( 'Το περιεχόμενο κρατιέται σφραγισμένο για 7 ημέρες, ποτέ για ευαίσθητα email.', 'mail-relay' ) : __( 'Το περιεχόμενο των email δεν κρατιέται (Ρυθμίσεις).', 'mail-relay' ) );
			?>
		</p>
		<?php
	}

	private static function render_view( int $id ): void {
		$r = MRL_Log::get( $id );
		$b = MRL_Log::body( $id );
		if ( ! $r || ! $b ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το περιεχόμενο αυτού του email δεν κρατήθηκε.', 'mail-relay' ) . '</p></div>';
			return;
		}
		$msg     = isset( $b['message'] ) ? (string) $b['message'] : '';
		$headers = isset( $b['headers'] ) ? $b['headers'] : '';
		$htext   = is_array( $headers ) ? implode( "\n", array_map( 'strval', $headers ) ) : (string) $headers;
		$is_html = false !== stripos( $htext, 'text/html' ) || ( false !== strpos( $msg, '<' ) && preg_match( '/<(html|body|table|p|div|br)\b/i', $msg ) );
		?>
		<div class="mrl-zone mrl-view">
			<h2 class="mrl-h2"><?php echo esc_html( $r['subject'] ); ?></h2>
			<p class="mrl-muted"><?php echo esc_html( $r['to'] . ' · ' . wp_date( self::fmt(), $r['created'] ) ); ?></p>
			<?php if ( $is_html ) : ?>
				<iframe class="mrl-frame" sandbox="" referrerpolicy="no-referrer" srcdoc="<?php echo esc_attr( $msg ); ?>"></iframe>
			<?php else : ?>
				<pre class="mrl-pre"><?php echo esc_html( $msg ); ?></pre>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( self::url( 'log' ) ); ?>"><?php esc_html_e( 'Κλείσιμο', 'mail-relay' ); ?></a></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Connection tab
	 * ------------------------------------------------------------------- */

	private static function render_connection(): void {
		$s       = MRL_Settings::get();
		$foreign = MRL_Mailer::foreign_wp_mail();
		$others  = MRL_Mailer::other_smtp_plugins();
		?>
		<h2 class="mrl-h2"><?php esc_html_e( 'Αποστολή μέσω Mail Relay', 'mail-relay' ); ?></h2>
		<div class="mrl-zone">
			<?php
			if ( 'on' === $s['mode'] ) {
				echo '<p>' . self::badge( __( 'Ενεργή', 'mail-relay' ), 'ok' ) . ' ' . esc_html__( 'Όλα τα email του site φεύγουν από τη σύνδεση που ορίζεις εδώ.', 'mail-relay' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				if ( ! MRL_Settings::tested( 'primary' ) ) {
					echo '<p>' . self::badge( __( 'Χωρίς δοκιμή', 'mail-relay' ), 'warn' ) . ' ' . esc_html__( 'Η κύρια σύνδεση άλλαξε μετά την τελευταία επιτυχημένη δοκιμή. Στείλε δοκιμαστικό από τα Εργαλεία.', 'mail-relay' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				self::button_form( 'mode_off', array(), __( 'Απενεργοποίηση (το WordPress στέλνει όπως χωρίς το plugin)', 'mail-relay' ) );
			} else {
				echo '<p>' . self::badge( __( 'Κλειστή', 'mail-relay' ), 'muted' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				$block = array();
				if ( ! MRL_Settings::tested( 'primary' ) ) {
					$block[] = __( 'Στείλε πρώτα ένα επιτυχημένο δοκιμαστικό από την κύρια σύνδεση (Εργαλεία).', 'mail-relay' );
				}
				if ( '' !== $foreign ) {
					/* translators: %s: file path */
					$block[] = sprintf( __( 'Άλλο plugin έχει αντικαταστήσει τη συνάρτηση wp_mail() (%s).', 'mail-relay' ), str_replace( wp_normalize_path( ABSPATH ), '', $foreign ) );
				}
				if ( $others ) {
					/* translators: %s: plugin names */
					$block[] = sprintf( __( 'Απενεργοποίησε πρώτα: %s. Δύο plugins email μαζί στέλνουν από λάθος λογαριασμό ή δύο φορές.', 'mail-relay' ), implode( ', ', $others ) );
				}
				if ( $block ) {
					echo '<ul class="mrl-list">';
					foreach ( $block as $b ) {
						echo '<li>' . esc_html( $b ) . '</li>';
					}
					echo '</ul>';
				} else {
					?>
					<form method="post" data-mrl-confirm="<?php esc_attr_e( 'Από τώρα όλα τα email του site θα φεύγουν από αυτή τη σύνδεση. Συνέχεια;', 'mail-relay' ); ?>">
						<?php self::form_head( 'mode_on' ); ?>
						<?php self::checkbox( 'mrl_agree', false, __( 'Συμφωνώ: όλα τα email του site θα φεύγουν από την κύρια σύνδεση (και την εφεδρική, αν είναι ανοιχτή).', 'mail-relay' ) ); ?>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Ενεργοποίηση', 'mail-relay' ); ?></button></p>
					</form>
					<?php
				}
			}
			?>
		</div>

		<form method="post">
			<?php self::form_head( 'save_from' ); ?>
			<h2 class="mrl-h2"><?php esc_html_e( 'Αποστολέας', 'mail-relay' ); ?></h2>
			<div class="mrl-zone">
				<div class="mrl-grid">
					<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'Email αποστολέα (From)', 'mail-relay' ); ?></span>
						<input type="email" name="mrl_from_email" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" /></label>
					<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'Όνομα αποστολέα', 'mail-relay' ); ?></span>
						<input type="text" name="mrl_from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>" /></label>
				</div>
				<?php self::checkbox( 'mrl_force_from', $s['force_from'], __( 'Κάθε email φεύγει με αυτόν τον αποστολέα· ο αρχικός αποστολέας ενός plugin μπαίνει ως Reply-To', 'mail-relay' ) ); ?>
				<p class="description"><?php esc_html_e( 'Το Proton Mail και οι πάροχοι API δέχονται μόνο διευθύνσεις που έχεις επιβεβαιώσει εκεί. Για το Proton, το From πρέπει να είναι η διεύθυνση για την οποία έφτιαξες το SMTP token.', 'mail-relay' ); ?></p>
				<p class="mrl-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'mail-relay' ); ?></button></p>
			</div>
		</form>
		<?php
		self::render_conn_form( 'primary', __( 'Κύρια σύνδεση', 'mail-relay' ) );
		self::render_conn_form( 'backup', __( 'Εφεδρική σύνδεση', 'mail-relay' ) );
	}

	private static function render_conn_form( string $slot, string $title ): void {
		$s      = MRL_Settings::get();
		$c      = $s[ $slot ];
		$bind   = MRL_Settings::bind( $c );
		$state  = MRL_Crypt::state( $slot, $bind );
		$labels = MRL_Api::labels();
		$p      = 'mrl_' . $slot . '_';
		?>
		<form method="post" class="mrl-conn" data-mrl-conn>
			<?php self::form_head( 'save_conn' ); ?>
			<input type="hidden" name="mrl_slot" value="<?php echo esc_attr( $slot ); ?>" />
			<h2 class="mrl-h2"><?php echo esc_html( $title ); ?></h2>
			<div class="mrl-zone">
				<?php
				if ( 'backup' === $slot ) {
					self::checkbox( 'mrl_backup_on', $s['backup_on'], __( 'Όταν η κύρια σύνδεση αποτυγχάνει, το ίδιο email δοκιμάζεται αμέσως από εδώ', 'mail-relay' ) );
				}
				$problems = MRL_Settings::problems( $slot, false );
				if ( MRL_Settings::tested( $slot ) ) {
					echo '<p>' . self::badge( __( 'Δοκιμάστηκε', 'mail-relay' ), 'ok' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				} elseif ( $problems && ( 'primary' === $slot || $s['backup_on'] ) ) {
					echo '<ul class="mrl-list mrl-warn">';
					foreach ( $problems as $pr ) {
						echo '<li>' . esc_html( $pr ) . '</li>';
					}
					echo '</ul>';
				}
				?>
				<div class="mrl-grid">
					<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'Πάροχος', 'mail-relay' ); ?></span>
						<select name="<?php echo esc_attr( $p . 'type' ); ?>" data-mrl-type>
							<?php foreach ( $labels as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['type'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label class="mrl-field" data-mrl-for="smtp"><span class="mrl-label"><?php esc_html_e( 'Έτοιμη ρύθμιση', 'mail-relay' ); ?></span>
						<select name="<?php echo esc_attr( $p . 'preset' ); ?>" data-mrl-preset>
							<option value="proton" <?php selected( $c['preset'], 'proton' ); ?>>Proton Mail (smtp.protonmail.ch)</option>
							<option value="gmail" <?php selected( $c['preset'], 'gmail' ); ?>><?php esc_html_e( 'Gmail / Google Workspace (κωδικός εφαρμογής)', 'mail-relay' ); ?></option>
							<option value="outlook" <?php selected( $c['preset'], 'outlook' ); ?>><?php esc_html_e( 'Microsoft 365 / Outlook', 'mail-relay' ); ?></option>
							<option value="siteground" <?php selected( $c['preset'], 'siteground' ); ?>><?php /* translators: %s: domain */ echo esc_html( sprintf( __( 'Email του hosting (mail.%s)', 'mail-relay' ), MRL_Settings::site_domain() ) ); ?></option>
							<option value="custom" <?php selected( $c['preset'], 'custom' ); ?>><?php esc_html_e( 'Άλλος SMTP server', 'mail-relay' ); ?></option>
						</select></label>
				</div>
				<div class="mrl-grid" data-mrl-for="smtp" data-mrl-custom>
					<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'SMTP server', 'mail-relay' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $p . 'host' ); ?>" value="<?php echo esc_attr( $c['host'] ); ?>" autocomplete="off" /></label>
					<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'Θύρα', 'mail-relay' ); ?></span>
						<input type="number" class="mrl-num" name="<?php echo esc_attr( $p . 'port' ); ?>" value="<?php echo (int) $c['port']; ?>" min="1" max="65535" /></label>
					<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'Κρυπτογράφηση', 'mail-relay' ); ?></span>
						<select name="<?php echo esc_attr( $p . 'enc' ); ?>">
							<option value="tls" <?php selected( $c['enc'], 'tls' ); ?>>STARTTLS</option>
							<option value="ssl" <?php selected( $c['enc'], 'ssl' ); ?>>SSL / TLS</option>
							<option value="none" <?php selected( $c['enc'], 'none' ); ?>><?php esc_html_e( 'Καμία (μόνο τοπικό relay χωρίς κωδικό)', 'mail-relay' ); ?></option>
						</select></label>
				</div>
				<div class="mrl-grid">
					<label class="mrl-field" data-mrl-for="smtp"><span class="mrl-label"><?php esc_html_e( 'Χρήστης (συνήθως η διεύθυνση email)', 'mail-relay' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $p . 'user' ); ?>" value="<?php echo esc_attr( 'smtp' === $c['type'] ? $c['user'] : '' ); ?>" autocomplete="off" /></label>
					<label class="mrl-field" data-mrl-for="ses"><span class="mrl-label">Access key ID</span>
						<input type="text" name="<?php echo esc_attr( $p . 'keyid' ); ?>" value="<?php echo esc_attr( 'ses' === $c['type'] ? $c['user'] : '' ); ?>" autocomplete="off" /></label>
					<label class="mrl-field" data-mrl-for="ses"><span class="mrl-label"><?php esc_html_e( 'Περιοχή', 'mail-relay' ); ?></span>
						<select name="<?php echo esc_attr( $p . 'ses_region' ); ?>">
							<?php foreach ( MRL_Settings::SES_REGIONS as $rg ) : ?>
								<option value="<?php echo esc_attr( $rg ); ?>" <?php selected( 'ses' === $c['type'] ? $c['region'] : 'eu-central-1', $rg ); ?>><?php echo esc_html( $rg ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label class="mrl-field" data-mrl-for="mailgun"><span class="mrl-label"><?php esc_html_e( 'Domain αποστολής', 'mail-relay' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $p . 'domain' ); ?>" value="<?php echo esc_attr( $c['domain'] ); ?>" placeholder="mg.example.com" /></label>
					<label class="mrl-field" data-mrl-for="mailgun"><span class="mrl-label"><?php esc_html_e( 'Περιοχή', 'mail-relay' ); ?></span>
						<select name="<?php echo esc_attr( $p . 'mg_region' ); ?>">
							<option value="us" <?php selected( 'mailgun' === $c['type'] ? $c['region'] : 'us', 'us' ); ?>>US (api.mailgun.net)</option>
							<option value="eu" <?php selected( 'mailgun' === $c['type'] ? $c['region'] : 'us', 'eu' ); ?>>EU (api.eu.mailgun.net)</option>
						</select></label>
				</div>
				<div class="mrl-grid">
					<label class="mrl-field"><span class="mrl-label" data-mrl-secret-label
						data-smtp="<?php esc_attr_e( 'Κωδικός (για Proton Mail: το SMTP token)', 'mail-relay' ); ?>"
						data-api="<?php esc_attr_e( 'Κλειδί API', 'mail-relay' ); ?>"
						data-ses="Secret access key"><?php echo esc_html( 'smtp' === $c['type'] ? __( 'Κωδικός (για Proton Mail: το SMTP token)', 'mail-relay' ) : ( 'ses' === $c['type'] ? 'Secret access key' : __( 'Κλειδί API', 'mail-relay' ) ) ); ?></span>
						<input type="password" name="<?php echo esc_attr( $p . 'secret' ); ?>" value="" autocomplete="new-password" <?php disabled( 'constant' === $state ); ?> /></label>
				</div>
				<p class="description">
					<?php
					$c_name = MRL_Crypt::SLOTS[ $slot ];
					switch ( $state ) {
						case 'constant':
							/* translators: %s: constant name */
							echo esc_html( sprintf( __( 'Ο κωδικός έρχεται από τη σταθερά %s στο wp-config.php.', 'mail-relay' ), $c_name ) );
							break;
						case 'set':
							esc_html_e( 'Υπάρχει αποθηκευμένος κωδικός (σφραγισμένος). Κενό πεδίο = μένει ο τωρινός.', 'mail-relay' );
							break;
						case 'unreadable':
							esc_html_e( 'Ο αποθηκευμένος κωδικός δεν ανοίγει πια (άλλαξαν τα salts του wp-config.php). Γράψ\' τον ξανά.', 'mail-relay' );
							break;
						default:
							esc_html_e( 'Δεν υπάρχει αποθηκευμένος κωδικός.', 'mail-relay' );
					}
					echo ' ';
					if ( 'constant' !== $state ) {
						if ( ! MRL_Crypt::available() ) {
							/* translators: %s: constant name */
							echo '<strong>' . esc_html( sprintf( __( 'Το wp-config.php δεν έχει δικά του salts ή λείπει το OpenSSL, οπότε ο κωδικός δεν μπορεί να σφραγιστεί και δεν αποθηκεύεται στη βάση. Βάλ\' τον ως σταθερά %s στο wp-config.php.', 'mail-relay' ), $c_name ) ) . '</strong>';
						} else {
							/* translators: %s: constant name */
							echo esc_html( sprintf( __( 'Σφραγίζεται (AES-256-GCM) με τα salts του wp-config.php. Πιο ασφαλές: σταθερά %s στο wp-config.php. Σβήνεται μόνος του όταν αλλάξει ο server, ο χρήστης ή ο πάροχος.', 'mail-relay' ), $c_name ) );
						}
					}
					?>
				</p>
				<?php
				if ( 'set' === $state || 'unreadable' === $state ) {
					self::checkbox( 'mrl_forget', false, __( 'Διαγραφή του αποθηκευμένου κωδικού', 'mail-relay' ) );
				}
				?>
				<p class="mrl-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση σύνδεσης', 'mail-relay' ); ?></button></p>
			</div>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tools tab
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		$s    = MRL_Settings::get();
		$user = wp_get_current_user();
		?>
		<h2 class="mrl-h2"><?php esc_html_e( 'Δοκιμαστικό email', 'mail-relay' ); ?></h2>
		<div class="mrl-zone">
			<form method="post">
				<?php self::form_head( 'test' ); ?>
				<div class="mrl-grid">
					<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'Προς', 'mail-relay' ); ?></span>
						<input type="email" name="mrl_to" value="<?php echo esc_attr( (string) $user->user_email ); ?>" required /></label>
					<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'Σύνδεση', 'mail-relay' ); ?></span>
						<select name="mrl_slot">
							<option value="primary"><?php esc_html_e( 'Κύρια', 'mail-relay' ); ?></option>
							<option value="backup"><?php esc_html_e( 'Εφεδρική', 'mail-relay' ); ?></option>
						</select></label>
				</div>
				<p class="description"><?php esc_html_e( 'Στέλνει από τη σύνδεση που διαλέγεις, ακόμα κι όταν η αποστολή μέσω Mail Relay είναι κλειστή. Μια επιτυχημένη δοκιμή της κύριας σύνδεσης επιτρέπει την ενεργοποίηση.', 'mail-relay' ); ?></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποστολή δοκιμής', 'mail-relay' ); ?></button></p>
			</form>
		</div>

		<h2 class="mrl-h2"><?php esc_html_e( 'Φύλακας αντιγράφων (staging)', 'mail-relay' ); ?></h2>
		<div class="mrl-zone">
			<p><?php esc_html_e( 'Όταν η διεύθυνση του site αλλάζει (αντίγραφο σε staging, μεταφορά με το Data Migrator), η αποστολή παγώνει, ώστε ένα δοκιμαστικό site να μη στείλει ποτέ email σε πραγματικούς πελάτες. Τα email γράφονται στο αρχείο ως «Σε παύση».', 'mail-relay' ); ?></p>
			<p><code><?php echo esc_html( home_url( '/' ) ); ?></code>
			<?php
			if ( 'on' === $s['mode'] && MRL_Settings::moved() ) {
				echo ' ' . self::badge( __( 'Άλλαξε: αποστολή σε παύση', 'mail-relay' ), 'warn' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				self::button_form( 'confirm_site', array(), __( 'Αυτό είναι το πραγματικό site: συνέχεια αποστολής', 'mail-relay' ), 'button button-primary', __( 'Σίγουρα δεν είναι αντίγραφο; Τα email θα φεύγουν σε πραγματικούς παραλήπτες.', 'mail-relay' ) );
			} else {
				echo ' ' . self::badge( __( 'Επιβεβαιωμένη', 'mail-relay' ), 'ok' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		</div>

		<h2 class="mrl-h2"><?php esc_html_e( 'SPF, DKIM και DMARC', 'mail-relay' ); ?></h2>
		<div class="mrl-zone">
			<?php
			$d = MRL_Dns::check( '1' === self::q( 'mrl_dns' ) );
			if ( '' === $d['domain'] ) {
				echo '<p>' . esc_html__( 'Ορίσε πρώτα το email αποστολέα.', 'mail-relay' ) . '</p>';
			} else {
				/* translators: %s: domain */
				echo '<p>' . esc_html( sprintf( __( 'Domain αποστολέα: %s (μόνο ανάγνωση του DNS, τίποτα δεν αλλάζει).', 'mail-relay' ), $d['domain'] ) ) . '</p>';
				echo '<table class="widefat striped mrl-table"><tbody>';
				foreach ( array( 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC' ) as $k => $label ) {
					$x  = $d[ $k ];
					$bd = true === $x['ok'] ? self::badge( __( 'Εντάξει', 'mail-relay' ), 'ok' ) : ( false === $x['ok'] ? self::badge( __( 'Λείπει', 'mail-relay' ), 'danger' ) : self::badge( __( 'Άγνωστο', 'mail-relay' ), 'muted' ) );
					echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . $bd . '</td><td><code>' . esc_html( $x['value'] ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
					if ( '' !== $x['hint'] ) {
						/* translators: %s: DNS record */
						echo '<br /><span class="mrl-muted">' . esc_html( sprintf( __( 'Συνήθως: %s', 'mail-relay' ), $x['hint'] ) ) . '</span>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
				echo '<p><a class="button" href="' . esc_url( self::url( 'tools', array( 'mrl_dns' => '1' ) ) ) . '">' . esc_html__( 'Έλεγχος ξανά', 'mail-relay' ) . '</a></p>';
			}
			?>
		</div>

		<h2 class="mrl-h2"><?php esc_html_e( 'Εισαγωγή από FluentSMTP', 'mail-relay' ); ?></h2>
		<div class="mrl-zone">
			<?php
			$found = MRL_Import::fluent();
			if ( ! $found ) {
				echo '<p>' . esc_html__( 'Δεν βρέθηκαν ρυθμίσεις του FluentSMTP.', 'mail-relay' ) . '</p>';
			} else {
				echo '<ul class="mrl-list">';
				foreach ( $found as $i => $f ) {
					$c    = $f[0];
					$what = MRL_Api::labels()[ $c['type'] ] . ( 'smtp' === $c['type'] ? ' ' . $c['host'] . ':' . $c['port'] : '' );
					/* translators: 1: primary/backup, 2: provider and host, 3: sender */
					echo '<li>' . esc_html( sprintf( __( '%1$s: %2$s, αποστολέας %3$s', 'mail-relay' ), 0 === $i ? __( 'κύρια', 'mail-relay' ) : ( 1 === $i ? __( 'εφεδρική', 'mail-relay' ) : __( 'δεν εισάγεται', 'mail-relay' ) ), $what, '' !== $f[1] ? $f[1] : '—' ) ) . '</li>';
				}
				echo '</ul>';
				echo '<p class="description">' . esc_html__( 'Παίρνει μόνο server, θύρα, κρυπτογράφηση, χρήστη και αποστολέα. Τον κωδικό ή το κλειδί API το γράφεις ξανά στη «Σύνδεση» (ή το βάζεις σταθερά στο wp-config.php). Η αποστολή μένει κλειστή μέχρι να τη δοκιμάσεις και να την ενεργοποιήσεις.', 'mail-relay' ) . '</p>';
				self::button_form( 'import_fluent', array(), __( 'Εισαγωγή', 'mail-relay' ), 'button', __( 'Οι τωρινές ρυθμίσεις σύνδεσης θα αντικατασταθούν. Συνέχεια;', 'mail-relay' ) );
			}
			?>
		</div>

		<h2 class="mrl-h2"><?php esc_html_e( 'Αντίγραφο ρυθμίσεων', 'mail-relay' ); ?></h2>
		<div class="mrl-zone">
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'tools', array( 'mrl_export' => 'json' ) ), self::NONCE_EXP ) ); ?>"><?php esc_html_e( 'Εξαγωγή JSON', 'mail-relay' ); ?></a></p>
			<p class="description"><?php esc_html_e( 'Χωρίς κωδικούς, κλειδιά και αρχείο αποστολών. Η επαναφορά αφήνει την αποστολή κλειστή.', 'mail-relay' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php self::form_head( 'import_json' ); ?>
				<input type="file" name="mrl_json" accept=".json,application/json" />
				<button type="submit" class="button"><?php esc_html_e( 'Επαναφορά', 'mail-relay' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings tab
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s = MRL_Settings::get();
		?>
		<form method="post">
			<?php self::form_head( 'save_settings' ); ?>
			<h2 class="mrl-h2"><?php esc_html_e( 'Αρχείο αποστολών', 'mail-relay' ); ?></h2>
			<div class="mrl-zone">
				<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'Ημέρες που κρατιούνται οι εγγραφές (1–90)', 'mail-relay' ); ?></span>
					<input type="number" class="mrl-num" name="mrl_days" value="<?php echo (int) $s['days']; ?>" min="1" max="90" /></label>
				<?php self::checkbox( 'mrl_bodies', $s['bodies'], __( 'Να κρατιέται και το περιεχόμενο των email για 7 ημέρες, για προβολή και επαναποστολή', 'mail-relay' ) ); ?>
				<p class="description"><?php esc_html_e( 'Κλειστό από προεπιλογή. Όταν είναι ανοιχτό, το περιεχόμενο κρατιέται σφραγισμένο (AES-256-GCM) και σβήνεται μετά από 7 ημέρες. Ποτέ για ευαίσθητα email (επαναφορά κωδικού, σύνδεσμοι σύνδεσης ή επιβεβαίωσης, tokens), ποτέ τα συνημμένα. Όταν το κλείνεις, σβήνεται αμέσως ό,τι κρατήθηκε.', 'mail-relay' ); ?></p>
				<?php
				if ( ! MRL_Crypt::available() ) {
					echo '<p class="mrl-warn">' . esc_html__( 'Χωρίς δικά του salts στο wp-config.php ή χωρίς OpenSSL το περιεχόμενο δεν μπορεί να σφραγιστεί, οπότε δεν κρατιέται καθόλου.', 'mail-relay' ) . '</p>';
				}
				?>
			</div>

			<h2 class="mrl-h2"><?php esc_html_e( 'Ειδοποιήσεις', 'mail-relay' ); ?></h2>
			<div class="mrl-zone">
				<?php self::checkbox( 'mrl_alerts', $s['alerts'], __( 'Email σύνοψης όταν αποτυγχάνουν αποστολές (το πολύ ένα την ώρα)', 'mail-relay' ) ); ?>
				<label class="mrl-field"><span class="mrl-label"><?php esc_html_e( 'Προς', 'mail-relay' ); ?></span>
					<input type="email" name="mrl_alert_to" value="<?php echo esc_attr( $s['alert_to'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" /></label>
				<p class="description"><?php esc_html_e( 'Μόνο αριθμοί και το τελευταίο σφάλμα, ποτέ περιεχόμενο ή παραλήπτες πελατών. Φεύγει από την κύρια, την εφεδρική και, τελευταία, από τη mail() του server. Οι αποτυχίες φαίνονται και σε banner του admin.', 'mail-relay' ); ?></p>
			</div>

			<h2 class="mrl-h2"><?php esc_html_e( 'Απεγκατάσταση', 'mail-relay' ); ?></h2>
			<div class="mrl-zone">
				<?php self::checkbox( 'mrl_keep', $s['keep'], __( 'Να μείνουν οι ρυθμίσεις και το αρχείο όταν σβήσω το plugin (οι κωδικοί σβήνονται πάντα)', 'mail-relay' ) ); ?>
			</div>

			<p class="mrl-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'mail-relay' ); ?></button></p>
		</form>

		<h2 class="mrl-h2"><?php esc_html_e( 'Για developers', 'mail-relay' ); ?></h2>
		<div class="mrl-zone">
			<table class="widefat striped mrl-table">
				<tbody>
					<tr><td><code>noxpress_mail_sensitive</code> <?php esc_html_e( '(φίλτρο)', 'mail-relay' ); ?></td><td><?php esc_html_e( '( bool $sensitive, array $atts ): true = το email γράφεται χωρίς περιεχόμενο και δεν ξαναστέλνεται. Για email με links σύνδεσης ή κωδικούς.', 'mail-relay' ); ?></td></tr>
					<tr><td><code>mrl_mail_source</code> <?php esc_html_e( '(φίλτρο)', 'mail-relay' ); ?></td><td><?php esc_html_e( '( string $source, string $file ): η πηγή που γράφεται στο αρχείο.', 'mail-relay' ); ?></td></tr>
					<tr><td><code>MRL_PRIMARY_SECRET, MRL_BACKUP_SECRET</code></td><td><?php esc_html_e( 'Στο wp-config.php: ο κωδικός ή το κλειδί API κάθε σύνδεσης, με προτεραιότητα.', 'mail-relay' ); ?></td></tr>
					<tr><td><code>MRL_ALLOW_LOCAL_SMTP</code></td><td><?php esc_html_e( 'Στο wp-config.php: επιτρέπει τοπικό SMTP relay (localhost, ιδιωτική IP), μόνο χωρίς κωδικό.', 'mail-relay' ); ?></td></tr>
					<tr><td><code>MRL_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: το WordPress στέλνει όπως χωρίς το plugin.', 'mail-relay' ); ?></td></tr>
					<tr><td><code>mail-relay/status, mail-relay/log</code></td><td><?php esc_html_e( 'Abilities (WordPress 6.9+) για το Site Bridge, μόνο ανάγνωση, με manage_options· παραλήπτες μασκαρισμένοι, ποτέ περιεχόμενο.', 'mail-relay' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['mrl_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Mail_Relay::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'mail-relay' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['mrl_action'] ) ) ) {
			case 'save_from':
				$s               = MRL_Settings::get();
				$s['from_email'] = self::post( 'mrl_from_email' );
				$s['from_name']  = self::post( 'mrl_from_name' );
				$s['force_from'] = '1' === self::post( 'mrl_force_from' );
				MRL_Settings::save( $s );
				$msgs = array( self::msg( 'success', __( 'Ο αποστολέας αποθηκεύτηκε.', 'mail-relay' ) ) );
				if ( '' !== self::post( 'mrl_from_email' ) && '' === MRL_Settings::get()['from_email'] ) {
					$msgs[] = self::msg( 'error', __( 'Μη έγκυρη διεύθυνση.', 'mail-relay' ) );
				}
				self::redirect( 'connection', $msgs );
				break;
			case 'save_conn':
				self::do_save_conn();
				break;
			case 'mode_on':
				self::do_mode_on();
				break;
			case 'mode_off':
				$s         = MRL_Settings::get();
				$s['mode'] = 'off';
				MRL_Settings::save( $s );
				self::redirect( 'connection', array( self::msg( 'success', __( 'Η αποστολή μέσω Mail Relay έκλεισε: το WordPress στέλνει όπως χωρίς το plugin.', 'mail-relay' ) ) ) );
				break;
			case 'test':
				self::do_test();
				break;
			case 'resend':
				$r = MRL_Mailer::resend( (int) self::post( 'mrl_id' ) );
				self::redirect( 'log', array( $r[0] ? self::msg( 'success', __( 'Το email στάλθηκε ξανά.', 'mail-relay' ) ) : self::msg( 'error', __( 'Δεν στάλθηκε ξανά:', 'mail-relay' ) . ' ' . $r[1] ) ) );
				break;
			case 'clear_log':
				$n = MRL_Log::clear();
				/* translators: %d: rows */
				self::redirect( 'log', array( self::msg( 'success', sprintf( __( 'Σβήστηκαν %d εγγραφές.', 'mail-relay' ), $n ) ) ) );
				break;
			case 'confirm_site':
				MRL_Settings::confirm_site();
				self::redirect( 'tools', array( self::msg( 'success', __( 'Η διεύθυνση επιβεβαιώθηκε: η αποστολή συνεχίζεται.', 'mail-relay' ) ) ) );
				break;
			case 'import_fluent':
				$n = MRL_Import::apply();
				self::redirect(
					'connection',
					array(
						$n
						? self::msg( 'success', __( 'Οι ρυθμίσεις του FluentSMTP μπήκαν χωρίς κωδικούς. Γράψε τον κωδικό ή το κλειδί API, στείλε δοκιμαστικό και ενεργοποίησε την αποστολή.', 'mail-relay' ) )
						: self::msg( 'error', __( 'Δεν βρέθηκαν ρυθμίσεις του FluentSMTP.', 'mail-relay' ) ),
					)
				);
				break;
			case 'import_json':
				self::redirect( 'tools', self::do_import_json() );
				break;
			case 'save_settings':
				$s       = MRL_Settings::get();
				$was     = $s['bodies'];
				$s['days']     = (int) self::post( 'mrl_days' );
				$s['bodies']   = '1' === self::post( 'mrl_bodies' );
				$s['alerts']   = '1' === self::post( 'mrl_alerts' );
				$s['alert_to'] = self::post( 'mrl_alert_to' );
				$s['keep']     = '1' === self::post( 'mrl_keep' );
				MRL_Settings::save( $s );
				if ( $was && ! $s['bodies'] ) {
					MRL_Log::drop_bodies();
				}
				self::redirect( 'settings', array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'mail-relay' ) ) ) );
				break;
			default:
				self::redirect( 'log', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'mail-relay' ) ) ) );
		}
	}

	private static function do_save_conn(): void {
		$slot = self::post( 'mrl_slot' );
		if ( ! isset( MRL_Crypt::SLOTS[ $slot ] ) ) {
			self::redirect( 'connection', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'mail-relay' ) ) ) );
		}
		$p    = 'mrl_' . $slot . '_';
		$type = sanitize_key( self::post( $p . 'type' ) );
		$in   = array(
			'type'   => $type,
			'preset' => sanitize_key( self::post( $p . 'preset' ) ),
			'host'   => self::post( $p . 'host' ),
			'port'   => (int) self::post( $p . 'port' ),
			'enc'    => sanitize_key( self::post( $p . 'enc' ) ),
			'user'   => 'ses' === $type ? self::post( $p . 'keyid' ) : self::post( $p . 'user' ),
			'region' => 'ses' === $type ? self::post( $p . 'ses_region' ) : self::post( $p . 'mg_region' ),
			'domain' => self::post( $p . 'domain' ),
		);
		$s        = MRL_Settings::get();
		$old      = $s[ $slot ];
		$old_bind = MRL_Settings::bind( $old );
		$had      = 'set' === MRL_Crypt::state( $slot, $old_bind );
		$s[ $slot ] = MRL_Settings::sanitize_conn( $in );
		if ( 'backup' === $slot ) {
			$s['backup_on'] = '1' === self::post( 'mrl_backup_on' );
		}
		$s    = MRL_Settings::save( $s );
		$bind = MRL_Settings::bind( $s[ $slot ] );
		$msgs = array();

		if ( $had && $old_bind !== $bind ) {
			$msgs[] = self::msg( 'warning', __( 'Ο αποθηκευμένος κωδικός σβήστηκε, γιατί άλλαξε ο server, ο χρήστης ή ο πάροχος. Γράψ\' τον ξανά.', 'mail-relay' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in route(); a secret is used as typed.
		$secret = isset( $_POST[ $p . 'secret' ] ) && is_string( $_POST[ $p . 'secret' ] ) ? trim( (string) wp_unslash( $_POST[ $p . 'secret' ] ) ) : '';
		$secret = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $secret );
		if ( MRL_Crypt::from_constant( $slot ) ) {
			// The constant wins; nothing typed is stored.
			$secret = '';
		}
		if ( '1' === self::post( 'mrl_forget' ) && '' === $secret ) {
			MRL_Crypt::put( $slot, '', $bind );
			$msgs[] = self::msg( 'success', __( 'Ο αποθηκευμένος κωδικός σβήστηκε.', 'mail-relay' ) );
		} elseif ( '' !== $secret ) {
			if ( strlen( $secret ) > 512 ) {
				$msgs[] = self::msg( 'error', __( 'Ο κωδικός είναι πολύ μεγάλος.', 'mail-relay' ) );
			} elseif ( 'smtp' === $s[ $slot ]['type'] && 'none' === $s[ $slot ]['enc'] ) {
				$msgs[] = self::msg( 'error', __( 'Ο κωδικός δεν αποθηκεύτηκε: χωρίς κρυπτογράφηση δεν στέλνεται ποτέ. Διάλεξε SSL ή STARTTLS.', 'mail-relay' ) );
			} elseif ( MRL_Crypt::put( $slot, $secret, $bind ) ) {
				$msgs[] = self::msg( 'success', __( 'Ο κωδικός αποθηκεύτηκε σφραγισμένος.', 'mail-relay' ) );
			} else {
				/* translators: %s: constant name */
				$msgs[] = self::msg( 'error', sprintf( __( 'Ο κωδικός δεν αποθηκεύτηκε: λείπουν τα salts του wp-config.php ή το OpenSSL. Βάλ\' τον ως σταθερά %s στο wp-config.php.', 'mail-relay' ), MRL_Crypt::SLOTS[ $slot ] ) );
			}
		}
		$secret = '';

		$msgs[] = self::msg( 'success', __( 'Η σύνδεση αποθηκεύτηκε.', 'mail-relay' ) );
		foreach ( MRL_Settings::problems( $slot, true ) as $pr ) {
			if ( 'backup' === $slot && ! $s['backup_on'] ) {
				break;
			}
			$msgs[] = self::msg( 'warning', $pr );
		}
		self::redirect( 'connection', $msgs );
	}

	private static function do_mode_on(): void {
		if ( '1' !== self::post( 'mrl_agree' ) ) {
			self::redirect( 'connection', array( self::msg( 'error', __( 'Τσέκαρε το «Συμφωνώ» για να ενεργοποιηθεί η αποστολή.', 'mail-relay' ) ) ) );
		}
		if ( ! MRL_Settings::tested( 'primary' ) || '' !== MRL_Mailer::foreign_wp_mail() || MRL_Mailer::other_smtp_plugins() ) {
			self::redirect( 'connection', array( self::msg( 'error', __( 'Η αποστολή δεν ενεργοποιήθηκε: δες τι λείπει παραπάνω.', 'mail-relay' ) ) ) );
		}
		$s         = MRL_Settings::get();
		$s['mode'] = 'on';
		MRL_Settings::save( $s );
		MRL_Settings::confirm_site();
		self::redirect( 'connection', array( self::msg( 'success', __( 'Η αποστολή μέσω Mail Relay είναι ενεργή.', 'mail-relay' ) ) ) );
	}

	private static function do_test(): void {
		$key = 'mrl_test_' . get_current_user_id();
		$n   = (int) get_transient( $key );
		if ( $n >= self::TEST_LIMIT ) {
			self::redirect( 'tools', array( self::msg( 'error', __( 'Πολλές δοκιμές σε λίγο χρόνο. Ξαναδοκίμασε σε 10 λεπτά.', 'mail-relay' ) ) ) );
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		$slot = 'backup' === self::post( 'mrl_slot' ) ? 'backup' : 'primary';
		$r    = MRL_Mailer::test( $slot, self::post( 'mrl_to' ) );
		self::redirect(
			'tools',
			array(
				$r[0]
				? self::msg( 'success', __( 'Το δοκιμαστικό στάλθηκε. Έλεγξε τα εισερχόμενα (και τα ανεπιθύμητα).', 'mail-relay' ) )
				: self::msg( 'error', __( 'Το δοκιμαστικό δεν στάλθηκε:', 'mail-relay' ) . ' ' . $r[1] ),
			)
		);
	}

	/* =====================================================================
	 * Export (GET) and JSON restore
	 * =================================================================== */

	public static function route_export(): void {
		$kind = self::q( 'mrl_export' );
		if ( self::SLUG !== self::current_page() || '' === $kind ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_EXP ) || ! current_user_can( Mail_Relay::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'mail-relay' ) );
		}
		nocache_headers();
		if ( 'csv' === $kind ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="mail-relay-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheets.
			MRL_Log::csv(
				$out,
				array(
					'status' => sanitize_key( self::q( 'mrl_status' ) ),
					'search' => self::q( 'mrl_s' ),
				)
			);
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			exit;
		}
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="mail-relay-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			array(
				'plugin'  => 'mail-relay',
				'version' => MRL_VERSION,
				'options' => array( MRL_Settings::OPT => MRL_Settings::export() ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		exit;
	}

	private static function do_import_json(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['mrl_json']['error'] ) ? (int) $_FILES['mrl_json']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['mrl_json']['size'] ) ? (int) $_FILES['mrl_json']['size'] : 0;
		$tmp  = isset( $_FILES['mrl_json']['tmp_name'] ) ? (string) $_FILES['mrl_json']['tmp_name'] : '';
		// phpcs:enable
		if ( $size > self::MAX_JSON ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'mail-relay' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο.', 'mail-relay' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $tmp ), true );
		if ( ! is_array( $state ) || ! isset( $state['plugin'] ) || 'mail-relay' !== $state['plugin'] || ! isset( $state['options'][ MRL_Settings::OPT ] ) || ! is_array( $state['options'][ MRL_Settings::OPT ] ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο ρυθμίσεων του Mail Relay.', 'mail-relay' ) ) );
		}
		$in         = $state['options'][ MRL_Settings::OPT ];
		$in['mode'] = 'off';
		MRL_Settings::save( $in );
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις επανήλθαν. Η αποστολή είναι κλειστή: γράψε τους κωδικούς, στείλε δοκιμαστικό και ενεργοποίησέ την.', 'mail-relay' ) ) );
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
			set_transient( 'mrl_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ) : '';
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'mrl_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'mrl_aui_msg_' . get_current_user_id() );
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
		<p class="mrl-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'mail-relay' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="mrl-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="mrl-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'mail-relay' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="mrl-footer-cta">
			<a class="mrl-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'mail-relay' ); ?>
			</a>
			<a class="mrl-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'mail-relay' ); ?>
			</a>
		</p>
		<?php
	}
}
