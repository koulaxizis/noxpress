<?php
/**
 * LIM_Admin_UI — menu, settings page, media column (Bible §3–§8, §11).
 *
 * Submenu «Lean Images» of the shared "Noxpress" menu (admin_menu
 * priority 310), slug lim-settings, capability manage_options. Tabs:
 *  - overview  status, progress, space, last batch, start / pause, one
 *              batch now, test mode
 *  - settings  formats, quality, threshold, batch size, budget, night,
 *              whole page
 *  - tools     sample test, retry failed, one attachment, orphans,
 *              delete all, language
 * A «Lean Images» column in the media library (list view).
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient lim_aui_msg_{uid}).
 */

defined( 'ABSPATH' ) || exit;

final class LIM_Admin_UI {

	const NONCE       = 'lim_admin';
	const NONCE_FIELD = 'lim_nonce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 310 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LIM_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'manage_media_columns', array( __CLASS__, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'media_cell' ), 10, 2 );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'overview' => __( 'Επισκόπηση', 'lean-images' ),
			'settings' => __( 'Ρυθμίσεις', 'lean-images' ),
			'tools'    => __( 'Εργαλεία', 'lean-images' ),
		);
	}

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			'noxpress',
			__( 'Lean Images', 'lean-images' ),
			__( 'Lean Images', 'lean-images' ),
			Lean_Images::CAP,
			LIM_Settings::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . LIM_Settings::PAGE ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'lean-images' ) . '</a>'
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

	public static function assets( $hook = '' ): void {
		if ( LIM_Settings::PAGE !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'lim-admin', LIM_URL . 'assets/admin.css', array(), LIM_VERSION );
		wp_enqueue_script( 'lim-admin', LIM_URL . 'assets/admin.js', array(), LIM_VERSION, true );
	}

	/** Imagify still serves its images: say so on the plugins screen and our page. */
	public static function notices(): void {
		if ( ! current_user_can( Lean_Images::CAP ) || ! LIM_Tools::imagify_active() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( LIM_Settings::PAGE !== self::current_page() && ( ! $screen || 'plugins' !== $screen->id ) ) {
			return;
		}
		$text = LIM_Tools::imagify_serves()
			? __( 'Lean Images: το Imagify είναι ενεργό και δείχνει τις δικές του εικόνες WebP, οπότε το Lean Images περιμένει. Τα WebP του Imagify αναγνωρίζονται χωρίς νέα μετατροπή. Όταν η μετατροπή τελειώσει, απενεργοποίησε το Imagify.', 'lean-images' )
			: __( 'Lean Images: το Imagify είναι ακόμα ενεργό. Απενεργοποίησέ το, ώστε οι εικόνες να μη μετατρέπονται δύο φορές.', 'lean-images' );
		echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . '</p></div>';
	}

	/* =====================================================================
	 * Media library column
	 * =================================================================== */

	public static function media_column( $cols ) {
		$cols        = is_array( $cols ) ? $cols : array();
		$cols['lim'] = __( 'Lean Images', 'lean-images' );
		return $cols;
	}

	public static function media_cell( $col, $id ): void {
		if ( 'lim' !== $col ) {
			return;
		}
		$id    = (int) $id;
		$state = (string) get_post_meta( $id, LIM_Files::STATE, true );
		if ( ! in_array( get_post_mime_type( $id ), LIM_Files::MIMES, true ) ) {
			echo '—';
			return;
		}
		if ( 'failed' === $state || 'retry' === $state ) {
			$rec = LIM_Files::record( $id );
			echo '<span title="' . esc_attr( isset( $rec['err'] ) ? (string) $rec['err'] : '' ) . '">' . esc_html__( 'σφάλμα', 'lean-images' ) . '</span>';
			return;
		}
		if ( 'done' !== $state ) {
			esc_html_e( 'σε αναμονή', 'lean-images' );
			return;
		}
		$rec = LIM_Files::record( $id );
		$out = array();
		foreach ( LIM_Files::FORMATS as $fmt ) {
			$src = 0;
			$dst = 0;
			foreach ( isset( $rec['f'] ) && is_array( $rec['f'] ) ? $rec['f'] : array() as $f ) {
				if ( is_array( $f ) && ! empty( $f[ $fmt ] ) ) {
					$src += (int) $f['s'];
					$dst += (int) $f[ $fmt ];
				}
			}
			if ( $src > 0 ) {
				$out[] = strtoupper( $fmt ) . ' −' . round( 100 - 100 * $dst / $src ) . '%';
			}
		}
		echo esc_html( $out ? implode( ' · ', $out ) : __( 'χωρίς κέρδος', 'lean-images' ) );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Lean_Images::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'lean-images' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap lim-wrap">
			<h1><?php esc_html_e( 'Lean Images', 'lean-images' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Οι εικόνες JPEG και PNG γίνονται WebP (και προαιρετικά AVIF) στον δικό σου server, σε μικρές παρτίδες στο παρασκήνιο. Τα πρωτότυπα δεν αλλάζουν ποτέ, και οι browsers που δεν ξέρουν WebP παίρνουν το πρωτότυπο.', 'lean-images' ); ?></p>

			<nav class="nav-tab-wrapper lim-tabs">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . LIM_Settings::PAGE . ( 'overview' === $slug ? '' : '&tab=' . $slug ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php self::print_notices( self::take_msgs() ); ?>

			<?php
			switch ( $tab ) {
				case 'settings':
					self::render_settings();
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
		return '<span class="lim-badge lim-badge--' . esc_attr( $type ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function line( string $label, string $badge, string $hint = '' ): void {
		echo '<p><strong>' . esc_html( $label ) . '</strong> ' . $badge // phpcs:ignore WordPress.Security.EscapeOutput -- built by badge().
			. ( '' !== $hint ? '<span class="lim-hint">' . esc_html( $hint ) . '</span>' : '' ) . '</p>';
	}

	private static function action_button( string $action, string $label, bool $primary = false, string $confirm = '', array $fields = array() ): void {
		?>
		<form method="post" class="lim-inline-form"<?php echo '' !== $confirm ? ' data-lim-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="lim_action" value="<?php echo esc_attr( $action ); ?>" />
			<?php foreach ( $fields as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>" />
			<?php endforeach; ?>
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

	/** Text of why a batch stopped. */
	private static function why( string $code ): string {
		$map = array(
			'empty'       => __( 'δεν υπήρχαν εικόνες σε αναμονή', 'lean-images' ),
			'size'        => __( 'έφτασε το μέγεθος της παρτίδας', 'lean-images' ),
			'time'        => __( 'έφτασε το όριο χρόνου', 'lean-images' ),
			'memory'      => __( 'η μνήμη πλησίασε το όριο', 'lean-images' ),
			'load'        => __( 'υψηλό φορτίο στον server', 'lean-images' ),
			'unsupported' => __( 'ο server δεν γράφει τις μορφές που επέλεξες', 'lean-images' ),
			'error'       => __( 'σφάλμα', 'lean-images' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : $code;
	}

	/* ----------------------------------------------------------------- overview */

	private static function render_overview(): void {
		$s     = LIM_Settings::get();
		$c     = LIM_Queue::counts();
		$stats = LIM_Queue::stats();
		$last  = get_option( LIM_Queue::LAST, array() );
		$last  = is_array( $last ) ? $last : array();
		?>
		<h2 class="lim-h2"><?php esc_html_e( 'Κατάσταση', 'lean-images' ); ?></h2>
		<div class="lim-zone lim-status">
			<?php
			if ( Lean_Images::killed() ) {
				self::line( __( 'Σταθερά LIM_DISABLE', 'lean-images' ), self::badge( 'warn', __( 'ενεργή', 'lean-images' ) ), __( 'Η μετατροπή και το σερβίρισμα είναι σταματημένα από το wp-config.php.', 'lean-images' ) );
			}

			if ( ! $s['running'] && 0 === $c['done'] ) {
				self::line( __( 'Μετατροπή', 'lean-images' ), self::badge( 'muted', __( 'δεν ξεκίνησε', 'lean-images' ) ), __( 'Τίποτα δεν αλλάζει μέχρι να πατήσεις «Έναρξη».', 'lean-images' ) );
			} elseif ( ! $s['running'] ) {
				self::line( __( 'Μετατροπή', 'lean-images' ), self::badge( 'warn', __( 'σε παύση', 'lean-images' ) ) );
			} elseif ( $s['night'] ) {
				self::line( __( 'Μετατροπή', 'lean-images' ), self::badge( 'ok', __( 'τρέχει τη νύχτα', 'lean-images' ) ), __( 'Μία παρτίδα κάθε 5 λεπτά, 01:00–07:00 ώρα του site.', 'lean-images' ) );
			} else {
				self::line( __( 'Μετατροπή', 'lean-images' ), self::badge( 'ok', __( 'τρέχει', 'lean-images' ) ), __( 'Μία παρτίδα κάθε 5 λεπτά στο παρασκήνιο.', 'lean-images' ) );
			}

			if ( LIM_Tools::imagify_serves() ) {
				self::line( __( 'Σερβίρισμα', 'lean-images' ), self::badge( 'warn', __( 'περιμένει το Imagify', 'lean-images' ) ), __( 'Το Imagify δείχνει ακόμα τις δικές του εικόνες. Απενεργοποίησέ το όταν είσαι έτοιμος.', 'lean-images' ) );
			} elseif ( $s['test'] ) {
				self::line( __( 'Σερβίρισμα', 'lean-images' ), self::badge( 'warn', __( 'λειτουργία δοκιμής', 'lean-images' ) ), __( 'Μόνο οι διαχειριστές βλέπουν τα WebP. Οι επισκέπτες παίρνουν ακόμα τα πρωτότυπα.', 'lean-images' ) );
			} else {
				self::line( __( 'Σερβίρισμα', 'lean-images' ), self::badge( 'ok', __( 'ενεργό για όλους', 'lean-images' ) ) );
			}

			$editor = LIM_Convert::editor();
			$fmts   = array();
			foreach ( LIM_Files::FORMATS as $fmt ) {
				$fmts[] = strtoupper( $fmt ) . ' ' . ( LIM_Convert::supports( $fmt ) ? '✓' : '✗' );
			}
			self::line(
				__( 'Server', 'lean-images' ),
				self::badge( LIM_Convert::supports( 'webp' ) ? 'ok' : 'danger', '' !== $editor ? str_replace( 'WP_Image_Editor_', '', $editor ) : __( 'κανένας επεξεργαστής εικόνων', 'lean-images' ) ),
				implode( ' · ', $fmts )
			);
			?>
			<p class="lim-actions">
				<?php
				if ( ! Lean_Images::killed() ) {
					if ( $s['running'] ) {
						self::action_button( 'pause', __( 'Παύση', 'lean-images' ) );
					} else {
						self::action_button( 'start', 0 === $c['done'] ? __( 'Έναρξη', 'lean-images' ) : __( 'Συνέχεια', 'lean-images' ), true );
					}
					if ( $c['waiting'] > 0 ) {
						self::action_button( 'batch', __( 'Μία παρτίδα τώρα', 'lean-images' ) );
					}
				}
				if ( $s['test'] ) {
					self::action_button( 'leave_test', __( 'Έξοδος από τη λειτουργία δοκιμής', 'lean-images' ), false, __( 'Από τώρα όλοι οι επισκέπτες θα παίρνουν τα WebP. Συνέχεια;', 'lean-images' ) );
				} else {
					self::action_button( 'enter_test', __( 'Λειτουργία δοκιμής', 'lean-images' ) );
				}
				?>
			</p>
		</div>

		<h2 class="lim-h2"><?php esc_html_e( 'Αριθμοί', 'lean-images' ); ?></h2>
		<div class="lim-zone">
			<table class="widefat lim-table">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Εικόνες JPEG / PNG', 'lean-images' ); ?></th>
						<td>
							<?php
							/* translators: 1: done, 2: total */
							echo esc_html( sprintf( __( '%1$d από %2$d έτοιμες', 'lean-images' ), $c['done'], $c['total'] ) );
							?>
							<?php if ( $c['total'] > 0 ) : ?>
								<span class="lim-bar"><span style="width:<?php echo esc_attr( (string) round( 100 * $c['done'] / $c['total'] ) ); ?>%"></span></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Σε αναμονή', 'lean-images' ); ?></th>
						<td>
							<?php
							echo esc_html( (string) $c['waiting'] );
							$eta = self::eta( $c['waiting'], $s );
							if ( $c['waiting'] > 0 && '' !== $eta ) {
								echo ' <span class="lim-muted">(' . esc_html( $eta ) . ')</span>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Σφάλματα', 'lean-images' ); ?></th>
						<td><?php echo esc_html( (string) $c['failed'] ); ?></td>
					</tr>
					<?php foreach ( LIM_Files::FORMATS as $fmt ) : ?>
						<?php
						if ( 0 === $stats[ $fmt ] ) {
							continue;
						}
						$src = $stats[ 'src_' . $fmt ];
						?>
						<tr>
							<th><?php echo esc_html( strtoupper( $fmt ) ); ?></th>
							<td>
								<?php
								/* translators: 1: source size, 2: sidecar size, 3: percent smaller */
								echo esc_html( sprintf( __( '%1$s → %2$s (−%3$s%%)', 'lean-images' ), self::size( $src ), self::size( $stats[ $fmt ] ), $src > 0 ? (string) round( 100 - 100 * $stats[ $fmt ] / $src ) : '0' ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th><?php esc_html_e( 'Επιπλέον χώρος στον δίσκο', 'lean-images' ); ?></th>
						<td>
							<?php
							/* translators: 1: size, 2: number of files */
							echo esc_html( sprintf( __( '%1$s σε %2$d αρχεία', 'lean-images' ), self::size( $stats['webp'] + $stats['avif'] ), $stats['files'] ) );
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Τελευταία παρτίδα', 'lean-images' ); ?></th>
						<td>
							<?php
							if ( empty( $last['t'] ) ) {
								esc_html_e( 'δεν έτρεξε ακόμα', 'lean-images' );
							} else {
								/* translators: 1: date, 2: images done, 3: seconds, 4: why it stopped */
								echo esc_html( sprintf( __( '%1$s · %2$d εικόνες σε %3$s s · %4$s', 'lean-images' ), self::when( (int) $last['t'] ), (int) $last['done'], (string) $last['secs'], self::why( (string) $last['why'] ) ) );
								if ( ! empty( $last['err'] ) ) {
									echo '<br><span class="lim-muted">' . esc_html( (string) $last['err'] ) . '</span>';
								}
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Rough time left, from the batch size and the 5-minute rhythm. */
	private static function eta( int $waiting, array $s ): string {
		$per_hour = 12 * max( 1, (int) $s['batch'] );
		$hours    = $waiting / $per_hour;
		if ( $s['night'] ) {
			// Six hours a night.
			$days = (int) ceil( $hours / 6 );
			/* translators: %d: nights */
			return sprintf( _n( 'περίπου %d νύχτα', 'περίπου %d νύχτες', $days, 'lean-images' ), $days );
		}
		if ( $hours < 1 ) {
			return __( 'λιγότερο από μία ώρα', 'lean-images' );
		}
		$h = (int) ceil( $hours );
		/* translators: %d: hours */
		return sprintf( _n( 'περίπου %d ώρα', 'περίπου %d ώρες', $h, 'lean-images' ), $h );
	}

	/* ----------------------------------------------------------------- settings */

	private static function render_settings(): void {
		$s = LIM_Settings::get();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="lim_action" value="save_settings" />

			<h2 class="lim-h2"><?php esc_html_e( 'Μορφές', 'lean-images' ); ?></h2>
			<div class="lim-zone">
				<table class="form-table lim-form" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'WebP', 'lean-images' ); ?></th>
						<td>
							<label><input type="checkbox" name="lim[webp]" value="1" <?php checked( $s['webp'] ); ?> /> <?php esc_html_e( 'Φτιάξε και σέρβιρε WebP', 'lean-images' ); ?></label>
							<p class="description"><?php esc_html_e( 'Το δέχονται σχεδόν όλοι οι browsers. Ποιότητα:', 'lean-images' ); ?> <input type="number" class="small-text" name="lim[q_webp]" min="50" max="100" value="<?php echo esc_attr( (string) $s['q_webp'] ); ?>" /></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AVIF', 'lean-images' ); ?></th>
						<td>
							<label><input type="checkbox" name="lim[avif]" value="1" <?php checked( $s['avif'] ); ?> <?php disabled( ! LIM_Convert::supports( 'avif' ) && ! $s['avif'] ); ?> /> <?php esc_html_e( 'Φτιάξε και σέρβιρε και AVIF', 'lean-images' ); ?></label>
							<p class="description">
								<?php
								echo esc_html(
									LIM_Convert::supports( 'avif' )
										? __( 'Περίπου 20% μικρότερο από το WebP, αλλά θέλει πολλαπλάσια CPU και επιπλέον χώρο. Σε shared hosting άφησέ το κλειστό. Ποιότητα:', 'lean-images' )
										: __( 'Ο server δεν μπορεί να γράψει AVIF.', 'lean-images' )
								);
								?>
								<?php if ( LIM_Convert::supports( 'avif' ) ) : ?>
									<input type="number" class="small-text" name="lim[q_avif]" min="30" max="100" value="<?php echo esc_attr( (string) $s['q_avif'] ); ?>" />
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ελάχιστο κέρδος', 'lean-images' ); ?></th>
						<td>
							<input type="number" class="small-text" name="lim[min_saving]" min="0" max="50" value="<?php echo esc_attr( (string) $s['min_saving'] ); ?>" /> %
							<p class="description"><?php esc_html_e( 'Ένα αρχείο κρατιέται μόνο αν είναι τουλάχιστον τόσο μικρότερο από το πρωτότυπο.', 'lean-images' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<h2 class="lim-h2"><?php esc_html_e( 'Παρτίδες', 'lean-images' ); ?></h2>
			<div class="lim-zone">
				<table class="form-table lim-form" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Εικόνες ανά παρτίδα', 'lean-images' ); ?></th>
						<td>
							<select name="lim[batch]">
								<?php foreach ( LIM_Settings::BATCHES as $n ) : ?>
									<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $s['batch'], $n ); ?>><?php echo esc_html( (string) $n ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Μία παρτίδα κάθε 5 λεπτά. Κάθε εικόνα έχει συνήθως 6–10 μεγέθη.', 'lean-images' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Όριο χρόνου', 'lean-images' ); ?></th>
						<td>
							<select name="lim[budget]">
								<?php foreach ( LIM_Settings::BUDGETS as $n ) : ?>
									<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $s['budget'], $n ); ?>><?php echo esc_html( $n . ' s' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Η παρτίδα σταματά μετά από τόσα δευτερόλεπτα, ή όταν η μνήμη φτάσει το 80% του ορίου.', 'lean-images' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Μόνο τη νύχτα', 'lean-images' ); ?></th>
						<td>
							<label><input type="checkbox" name="lim[night]" value="1" <?php checked( $s['night'] ); ?> /> <?php esc_html_e( 'Παρτίδες μόνο 01:00–07:00, ώρα του site', 'lean-images' ); ?></label>
						</td>
					</tr>
				</table>
			</div>

			<h2 class="lim-h2"><?php esc_html_e( 'Σερβίρισμα', 'lean-images' ); ?></h2>
			<div class="lim-zone">
				<table class="form-table lim-form" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Όλη η σελίδα', 'lean-images' ); ?></th>
						<td>
							<label><input type="checkbox" name="lim[full_page]" value="1" <?php checked( $s['full_page'] ); ?> /> <?php esc_html_e( 'Και εικόνες που δεν τύπωσε το WordPress', 'lean-images' ); ?></label>
							<p class="description"><?php esc_html_e( 'Για page builders ή εικόνες που γράφτηκαν με το χέρι στο HTML. Οι εικόνες του θέματος, του WooCommerce και του περιεχομένου καλύπτονται ήδη χωρίς αυτό.', 'lean-images' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'lean-images' ); ?></button></p>
		</form>
		<?php
	}

	/* ----------------------------------------------------------------- tools */

	private static function render_tools(): void {
		$c = LIM_Queue::counts();
		?>
		<h2 class="lim-h2"><?php esc_html_e( 'Δοκιμή σε 20 εικόνες', 'lean-images' ); ?></h2>
		<div class="lim-zone">
			<p><?php esc_html_e( 'Μετατρέπει έως 20 εικόνες σε προσωρινό φάκελο, μετρά χρόνο και μέγεθος και τα σβήνει όλα. Δίνει εκτίμηση για όλη τη βιβλιοθήκη πριν από την «Έναρξη».', 'lean-images' ); ?></p>
			<?php self::action_button( 'sample', __( 'Δοκιμή', 'lean-images' ) ); ?>
		</div>

		<h2 class="lim-h2"><?php esc_html_e( 'Μία εικόνα', 'lean-images' ); ?></h2>
		<div class="lim-zone">
			<form method="post" class="lim-inline-form">
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<input type="hidden" name="lim_action" value="one" />
				<label><?php esc_html_e( 'ID εικόνας', 'lean-images' ); ?> <input type="number" class="small-text" name="lim_id" min="1" /></label>
				<button type="submit" class="button"><?php esc_html_e( 'Μετατροπή τώρα', 'lean-images' ); ?></button>
			</form>
		</div>

		<h2 class="lim-h2"><?php esc_html_e( 'Συντήρηση', 'lean-images' ); ?></h2>
		<div class="lim-zone">
			<p>
				<?php
				if ( $c['failed'] > 0 ) {
					/* translators: %d: failed images */
					self::action_button( 'retry', sprintf( __( 'Ξαναδοκίμασε τα σφάλματα (%d)', 'lean-images' ), $c['failed'] ) );
				}
				self::action_button( 'orphans', __( 'Καθαρισμός ορφανών', 'lean-images' ) );
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Ορφανά: αρχεία WebP / AVIF που έμειναν όταν σβήστηκε ή μετονομάστηκε το πρωτότυπο.', 'lean-images' ); ?></p>
		</div>

		<h2 class="lim-h2"><?php esc_html_e( 'Διαγραφή όλων', 'lean-images' ); ?></h2>
		<div class="lim-zone lim-zone--danger">
			<p><?php esc_html_e( 'Σβήνει όλα τα WebP / AVIF που έφτιαξε ή υιοθέτησε το Lean Images και σταματά τη μετατροπή. Τα πρωτότυπα δεν αγγίζονται. Το ίδιο γίνεται όταν διαγράφεις το plugin.', 'lean-images' ); ?></p>
			<?php self::action_button( 'delete_all', __( 'Διαγραφή όλων των αρχείων που φτιάξαμε', 'lean-images' ), false, __( 'Να σβηστούν όλα τα WebP / AVIF του Lean Images; Οι σελίδες θα δείχνουν ξανά τα πρωτότυπα.', 'lean-images' ) ); ?>
		</div>

		<?php if ( ! LIM_Lang::rs_active() ) : ?>
			<h2 class="lim-h2"><?php esc_html_e( 'Γλώσσα', 'lean-images' ); ?></h2>
			<div class="lim-zone">
				<form method="post" class="lim-inline-form">
					<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
					<input type="hidden" name="lim_action" value="save_lang" />
					<?php $cur = (string) get_user_meta( get_current_user_id(), 'rs_lang', true ); ?>
					<select name="lim_lang">
						<option value="" <?php selected( $cur, '' ); ?>><?php esc_html_e( 'Αυτόματα', 'lean-images' ); ?></option>
						<option value="el" <?php selected( $cur, 'el' ); ?>>Ελληνικά</option>
						<option value="en" <?php selected( $cur, 'en' ); ?>>English</option>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Αποθήκευση', 'lean-images' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
		<?php
	}

	/* =====================================================================
	 * POST
	 * =================================================================== */

	public static function route(): void {
		if ( LIM_Settings::PAGE !== self::current_page() || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || ! isset( $_POST['lim_action'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- compared only; nonce below.
			return;
		}
		if ( ! current_user_can( Lean_Images::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'lean-images' ) );
		}
		check_admin_referer( self::NONCE, self::NONCE_FIELD );
		$action = sanitize_key( self::post( 'lim_action' ) );
		$tab    = self::current_tab();

		switch ( $action ) {
			case 'start':
				if ( ! Lean_Images::killed() ) {
					LIM_Queue::set_running( true );
				}
				self::redirect( array( self::msg( 'success', __( 'Η μετατροπή ξεκίνησε. Η πρώτη παρτίδα τρέχει σε λίγα λεπτά.', 'lean-images' ) ) ), $tab );
				break;
			case 'pause':
				LIM_Queue::set_running( false );
				self::redirect( array( self::msg( 'success', __( 'Η μετατροπή σταμάτησε. Οι εικόνες που έγιναν ήδη συνεχίζουν να σερβίρονται.', 'lean-images' ) ) ), $tab );
				break;
			case 'batch':
				$r = Lean_Images::killed() ? array( 'busy' => true ) : LIM_Queue::batch();
				if ( ! empty( $r['busy'] ) ) {
					self::redirect( array( self::msg( 'warning', __( 'Τρέχει ήδη μια παρτίδα. Δοκίμασε σε λίγο.', 'lean-images' ) ) ), $tab );
				}
				/* translators: 1: images done, 2: seconds, 3: why it stopped, 4: waiting */
				$m = array( self::msg( 'success', sprintf( __( '%1$d εικόνες σε %2$s s (%3$s). Σε αναμονή: %4$d.', 'lean-images' ), (int) $r['done'], (string) $r['secs'], self::why( (string) $r['why'] ), (int) $r['left'] ) ) );
				if ( ! empty( $r['err'] ) ) {
					$m[] = self::msg( 'warning', (string) $r['err'] );
				}
				self::redirect( $m, $tab );
				break;
			case 'leave_test':
				LIM_Settings::update( array( 'test' => false ) );
				LIM_Queue::purge();
				self::redirect( array( self::msg( 'success', __( 'Όλοι οι επισκέπτες παίρνουν τώρα τα WebP. Η page cache καθαρίστηκε.', 'lean-images' ) ) ), $tab );
				break;
			case 'enter_test':
				LIM_Settings::update( array( 'test' => true ) );
				LIM_Queue::purge();
				self::redirect( array( self::msg( 'success', __( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τα WebP.', 'lean-images' ) ) ), $tab );
				break;
			case 'save_settings':
				self::save_keys( array( 'webp', 'avif', 'q_webp', 'q_avif', 'min_saving', 'batch', 'budget', 'night', 'full_page' ) );
				$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'lean-images' ) ) );
				if ( ! LIM_Settings::formats() ) {
					$msgs[] = self::msg( 'warning', __( 'Καμία μορφή δεν είναι επιλεγμένη: οι παρτίδες δεν κάνουν τίποτα και τίποτα δεν σερβίρεται.', 'lean-images' ) );
				}
				if ( ! LIM_Settings::get()['test'] ) {
					LIM_Queue::purge();
				}
				self::redirect( $msgs, $tab );
				break;
			case 'sample':
				$r = LIM_Convert::sample();
				self::redirect( self::sample_msgs( $r ), $tab );
				break;
			case 'one':
				$id = absint( self::post( 'lim_id' ) );
				if ( ! $id || ! in_array( get_post_mime_type( $id ), LIM_Files::MIMES, true ) ) {
					self::redirect( array( self::msg( 'error', __( 'Δεν βρέθηκε εικόνα JPEG ή PNG με αυτό το ID.', 'lean-images' ) ) ), $tab );
				}
				$r = LIM_Tools::one( $id );
				if ( 'busy' === $r['state'] ) {
					self::redirect( array( self::msg( 'warning', __( 'Τρέχει ήδη μια παρτίδα. Δοκίμασε σε λίγο.', 'lean-images' ) ) ), $tab );
				}
				if ( '' !== $r['err'] ) {
					self::redirect( array( self::msg( 'error', $r['err'] ) ), $tab );
				}
				/* translators: 1: files kept, 2: source size, 3: sidecar size */
				self::redirect( array( self::msg( 'success', sprintf( __( 'Έτοιμο: %1$d αρχεία, %2$s → %3$s.', 'lean-images' ), (int) $r['kept'], self::size( (int) $r['src'] ), self::size( (int) $r['out'] ) ) ) ), $tab );
				break;
			case 'retry':
				$n = LIM_Tools::retry_failed();
				/* translators: %d: images */
				self::redirect( array( self::msg( 'success', sprintf( __( '%d εικόνες μπήκαν ξανά σε αναμονή.', 'lean-images' ), $n ) ) ), $tab );
				break;
			case 'orphans':
				$r = LIM_Tools::orphans();
				/* translators: 1: images checked, 2: files deleted */
				$m = array( self::msg( 'success', sprintf( __( 'Ελέγχθηκαν %1$d εικόνες, σβήστηκαν %2$d ορφανά αρχεία.', 'lean-images' ), $r['checked'], $r['deleted'] ) ) );
				if ( ! $r['done'] ) {
					$m[] = self::msg( 'info', __( 'Έμειναν κι άλλες: πάτα ξανά για να συνεχίσει.', 'lean-images' ) );
				}
				self::redirect( $m, $tab );
				break;
			case 'delete_all':
				$r = LIM_Tools::delete_all();
				/* translators: 1: files deleted, 2: images */
				$m = array( self::msg( 'success', sprintf( __( 'Σβήστηκαν %1$d αρχεία από %2$d εικόνες. Η μετατροπή σταμάτησε.', 'lean-images' ), $r['deleted'], $r['items'] ) ) );
				if ( $r['left'] > 0 ) {
					$m[] = self::msg( 'info', __( 'Έμειναν κι άλλες: πάτα ξανά για να συνεχίσει.', 'lean-images' ) );
				}
				self::redirect( $m, $tab );
				break;
			case 'save_lang':
				if ( ! LIM_Lang::rs_active() ) {
					$lang = self::post( 'lim_lang' );
					if ( in_array( $lang, array( 'el', 'en' ), true ) ) {
						update_user_meta( get_current_user_id(), 'rs_lang', $lang );
					} else {
						delete_user_meta( get_current_user_id(), 'rs_lang' );
					}
					LIM_Lang::reset();
				}
				self::redirect( array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'lean-images' ) ) ), $tab );
				break;
			default:
				self::redirect( array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'lean-images' ) ) ), $tab );
		}
	}

	/** Messages of the sample test, with the estimate for the library. */
	private static function sample_msgs( array $r ): array {
		if ( 0 === $r['items'] ) {
			return array( self::msg( 'warning', __( 'Δεν βρέθηκαν εικόνες JPEG ή PNG για δοκιμή, ή ο server δεν γράφει τις μορφές που επέλεξες.', 'lean-images' ) ) );
		}
		$out = array_sum( $r['out'] );
		/* translators: 1: images, 2: files, 3: seconds, 4: source size, 5: size of the kept sidecars */
		$m = array( self::msg( 'success', sprintf( __( 'Δοκιμή: %1$d εικόνες (%2$d αρχεία) σε %3$s s. Πρωτότυπα %4$s, νέα αρχεία %5$s.', 'lean-images' ), $r['items'], $r['files'], (string) $r['secs'], self::size( (int) $r['src'] ), self::size( (int) $out ) ) ) );
		$c = LIM_Queue::counts();
		if ( $c['waiting'] > 0 ) {
			$per   = $r['secs'] / $r['items'];
			$mins  = (int) ceil( $per * $c['waiting'] / 60 );
			$extra = (int) round( $out / $r['items'] * $c['waiting'] );
			$mins  = max( 1, $mins );
			/* translators: %d: minutes */
			$work = sprintf( _n( '%d λεπτό', '%d λεπτά', $mins, 'lean-images' ), $mins );
			/* translators: 1: waiting images, 2: minutes of server work, 3: disk space */
			$m[] = self::msg( 'info', sprintf( __( 'Εκτίμηση για τις %1$d εικόνες σε αναμονή: περίπου %2$s δουλειάς του server συνολικά (μοιρασμένα σε παρτίδες) και %3$s επιπλέον χώρος.', 'lean-images' ), $c['waiting'], $work, self::size( $extra ) ) );
		}
		if ( $r['errors'] > 0 ) {
			/* translators: %d: files */
			$m[] = self::msg( 'warning', sprintf( __( '%d αρχεία δεν μετατράπηκαν.', 'lean-images' ), $r['errors'] ) );
		}
		if ( $r['stopped'] ) {
			$m[] = self::msg( 'info', __( 'Η δοκιμή σταμάτησε στα 20 δευτερόλεπτα.', 'lean-images' ) );
		}
		return $m;
	}

	/** Saves the posted keys (unchecked boxes = false). */
	private static function save_keys( array $keys ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); LIM_Settings::sanitize() validates every key.
		$raw = isset( $_POST['lim'] ) && is_array( $_POST['lim'] ) ? wp_unslash( $_POST['lim'] ) : array();
		$in  = array();
		foreach ( $keys as $k ) {
			if ( in_array( $k, LIM_Settings::bools(), true ) ) {
				$in[ $k ] = isset( $raw[ $k ] ) && '1' === (string) $raw[ $k ];
			} elseif ( isset( $raw[ $k ] ) && is_scalar( $raw[ $k ] ) ) {
				$in[ $k ] = (string) $raw[ $k ];
			}
		}
		// AVIF stays off when the server cannot write it.
		if ( ! empty( $in['avif'] ) && ! LIM_Convert::supports( 'avif' ) ) {
			$in['avif'] = false;
		}
		LIM_Settings::update( $in );
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
		set_transient( 'lim_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . LIM_Settings::PAGE . ( 'overview' === $tab ? '' : '&tab=' . $tab ) ) );
		exit;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'lim_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'lim_aui_msg_' . get_current_user_id() );
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
		<p class="lim-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'lean-images' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="lim-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="lim-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'lean-images' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="lim-footer-cta">
			<a class="lim-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'lean-images' ); ?>
			</a>
			<a class="lim-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'lean-images' ); ?>
			</a>
		</p>
		<?php
	}
}
