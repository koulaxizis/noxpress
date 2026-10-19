<?php
/**
 * CRT_Metabox — the creator edit screen.
 *
 *  - «Στοιχεία»: roles text, sort name, gender (product tab title), links
 *    (repeater).
 *  - «Συνεντεύξεις, βίντεο, κριτικές»: one list, each row with a type
 *    (repeater; videos may have a local image).
 *  - «Ταίριασμα με χαρακτηριστικά»: the matching key, the terms found
 *    with it (each can be excluded), extra terms (AJAX search from the
 *    3rd character, 30 results), conflicts.
 *
 * Save: save_post_nox_creator (priority 10; CRT_Match recomputes at 20 →
 * shutdown), nonce + edit_post capability, strict validation through
 * CRT_CPT::sanitize_*. The photo is the featured image; the biography is
 * the content.
 */

defined( 'ABSPATH' ) || exit;

final class CRT_Metabox {

	const NONCE      = 'crt_mb';
	const NONCE_AJAX = 'crt_terms';

	public static function init(): void {
		add_action( 'add_meta_boxes_' . CRT_CPT::PT, array( __CLASS__, 'add' ) );
		add_action( 'save_post_' . CRT_CPT::PT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_crt_terms', array( __CLASS__, 'ajax_terms' ) );
	}

	public static function assets( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || CRT_CPT::PT !== get_post_type() ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'crt-admin', CRT_URL . 'assets/admin.css', array(), CRT_VERSION );
		wp_enqueue_script( 'crt-admin', CRT_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), CRT_VERSION, true );
		wp_localize_script(
			'crt-admin',
			'crtAdmin',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_AJAX ),
				'noResults' => __( 'Κανένα αποτέλεσμα.', 'featured-creators' ),
				'pick'      => __( 'Εικόνα βίντεο', 'featured-creators' ),
				'use'       => __( 'Χρήση εικόνας', 'featured-creators' ),
				'remove'    => __( 'Αφαίρεση', 'featured-creators' ),
			)
		);
	}

	public static function add(): void {
		add_meta_box( 'crt-details', __( 'Στοιχεία δημιουργού', 'featured-creators' ), array( __CLASS__, 'box_details' ), CRT_CPT::PT, 'normal', 'high' );
		add_meta_box( 'crt-press', __( 'Συνεντεύξεις, βίντεο, κριτικές και αναφορές', 'featured-creators' ), array( __CLASS__, 'box_press' ), CRT_CPT::PT, 'normal', 'default' );
		add_meta_box( 'crt-match', __( 'Ταίριασμα με χαρακτηριστικά', 'featured-creators' ), array( __CLASS__, 'box_match' ), CRT_CPT::PT, 'normal', 'default' );
	}

	/* =====================================================================
	 * Boxes
	 * =================================================================== */

	public static function box_details( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, 'crt_mb_nonce' );
		$links = CRT_CPT::links( $post->ID );
		?>
		<div class="crt-mb">
			<label class="crt-label" for="crt-roles-text"><?php esc_html_e( 'Ιδιότητες', 'featured-creators' ); ?></label>
			<input type="text" id="crt-roles-text" class="crt-w100" name="crt_roles_text" maxlength="160" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, CRT_CPT::M_ROLES_TEXT, true ) ); ?>" placeholder="<?php echo esc_attr( CRT_Front::roles_line_auto( $post->ID ) ); ?>" />
			<p class="description"><?php esc_html_e( 'Π.χ. «Ποιήτρια, μουσικός». Κενό = οι ρόλοι όπου έχει προϊόντα (φαίνονται ως placeholder).', 'featured-creators' ); ?></p>

			<label class="crt-label" for="crt-sort"><?php esc_html_e( 'Όνομα ταξινόμησης', 'featured-creators' ); ?></label>
			<input type="text" id="crt-sort" name="crt_sort" maxlength="120" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, CRT_CPT::M_SORT, true ) ); ?>" placeholder="<?php echo esc_attr( $post->post_title ); ?>" />
			<p class="description"><?php esc_html_e( 'Για τη σειρά στο ευρετήριο, π.χ. «Κουλαξίζης Χρήστος». Κενό = το όνομα όπως γράφεται.', 'featured-creators' ); ?></p>

			<label class="crt-label" for="crt-gender"><?php esc_html_e( 'Άρθρο στον τίτλο της καρτέλας', 'featured-creators' ); ?></label>
			<select id="crt-gender" name="crt_gender">
				<?php
				foreach ( array(
					''  => __( 'Χωρίς άρθρο («Συγγραφέας»)', 'featured-creators' ),
					'm' => __( 'Ο («Ο συγγραφέας»)', 'featured-creators' ),
					'f' => __( 'Η («Η συγγραφέας»)', 'featured-creators' ),
				) as $k => $label ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( CRT_CPT::gender( $post->ID ), $k, false ) . '>' . esc_html( $label ) . '</option>';
				}
				?>
			</select>
			<p class="description"><?php esc_html_e( 'Για την καρτέλα δημιουργού στο προϊόν, όταν το προϊόν έχει μόνο αυτόν τον δημιουργό.', 'featured-creators' ); ?></p>

			<span class="crt-label"><?php esc_html_e( 'Σύνδεσμοι', 'featured-creators' ); ?></span>
			<div class="crt-rows" data-crt-rows="links">
				<?php
				foreach ( $links as $i => $l ) {
					self::link_row( (string) $i, $l );
				}
				?>
			</div>
			<template data-crt-tpl="links"><?php self::link_row( '__i__', array( 'url' => '', 'label' => '' ) ); ?></template>
			<button type="button" class="button" data-crt-add="links">+ <?php esc_html_e( 'Προσθήκη συνδέσμου', 'featured-creators' ); ?></button>
			<p class="description"><?php esc_html_e( 'Site, μουσικές υπηρεσίες, κοινωνικά δίκτυα. Η υπηρεσία αναγνωρίζεται από τη διεύθυνση (Spotify, YouTube, Instagram…)· η ετικέτα είναι προαιρετική.', 'featured-creators' ); ?></p>
		</div>
		<?php
	}

	private static function link_row( string $i, array $l ): void {
		?>
		<div class="crt-rowline">
			<span class="crt-handle" aria-hidden="true">⋮⋮</span>
			<input type="url" name="crt_links[<?php echo esc_attr( $i ); ?>][url]" value="<?php echo esc_attr( $l['url'] ); ?>" placeholder="https://" />
			<input type="text" name="crt_links[<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( $l['label'] ); ?>" maxlength="60" placeholder="<?php esc_attr_e( 'Ετικέτα (προαιρετική)', 'featured-creators' ); ?>" />
			<a href="#" class="crt-del" data-crt-del title="<?php esc_attr_e( 'Αφαίρεση', 'featured-creators' ); ?>">×</a>
		</div>
		<?php
	}

	public static function box_press( WP_Post $post ): void {
		$items = CRT_CPT::sanitize_press( CRT_CPT::json_meta( $post->ID, CRT_CPT::M_PRESS ) );
		?>
		<div class="crt-mb">
			<div class="crt-rows" data-crt-rows="press">
				<?php
				foreach ( $items as $i => $it ) {
					self::press_row( (string) $i, $it );
				}
				?>
			</div>
			<template data-crt-tpl="press">
				<?php
				self::press_row(
					'__i__',
					array(
						'type'   => 'interview',
						'date'   => '',
						'outlet' => '',
						'title'  => '',
						'url'    => '',
						'image'  => 0,
					)
				);
				?>
			</template>
			<button type="button" class="button" data-crt-add="press">+ <?php esc_html_e( 'Προσθήκη', 'featured-creators' ); ?></button>
			<p class="description"><?php esc_html_e( 'Στη σελίδα εμφανίζονται σε τρεις λίστες, νεότερο πρώτο. Τα βίντεο είναι σύνδεσμοι (χωρίς ενσωμάτωση, άρα χωρίς cookies τρίτων), με προαιρετική εικόνα από τη βιβλιοθήκη πολυμέσων.', 'featured-creators' ); ?></p>
		</div>
		<?php
	}

	private static function press_row( string $i, array $it ): void {
		$n     = 'crt_press[' . $i . ']';
		$types = array(
			'interview' => __( 'Συνέντευξη', 'featured-creators' ),
			'video'     => __( 'Βίντεο', 'featured-creators' ),
			'review'    => __( 'Κριτική / αναφορά', 'featured-creators' ),
		);
		$img   = $it['image'] ? wp_get_attachment_image_url( (int) $it['image'], 'thumbnail' ) : '';
		?>
		<div class="crt-rowline" data-crt-press>
			<span class="crt-handle" aria-hidden="true">⋮⋮</span>
			<select name="<?php echo esc_attr( $n ); ?>[type]" data-crt-type>
				<?php foreach ( $types as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $it['type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" name="<?php echo esc_attr( $n ); ?>[date]" value="<?php echo esc_attr( $it['date'] ); ?>" />
			<input type="text" name="<?php echo esc_attr( $n ); ?>[outlet]" value="<?php echo esc_attr( $it['outlet'] ); ?>" maxlength="120" placeholder="<?php esc_attr_e( 'Μέσο', 'featured-creators' ); ?>" />
			<input type="text" name="<?php echo esc_attr( $n ); ?>[title]" value="<?php echo esc_attr( $it['title'] ); ?>" maxlength="200" placeholder="<?php esc_attr_e( 'Τίτλος', 'featured-creators' ); ?>" />
			<input type="url" name="<?php echo esc_attr( $n ); ?>[url]" value="<?php echo esc_attr( $it['url'] ); ?>" placeholder="https://" />
			<span data-crt-video<?php echo 'video' === $it['type'] ? '' : ' hidden'; ?>>
				<input type="hidden" name="<?php echo esc_attr( $n ); ?>[image]" value="<?php echo (int) $it['image']; ?>" data-crt-img />
				<img class="crt-thumb" src="<?php echo esc_url( (string) $img ); ?>" alt="" <?php echo $img ? '' : 'hidden'; ?> data-crt-imgview />
				<button type="button" class="button" data-crt-pick><?php esc_html_e( 'Εικόνα', 'featured-creators' ); ?></button>
			</span>
			<a href="#" class="crt-del" data-crt-del title="<?php esc_attr_e( 'Αφαίρεση', 'featured-creators' ); ?>">×</a>
		</div>
		<?php
	}

	public static function box_match( WP_Post $post ): void {
		$taxes = CRT_Settings::role_taxes();
		if ( ! $taxes ) {
			echo '<div class="crt-mb"><p class="crt-warn">' . esc_html__( 'Δεν έχουν οριστεί ρόλοι.', 'featured-creators' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . CRT_Admin_UI::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'featured-creators' ) . '</a></p></div>';
			return;
		}
		$labels  = CRT_Settings::attribute_taxonomies();
		$key     = CRT_CPT::key( $post->ID );
		$stored  = (string) get_post_meta( $post->ID, CRT_CPT::M_KEY, true );
		$exclude = CRT_CPT::ids_meta( $post->ID, CRT_CPT::M_EXCLUDE );
		$extra   = CRT_CPT::ids_meta( $post->ID, CRT_CPT::M_EXTRA );
		$owned   = array_flip( CRT_CPT::ids_meta( $post->ID, CRT_CPT::M_TERMS ) );
		$conf    = CRT_Match::conflicts();

		// Terms found with the key.
		$found = array();
		foreach ( CRT_Match::role_terms() as $tt => $t ) {
			if ( '' !== $key && urldecode( (string) $t->slug ) === $key ) {
				$found[ $tt ] = $t;
			}
		}
		$counts = CRT_Match::term_counts( array_merge( array_keys( $found ), $extra ) );
		?>
		<div class="crt-mb">
			<label class="crt-label" for="crt-key"><?php esc_html_e( 'Κλειδί (slug των terms)', 'featured-creators' ); ?></label>
			<input type="text" id="crt-key" name="crt_key" maxlength="200" value="<?php echo esc_attr( $stored ); ?>" placeholder="<?php echo esc_attr( urldecode( (string) $post->post_name ) ); ?>" />
			<p class="description"><?php esc_html_e( 'Ο δημιουργός παίρνει αυτόματα κάθε term με αυτό το slug σε όλους τους ρόλους. Κενό = το slug της σελίδας.', 'featured-creators' ); ?></p>

			<span class="crt-label"><?php esc_html_e( 'Terms με αυτό το κλειδί', 'featured-creators' ); ?></span>
			<?php if ( ! $found ) : ?>
				<p class="crt-muted"><?php esc_html_e( 'Κανένα term δεν έχει αυτό το slug. Γράψε το σωστό κλειδί ή πρόσθεσε terms παρακάτω.', 'featured-creators' ); ?></p>
			<?php else : ?>
				<table class="crt-terms">
					<thead><tr>
						<th><?php esc_html_e( 'Ρόλος', 'featured-creators' ); ?></th>
						<th><?php esc_html_e( 'Όνομα', 'featured-creators' ); ?></th>
						<th><?php esc_html_e( 'Προϊόντα', 'featured-creators' ); ?></th>
						<th><?php esc_html_e( 'Κατάσταση', 'featured-creators' ); ?></th>
						<th><?php esc_html_e( 'Εξαίρεση', 'featured-creators' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $found as $tt => $t ) : ?>
						<tr>
							<td><?php echo esc_html( isset( $labels[ $t->taxonomy ] ) ? $labels[ $t->taxonomy ] : $t->taxonomy ); ?></td>
							<td><?php echo esc_html( $t->name ); ?></td>
							<td><?php echo (int) ( isset( $counts[ $tt ] ) ? $counts[ $tt ] : 0 ); ?></td>
							<td><?php self::term_state( (int) $tt, $post->ID, $owned, $conf ); ?></td>
							<td><input type="checkbox" name="crt_exclude[]" value="<?php echo (int) $tt; ?>" <?php checked( in_array( (int) $tt, $exclude, true ) ); ?> /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Εξαίρεση: για συνωνυμίες, όταν ένα term με το ίδιο slug ανήκει σε άλλο πρόσωπο. Ο άλλος δημιουργός το προσθέτει στα «Επιπλέον terms».', 'featured-creators' ); ?></p>
			<?php endif; ?>

			<span class="crt-label"><?php esc_html_e( 'Επιπλέον terms (ψευδώνυμα, άλλη γραφή)', 'featured-creators' ); ?></span>
			<div data-crt-chips>
				<?php
				foreach ( $extra as $tt ) {
					$t = get_term_by( 'term_taxonomy_id', $tt );
					if ( ! $t instanceof WP_Term ) {
						continue;
					}
					echo '<span class="crt-chip"><input type="hidden" name="crt_extra[]" value="' . (int) $tt . '" />'
						. esc_html( $t->name . ' · ' . ( isset( $labels[ $t->taxonomy ] ) ? $labels[ $t->taxonomy ] : $t->taxonomy ) . ' · ' . ( isset( $counts[ $tt ] ) ? (int) $counts[ $tt ] : 0 ) )
						. ' ';
					self::term_state( (int) $tt, $post->ID, $owned, $conf );
					echo ' <a href="#" data-crt-chip-del title="' . esc_attr__( 'Αφαίρεση', 'featured-creators' ) . '">×</a></span>';
				}
				?>
			</div>
			<div class="crt-ac">
				<input type="search" class="crt-w100" data-crt-ac placeholder="<?php esc_attr_e( 'Αναζήτηση ονόματος σε όλους τους ρόλους (3+ γράμματα)', 'featured-creators' ); ?>" autocomplete="off" />
				<div class="crt-ac-list" data-crt-ac-list hidden></div>
			</div>
			<p class="description"><?php esc_html_e( 'Οι αλλαγές ισχύουν με την αποθήκευση. Ένα term ανήκει σε έναν μόνο δημιουργό: το χειροκίνητο νικά το αυτόματο, και δύο ίσες διεκδικήσεις είναι σύγκρουση (κανείς δεν το παίρνει μέχρι να λυθεί).', 'featured-creators' ); ?></p>
		</div>
		<?php
	}

	/** Badge: owned / conflict / another creator / not yet saved. */
	private static function term_state( int $tt, int $cid, array $owned, array $conf ): void {
		if ( isset( $owned[ $tt ] ) ) {
			echo '<span class="crt-muted">✓ ' . esc_html__( 'δικό του', 'featured-creators' ) . '</span>';
			return;
		}
		if ( isset( $conf[ $tt ] ) && in_array( $cid, array_map( 'intval', (array) $conf[ $tt ] ), true ) ) {
			$others = array();
			foreach ( (array) $conf[ $tt ] as $o ) {
				if ( (int) $o !== $cid ) {
					$others[] = '<a href="' . esc_url( (string) get_edit_post_link( (int) $o ) ) . '">' . esc_html( get_the_title( (int) $o ) ) . '</a>';
				}
			}
			echo '<span class="crt-warn">' . esc_html__( 'σύγκρουση με', 'featured-creators' ) . '</span> ' . implode( ', ', $others ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			return;
		}
		$t = get_term_by( 'term_taxonomy_id', $tt );
		$o = $t instanceof WP_Term ? CRT_Match::owner( (int) $t->term_id ) : 0;
		if ( $o && $o !== $cid ) {
			echo '<span class="crt-warn">' . esc_html__( 'ανήκει σε:', 'featured-creators' ) . '</span> <a href="' . esc_url( (string) get_edit_post_link( $o ) ) . '">' . esc_html( get_the_title( $o ) ) . '</a>';
			return;
		}
		echo '<span class="crt-muted">' . esc_html__( 'μετά την αποθήκευση', 'featured-creators' ) . '</span>';
	}

	/* =====================================================================
	 * Save
	 * =================================================================== */

	public static function save( $post_id, $post ): void {
		if ( ! isset( $_POST['crt_mb_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['crt_mb_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$text = isset( $_POST['crt_roles_text'] ) && is_string( $_POST['crt_roles_text'] ) ? CRT_Settings::clip( sanitize_text_field( wp_unslash( $_POST['crt_roles_text'] ) ), 160 ) : '';
		self::set_or_delete( (int) $post_id, CRT_CPT::M_ROLES_TEXT, $text );

		$sort = isset( $_POST['crt_sort'] ) && is_string( $_POST['crt_sort'] ) ? CRT_Settings::clip( sanitize_text_field( wp_unslash( $_POST['crt_sort'] ) ), 120 ) : '';
		self::set_or_delete( (int) $post_id, CRT_CPT::M_SORT, $sort );

		$gender = isset( $_POST['crt_gender'] ) && is_string( $_POST['crt_gender'] ) ? CRT_CPT::rest_gender( sanitize_key( wp_unslash( $_POST['crt_gender'] ) ) ) : '';
		self::set_or_delete( (int) $post_id, CRT_CPT::M_GENDER, $gender );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by CRT_CPT::sanitize_links().
		$links = isset( $_POST['crt_links'] ) && is_array( $_POST['crt_links'] ) ? wp_unslash( $_POST['crt_links'] ) : array();
		CRT_CPT::set_json_meta( (int) $post_id, CRT_CPT::M_LINKS, CRT_CPT::sanitize_links( array_values( $links ) ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by CRT_CPT::sanitize_press().
		$press = isset( $_POST['crt_press'] ) && is_array( $_POST['crt_press'] ) ? wp_unslash( $_POST['crt_press'] ) : array();
		CRT_CPT::set_json_meta( (int) $post_id, CRT_CPT::M_PRESS, CRT_CPT::sanitize_press( array_values( $press ) ) );

		if ( isset( $_POST['crt_key'] ) ) {
			$key = is_string( $_POST['crt_key'] ) ? sanitize_title( wp_unslash( $_POST['crt_key'] ) ) : '';
			self::set_or_delete( (int) $post_id, CRT_CPT::M_KEY, urldecode( $key ) );
		}

		$valid = CRT_Match::role_terms();
		foreach ( array(
			'crt_exclude' => CRT_CPT::M_EXCLUDE,
			'crt_extra'   => CRT_CPT::M_EXTRA,
		) as $field => $meta ) {
			$list = array();
			if ( isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ) {
				foreach ( wp_unslash( $_POST[ $field ] ) as $tt ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- intval + whitelist below.
					$tt = (int) $tt;
					if ( isset( $valid[ $tt ] ) ) {
						$list[] = $tt;
					}
				}
			}
			$list = array_values( array_unique( $list ) );
			sort( $list );
			CRT_CPT::set_json_meta( (int) $post_id, $meta, $list );
		}
	}

	private static function set_or_delete( int $id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $id, $key );
		} else {
			update_post_meta( $id, $key, wp_slash( $value ) );
		}
	}

	/* =====================================================================
	 * AJAX: term search (Bible §5, §11)
	 * =================================================================== */

	public static function ajax_terms(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array(), 403 );
		}
		$q = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q );
		$taxes = CRT_Settings::role_taxes();
		if ( $len < 3 || ! $taxes ) {
			wp_send_json_success( array() );
		}
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_taxonomy_id AS tt, tt.taxonomy AS tax, t.name, t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ($in) AND ( t.name LIKE %s OR t.slug LIKE %s ) ORDER BY t.name ASC LIMIT 30", array_merge( $taxes, array( $like, $like ) ) ) );
		$labels = CRT_Settings::attribute_taxonomies();
		$out    = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'tt'    => (int) $r->tt,
				'label' => $r->name . ' · ' . ( isset( $labels[ $r->tax ] ) ? $labels[ $r->tax ] : $r->tax ) . ' · ' . urldecode( (string) $r->slug ),
			);
		}
		wp_send_json_success( $out );
	}
}
