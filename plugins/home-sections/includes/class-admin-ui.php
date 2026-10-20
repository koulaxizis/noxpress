<?php
/**
 * HMS_Admin_UI — menu, settings page, PRG routes (Bible §3–§8, §11).
 *
 * One page, submenu «Ενότητες αρχικής» of the shared "Noxpress" menu
 * (admin_menu priority 230), slug hms-settings:
 *  - the built-in markers (a reference table)
 *  - the alias prefix and the presets (one card each, plus an empty one)
 *  - the [hs_video] shortcode (attributes) and its visitor texts
 *  - a shortcode test: which products a [products …] shortcode returns
 *    and how each of its classes is read, or which product and video an
 *    [hs_video …] shortcode picks (without its cache)
 *  - developer notes, footer
 *
 * Saving: POST on admin_init, slug + nonce + capability, strict
 * validation (HMS_Settings::sanitize), PRG with the message in the
 * transient hms_aui_msg_{uid}. The shortcode test changes nothing, so it
 * answers in the same request (nonce + capability as well).
 */

defined( 'ABSPATH' ) || exit;

final class HMS_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'hms-settings';
	const NONCE       = 'hms_admin';
	const NONCE_FIELD = 'hms_nonce';

	/** Products listed by the shortcode test. */
	const TEST_MAX = 20;

	/** @var array|null Result of the shortcode test in this request. */
	private static $test = null;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 230 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HMS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Home Sections', 'home-sections' ),
			__( 'Ενότητες αρχικής', 'home-sections' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'home-sections' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'hms-admin', HMS_URL . 'assets/admin.css', array(), HMS_VERSION );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'home-sections' ) );
		}
		$s = HMS_Settings::get();
		?>
		<div class="wrap hms-wrap">
			<h1><?php esc_html_e( 'Home Sections', 'home-sections' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Marker classes για το shortcode [products] του WooCommerce: γράφεις ένα class στο shortcode και η ενότητα αφήνει έξω προϊόντα, κατηγορίες ή προπαραγγελίες, ή κρατά μόνο προϊόντα με μια λέξη στον τίτλο. Τα προϊόντα δεν αλλάζουν.', 'home-sections' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>
			<?php
			if ( Home_Sections::killed() ) {
				echo '<p class="hms-warn">' . esc_html__( 'Το HMS_DISABLE είναι ενεργό στο wp-config.php: κανένας marker δεν ισχύει.', 'home-sections' ) . '</p>';
			}
			?>

			<h2 class="hms-h2"><?php esc_html_e( 'Έτοιμοι markers', 'home-sections' ); ?></h2>
			<div class="hms-zone">
				<p class="description"><?php esc_html_e( 'Δουλεύουν χωρίς ρύθμιση. Βάλε όσους θέλεις στο class του shortcode, χωρισμένους με κενό· οι συνθήκες τους ισχύουν όλες μαζί.', 'home-sections' ); ?></p>
				<?php self::builtin_table( $s['alias'] ); ?>
				<p class="description hms-mt-8"><code>[products limit="4" orderby="date" order="DESC" class="hs-no-preorders hs-exclude-cat-magazines hs-exclude-123"]</code></p>
			</div>

			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="hms_action" value="save" />

				<h2 class="hms-h2"><?php esc_html_e( 'Πρόθεμα alias', 'home-sections' ); ?></h2>
				<div class="hms-zone">
					<p class="hms-field"><span><?php esc_html_e( 'Πρόθεμα', 'home-sections' ); ?></span><input type="text" name="hms_alias" value="<?php echo esc_attr( $s['alias'] ); ?>" maxlength="20" class="hms-num" placeholder="glk" /></p>
					<p class="description"><?php esc_html_e( 'Προαιρετικό. Με πρόθεμα «glk», οι έτοιμοι markers δουλεύουν και ως glk-exclude-{ID}, glk-exclude-cat-{slug}, glk-no-preorders και glk-title-{λέξη}, ώστε να μένουν ίδια τα classes σελίδων που υπάρχουν ήδη. Μόνο πεζά λατινικά και αριθμοί, έως 20.', 'home-sections' ); ?></p>
				</div>

				<h2 class="hms-h2"><?php esc_html_e( 'Presets', 'home-sections' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Ένα preset είναι ένας marker με δικό σου όνομα. Γράψε το όνομα στο class του shortcode και ισχύουν όλες οι συνθήκες του.', 'home-sections' ); ?></p>
				<?php
				$terms = self::category_options();
				$i     = 0;
				foreach ( $s['presets'] as $p ) {
					self::preset_card( $i, $p, $terms );
					++$i;
				}
				if ( $i < HMS_Settings::MAX_PRESETS ) {
					self::preset_card( $i, null, $terms );
				}
				?>
				<p class="hms-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'home-sections' ); ?></button></p>
			</form>

			<?php self::render_video( $s['video'] ); ?>

			<?php self::render_test(); ?>

			<h2 class="hms-h2"><?php esc_html_e( 'Για προγραμματιστές', 'home-sections' ); ?></h2>
			<div class="hms-zone">
				<table class="widefat hms-table">
					<tbody>
						<tr><td><code>hms_preorder_meta</code></td><td><?php esc_html_e( 'Φίλτρο στα meta keys των προπαραγγελιών (meta key => τιμή). Προεπιλογή: _simple_preorder = yes (PRENA) και _por_on = yes (Pre-Orders).', 'home-sections' ); ?></td></tr>
						<tr><td><code>hms_video_source</code></td><td><?php esc_html_e( 'Φίλτρο στην πηγή των βίντεο: meta_key (προεπιλογή wb_custom_tabs) και positions, η θέση της καρτέλας ανά τύπο (προεπιλογή trailer 30, presentation 31).', 'home-sections' ); ?></td></tr>
						<tr><td><code>hms_video_classes</code></td><td><?php esc_html_e( 'Φίλτρο στα CSS classes του [hs_video]: heading, button, play, meta.', 'home-sections' ); ?></td></tr>
						<tr><td><code>hms_video_people_taxonomy</code></td><td><?php esc_html_e( 'Φίλτρο στην ταξινομία με τα πρόσωπα που μπαίνουν μετά το όνομα του προϊόντος (προεπιλογή pa_syggrafeis· κενό = κανένα).', 'home-sections' ); ?></td></tr>
						<tr><td><code>hms_video_text</code></td><td><?php esc_html_e( 'Φίλτρο σε κάθε κείμενο του [hs_video] (κείμενο, κλειδί, γλώσσα), π.χ. για WPML.', 'home-sections' ); ?></td></tr>
						<tr><td><code>hms_video_script</code></td><td><?php esc_html_e( 'Φίλτρο: false για να μη φορτώνεται το script του plugin, όταν η σελίδα έχει δικό της.', 'home-sections' ); ?></td></tr>
						<tr><td><code>HMS_DISABLE</code></td><td><?php esc_html_e( 'Σταθερά στο wp-config.php που σταματά κάθε marker και κάθε [hs_video].', 'home-sections' ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/** Reference table of the built-in markers. */
	private static function builtin_table( string $alias ): void {
		$rows = array(
			'exclude-{ID}'       => __( 'Αφήνει έξω το προϊόν με αυτό το ID. Μπαίνει όσες φορές θέλεις (π.χ. το προϊόν που δείχνει ήδη το hero).', 'home-sections' ),
			'exclude-cat-{slug}' => __( 'Αφήνει έξω τα προϊόντα μιας κατηγορίας και των υποκατηγοριών της, με το slug της. Μπαίνει όσες φορές θέλεις.', 'home-sections' ),
			'no-preorders'       => __( 'Αφήνει έξω τις προπαραγγελίες (PRENA και Pre-Orders).', 'home-sections' ),
			'title-{λέξη}'       => __( 'Κρατά μόνο προϊόντα με αυτή τη λέξη στον τίτλο, χωρίς διάκριση πεζών και κεφαλαίων (π.χ. hs-title-t-shirt βρίσκει «T-Shirt»). Μία λέξη, χωρίς κενά· για φράση με κενά φτιάξε preset.', 'home-sections' ),
		);
		?>
		<table class="widefat hms-table">
			<tbody>
				<?php foreach ( $rows as $marker => $text ) : ?>
					<tr>
						<td><code>hs-<?php echo esc_html( $marker ); ?></code><?php echo '' !== $alias ? '<br /><code>' . esc_html( $alias . '-' . $marker ) . '</code>' : ''; ?></td>
						<td><?php echo esc_html( $text ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Product categories as id => indented name, parents first.
	 *
	 * @return array<int,string>
	 */
	private static function category_options(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'number'     => 2000,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$children = array();
		foreach ( $terms as $t ) {
			$children[ (int) $t->parent ][] = $t;
		}
		$out  = array();
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $children ) {
			if ( empty( $children[ $parent ] ) || $depth > 10 ) {
				return;
			}
			foreach ( $children[ $parent ] as $t ) {
				$out[ (int) $t->term_id ] = str_repeat( '— ', $depth ) . $t->name;
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		// Terms whose parent is missing (orphans) still get a row.
		foreach ( $terms as $t ) {
			if ( ! isset( $out[ (int) $t->term_id ] ) ) {
				$out[ (int) $t->term_id ] = $t->name;
			}
		}
		return $out;
	}

	/**
	 * One preset card (an empty one for a new preset).
	 *
	 * @param int        $i     Row index.
	 * @param array|null $p     Preset, or null for a new one.
	 * @param array      $terms Category options.
	 */
	private static function preset_card( int $i, ?array $p, array $terms ): void {
		$new  = null === $p;
		$p    = $new ? HMS_Settings::preset_defaults() : $p;
		$name = 'hms_p[' . $i . ']';
		?>
		<fieldset class="hms-zone hms-preset-card<?php echo $new ? ' hms-preset-card--new' : ''; ?>">
			<legend><?php echo $new ? esc_html__( 'Νέο preset', 'home-sections' ) : esc_html( $p['name'] ); ?></legend>
			<p class="hms-field"><span><?php esc_html_e( 'Όνομα (class)', 'home-sections' ); ?></span><input type="text" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $p['name'] ); ?>" maxlength="60" <?php echo $new ? 'placeholder="home-new-books"' : ''; ?>/></p>
			<span class="hms-hint"><?php esc_html_e( 'Πεζά λατινικά, αριθμοί και παύλες, 3 έως 60 χαρακτήρες. Δεν ξεκινά με «hs-».', 'home-sections' ); ?></span>

			<p class="hms-field hms-mt-8"><span><?php esc_html_e( 'Χωρίς τις κατηγορίες', 'home-sections' ); ?></span>
				<select name="<?php echo esc_attr( $name ); ?>[cats][]" multiple>
					<?php
					foreach ( $terms as $id => $label ) {
						echo '<option value="' . esc_attr( (string) $id ) . '"' . ( in_array( (int) $id, $p['cats'], true ) ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
					}
					?>
				</select>
			</p>
			<span class="hms-hint"><?php esc_html_e( 'Και οι υποκατηγορίες τους. Ctrl/Cmd + κλικ για πολλές.', 'home-sections' ); ?></span>

			<label class="hms-check hms-mt-8"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[preorders]" value="1" <?php checked( $p['preorders'] ); ?> /> <?php esc_html_e( 'Χωρίς τις προπαραγγελίες', 'home-sections' ); ?></label>

			<p class="hms-field hms-mt-8"><span><?php esc_html_e( 'Ο τίτλος περιέχει', 'home-sections' ); ?></span><input type="text" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $p['title'] ); ?>" maxlength="100" <?php echo $new ? 'placeholder="T-Shirt"' : ''; ?>/></p>

			<p class="hms-field"><span><?php esc_html_e( 'Χωρίς τα προϊόντα (IDs)', 'home-sections' ); ?></span><input type="text" name="<?php echo esc_attr( $name ); ?>[ids]" value="<?php echo esc_attr( implode( ', ', $p['ids'] ) ); ?>" <?php echo $new ? 'placeholder="80748, 80750"' : ''; ?>/></p>

			<?php if ( ! $new ) : ?>
				<p class="hms-row"><span class="hms-badge"><code>class="<?php echo esc_html( $p['name'] ); ?>"</code></span></p>
				<p class="hms-row"><label class="hms-inline hms-warn"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete]" value="1" /> <?php esc_html_e( 'Διαγραφή', 'home-sections' ); ?></label></p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * [hs_video]: attributes + visitor texts
	 * ------------------------------------------------------------------- */

	/** Admin labels of the visitor texts (msgids). */
	private static function video_text_labels(): array {
		return array(
			'trailer_heading'      => __( 'Τίτλος: trailer', 'home-sections' ),
			'presentation_heading' => __( 'Τίτλος: παρουσίαση', 'home-sections' ),
			'trailer_title'        => __( 'Όνομα player: trailer ({name})', 'home-sections' ),
			'presentation_title'   => __( 'Όνομα player: παρουσίαση ({name})', 'home-sections' ),
			'play'                 => __( 'Ετικέτα κουμπιού ({title})', 'home-sections' ),
			'link'                 => __( 'Σύνδεσμος προς το προϊόν', 'home-sections' ),
		);
	}

	private static function render_video( array $v ): void {
		$rows = array(
			'type'      => __( 'trailer (προεπιλογή) ή presentation: η καρτέλα βίντεο του προϊόντος (θέση 30 ή 31).', 'home-sections' ),
			'id'        => __( 'Ένα συγκεκριμένο προϊόν αντί για το νεότερο.', 'home-sections' ),
			'exclude'   => __( 'IDs προϊόντων που μένουν έξω, χωρισμένα με κόμμα.', 'home-sections' ),
			'category'  => __( 'Μόνο προϊόντα αυτών των κατηγοριών (slugs, με κόμμα).', 'home-sections' ),
			'preorders' => __( 'no: χωρίς τις προπαραγγελίες (PRENA και Pre-Orders).', 'home-sections' ),
			'heading'   => __( 'Άλλος τίτλος από τον τίτλο των ρυθμίσεων· κενό = χωρίς τίτλο.', 'home-sections' ),
		);
		?>
		<h2 class="hms-h2"><?php esc_html_e( 'Shortcode βίντεο', 'home-sections' ); ?></h2>
		<div class="hms-zone">
			<p class="description"><?php esc_html_e( 'Το [hs_video] δείχνει το νεότερο δημοσιευμένο προϊόν που έχει βίντεο στην καρτέλα του (Custom Product Tabs for WooCommerce): εικόνα και κουμπί αναπαραγωγής, και ο player του YouTube φορτώνει μόνο με το πάτημα. Χωρίς αποτέλεσμα δεν τυπώνει τίποτα.', 'home-sections' ); ?></p>
			<table class="widefat hms-table">
				<tbody>
					<?php foreach ( $rows as $att => $text ) : ?>
						<tr><td><code><?php echo esc_html( $att ); ?>=""</code></td><td><?php echo esc_html( $text ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description hms-mt-8"><code>[hs_video type="trailer" preorders="no"]</code> <code>[hs_video type="presentation" id="123"]</code></p>
		</div>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="hms_action" value="video" />
			<h2 class="hms-h2"><?php esc_html_e( 'Κείμενα βίντεο', 'home-sections' ); ?></h2>
			<div class="hms-zone">
				<p class="description"><?php esc_html_e( 'Τα κείμενα που βλέπει ο επισκέπτης. Κενό ελληνικό κείμενο επιστρέφει στην προεπιλογή· κενό αγγλικό δείχνει το ελληνικό.', 'home-sections' ); ?></p>
				<p class="hms-field"><span><?php esc_html_e( 'Γλώσσα', 'home-sections' ); ?></span>
					<select name="hms_v[lang]">
						<option value="auto" <?php selected( $v['lang'], 'auto' ); ?>><?php esc_html_e( 'Αυτόματα (γλώσσα του site)', 'home-sections' ); ?></option>
						<option value="el" <?php selected( $v['lang'], 'el' ); ?>>Ελληνικά</option>
						<option value="en" <?php selected( $v['lang'], 'en' ); ?>>English</option>
					</select>
				</p>
				<table class="widefat hms-table hms-mt-8">
					<thead><tr><th></th><th>Ελληνικά</th><th>English</th></tr></thead>
					<tbody>
						<?php foreach ( self::video_text_labels() as $key => $label ) : ?>
							<tr>
								<td><?php echo esc_html( $label ); ?></td>
								<td><input type="text" class="hms-code" name="hms_v[texts][<?php echo esc_attr( $key ); ?>][el]" value="<?php echo esc_attr( $v['texts'][ $key ]['el'] ); ?>" maxlength="<?php echo (int) HMS_Settings::MAX_TEXT; ?>" /></td>
								<td><input type="text" class="hms-code" name="hms_v[texts][<?php echo esc_attr( $key ); ?>][en]" value="<?php echo esc_attr( $v['texts'][ $key ]['en'] ); ?>" maxlength="<?php echo (int) HMS_Settings::MAX_TEXT; ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="hms-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'home-sections' ); ?></button></p>
			</div>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Shortcode test
	 * ------------------------------------------------------------------- */

	private static function render_test(): void {
		$t = self::$test;
		?>
		<h2 class="hms-h2"><?php esc_html_e( 'Δοκιμή shortcode', 'home-sections' ); ?></h2>
		<div class="hms-zone">
			<p class="description"><?php esc_html_e( 'Επικόλλησε ένα shortcode [products …] για να δεις ποια προϊόντα επιστρέφει και πώς διαβάζεται κάθε class, ή ένα [hs_video …] για να δεις ποιο προϊόν και ποιο βίντεο διαλέγει. Δεν αλλάζει τίποτα.', 'home-sections' ); ?></p>
			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="hms_action" value="test" />
				<p><input type="text" name="hms_sc" class="hms-code" value="<?php echo esc_attr( $t ? $t['sc'] : '' ); ?>" placeholder='[products limit="4" class="hs-no-preorders"]' /></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Δοκιμή', 'home-sections' ); ?></button></p>
			</form>
			<?php
			if ( null === $t ) {
				echo '</div>';
				return;
			}
			if ( '' !== $t['error'] ) {
				echo '<p class="hms-warn">' . esc_html( $t['error'] ) . '</p></div>';
				return;
			}
			if ( isset( $t['video'] ) ) {
				self::render_video_test( $t['video'] );
				echo '</div>';
				return;
			}
			?>
			<h3 class="hms-h3"><?php esc_html_e( 'Classes', 'home-sections' ); ?></h3>
			<?php if ( ! $t['found'] ) : ?>
				<p class="description"><?php esc_html_e( 'Το shortcode δεν έχει class: κανένας marker δεν ισχύει.', 'home-sections' ); ?></p>
			<?php else : ?>
				<table class="widefat hms-table">
					<tbody>
						<?php foreach ( $t['found'] as $row ) : ?>
							<tr><td><code><?php echo esc_html( $row[0] ); ?></code></td><td<?php echo $row[2] ? ' class="hms-warn"' : ''; ?>><?php echo esc_html( $row[1] ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3 class="hms-h3"><?php esc_html_e( 'Προϊόντα', 'home-sections' ); ?></h3>
			<p>
				<?php
				/* translators: 1: products on the first page (at most 20 here), 2: products the query finds in total */
				echo esc_html( sprintf( __( 'Η πρώτη σελίδα δείχνει %1$d από τα %2$d προϊόντα που βρίσκει το query (εδώ έως 20).', 'home-sections' ), count( $t['products'] ), $t['total'] ) );
				?>
			</p>
			<?php if ( $t['products'] ) : ?>
				<ol class="hms-result">
					<?php foreach ( $t['products'] as $pid => $title ) : ?>
						<li><a href="<?php echo esc_url( (string) get_edit_post_link( $pid ) ); ?>">#<?php echo esc_html( (string) $pid ); ?></a> <?php echo esc_html( $title ); ?></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/** The result of an [hs_video] test. */
	private static function render_video_test( array $v ): void {
		$a = $v['atts'];
		?>
		<h3 class="hms-h3"><?php esc_html_e( 'Attributes', 'home-sections' ); ?></h3>
		<table class="widefat hms-table">
			<tbody>
				<tr><td><code>type</code></td><td<?php echo '' === $a['type'] ? ' class="hms-warn"' : ''; ?>><?php echo '' === $a['type'] ? esc_html__( 'Άκυρος τύπος: γράψε trailer ή presentation.', 'home-sections' ) : esc_html( $a['type'] ); ?></td></tr>
				<tr><td><code>id</code></td><td><?php echo $a['id'] ? esc_html( (string) $a['id'] ) : '—'; ?></td></tr>
				<tr><td><code>exclude</code></td><td><?php echo $a['exclude'] ? esc_html( implode( ', ', $a['exclude'] ) ) : '—'; ?></td></tr>
				<tr><td><code>category</code></td><td><?php echo $a['category'] ? esc_html( implode( ', ', $a['category'] ) ) : '—'; ?></td></tr>
				<tr><td><code>preorders</code></td><td><?php echo $a['preorders'] ? esc_html__( 'μαζί με τις προπαραγγελίες', 'home-sections' ) : esc_html__( 'χωρίς τις προπαραγγελίες', 'home-sections' ); ?></td></tr>
			</tbody>
		</table>

		<h3 class="hms-h3"><?php esc_html_e( 'Αποτέλεσμα', 'home-sections' ); ?></h3>
		<?php if ( 0 === $v['pid'] ) : ?>
			<p class="hms-warn"><?php esc_html_e( 'Κανένα προϊόν με βίντεο: το shortcode δεν τυπώνει τίποτα.', 'home-sections' ); ?></p>
		<?php else : ?>
			<p>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $v['pid'] ) ); ?>">#<?php echo esc_html( (string) $v['pid'] ); ?></a>
				<?php echo esc_html( get_the_title( $v['pid'] ) ); ?> · <?php esc_html_e( 'βίντεο', 'home-sections' ); ?> <code><?php echo esc_html( $v['vid'] ); ?></code>
			</p>
			<p><img src="<?php echo esc_url( 'https://i.ytimg.com/vi/' . $v['vid'] . '/mqdefault.jpg' ); ?>" alt="" width="320" height="180" loading="lazy" /></p>
			<p><textarea class="hms-code" rows="6" readonly><?php echo esc_textarea( $v['html'] ); ?></textarea></p>
		<?php endif; ?>
		<?php
	}

	/** Picks the [hs_video] product without the cache (nothing is written). */
	private static function do_video_test( string $sc, array $out ): array {
		$atts              = shortcode_parse_atts( preg_replace( '/^\[hs_video\s*|\]$/', '', $sc ) );
		$a                 = HMS_Video::parse_atts( is_array( $atts ) ? $atts : array() );
		list( $pid, $vid ) = HMS_Video::find( $a );
		$out['video']      = array(
			'atts' => $a,
			'pid'  => $pid,
			'vid'  => $vid,
			'html' => $pid ? HMS_Video::html( $a, $pid, $vid ) : '',
		);
		return $out;
	}

	/**
	 * Runs the shortcode's own query (WooCommerce builds the args, our
	 * filter adds the markers) without rendering it.
	 */
	private static function do_test( string $sc ): array {
		$out = array(
			'sc'       => $sc,
			'error'    => '',
			'found'    => array(),
			'products' => array(),
			'total'    => 0,
		);
		if ( preg_match( '/^\[hs_video(\s[^\]]*)?\]$/', $sc ) ) {
			try {
				return self::do_video_test( $sc, $out );
			} catch ( \Throwable $e ) {
				$out['error'] = __( 'Η δοκιμή απέτυχε.', 'home-sections' );
				return $out;
			}
		}
		if ( ! preg_match( '/^\[products(\s[^\]]*)?\]$/', $sc, $m ) ) {
			$out['error'] = __( 'Γράψε ένα μόνο shortcode της μορφής [products …] ή [hs_video …].', 'home-sections' );
			return $out;
		}
		if ( ! class_exists( 'WC_Shortcode_Products' ) ) {
			$out['error'] = __( 'Το WooCommerce δεν έχει το shortcode [products].', 'home-sections' );
			return $out;
		}
		$atts = shortcode_parse_atts( isset( $m[1] ) ? trim( $m[1] ) : '' );
		$atts = is_array( $atts ) ? $atts : array();

		$class = isset( $atts['class'] ) && is_scalar( $atts['class'] ) ? (string) $atts['class'] : '';
		$spec  = HMS_Markers::parse( $class );
		foreach ( $spec['found'] as $row ) {
			$out['found'][] = self::describe( $row );
		}

		try {
			$shortcode = new WC_Shortcode_Products( $atts, 'products' );
			$args      = $shortcode->get_query_args();
			if ( Home_Sections::killed() ) {
				$out['error'] = __( 'Το HMS_DISABLE είναι ενεργό στο wp-config.php: κανένας marker δεν ισχύει.', 'home-sections' );
				return $out;
			}
			// The shortcode's own limit (its first page), at most TEST_MAX.
			$per                    = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 0;
			$args['posts_per_page'] = $per > 0 ? min( $per, self::TEST_MAX ) : self::TEST_MAX;
			$args['paged']          = 1;
			$args['no_found_rows']  = false;
			$args['fields']         = 'ids';
			unset( $args['offset'] );
			$q            = new WP_Query( $args );
			$out['total'] = (int) $q->found_posts;
			foreach ( $q->posts as $pid ) {
				$out['products'][ (int) $pid ] = get_the_title( (int) $pid );
			}
		} catch ( \Throwable $e ) {
			$out['error'] = __( 'Η δοκιμή απέτυχε: το WooCommerce δεν μπόρεσε να φτιάξει το query αυτού του shortcode.', 'home-sections' );
		}
		return $out;
	}

	/**
	 * One class of the test as array( class, text, warn ).
	 *
	 * @param array $row array( class, kind, value ) from HMS_Markers::parse().
	 */
	private static function describe( array $row ): array {
		list( $class, $kind, $value ) = $row;
		switch ( $kind ) {
			case 'id':
				$title = get_the_title( (int) $value );
				if ( 'product' !== get_post_type( (int) $value ) ) {
					/* translators: %d: product id */
					return array( $class, sprintf( __( 'Δεν υπάρχει προϊόν #%d: δεν αφήνει έξω τίποτα.', 'home-sections' ), $value ), true );
				}
				/* translators: 1: product id, 2: product title */
				return array( $class, sprintf( __( 'Αφήνει έξω το προϊόν #%1$d «%2$s».', 'home-sections' ), $value, $title ), false );
			case 'cat':
				$term = get_term_by( 'slug', (string) $value, 'product_cat' );
				if ( ! $term instanceof WP_Term ) {
					/* translators: %s: category slug */
					return array( $class, sprintf( __( 'Δεν υπάρχει κατηγορία με slug «%s»: δεν αφήνει έξω τίποτα.', 'home-sections' ), $value ), true );
				}
				/* translators: %s: category name */
				return array( $class, sprintf( __( 'Αφήνει έξω την κατηγορία «%s» και τις υποκατηγορίες της.', 'home-sections' ), $term->name ), false );
			case 'preorders':
				return array( $class, __( 'Αφήνει έξω τις προπαραγγελίες.', 'home-sections' ), false );
			case 'title':
				/* translators: %s: word */
				return array( $class, sprintf( __( 'Μόνο προϊόντα με «%s» στον τίτλο.', 'home-sections' ), $value ), false );
			case 'preset':
				return array( $class, self::preset_summary( $value ), false );
		}
		return array( $class, __( 'Δεν είναι marker: μένει απλό CSS class.', 'home-sections' ), false );
	}

	/** What a preset does, in one line. */
	private static function preset_summary( array $p ): string {
		$parts = array();
		if ( $p['cats'] ) {
			$names = array();
			foreach ( $p['cats'] as $id ) {
				$t       = get_term( $id, 'product_cat' );
				$names[] = $t instanceof WP_Term ? $t->name : '#' . $id;
			}
			/* translators: %s: category names */
			$parts[] = sprintf( __( 'χωρίς τις κατηγορίες %s', 'home-sections' ), implode( ', ', $names ) );
		}
		if ( $p['preorders'] ) {
			$parts[] = __( 'χωρίς τις προπαραγγελίες', 'home-sections' );
		}
		if ( '' !== $p['title'] ) {
			/* translators: %s: text */
			$parts[] = sprintf( __( 'μόνο με «%s» στον τίτλο', 'home-sections' ), $p['title'] );
		}
		if ( $p['ids'] ) {
			/* translators: %s: product ids */
			$parts[] = sprintf( __( 'χωρίς τα προϊόντα %s', 'home-sections' ), implode( ', ', $p['ids'] ) );
		}
		/* translators: %s: the preset's conditions */
		return sprintf( __( 'Preset: %s.', 'home-sections' ), implode( '· ', $parts ) );
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
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['hms_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'home-sections' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['hms_action'] ) ) ) {
			case 'save':
				self::redirect( self::do_save() );
				break;
			case 'video':
				self::redirect( self::do_save_video() );
				break;
			case 'test':
				// Read-only: answered in this request, no redirect.
				self::$test = self::do_test( trim( substr( self::post( 'hms_sc' ), 0, 2000 ) ) );
				break;
			default:
				self::redirect( array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'home-sections' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( array $msgs ): void {
		set_transient( 'hms_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by HMS_Settings::sanitize()). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by HMS_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	private static function do_save(): array {
		$raw_alias = trim( self::post( 'hms_alias' ) );
		$alias     = HMS_Settings::clean_alias( $raw_alias );
		$msgs      = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'home-sections' ) ) );
		if ( '' !== $raw_alias && '' === $alias ) {
			$msgs[] = self::msg( 'warning', __( 'Το πρόθεμα δεν κρατήθηκε: μόνο πεζά λατινικά και αριθμοί, έως 20, που ξεκινούν με γράμμα, και όχι «hs».', 'home-sections' ) );
		}

		$presets = array();
		$bad     = array();
		$empty   = array();
		$seen    = array();
		$dupe    = false;
		foreach ( self::post_array( 'hms_p' ) as $row ) {
			if ( ! is_array( $row ) || ( isset( $row['delete'] ) && '1' === $row['delete'] ) ) {
				continue;
			}
			$name = isset( $row['name'] ) && is_scalar( $row['name'] ) ? strtolower( trim( (string) $row['name'] ) ) : '';
			if ( '' === $name ) {
				continue;
			}
			$row['name'] = $name;
			if ( ! HMS_Settings::valid_name( $name ) || HMS_Markers::is_builtin( $name, $alias ) ) {
				$bad[] = $name;
				continue;
			}
			if ( isset( $seen[ $name ] ) ) {
				$dupe = true;
				continue;
			}
			if ( null === HMS_Settings::preset( $row, $alias, true ) ) {
				$empty[] = $name;
				continue;
			}
			$seen[ $name ] = true;
			$presets[]     = $row;
		}

		if ( $bad ) {
			/* translators: %s: preset names */
			$msgs[] = self::msg( 'warning', sprintf( __( 'Δεν κρατήθηκαν presets με άκυρο όνομα ή όνομα έτοιμου marker: %s.', 'home-sections' ), implode( ', ', $bad ) ) );
		}
		if ( $empty ) {
			/* translators: %s: preset names */
			$msgs[] = self::msg( 'warning', sprintf( __( 'Δεν κρατήθηκαν presets χωρίς καμία συνθήκη: %s.', 'home-sections' ), implode( ', ', $empty ) ) );
		}
		if ( $dupe ) {
			$msgs[] = self::msg( 'warning', __( 'Δύο presets είχαν το ίδιο όνομα: κρατήθηκε το πρώτο.', 'home-sections' ) );
		}
		if ( count( $presets ) > HMS_Settings::MAX_PRESETS ) {
			$msgs[] = self::msg( 'warning', __( 'Κρατήθηκαν μόνο τα πρώτα 20 presets.', 'home-sections' ) );
		}

		HMS_Settings::save(
			array(
				'alias'   => $alias,
				'presets' => $presets,
				'video'   => HMS_Settings::get()['video'],
			)
		);
		return array_merge( $msgs, self::purge_caches() );
	}

	/** Saves the [hs_video] texts only; alias and presets stay as they are. */
	private static function do_save_video(): array {
		$s          = HMS_Settings::get();
		$s['video'] = HMS_Settings::video( self::post_array( 'hms_v' ) );
		HMS_Settings::save( $s );
		return array_merge( array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'home-sections' ) ) ), self::purge_caches() );
	}

	/**
	 * Clears the page caches we know, through their own public functions,
	 * so pages with a changed preset show it at once. The [products]
	 * results cache needs nothing: its key is the final query args.
	 */
	private static function purge_caches(): array {
		$done = array();
		try {
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
				$done[] = 'WP Rocket';
			}
			if ( defined( 'LSCWP_V' ) ) {
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- LiteSpeed Cache's own action.
				$done[] = 'LiteSpeed Cache';
			}
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
				$done[] = 'W3 Total Cache';
			}
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
				$done[] = 'WP Super Cache';
			}
		} catch ( \Throwable $e ) {
			$done = $done ? $done : array();
		}
		if ( ! $done ) {
			return array();
		}
		/* translators: %s: names of the page caches that were cleared */
		return array( self::msg( 'info', sprintf( __( 'Καθαρίστηκε η page cache: %s.', 'home-sections' ), implode( ', ', $done ) ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'hms_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'hms_aui_msg_' . get_current_user_id() );
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
		<p class="hms-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'home-sections' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="hms-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="hms-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'home-sections' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="hms-footer-cta">
			<a class="hms-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'home-sections' ); ?>
			</a>
			<a class="hms-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'home-sections' ); ?>
			</a>
		</p>
		<?php
	}
}
