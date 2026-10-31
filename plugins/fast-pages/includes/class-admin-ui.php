<?php
/**
 * FPG_Admin_UI — menu, settings page, checks (Bible §3–§8, §11).
 *
 * Submenu «Fast Pages» of the shared "Noxpress" menu (admin_menu priority
 * 270), slug fpg-settings, capability manage_options. Tabs:
 *  - overview  status, checks, numbers, turn on / off, test mode, purge,
 *              preload
 *  - cache     lifespan, phones, exclusions, preload
 *  - optimize  minify, lazy load, YouTube, dimensions, hover preload
 *  - tools     settings from WP Rocket, database cleanup, language
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient fpg_aui_msg_{uid}).
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Admin_UI {

	const NONCE       = 'fpg_admin';
	const NONCE_FIELD = 'fpg_nonce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 270 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FPG_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'overview' => __( 'Επισκόπηση', 'fast-pages' ),
			'cache'    => __( 'Cache', 'fast-pages' ),
			'optimize' => __( 'Βελτιστοποίηση', 'fast-pages' ),
			'tools'    => __( 'Εργαλεία', 'fast-pages' ),
		);
	}

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			'noxpress',
			__( 'Fast Pages', 'fast-pages' ),
			__( 'Fast Pages', 'fast-pages' ),
			Fast_Pages::CAP,
			FPG_Settings::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . FPG_Settings::PAGE ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'fast-pages' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
	}

	public static function assets(): void {
		if ( FPG_Settings::PAGE !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'fpg-admin', FPG_URL . 'assets/admin.css', array(), FPG_VERSION );
		wp_enqueue_script( 'fpg-admin', FPG_URL . 'assets/admin.js', array(), FPG_VERSION, true );
	}

	/**
	 * Another page cache is active: say so on the plugins screen and our
	 * page (Bible §14.17, a reminder while the plugin we replace runs).
	 */
	public static function notices(): void {
		if ( ! current_user_can( Fast_Pages::CAP ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( FPG_Settings::PAGE !== self::current_page() && ( ! $screen || 'plugins' !== $screen->id ) ) {
			return;
		}
		$conf = FPG_Setup::conflicts();
		if ( $conf ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: %s: plugin names */
					__( 'Fast Pages: είναι ενεργό το %s. Δύο page caches μαζί δεν δουλεύουν, οπότε η cache του Fast Pages περιμένει. Πέρασε πρώτα τις ρυθμίσεις (Εργαλεία) και μετά απενεργοποίησέ το.', 'fast-pages' ),
					implode( ', ', $conf )
				)
			) . '</p></div>';
		}
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Fast_Pages::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'fast-pages' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap fpg-wrap">
			<h1><?php esc_html_e( 'Fast Pages', 'fast-pages' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Page cache για επισκέπτες, preload, minify, lazy load και YouTube με κλικ. Οι συνδεδεμένοι χρήστες, το καλάθι, το ταμείο και ο λογαριασμός δεν μπαίνουν ποτέ στην cache.', 'fast-pages' ); ?></p>

			<nav class="nav-tab-wrapper fpg-tabs">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . FPG_Settings::PAGE . ( 'overview' === $slug ? '' : '&tab=' . $slug ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php self::print_notices( self::take_msgs() ); ?>

			<?php
			switch ( $tab ) {
				case 'cache':
					self::render_cache();
					break;
				case 'optimize':
					self::render_optimize();
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

	private static function badge( string $type, string $text ): string {
		return '<span class="fpg-badge fpg-badge--' . esc_attr( $type ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function line( string $label, string $badge, string $hint = '' ): void {
		echo '<p><strong>' . esc_html( $label ) . '</strong> ' . $badge // phpcs:ignore WordPress.Security.EscapeOutput -- built by badge().
			. ( '' !== $hint ? '<span class="fpg-hint">' . esc_html( $hint ) . '</span>' : '' ) . '</p>';
	}

	private static function action_button( string $action, string $label, bool $primary = false, string $confirm = '' ): void {
		?>
		<form method="post" class="fpg-inline-form"<?php echo '' !== $confirm ? ' data-fpg-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="fpg_action" value="<?php echo esc_attr( $action ); ?>" />
			<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function size( int $bytes ): string {
		return size_format( $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 );
	}

	private static function when( int $ts ): string {
		return $ts ? wp_date( 'j/n/Y H:i', $ts ) : '—';
	}

	/* ----------------------------------------------------------------- overview */

	private static function render_overview(): void {
		$s      = FPG_Settings::get();
		$ready  = FPG_Setup::ready();
		$checks = FPG_Abilities::checks();
		$stats  = FPG_Files::stats();
		$pre    = FPG_Preload::summary();
		$st     = FPG_Setup::state();
		?>
		<h2 class="fpg-h2"><?php esc_html_e( 'Κατάσταση', 'fast-pages' ); ?></h2>
		<div class="fpg-zone fpg-status">
			<?php
			if ( ! $s['enabled'] ) {
				self::line( __( 'Page cache', 'fast-pages' ), self::badge( 'muted', __( 'κλειστή', 'fast-pages' ) ), __( 'Τίποτα δεν αλλάζει στο site μέχρι να την ενεργοποιήσεις.', 'fast-pages' ) );
			} elseif ( ! $ready ) {
				self::line( __( 'Page cache', 'fast-pages' ), self::badge( 'warn', __( 'περιμένει', 'fast-pages' ) ), __( 'Δες τους ελέγχους από κάτω.', 'fast-pages' ) );
			} elseif ( $s['test'] ) {
				self::line( __( 'Page cache', 'fast-pages' ), self::badge( 'warn', __( 'λειτουργία δοκιμής', 'fast-pages' ) ), __( 'Οι σελίδες γράφονται στην cache, αλλά οι επισκέπτες τις παίρνουν ακόμα από το WordPress. Δες τες με ?fpg-test=1.', 'fast-pages' ) );
			} else {
				self::line( __( 'Page cache', 'fast-pages' ), self::badge( 'ok', __( 'ενεργή για τους επισκέπτες', 'fast-pages' ) ) );
			}

			if ( $s['enabled'] || ! $checks['conflict'][0] ) {
				$labels = array(
					'killed'   => __( 'Σταθερά FPG_DISABLE', 'fast-pages' ),
					'conflict' => __( 'Άλλη page cache', 'fast-pages' ),
					'dropin'   => __( 'Drop-in advanced-cache.php', 'fast-pages' ),
					'wp_cache' => __( 'WP_CACHE στο wp-config.php', 'fast-pages' ),
					'folder'   => __( 'Φάκελος wp-content/cache/fast-pages', 'fast-pages' ),
				);
				$conf   = FPG_Setup::conflicts();
				foreach ( $checks as $k => $c ) {
					if ( 'killed' === $k && $c[0] ) {
						continue;
					}
					if ( ! $s['enabled'] && 'conflict' !== $k ) {
						continue;
					}
					if ( $c[0] ) {
						self::line( $labels[ $k ], self::badge( 'ok', __( 'εντάξει', 'fast-pages' ) ) );
						continue;
					}
					$hint = '';
					switch ( $k ) {
						case 'killed':
							$hint = __( 'Η cache και οι βελτιστοποιήσεις είναι σταματημένες από το wp-config.php.', 'fast-pages' );
							break;
						case 'conflict':
							/* translators: %s: plugin names */
							$hint = sprintf( __( 'Ενεργό: %s. Απενεργοποίησέ το για να ξεκινήσει η cache του Fast Pages.', 'fast-pages' ), implode( ', ', $conf ) );
							break;
						case 'dropin':
							$hint = 'foreign' === FPG_Setup::dropin()
								? __( 'Το wp-content/advanced-cache.php είναι άλλου plugin. Απενεργοποίησέ το plugin αυτό, ή σβήσε το αρχείο αν δεν χρησιμοποιείται πια.', 'fast-pages' )
								: __( 'Λείπει. Πάτα «Ενεργοποίηση» ξανά για να γραφτεί.', 'fast-pages' );
							break;
						case 'wp_cache':
							$hint = __( 'Πρόσθεσε στο wp-config.php, κάτω από το <?php:', 'fast-pages' ) . ' ' . FPG_Setup::LINE;
							break;
						case 'folder':
							$hint = __( 'Ο server δεν επιτρέπει εγγραφή εκεί. Δώσε δικαίωμα εγγραφής στον φάκελο wp-content/cache.', 'fast-pages' );
							break;
					}
					self::line( $labels[ $k ], self::badge( 'danger', __( 'πρόβλημα', 'fast-pages' ) ), $hint );
				}
			}

			$mins = FPG_Setup::minifiers();
			if ( ( $s['minify_css'] || $s['minify_js'] ) && $mins ) {
				/* translators: %s: plugin names */
				self::line( __( 'Άλλο minify', 'fast-pages' ), self::badge( 'warn', implode( ', ', $mins ) ), __( 'Κράτα το minify σε ένα μόνο plugin.', 'fast-pages' ) );
			}
			?>
			<p class="fpg-mt-8">
				<?php
				if ( ! $s['enabled'] ) {
					?>
					<form method="post" class="fpg-enable" data-fpg-confirm="<?php echo esc_attr__( 'Να ενεργοποιηθεί η page cache; Θα γραφτούν το wp-content/advanced-cache.php και η γραμμή WP_CACHE στο wp-config.php.', 'fast-pages' ); ?>">
						<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
						<input type="hidden" name="fpg_action" value="enable" />
						<label class="fpg-check"><input type="checkbox" name="fpg_agree" value="1" required /> <?php esc_html_e( 'Συμφωνώ να γραφτεί το wp-content/advanced-cache.php και, αν λείπει, η γραμμή define( \'WP_CACHE\', true ); στο wp-config.php. Και τα δύο φεύγουν όταν απενεργοποιηθεί το plugin.', 'fast-pages' ); ?></label>
						<button type="submit" class="button button-primary"<?php disabled( (bool) FPG_Setup::conflicts() || Fast_Pages::killed() ); ?>><?php esc_html_e( 'Ενεργοποίηση page cache', 'fast-pages' ); ?></button>
					</form>
					<?php
				} else {
					if ( $s['test'] ) {
						self::action_button( 'live', __( 'Έξοδος από τη λειτουργία δοκιμής', 'fast-pages' ), true, __( 'Από τώρα όλοι οι επισκέπτες θα παίρνουν τις σελίδες από την cache. Συνέχεια;', 'fast-pages' ) );
						echo ' <a class="button" href="' . esc_url( add_query_arg( FPG_Key::TEST_QS, '1', home_url( '/' ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Η αρχική όπως στην cache', 'fast-pages' ) . '</a> ';
					} else {
						self::action_button( 'test', __( 'Λειτουργία δοκιμής', 'fast-pages' ) );
					}
					self::action_button( 'purge', __( 'Καθάρισμα όλης της cache', 'fast-pages' ) );
					if ( 'running' === $pre['state'] ) {
						self::action_button( 'preload_stop', __( 'Διακοπή preload', 'fast-pages' ) );
					} elseif ( $ready ) {
						self::action_button( 'preload_start', __( 'Preload τώρα', 'fast-pages' ) );
					}
					self::action_button( 'disable', __( 'Απενεργοποίηση page cache', 'fast-pages' ), false, __( 'Να σταματήσει η page cache; Η cache σβήνεται και φεύγουν το advanced-cache.php και η γραμμή WP_CACHE που έβαλε το plugin.', 'fast-pages' ) );
				}
				?>
			</p>
		</div>

		<h2 class="fpg-h2"><?php esc_html_e( 'Αριθμοί', 'fast-pages' ); ?></h2>
		<div class="fpg-zone">
			<?php
			/* translators: 1: number of pages, 2: size */
			self::line( __( 'Σελίδες στην cache', 'fast-pages' ), self::badge( $stats['pages'] ? 'ok' : 'muted', sprintf( __( '%1$d (%2$s)', 'fast-pages' ), $stats['pages'], self::size( $stats['bytes'] ) ) ) );
			self::line( __( 'Αρχεία minify', 'fast-pages' ), self::badge( 'muted', (string) $stats['min'] ) );
			self::line(
				__( 'Διάρκεια', 'fast-pages' ),
				/* translators: %d: hours */
				self::badge( 'muted', 0 === (int) $s['life'] ? __( 'μέχρι το επόμενο καθάρισμα', 'fast-pages' ) : sprintf( _n( '%d ώρα', '%d ώρες', (int) $s['life'], 'fast-pages' ), (int) $s['life'] ) )
			);
			self::line( __( 'Τελευταίο πλήρες καθάρισμα', 'fast-pages' ), self::badge( 'muted', self::when( $st['last_purge'] ) ) );
			$states = array(
				'never'   => __( 'δεν έτρεξε ακόμα', 'fast-pages' ),
				'running' => __( 'τρέχει', 'fast-pages' ),
				'done'    => __( 'ολοκληρώθηκε', 'fast-pages' ),
				'stopped' => __( 'σταμάτησε', 'fast-pages' ),
			);
			$text   = $states[ $pre['state'] ] ?? $pre['state'];
			if ( $pre['total'] ) {
				/* translators: 1: state, 2: position, 3: total, 4: fetched, 5: already cached, 6: errors */
				$text = sprintf( __( '%1$s: %2$d / %3$d (νέες %4$d, ήδη στην cache %5$d, σφάλματα %6$d)', 'fast-pages' ), $text, $pre['pos'], $pre['total'], $pre['done'], $pre['skipped'], $pre['errors'] );
			}
			$hint = '';
			if ( ! $s['preload'] ) {
				$hint = __( 'Το αυτόματο preload είναι κλειστό (καρτέλα Cache).', 'fast-pages' );
			} elseif ( 'sitemap' === $pre['source'] ) {
				$hint = __( 'Λίστα από το sitemap.', 'fast-pages' );
			} elseif ( 'posts' === $pre['source'] ) {
				$hint = __( 'Χωρίς sitemap: λίστα από τα δημοσιευμένα άρθρα, σελίδες και προϊόντα.', 'fast-pages' );
			}
			self::line( __( 'Preload', 'fast-pages' ), self::badge( 'running' === $pre['state'] ? 'ok' : 'muted', $text ), $hint );
			?>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- forms */

	private static function form_open( string $action ): void {
		echo '<form method="post">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="fpg_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function form_close(): void {
		echo '<p class="fpg-submit"><button type="submit" class="button button-primary">' . esc_html__( 'Αποθήκευση', 'fast-pages' ) . '</button></p></form>';
	}

	private static function checkbox( string $key, string $label, string $hint = '' ): void {
		$s = FPG_Settings::get();
		printf(
			'<label class="fpg-check"><input type="checkbox" name="fpg[%1$s]" value="1"%2$s /> %3$s</label>',
			esc_attr( $key ),
			checked( (bool) $s[ $key ], true, false ),
			esc_html( $label )
		);
		if ( '' !== $hint ) {
			echo '<p class="fpg-hint fpg-hint--under">' . esc_html( $hint ) . '</p>';
		}
	}

	private static function textarea( string $key, string $label, string $hint, string $placeholder = '' ): void {
		$s = FPG_Settings::get();
		echo '<label class="fpg-label" for="fpg_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<textarea class="large-text code" rows="4" id="fpg_' . esc_attr( $key ) . '" name="fpg[' . esc_attr( $key ) . ']" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( (string) $s[ $key ] ) . '</textarea>';
		echo '<p class="fpg-hint fpg-hint--under">' . esc_html( $hint ) . '</p>';
	}

	private static function select( string $key, string $label, array $options, string $hint = '' ): void {
		$s = FPG_Settings::get();
		echo '<label class="fpg-label" for="fpg_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="fpg_' . esc_attr( $key ) . '" name="fpg[' . esc_attr( $key ) . ']">';
		foreach ( $options as $v => $l ) {
			echo '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $s[ $key ], (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		if ( '' !== $hint ) {
			echo '<p class="fpg-hint fpg-hint--under">' . esc_html( $hint ) . '</p>';
		}
	}

	private static function render_cache(): void {
		$s     = FPG_Settings::get();
		$lives = array();
		foreach ( FPG_Settings::LIVES as $h ) {
			/* translators: %d: hours */
			$lives[ $h ] = 0 === $h ? __( 'Μέχρι το επόμενο καθάρισμα', 'fast-pages' ) : sprintf( _n( '%d ώρα', '%d ώρες', $h, 'fast-pages' ), $h );
		}
		$rates = array();
		foreach ( FPG_Settings::RATES as $r ) {
			/* translators: %d: pages per minute */
			$rates[ $r ] = sprintf( __( '%d σελίδες το λεπτό', 'fast-pages' ), $r );
		}
		self::form_open( 'save_cache' );
		?>
		<h2 class="fpg-h2"><?php esc_html_e( 'Page cache', 'fast-pages' ); ?></h2>
		<div class="fpg-zone">
			<?php
			self::select( 'life', __( 'Διάρκεια μιας σελίδας στην cache', 'fast-pages' ), $lives, __( 'Μετά ξαναφτιάχνεται. Έως 12 ώρες, ώστε οι φόρμες και τα κουμπιά (nonces) να μη μένουν παλιά.', 'fast-pages' ) );
			self::checkbox( 'mobile', __( 'Ξεχωριστή cache για κινητά', 'fast-pages' ), __( 'Μόνο αν το θέμα ή κάποιο plugin δείχνει άλλο HTML στα κινητά (wp_is_mobile). Τα responsive θέματα δεν το χρειάζονται.', 'fast-pages' ) );
			self::textarea( 'exclude_urls', __( 'Σελίδες που δεν μπαίνουν ποτέ στην cache', 'fast-pages' ), __( 'Μία ανά γραμμή, * = οτιδήποτε. Το καλάθι, το ταμείο, ο λογαριασμός, το admin, τα feeds, οι αναζητήσεις και οι διευθύνσεις με παραμέτρους μένουν πάντα έξω.', 'fast-pages' ), "/contact/\n/events/*" );
			self::textarea( 'exclude_cookies', __( 'Cookies που σημαίνουν προσωπική σελίδα', 'fast-pages' ), __( 'Ένα όνομα (ή αρχή ονόματος) ανά γραμμή. Οι συνδεδεμένοι χρήστες και όσοι έχουν καλάθι ή σχόλιο σε αναμονή μένουν πάντα έξω.', 'fast-pages' ) );
			?>
		</div>

		<h2 class="fpg-h2"><?php esc_html_e( 'Preload', 'fast-pages' ); ?></h2>
		<div class="fpg-zone">
			<?php
			self::checkbox( 'preload', __( 'Γέμισμα της cache στο παρασκήνιο', 'fast-pages' ), __( 'Από το sitemap (Rank Math, Yoast ή του WordPress), μετά από κάθε πλήρες καθάρισμα και μία φορά ανά διάρκεια. Οι σελίδες που καθαρίστηκαν μπαίνουν πρώτες.', 'fast-pages' ) );
			self::select( 'rate', __( 'Ρυθμός', 'fast-pages' ), $rates, __( 'Χαμηλά για μικρούς servers.', 'fast-pages' ) );
			echo '<label class="fpg-label" for="fpg_preload_max">' . esc_html__( 'Το πολύ σελίδες ανά preload', 'fast-pages' ) . '</label>';
			echo '<input type="number" min="50" max="10000" step="50" id="fpg_preload_max" name="fpg[preload_max]" value="' . esc_attr( (string) $s['preload_max'] ) . '" />';
			self::textarea( 'preload_exclude', __( 'Σελίδες χωρίς preload', 'fast-pages' ), __( 'Μία ανά γραμμή, * = οτιδήποτε. Μπαίνουν στην cache μόνο όταν τις ανοίξει επισκέπτης.', 'fast-pages' ), '/product-tag/*' );
			?>
		</div>
		<?php
		self::form_close();
	}

	private static function render_optimize(): void {
		self::form_open( 'save_optimize' );
		?>
		<p class="fpg-hint"><?php esc_html_e( 'Ισχύουν μόνο στις σελίδες που γράφονται στην cache, μία φορά, όταν γράφονται. Μετά από αλλαγή η cache καθαρίζεται.', 'fast-pages' ); ?></p>
		<h2 class="fpg-h2"><?php esc_html_e( 'Minify', 'fast-pages' ); ?></h2>
		<div class="fpg-zone">
			<?php
			self::checkbox( 'minify_css', __( 'Minify των αρχείων CSS του site', 'fast-pages' ), __( 'Ένα μικρότερο αντίγραφο ανά αρχείο, χωρίς ένωση αρχείων. Τα .min.css μένουν ως έχουν.', 'fast-pages' ) );
			self::checkbox( 'minify_js', __( 'Minify των αρχείων JavaScript του site', 'fast-pages' ), __( 'Όπως το CSS. Αρχεία που φορτώνουν άλλα αρχεία από τον φάκελό τους (webpack, modules) μένουν ως έχουν.', 'fast-pages' ) );
			self::textarea( 'minify_exclude', __( 'Αρχεία χωρίς minify', 'fast-pages' ), __( 'Ένα κομμάτι της διεύθυνσης ανά γραμμή, π.χ. όνομα αρχείου ή φακέλου plugin.', 'fast-pages' ), "/plugins/some-plugin/\nslider.js" );
			?>
		</div>

		<h2 class="fpg-h2"><?php esc_html_e( 'Lazy load', 'fast-pages' ); ?></h2>
		<div class="fpg-zone">
			<?php
			self::checkbox( 'lazy_img', __( 'Εικόνες: loading="lazy" όπου λείπει', 'fast-pages' ), __( 'Ο browser φορτώνει τις εικόνες όταν πλησιάζουν στην οθόνη. Το WordPress το κάνει ήδη στο περιεχόμενο· αυτό καλύπτει θέμα και plugins.', 'fast-pages' ) );
			self::select(
				'lazy_skip',
				__( 'Οι πρώτες εικόνες της σελίδας φορτώνουν αμέσως', 'fast-pages' ),
				array_combine( range( 0, 10 ), array_map( 'strval', range( 0, 10 ) ) ),
				__( 'Λογότυπο και πρώτη εικόνα (LCP) δεν πρέπει να περιμένουν.', 'fast-pages' )
			);
			self::checkbox( 'lazy_iframe', __( 'Iframes: loading="lazy" όπου λείπει', 'fast-pages' ) );
			self::checkbox( 'lazy_bg', __( 'Εικόνες φόντου (inline style) όταν πλησιάζουν στην οθόνη', 'fast-pages' ), __( 'Μικρό script μόνο στις σελίδες που έχουν τέτοιο φόντο. Τα φόντα από αρχεία CSS δεν αλλάζουν.', 'fast-pages' ) );
			self::textarea( 'lazy_exclude', __( 'Χωρίς lazy load', 'fast-pages' ), __( 'Ένα κομμάτι του src ή του class ανά γραμμή. Το class skip-lazy ή no-lazy κάνει το ίδιο.', 'fast-pages' ), 'logo' );
			?>
		</div>

		<h2 class="fpg-h2"><?php esc_html_e( 'Βίντεο, διαστάσεις, hover', 'fast-pages' ); ?></h2>
		<div class="fpg-zone">
			<?php
			self::checkbox( 'youtube', __( 'YouTube ως εικόνα με κουμπί', 'fast-pages' ), __( 'Ο player (και τα cookies του YouTube) φορτώνει μόνο με το κλικ, από το youtube-nocookie.com.', 'fast-pages' ) );
			self::checkbox( 'dims', __( 'Διαστάσεις (width / height) στις εικόνες του site που δεν τις έχουν', 'fast-pages' ), __( 'Η σελίδα δεν «πηδάει» όσο φορτώνουν (CLS).', 'fast-pages' ) );
			self::select(
				'hover',
				__( 'Preload στο hover', 'fast-pages' ),
				array(
					'core'      => __( 'Όπως το WordPress (προεπιλογή)', 'fast-pages' ),
					'prefetch'  => __( 'Prefetch στο hover', 'fast-pages' ),
					'prerender' => __( 'Prerender στο hover', 'fast-pages' ),
					'off'       => __( 'Κλειστό', 'fast-pages' ),
				),
				__( 'Με το speculative loading του WordPress 6.8+. Το καλάθι, το ταμείο και ο λογαριασμός μένουν πάντα έξω.', 'fast-pages' )
			);
			?>
		</div>
		<?php
		self::form_close();
	}

	private static function render_tools(): void {
		$r      = FPG_Tools::rocket();
		$counts = FPG_Tools::db_counts();
		$st     = FPG_Setup::state();
		?>
		<h2 class="fpg-h2"><?php esc_html_e( 'Ρυθμίσεις από το WP Rocket', 'fast-pages' ); ?></h2>
		<div class="fpg-zone">
			<?php if ( ! $r ) : ?>
				<p class="fpg-hint"><?php esc_html_e( 'Δεν βρέθηκαν ρυθμίσεις του WP Rocket σε αυτό το site.', 'fast-pages' ); ?></p>
			<?php else : ?>
				<p class="fpg-hint"><?php esc_html_e( 'Διαβάζει μόνο τις ρυθμίσεις του WP Rocket (δεν τις αλλάζει) και αντιγράφει minify, lazy load, YouTube, διαστάσεις, preload και εξαιρέσεις.', 'fast-pages' ); ?></p>
				<?php if ( $st['imported'] ) : ?>
					<?php /* translators: %s: date */ ?>
					<p class="fpg-hint"><?php echo esc_html( sprintf( __( 'Τελευταία εισαγωγή: %s', 'fast-pages' ), self::when( $st['imported'] ) ) ); ?></p>
				<?php endif; ?>
				<?php self::action_button( 'import_rocket', __( 'Εισαγωγή από το WP Rocket', 'fast-pages' ), true ); ?>
			<?php endif; ?>
		</div>

		<h2 class="fpg-h2"><?php esc_html_e( 'Καθαρισμός βάσης', 'fast-pages' ); ?></h2>
		<div class="fpg-zone">
			<?php
			/* translators: 1: revisions, 2: expired transients */
			echo '<p>' . esc_html( sprintf( __( 'Αναθεωρήσεις άρθρων: %1$d · Ληγμένα transients: %2$d', 'fast-pages' ), $counts['revisions'], $counts['transients'] ) ) . '</p>';
			?>
			<p class="fpg-hint"><?php esc_html_e( 'Σβήνει τις αναθεωρήσεις (έως 500 κάθε φορά) και τα ληγμένα transients. Δεν αναιρείται: πάρε πρώτα backup.', 'fast-pages' ); ?></p>
			<?php
			if ( $counts['revisions'] || $counts['transients'] ) {
				self::action_button( 'db_cleanup', __( 'Καθαρισμός τώρα', 'fast-pages' ), false, __( 'Να σβηστούν οι αναθεωρήσεις και τα ληγμένα transients; Δεν αναιρείται.', 'fast-pages' ) );
			}
			?>
		</div>
		<?php
		if ( ! FPG_Lang::rs_active() ) {
			$cur = (string) get_user_meta( get_current_user_id(), 'rs_lang', true );
			self::form_open( 'save_lang' );
			echo '<h2 class="fpg-h2">' . esc_html__( 'Γλώσσα', 'fast-pages' ) . '</h2><div class="fpg-zone">';
			foreach ( array(
				''   => __( 'Όπως η γλώσσα του λογαριασμού', 'fast-pages' ),
				'el' => 'Ελληνικά',
				'en' => 'English',
			) as $k => $l ) {
				echo '<label class="fpg-inline"><input type="radio" name="fpg_lang" value="' . esc_attr( $k ) . '"' . checked( in_array( $cur, array( 'el', 'en' ), true ) ? $cur : '', $k, false ) . '> ' . esc_html( $l ) . '</label>';
			}
			echo '<p class="fpg-hint fpg-hint--under">' . esc_html__( 'Ισχύει για όλα τα plugins της σουίτας Noxpress.', 'fast-pages' ) . '</p></div>';
			self::form_close();
		}
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( FPG_Settings::PAGE !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['fpg_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Fast_Pages::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'fast-pages' ) );
		}
		$tab = self::current_tab();
		switch ( sanitize_key( wp_unslash( $_POST['fpg_action'] ) ) ) {
			case 'enable':
				if ( '1' !== self::post( 'fpg_agree' ) ) {
					self::redirect( array( self::msg( 'error', __( 'Τσέκαρε πρώτα ότι συμφωνείς.', 'fast-pages' ) ) ), $tab );
				}
				list( $ok, $problems ) = FPG_Setup::enable();
				$msgs                  = array();
				if ( $ok ) {
					$msgs[] = self::msg( 'success', __( 'Η page cache ενεργοποιήθηκε σε λειτουργία δοκιμής. Δες τις σελίδες με ?fpg-test=1 και μετά βγες από τη λειτουργία δοκιμής.', 'fast-pages' ) );
				}
				foreach ( $problems as $p ) {
					$msgs[] = self::msg( $ok ? 'warning' : 'error', self::problem( $p ) );
				}
				self::redirect( $msgs, $tab );
				break;
			case 'disable':
				$problems = FPG_Setup::disable();
				$msgs     = array( self::msg( 'success', __( 'Η page cache σταμάτησε και η cache σβήστηκε.', 'fast-pages' ) ) );
				foreach ( $problems as $p ) {
					$msgs[] = self::msg( 'warning', self::problem( $p ) . ' ' . __( 'Σβήσε με το χέρι τη γραμμή:', 'fast-pages' ) . ' ' . FPG_Setup::LINE );
				}
				self::redirect( $msgs, $tab );
				break;
			case 'live':
				FPG_Settings::update( array( 'test' => false ) );
				FPG_Preload::after_purge();
				self::redirect( array( self::msg( 'success', __( 'Η cache απαντά τώρα σε όλους τους επισκέπτες.', 'fast-pages' ) ) ), $tab );
				break;
			case 'test':
				FPG_Settings::update( array( 'test' => true ) );
				self::redirect( array( self::msg( 'success', __( 'Λειτουργία δοκιμής: οι επισκέπτες παίρνουν τις σελίδες από το WordPress.', 'fast-pages' ) ) ), $tab );
				break;
			case 'purge':
				/* translators: %d: pages */
				self::redirect( array( self::msg( 'success', sprintf( __( 'Η cache καθαρίστηκε (%d σελίδες).', 'fast-pages' ), FPG_Purge::all() ) ) ), $tab );
				break;
			case 'preload_start':
				$ok = FPG_Preload::start();
				self::redirect( array( $ok ? self::msg( 'success', __( 'Το preload ξεκίνησε στο παρασκήνιο.', 'fast-pages' ) ) : self::msg( 'error', __( 'Η page cache δεν είναι έτοιμη.', 'fast-pages' ) ) ), $tab );
				break;
			case 'preload_stop':
				FPG_Preload::stop();
				self::redirect( array( self::msg( 'success', __( 'Το preload σταμάτησε.', 'fast-pages' ) ) ), $tab );
				break;
			case 'save_cache':
				self::save_keys( array( 'life', 'mobile', 'exclude_urls', 'exclude_cookies', 'preload', 'rate', 'preload_max', 'preload_exclude' ) );
				FPG_Purge::all();
				self::redirect( array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν και η cache καθαρίστηκε.', 'fast-pages' ) ) ), $tab );
				break;
			case 'save_optimize':
				self::save_keys( array( 'minify_css', 'minify_js', 'minify_exclude', 'lazy_img', 'lazy_skip', 'lazy_iframe', 'lazy_bg', 'lazy_exclude', 'youtube', 'dims', 'hover' ) );
				FPG_Purge::all();
				self::redirect( array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν και η cache καθαρίστηκε.', 'fast-pages' ) ) ), $tab );
				break;
			case 'import_rocket':
				list( , $notes ) = FPG_Tools::import_rocket();
				FPG_Purge::all();
				$msgs = array();
				foreach ( $notes as $i => $n ) {
					$msgs[] = self::msg( 0 === $i ? 'success' : 'info', $n );
				}
				self::redirect( $msgs, $tab );
				break;
			case 'db_cleanup':
				$r = FPG_Tools::db_cleanup();
				/* translators: 1: revisions before, 2: after, 3: transients before, 4: after */
				self::redirect( array( self::msg( 'success', sprintf( __( 'Αναθεωρήσεις: %1$d → %2$d · Ληγμένα transients: %3$d → %4$d', 'fast-pages' ), $r['before']['revisions'], $r['after']['revisions'], $r['before']['transients'], $r['after']['transients'] ) ) ), $tab );
				break;
			case 'save_lang':
				if ( ! FPG_Lang::rs_active() ) {
					$lang = self::post( 'fpg_lang' );
					if ( in_array( $lang, array( 'el', 'en' ), true ) ) {
						update_user_meta( get_current_user_id(), 'rs_lang', $lang );
					} else {
						delete_user_meta( get_current_user_id(), 'rs_lang' );
					}
					FPG_Lang::reset();
				}
				self::redirect( array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'fast-pages' ) ) ), $tab );
				break;
			default:
				self::redirect( array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'fast-pages' ) ) ), $tab );
		}
	}

	/** Saves the posted keys of one tab (unchecked boxes = false). */
	private static function save_keys( array $keys ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); FPG_Settings::sanitize() validates every key.
		$raw = isset( $_POST['fpg'] ) && is_array( $_POST['fpg'] ) ? wp_unslash( $_POST['fpg'] ) : array();
		$in  = array();
		foreach ( $keys as $k ) {
			if ( in_array( $k, FPG_Settings::bools(), true ) ) {
				$in[ $k ] = isset( $raw[ $k ] ) && '1' === (string) $raw[ $k ];
			} elseif ( isset( $raw[ $k ] ) && is_scalar( $raw[ $k ] ) ) {
				$in[ $k ] = (string) $raw[ $k ];
			}
		}
		FPG_Settings::update( $in );
	}

	/** Text of a setup problem code. */
	private static function problem( string $code ): string {
		$map = array(
			'killed'              => __( 'Η σταθερά FPG_DISABLE είναι ενεργή στο wp-config.php.', 'fast-pages' ),
			'conflict'            => __( 'Είναι ενεργή άλλη page cache: απενεργοποίησέ την πρώτα.', 'fast-pages' ),
			'foreign'             => __( 'Το wp-content/advanced-cache.php είναι άλλου plugin και δεν το αγγίζουμε.', 'fast-pages' ),
			'folder'              => __( 'Δεν γράφεται ο φάκελος wp-content/cache/fast-pages.', 'fast-pages' ),
			'dropin'              => __( 'Δεν γράφτηκε το wp-content/advanced-cache.php (δικαιώματα;).', 'fast-pages' ),
			'wpconfig_missing'    => __( 'Δεν βρέθηκε το wp-config.php.', 'fast-pages' ),
			'wpconfig_readonly'   => __( 'Το wp-config.php δεν γράφεται από τον server.', 'fast-pages' ),
			'wpconfig_unreadable' => __( 'Το wp-config.php δεν διαβάζεται.', 'fast-pages' ),
			'wpconfig_unknown'    => __( 'Το wp-config.php έχει μορφή που δεν αλλάζουμε αυτόματα.', 'fast-pages' ),
			'wpconfig_parse'      => __( 'Η αλλαγή στο wp-config.php δεν θα ήταν έγκυρη PHP, οπότε δεν έγινε.', 'fast-pages' ),
			'wpconfig_write'      => __( 'Η εγγραφή στο wp-config.php απέτυχε και το αρχείο έμεινε όπως ήταν.', 'fast-pages' ),
		);
		$text = $map[ $code ] ?? $code;
		if ( 0 === strpos( $code, 'wpconfig_' ) && FPG_Settings::get()['enabled'] ) {
			$text .= ' ' . __( 'Πρόσθεσε με το χέρι, κάτω από το <?php:', 'fast-pages' ) . ' ' . FPG_Setup::LINE;
		}
		return $text;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( array $msgs, string $tab ): void {
		set_transient( 'fpg_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . FPG_Settings::PAGE . ( 'overview' === $tab ? '' : '&tab=' . $tab ) ) );
		exit;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'fpg_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'fpg_aui_msg_' . get_current_user_id() );
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
		<p class="fpg-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'fast-pages' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="fpg-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="fpg-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'fast-pages' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="fpg-footer-cta">
			<a class="fpg-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'fast-pages' ); ?>
			</a>
			<a class="fpg-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'fast-pages' ); ?>
			</a>
		</p>
		<?php
	}
}
