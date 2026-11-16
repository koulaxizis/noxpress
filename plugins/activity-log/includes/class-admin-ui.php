<?php
/**
 * ACL_Admin_UI — menu, history, settings, CSV, the «History» box on
 * products and orders, the dashboard widget (Bible §3–§8, §11).
 *
 * Submenu «Ιστορικό αλλαγών» of the shared "Noxpress" menu, slug acl-log,
 * capability manage_options (administrators only, Chris 2026-11-16). Tabs:
 *  - log       filters (dates, who, category, from where, importance,
 *              search), 50 rows a page, each with its changes; CSV
 *  - settings  categories, IP, retention, e-mail, extra options, «Empty
 *              the history»
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient acl_aui_msg_{uid}). CSV: privileged GET with
 * its own nonce. Rows are only read here; nothing edits one.
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'acl-log';
	const PRIORITY    = 410;
	const NONCE       = 'acl_admin';
	const NONCE_FIELD = 'acl_nonce';
	const NONCE_CSV   = 'acl_csv';
	const PER_PAGE    = 50;
	const CSV_MAX     = 50000;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), self::PRIORITY );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_csv' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ), 30, 2 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ACL_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'log'      => __( 'Ιστορικό', 'activity-log' ),
			'settings' => __( 'Ρυθμίσεις', 'activity-log' ),
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
			__( 'Ιστορικό αλλαγών', 'activity-log' ),
			__( 'Ιστορικό αλλαγών', 'activity-log' ),
			Activity_Log::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ιστορικό', 'activity-log' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'log';
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets( $hook = '' ): void {
		$page = self::current_page();
		if ( self::SLUG === $page ) {
			wp_enqueue_style( 'acl-admin', ACL_URL . 'assets/admin.css', array(), ACL_VERSION );
			return;
		}
		// The small box styles on product / order screens and the dashboard.
		if ( current_user_can( Activity_Log::CAP ) && in_array( (string) $hook, array( 'post.php', 'index.php', 'woocommerce_page_wc-orders' ), true ) ) {
			wp_enqueue_style( 'acl-admin', ACL_URL . 'assets/admin.css', array(), ACL_VERSION );
		}
	}

	/** The filters of the page, from the query string. */
	private static function filters(): array {
		$f = array();
		foreach ( array( 'from', 'to', 'category', 'context', 'q', 'object_type' ) as $k ) {
			$v = self::q( 'acl_' . $k );
			if ( '' !== $v ) {
				$f[ $k ] = $v;
			}
		}
		$sev = self::q( 'acl_sev' );
		if ( in_array( $sev, array( 'notice', 'warning', 'critical' ), true ) ) {
			$f['min_severity'] = $sev;
		}
		$actor = self::q( 'acl_actor' );
		if ( '' !== $actor && ctype_digit( $actor ) ) {
			$f['actor'] = (int) $actor;
		}
		$oid = self::q( 'acl_object_id' );
		if ( '' !== $oid && ctype_digit( $oid ) ) {
			$f['object_id'] = (int) $oid;
		}
		return $f;
	}

	/** Filter keys back to query-string names. */
	private static function filter_args( array $f ): array {
		$out = array();
		foreach ( $f as $k => $v ) {
			$name         = 'min_severity' === $k ? 'acl_sev' : 'acl_' . $k;
			$out[ $name ] = $v;
		}
		return $out;
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Activity_Log::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'activity-log' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap acl-wrap">
			<h1><?php esc_html_e( 'Ιστορικό αλλαγών', 'activity-log' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ποιος άλλαξε τι και πότε στο site, από το admin, το REST API, το WP-CLI, το cron ή το Site Bridge. Χωρίς μυστικά και χωρίς στοιχεία πελατών.', 'activity-log' ); ?></p>
			<?php
			self::print_notices( self::take_msgs() );
			if ( Activity_Log::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το ACL_DISABLE είναι ενεργό στο wp-config.php: τίποτα δεν καταγράφεται.', 'activity-log' ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper acl-tabs">
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
			if ( 'settings' === $tab ) {
				self::render_settings();
			} else {
				self::render_log();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function select( string $name, array $options, string $current, string $all ): void {
		echo '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $all ) . '</option>';
		foreach ( $options as $v => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $v ), selected( (string) $v, $current, false ), esc_html( (string) $label ) );
		}
		echo '</select>';
	}

	private static function render_log(): void {
		$f     = self::filters();
		$page  = max( 1, (int) self::q( 'paged' ) );
		$total = ACL_Store::count( $f );
		$rows  = ACL_Store::rows( $f, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$cats  = array();
		foreach ( ACL_Settings::categories() as $k => $c ) {
			$cats[ $k ] = __( $c[0], 'activity-log' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgids listed in ACL_Settings::categories() and ACL_Lang.
		}
		?>
		<form method="get" class="acl-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="log" />
			<label><?php esc_html_e( 'Από', 'activity-log' ); ?><input type="date" name="acl_from" value="<?php echo esc_attr( $f['from'] ?? '' ); ?>" /></label>
			<label><?php esc_html_e( 'Έως', 'activity-log' ); ?><input type="date" name="acl_to" value="<?php echo esc_attr( $f['to'] ?? '' ); ?>" /></label>
			<label><?php esc_html_e( 'Ποιος', 'activity-log' ); ?>
				<?php self::select( 'acl_actor', ACL_Store::actors(), isset( $f['actor'] ) ? (string) $f['actor'] : '', __( 'Όλοι', 'activity-log' ) ); ?>
			</label>
			<label><?php esc_html_e( 'Κατηγορία', 'activity-log' ); ?>
				<?php self::select( 'acl_category', $cats, $f['category'] ?? '', __( 'Όλες', 'activity-log' ) ); ?>
			</label>
			<label><?php esc_html_e( 'Πηγή', 'activity-log' ); ?>
				<?php self::select( 'acl_context', ACL_Events::contexts(), $f['context'] ?? '', __( 'Όλες', 'activity-log' ) ); ?>
			</label>
			<label><?php esc_html_e( 'Σημασία', 'activity-log' ); ?>
				<?php
				self::select(
					'acl_sev',
					array(
						'notice'   => __( 'Σημαντικό και πάνω', 'activity-log' ),
						'warning'  => __( 'Προσοχή και πάνω', 'activity-log' ),
						'critical' => __( 'Μόνο κρίσιμα', 'activity-log' ),
					),
					$f['min_severity'] ?? '',
					__( 'Όλα', 'activity-log' )
				);
				?>
			</label>
			<label><?php esc_html_e( 'Αναζήτηση', 'activity-log' ); ?><input type="text" name="acl_q" value="<?php echo esc_attr( $f['q'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'προϊόν, ρύθμιση, χρήστης…', 'activity-log' ); ?>" /></label>
			<?php if ( isset( $f['object_type'], $f['object_id'] ) ) : ?>
				<input type="hidden" name="acl_object_type" value="<?php echo esc_attr( $f['object_type'] ); ?>" />
				<input type="hidden" name="acl_object_id" value="<?php echo esc_attr( (string) $f['object_id'] ); ?>" />
			<?php endif; ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Φίλτρο', 'activity-log' ); ?></button>
			<?php if ( $f ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( 'log' ) ); ?>"><?php esc_html_e( 'Καθαρισμός', 'activity-log' ); ?></a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'log', array_merge( self::filter_args( $f ), array( 'acl_csv' => 1 ) ) ), self::NONCE_CSV ) ); ?>"><?php esc_html_e( 'Εξαγωγή CSV', 'activity-log' ); ?></a>
		</form>

		<p class="acl-muted">
			<?php
			/* translators: 1: number of rows, 2: days kept */
			echo esc_html( sprintf( _n( '%1$s γραμμή. Το ιστορικό κρατά %2$d ημέρες.', '%1$s γραμμές. Το ιστορικό κρατά %2$d ημέρες.', $total, 'activity-log' ), number_format_i18n( $total ), (int) ACL_Settings::get()['days'] ) );
			?>
		</p>

		<div class="acl-table-wrap">
		<table class="widefat striped acl-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Πότε', 'activity-log' ); ?></th>
					<th><?php esc_html_e( 'Ποιος', 'activity-log' ); ?></th>
					<th><?php esc_html_e( 'Τι', 'activity-log' ); ?></th>
					<th><?php esc_html_e( 'Πηγή', 'activity-log' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="4" class="acl-empty"><?php esc_html_e( 'Καμία αλλαγή ακόμα.', 'activity-log' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td class="acl-when"><?php echo esc_html( get_date_from_gmt( (string) $r['ts'], 'Y-m-d H:i:s' ) ); ?></td>
					<td class="acl-who">
						<?php echo self::actor_html( $r ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
					</td>
					<td>
						<span class="acl-sev acl-sev--<?php echo esc_attr( (string) $r['severity'] ); ?>" title="<?php echo esc_attr( ACL_Events::severities()[ $r['severity'] ] ?? '' ); ?>"></span>
						<?php echo esc_html( ACL_Events::action( (string) $r['action'] ) ); ?>
						<?php
						$obj = ACL_Events::object( $r );
						if ( '' !== $obj ) {
							$link = self::object_link( $r );
							echo ': ' . ( '' !== $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $obj ) . '</a>' : '<b>' . esc_html( $obj ) . '</b>' );
							if ( $r['object_id'] > 0 && '' !== $r['object_type'] ) {
								echo ' <a class="acl-muted" href="' . esc_url( self::url( 'log', array( 'acl_object_type' => $r['object_type'], 'acl_object_id' => $r['object_id'] ) ) ) . '" title="' . esc_attr__( 'Όλο το ιστορικό του', 'activity-log' ) . '">⧉</a>';
							}
						}
						self::details_html( (array) $r['details'] );
						?>
					</td>
					<td class="acl-from">
						<?php
						echo esc_html( ACL_Events::context( (string) $r['context'] ) );
						if ( 0 === strpos( (string) $r['ref'], 'sbr:' ) ) {
							$sbr = admin_url( 'admin.php?page=sbr-settings&tab=log' );
							/* translators: %s: Site Bridge audit log row id */
							echo '<small><a href="' . esc_url( $sbr ) . '">' . esc_html( sprintf( __( 'γραμμή #%s του Site Bridge', 'activity-log' ), substr( (string) $r['ref'], 4 ) ) ) . '</a></small>';
						}
						if ( '' !== (string) $r['ip'] ) {
							echo '<small>' . esc_html( (string) $r['ip'] ) . '</small>';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
		self::pages( $total, $page, $f );
	}

	private static function pages( int $total, int $page, array $f ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<p class="acl-pages">';
		$show = array_unique( array_filter( array( 1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages ), static function ( $p ) use ( $pages ) { return $p >= 1 && $p <= $pages; } ) );
		sort( $show );
		$prev = 0;
		foreach ( $show as $p ) {
			if ( $prev && $p > $prev + 1 ) {
				echo '<span class="acl-muted">…</span>';
			}
			if ( $p === $page ) {
				echo '<strong>' . (int) $p . '</strong>';
			} else {
				echo '<a href="' . esc_url( self::url( 'log', array_merge( self::filter_args( $f ), array( 'paged' => $p ) ) ) ) . '">' . (int) $p . '</a>';
			}
			$prev = $p;
		}
		echo '</p>';
	}

	private static function actor_html( array $r ): string {
		$name = ACL_Events::actor( $r );
		$out  = $r['actor_id'] > 0 && ! in_array( $r['actor_label'], array( 'customer', 'deleted' ), true ) && get_userdata( (int) $r['actor_id'] )
			? '<a href="' . esc_url( self::url( 'log', array( 'acl_actor' => (int) $r['actor_id'] ) ) ) . '">' . esc_html( $name ) . '</a>'
			: esc_html( $name );
		if ( '' !== (string) $r['actor_role'] ) {
			$roles = wp_roles()->get_names();
			$role  = isset( $roles[ $r['actor_role'] ] ) ? translate_user_role( $roles[ $r['actor_role'] ] ) : $r['actor_role'];
			$out  .= '<small>' . esc_html( $role ) . '</small>';
		}
		return $out;
	}

	/** Changes: the first 4 shown, the rest behind «more». */
	private static function details_html( array $details ): void {
		if ( ! $details ) {
			return;
		}
		$items = array();
		foreach ( $details as $k => $v ) {
			$k       = (string) $k;
			$leaf    = (string) preg_replace( '/^.*\./', '', $k );
			$name    = $leaf === $k ? ACL_Events::field( $k ) : $k;
			$items[] = '<li><b>' . esc_html( $name ) . ':</b> ' . esc_html( ACL_Events::detail( $v ) ) . '</li>';
		}
		$first = array_slice( $items, 0, 4 );
		$rest  = array_slice( $items, 4 );
		echo '<ul class="acl-changes">' . implode( '', $first ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		if ( $rest ) {
			/* translators: %d: number of more changes */
			echo '<details class="acl-more"><summary>' . esc_html( sprintf( __( '+%d ακόμα', 'activity-log' ), count( $rest ) ) ) . '</summary><ul class="acl-changes">' . implode( '', $rest ) . '</ul></details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
	}

	/** Edit link of the object, when it still exists and we may open it. */
	private static function object_link( array $r ): string {
		$id = (int) $r['object_id'];
		if ( $id <= 0 ) {
			return '';
		}
		switch ( (string) $r['object_type'] ) {
			case 'order':
				if ( function_exists( 'wc_get_order' ) ) {
					$o = wc_get_order( $id );
					return $o && is_callable( array( $o, 'get_edit_order_url' ) ) ? (string) $o->get_edit_order_url() : '';
				}
				return '';
			case 'user':
				return get_userdata( $id ) ? (string) get_edit_user_link( $id ) : '';
			case 'attachment':
			case 'product':
			case 'coupon':
				return get_post( $id ) ? (string) get_edit_post_link( $id, 'raw' ) : '';
			case 'creator':
				$t = get_term( $id );
				return $t instanceof WP_Term ? (string) get_edit_term_link( $t ) : '';
		}
		$type = (string) $r['object_type'];
		if ( post_type_exists( $type ) && get_post( $id ) ) {
			return (string) get_edit_post_link( $id, 'raw' );
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s = ACL_Settings::get();
		?>
		<form method="post">
			<?php self::form_head( 'save' ); ?>
			<h2 class="acl-h2"><?php esc_html_e( 'Τι καταγράφεται', 'activity-log' ); ?></h2>
			<div class="acl-zone">
				<div class="acl-cats">
				<?php foreach ( ACL_Settings::categories() as $k => $c ) : ?>
					<label class="acl-check"><input type="checkbox" name="acl_cats[<?php echo esc_attr( (string) $k ); ?>]" value="1"<?php checked( ! empty( $s['cats'][ $k ] ) ); ?> /> <?php echo esc_html( __( $c[0], 'activity-log' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></label>
				<?php endforeach; ?>
				</div>
				<p class="acl-hint"><?php esc_html_e( 'Δεν καταγράφονται ποτέ: αναγνώσεις, κωδικοί και κλειδιά, ονόματα, email και διευθύνσεις πελατών, ό,τι πληκτρολογήθηκε σε αποτυχημένη σύνδεση. Τα 404 τα κρατά το Redirects και τις κλήσεις του Claude το Site Bridge.', 'activity-log' ); ?></p>
			</div>

			<h2 class="acl-h2"><?php esc_html_e( 'Ιδιωτικότητα και διάρκεια', 'activity-log' ); ?></h2>
			<div class="acl-zone">
				<p><strong><?php esc_html_e( 'Διεύθυνση IP', 'activity-log' ); ?></strong></p>
				<?php
				self::radio( 'acl_ip', 'mask', $s['ip'], __( 'Με μάσκα: χωρίς το τελευταίο μέρος (προτείνεται)', 'activity-log' ) );
				self::radio( 'acl_ip', 'full', $s['ip'], __( 'Πλήρης', 'activity-log' ) );
				self::radio( 'acl_ip', 'none', $s['ip'], __( 'Καθόλου', 'activity-log' ) );
				?>
				<div class="acl-grid acl-mt-8">
					<label class="acl-field"><span><?php esc_html_e( 'Ημέρες ιστορικού', 'activity-log' ); ?></span><input type="number" class="acl-num" name="acl_days" min="1" max="730" value="<?php echo (int) $s['days']; ?>" /></label>
					<label class="acl-field"><span><?php esc_html_e( 'Έως γραμμές', 'activity-log' ); ?></span><input type="number" class="acl-num" name="acl_max" min="10000" max="2000000" step="1000" value="<?php echo (int) $s['max']; ?>" /></label>
				</div>
				<p class="acl-hint"><?php esc_html_e( 'Κάθε μέρα σβήνονται οι γραμμές που πέρασαν τις ημέρες και οι παλαιότερες πάνω από το όριο.', 'activity-log' ); ?></p>
			</div>

			<h2 class="acl-h2"><?php esc_html_e( 'Email για κρίσιμες αλλαγές', 'activity-log' ); ?></h2>
			<div class="acl-zone">
				<?php self::checkbox( 'acl_notify', $s['notify'], __( 'Στείλε email όταν μπαίνει νέος διαχειριστής, εγκαθίσταται, ενεργοποιείται ή σβήνεται plugin, αλλάζει θέμα, αλλάζει η διεύθυνση ή το email του site, ή χρησιμοποιείται ο επεξεργαστής αρχείων', 'activity-log' ) ); ?>
				<label class="acl-field acl-mt-8"><span><?php esc_html_e( 'Προς', 'activity-log' ); ?></span><input type="email" name="acl_to" value="<?php echo esc_attr( $s['to'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" /></label>
				<p class="acl-hint"><?php esc_html_e( 'Ένα email το πολύ κάθε 15 λεπτά, με όλες τις αλλαγές μαζί. Κενό = το email διαχείρισης του site.', 'activity-log' ); ?></p>
			</div>

			<h2 class="acl-h2"><?php esc_html_e( 'Για προχωρημένους', 'activity-log' ); ?></h2>
			<div class="acl-zone">
				<label class="acl-block" for="acl_extra"><?php esc_html_e( 'Επιπλέον options που παρακολουθούνται (ένα ανά γραμμή)', 'activity-log' ); ?></label>
				<textarea id="acl_extra" name="acl_extra" class="acl-wide"><?php echo esc_textarea( implode( "\n", $s['extra'] ) ); ?></textarea>
				<p class="acl-hint"><?php esc_html_e( 'Ήδη μέσα: οι βασικές ρυθμίσεις του WordPress, όλες οι ρυθμίσεις του WooCommerce και οι ρυθμίσεις των plugins της σουίτας. Ονόματα που μοιάζουν με κωδικό ή κλειδί δεν γίνονται δεκτά. Για developers: φίλτρα acl_watched_options, acl_skip_event, acl_categories και η συνάρτηση acl_log(). Το ACL_DISABLE στο wp-config.php σταματά την καταγραφή.', 'activity-log' ); ?></p>
			</div>
			<p class="acl-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'activity-log' ); ?></button></p>
		</form>

		<h2 class="acl-h2"><?php esc_html_e( 'Άδειασμα ιστορικού', 'activity-log' ); ?></h2>
		<div class="acl-zone">
			<form method="post">
				<?php self::form_head( 'clear' ); ?>
				<p><?php esc_html_e( 'Σβήνει όλες τις γραμμές. Μένει μόνο μία, που λέει ποιος άδειασε το ιστορικό και πότε. Δεν αναιρείται.', 'activity-log' ); ?></p>
				<?php self::checkbox( 'acl_sure', false, __( 'Ναι, άδειασε το ιστορικό', 'activity-log' ) ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Άδειασμα', 'activity-log' ); ?></button></p>
			</form>
			<p class="acl-hint"><?php esc_html_e( 'Με τη διαγραφή του plugin φεύγουν ο πίνακας και οι ρυθμίσεις του.', 'activity-log' ); ?></p>
		</div>
		<?php
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="acl-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="acl-check acl-block"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="acl_action" value="' . esc_attr( $action ) . '" />';
	}

	/* =====================================================================
	 * POST routes
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['acl_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Activity_Log::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'activity-log' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		switch ( sanitize_key( wp_unslash( $_POST['acl_action'] ) ) ) {
			case 'save':
				$cats = array();
				$in   = isset( $_POST['acl_cats'] ) && is_array( $_POST['acl_cats'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['acl_cats'] ) ) ) : array();
				foreach ( array_keys( ACL_Settings::categories() ) as $k ) {
					$cats[ $k ] = in_array( $k, $in, true );
				}
				ACL_Settings::save(
					array(
						'cats'   => $cats,
						'ip'     => isset( $_POST['acl_ip'] ) ? sanitize_key( wp_unslash( $_POST['acl_ip'] ) ) : '',
						'days'   => isset( $_POST['acl_days'] ) ? (int) $_POST['acl_days'] : 0,
						'max'    => isset( $_POST['acl_max'] ) ? (int) $_POST['acl_max'] : 0,
						'notify' => ! empty( $_POST['acl_notify'] ),
						'to'     => isset( $_POST['acl_to'] ) ? sanitize_email( wp_unslash( $_POST['acl_to'] ) ) : '',
						'extra'  => isset( $_POST['acl_extra'] ) ? sanitize_textarea_field( wp_unslash( $_POST['acl_extra'] ) ) : '',
					)
				);
				self::redirect( 'settings', array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'activity-log' ) ) ) );
				break;
			case 'clear':
				if ( empty( $_POST['acl_sure'] ) ) {
					self::redirect( 'settings', array( self::msg( 'warning', __( 'Τσέκαρε «Ναι, άδειασε το ιστορικό» για να συνεχίσεις.', 'activity-log' ) ) ) );
				}
				ACL_Store::clear();
				self::redirect( 'log', array( self::msg( 'success', __( 'Το ιστορικό άδειασε.', 'activity-log' ) ) ) );
				break;
		}
		// phpcs:enable
	}

	/** CSV of the filtered rows (privileged GET with its own nonce). */
	public static function route_csv(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'acl_csv' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_CSV ) || ! current_user_can( Activity_Log::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'activity-log' ) );
		}
		$f = self::filters();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="activity-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheets.
		Noxpress_Safe::csv_row(
			$out,
			array(
				__( 'Πότε', 'activity-log' ),
				__( 'Ποιος', 'activity-log' ),
				__( 'Ρόλος', 'activity-log' ),
				__( 'Πηγή', 'activity-log' ),
				'IP',
				__( 'Κατηγορία', 'activity-log' ),
				__( 'Τι', 'activity-log' ),
				__( 'Αντικείμενο', 'activity-log' ),
				'ID',
				__( 'Σημασία', 'activity-log' ),
				__( 'Αλλαγές', 'activity-log' ),
			)
		);
		$done = 0;
		for ( $offset = 0; $done < self::CSV_MAX; $offset += 500 ) {
			$rows = ACL_Store::rows( $f, 500, $offset );
			foreach ( $rows as $r ) {
				Noxpress_Safe::csv_row(
					$out,
					array(
						get_date_from_gmt( (string) $r['ts'], 'Y-m-d H:i:s' ),
						ACL_Events::actor( $r ),
						(string) $r['actor_role'],
						ACL_Events::context( (string) $r['context'] ),
						(string) $r['ip'],
						(string) $r['category'],
						ACL_Events::action( (string) $r['action'] ),
						ACL_Events::object( $r ),
						(int) $r['object_id'],
						(string) $r['severity'],
						implode( ' | ', ACL_Events::details_lines( (array) $r['details'] ) ),
					)
				);
				++$done;
			}
			if ( count( $rows ) < 500 ) {
				break;
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		if ( $msgs ) {
			set_transient( 'acl_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'acl_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'acl_aui_msg_' . get_current_user_id() );
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
	 * «History» box on products and orders, dashboard widget
	 * =================================================================== */

	public static function meta_boxes( $post_type = '', $post = null ): void {
		if ( ! current_user_can( Activity_Log::CAP ) ) {
			return;
		}
		$screens = array( 'product' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		$screens[] = 'shop_order';
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'acl-history', __( 'Ιστορικό αλλαγών', 'activity-log' ), array( __CLASS__, 'box' ), $screen, 'side', 'low' );
		}
	}

	/** @param WP_Post|WC_Order|mixed $obj */
	public static function box( $obj ): void {
		$id   = 0;
		$type = 'product';
		if ( $obj instanceof WP_Post ) {
			$id   = (int) $obj->ID;
			$type = 'shop_order' === $obj->post_type ? 'order' : 'product';
		} elseif ( is_object( $obj ) && method_exists( $obj, 'get_id' ) ) {
			$id   = (int) $obj->get_id();
			$type = 'order';
		}
		$f    = array(
			'object_type' => $type,
			'object_id'   => $id,
		);
		$rows = $id ? ACL_Store::rows( $f, 10 ) : array();
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Καμία αλλαγή ακόμα.', 'activity-log' ) . '</p>';
			return;
		}
		self::mini_list( $rows, false );
		echo '<p><a href="' . esc_url( self::url( 'log', array( 'acl_object_type' => $type, 'acl_object_id' => $id ) ) ) . '">' . esc_html__( 'Όλο το ιστορικό', 'activity-log' ) . '</a></p>';
	}

	private static function mini_list( array $rows, bool $with_object ): void {
		echo '<ul class="acl-box-list">';
		foreach ( $rows as $r ) {
			echo '<li><span class="acl-box-meta">' . esc_html( get_date_from_gmt( (string) $r['ts'], 'Y-m-d H:i' ) . ' · ' . ACL_Events::actor( $r ) . ' · ' . ACL_Events::context( (string) $r['context'] ) ) . '</span>';
			echo '<strong>' . esc_html( ACL_Events::action( (string) $r['action'] ) ) . '</strong>';
			$obj = ACL_Events::object( $r );
			if ( $with_object && '' !== $obj ) {
				echo ': ' . esc_html( $obj );
			}
			foreach ( array_slice( ACL_Events::details_lines( (array) $r['details'] ), 0, 3 ) as $line ) {
				echo '<span class="acl-box-change">' . esc_html( $line ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	public static function dashboard(): void {
		if ( current_user_can( Activity_Log::CAP ) ) {
			wp_add_dashboard_widget( 'acl_dashboard', __( 'Ιστορικό αλλαγών: τελευταία σημαντικά', 'activity-log' ), array( __CLASS__, 'dashboard_widget' ) );
		}
	}

	public static function dashboard_widget(): void {
		$rows = ACL_Store::rows( array( 'min_severity' => 'warning' ), 5 );
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Καμία σημαντική αλλαγή ακόμα.', 'activity-log' ) . '</p>';
		} else {
			self::mini_list( $rows, true );
		}
		echo '<p><a href="' . esc_url( self::url( 'log' ) ) . '">' . esc_html__( 'Όλο το ιστορικό', 'activity-log' ) . '</a></p>';
	}

	/* =====================================================================
	 * Footer (Bible §7 — identical pattern)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="acl-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'activity-log' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="acl-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="acl-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'activity-log' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="acl-footer-cta">
			<a class="acl-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'activity-log' ); ?>
			</a>
			<a class="acl-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'activity-log' ); ?>
			</a>
		</p>
		<?php
	}
}
