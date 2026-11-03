<?php
/**
 * SSG_Editor — per-object fields: a «Search Signals» meta box on every
 * public post type (classic and block editor) and fields on the edit
 * screen of every public taxonomy.
 *
 * Fields: title, description (with counters and a search-result preview),
 * noindex / nofollow, canonical, share image, primary category / brand
 * (products: product_cat, product_brand; posts: category) when the object
 * has more than one. No «SEO score», no keyword analysis.
 *
 * Who: whoever may edit the object (edit_post / edit_term). Saved with a
 * nonce; an empty field deletes its meta (the template applies again).
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Editor {

	const NONCE = 'ssg_editor';
	const FIELD = 'ssg_editor_nonce';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'term_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Hidden meta, readable over REST only by those who may edit (not exposed by default). */
	public static function register_meta(): void {
		$keys = array( SSG_Meta::TITLE, SSG_Meta::DESC, SSG_Meta::CANON );
		foreach ( $keys as $k ) {
			register_meta(
				'post',
				$k,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static function ( $allowed, $key, $id ) {
						return current_user_can( 'edit_post', (int) $id );
					},
				)
			);
			register_meta(
				'term',
				$k,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static function ( $allowed, $key, $id ) {
						return current_user_can( 'edit_term', (int) $id );
					},
				)
			);
		}
	}

	private static function types(): array {
		return SSG_Settings::public_types();
	}

	public static function add_box( $post_type, $post = null ): void {
		if ( ! in_array( (string) $post_type, self::types(), true ) ) {
			return;
		}
		add_meta_box( 'ssg-box', 'Search Signals', array( __CLASS__, 'render_box' ), (string) $post_type, 'normal', 'default' );
	}

	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$post_screen = 'post' === $screen->base && in_array( (string) $screen->post_type, self::types(), true );
		$term_screen = 'term' === $screen->base && in_array( (string) $screen->taxonomy, SSG_Settings::public_taxes(), true );
		if ( ! $post_screen && ! $term_screen ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'ssg-editor', SSG_URL . 'assets/editor.css', array(), SSG_VERSION );
		wp_enqueue_script( 'ssg-editor', SSG_URL . 'assets/editor.js', array( 'jquery' ), SSG_VERSION, true );
		wp_localize_script(
			'ssg-editor',
			'ssgEditor',
			array(
				'pick'  => __( 'Διάλεξε εικόνα κοινοποίησης', 'search-signals' ),
				'use'   => __( 'Χρήση', 'search-signals' ),
				'chars' => __( '%d χαρακτήρες', 'search-signals' ),
			)
		);
	}

	/* =====================================================================
	 * Posts
	 * =================================================================== */

	/** @param WP_Post $post */
	public static function render_box( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::FIELD );
		$c      = SSG_Context::from_post( (int) $post->ID );
		$robots = get_post_meta( $post->ID, SSG_Meta::ROBOTS, true );
		$robots = is_array( $robots ) ? $robots : array();
		$prim   = array();
		foreach ( self::primary_taxes( (string) $post->post_type ) as $tx ) {
			$terms = get_the_terms( $post->ID, $tx );
			if ( is_array( $terms ) && count( $terms ) > 1 ) {
				$prim[ $tx ] = $terms;
			}
		}
		self::fields(
			array(
				'title'     => (string) get_post_meta( $post->ID, SSG_Meta::TITLE, true ),
				'desc'      => (string) get_post_meta( $post->ID, SSG_Meta::DESC, true ),
				'canonical' => (string) get_post_meta( $post->ID, SSG_Meta::CANON, true ),
				'image'     => (int) get_post_meta( $post->ID, SSG_Meta::IMAGE, true ),
				'robots'    => $robots,
			),
			$c,
			$prim,
			(int) $post->ID
		);
	}

	private static function primary_taxes( string $pt ): array {
		if ( 'product' === $pt ) {
			return array( 'product_cat', 'product_brand' );
		}
		return 'post' === $pt ? array( 'category' ) : array();
	}

	/**
	 * The fields, shared by posts and terms.
	 *
	 * @param array       $v    title, desc, canonical, image, robots.
	 * @param SSG_Context $c    For the preview (saved values).
	 * @param array       $prim taxonomy => WP_Term[] (posts only).
	 * @param int         $post_id
	 */
	private static function fields( array $v, SSG_Context $c, array $prim, int $post_id ): void {
		$tpl   = SSG_Settings::tpl( $c->tpl_key() );
		$title = SSG_Meta::title( $c );
		$desc  = SSG_Meta::description( $c );
		$url   = SSG_Meta::canonical( $c );
		if ( '' === $url ) {
			$url = SSG_Meta::base_url( $c );
		}
		$img = $v['image'] ? wp_get_attachment_image_url( $v['image'], 'thumbnail' ) : '';
		?>
		<div class="ssg-ed">
			<div class="ssg-ed-preview" aria-label="<?php esc_attr_e( 'Προεπισκόπηση αποτελέσματος αναζήτησης', 'search-signals' ); ?>">
				<div class="ssg-ed-url"><?php echo esc_html( $url ); ?></div>
				<div class="ssg-ed-title"><?php echo esc_html( $title ); ?></div>
				<div class="ssg-ed-desc"><?php echo esc_html( '' !== $desc ? $desc : __( '(χωρίς περιγραφή: η μηχανή αναζήτησης θα διαλέξει ένα κομμάτι της σελίδας)', 'search-signals' ) ); ?></div>
				<p class="ssg-ed-note"><?php esc_html_e( 'Η προεπισκόπηση δείχνει τις αποθηκευμένες τιμές.', 'search-signals' ); ?></p>
			</div>

			<p class="ssg-ed-row">
				<label for="ssg_title"><strong><?php esc_html_e( 'Τίτλος', 'search-signals' ); ?></strong></label>
				<input type="text" class="widefat ssg-count" data-max="60" id="ssg_title" name="ssg_title" maxlength="300" value="<?php echo esc_attr( $v['title'] ); ?>" placeholder="<?php echo esc_attr( $tpl['t'] ); ?>" />
				<span class="description"><?php esc_html_e( 'Κενό = το template του τύπου. Δέχεται μεταβλητές, π.χ. %title% %sep% %sitename%. Ως 60 χαρακτήρες φαίνονται ολόκληροι.', 'search-signals' ); ?> <span class="ssg-counter"></span></span>
			</p>
			<p class="ssg-ed-row">
				<label for="ssg_desc"><strong><?php esc_html_e( 'Περιγραφή', 'search-signals' ); ?></strong></label>
				<textarea class="widefat ssg-count" data-max="160" id="ssg_desc" name="ssg_desc" rows="3" maxlength="300" placeholder="<?php echo esc_attr( $tpl['d'] ); ?>"><?php echo esc_textarea( $v['desc'] ); ?></textarea>
				<span class="description"><?php esc_html_e( 'Κενό = το template του τύπου. Ως 160 χαρακτήρες φαίνονται ολόκληροι.', 'search-signals' ); ?> <span class="ssg-counter"></span></span>
			</p>
			<p class="ssg-ed-row">
				<label><input type="checkbox" name="ssg_robots[]" value="noindex" <?php checked( in_array( 'noindex', $v['robots'], true ) ); ?> /> <?php esc_html_e( 'noindex: να μη μπει στις μηχανές αναζήτησης (φεύγει και από το sitemap)', 'search-signals' ); ?></label><br />
				<label><input type="checkbox" name="ssg_robots[]" value="nofollow" <?php checked( in_array( 'nofollow', $v['robots'], true ) ); ?> /> <?php esc_html_e( 'nofollow: οι σύνδεσμοι της σελίδας να μη μετρούν', 'search-signals' ); ?></label><br />
				<span class="description"><?php echo esc_html( $tpl['n'] ? __( 'Ο τύπος είναι noindex από τις ρυθμίσεις· αν διαλέξεις κάτι εδώ, ισχύει μόνο αυτό.', 'search-signals' ) : __( 'Χωρίς επιλογή ισχύει η ρύθμιση του τύπου (index, follow).', 'search-signals' ) ); ?></span>
			</p>
			<p class="ssg-ed-row">
				<label for="ssg_canonical"><strong><?php esc_html_e( 'Canonical', 'search-signals' ); ?></strong></label>
				<input type="url" class="widefat" id="ssg_canonical" name="ssg_canonical" maxlength="500" value="<?php echo esc_attr( $v['canonical'] ); ?>" placeholder="<?php echo esc_attr( SSG_Meta::base_url( $c ) ); ?>" />
				<span class="description"><?php esc_html_e( 'Κενό = η διεύθυνση της ίδιας της σελίδας. Συμπλήρωσε μόνο αν το ίδιο περιεχόμενο ζει αλλού.', 'search-signals' ); ?></span>
			</p>
			<div class="ssg-ed-row ssg-ed-image">
				<strong><?php esc_html_e( 'Εικόνα κοινοποίησης', 'search-signals' ); ?></strong><br />
				<input type="hidden" name="ssg_image" class="ssg-image-id" value="<?php echo (int) $v['image']; ?>" />
				<span class="ssg-image-preview"><?php echo $img ? '<img src="' . esc_url( (string) $img ) . '" alt="" />' : ''; ?></span>
				<button type="button" class="button ssg-image-pick"><?php esc_html_e( 'Επιλογή', 'search-signals' ); ?></button>
				<button type="button" class="button-link ssg-image-clear"><?php esc_html_e( 'Αφαίρεση', 'search-signals' ); ?></button>
				<p class="description"><?php esc_html_e( 'Κενό = η κύρια εικόνα, αλλιώς η πρώτη εικόνα του περιεχομένου, αλλιώς η προεπιλογή των ρυθμίσεων. Ιδανικό μέγεθος 1200 × 630.', 'search-signals' ); ?></p>
			</div>
			<?php
			foreach ( $prim as $tx => $terms ) {
				$tax  = get_taxonomy( (string) $tx );
				$cur  = (int) get_post_meta( $post_id, SSG_Meta::PRIMARY . $tx, true );
				$auto = SSG_Meta::primary_term( $post_id, (string) $tx );
				?>
				<p class="ssg-ed-row">
					<label for="ssg_primary_<?php echo esc_attr( (string) $tx ); ?>"><strong><?php echo esc_html( sprintf( /* translators: %s: taxonomy name */ __( 'Κύρια: %s', 'search-signals' ), $tax ? $tax->labels->singular_name : (string) $tx ) ); ?></strong></label><br />
					<select id="ssg_primary_<?php echo esc_attr( (string) $tx ); ?>" name="ssg_primary[<?php echo esc_attr( (string) $tx ); ?>]">
						<option value="0"><?php echo esc_html( sprintf( /* translators: %s: term name */ __( 'Αυτόματα (%s)', 'search-signals' ), $auto && ! $cur ? $auto->name : '…' ) ); ?></option>
						<?php foreach ( $terms as $t ) : ?>
							<option value="<?php echo (int) $t->term_id; ?>" <?php selected( $cur, (int) $t->term_id ); ?>><?php echo esc_html( $t->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Για το breadcrumb και την κατηγορία στο schema. Δεν αλλάζει τη διεύθυνση.', 'search-signals' ); ?></span>
				</p>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function save_post( $post_id, $post ): void {
		if ( ! isset( $_POST[ self::FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( (int) $post_id ) || ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		$post_id = (int) $post_id;
		$vals    = self::posted();
		self::store( 'post', $post_id, $vals );

		$primary = isset( $_POST['ssg_primary'] ) && is_array( $_POST['ssg_primary'] ) ? wp_unslash( $_POST['ssg_primary'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value absint below.
		foreach ( self::primary_taxes( $post instanceof WP_Post ? (string) $post->post_type : '' ) as $tx ) {
			$tid = isset( $primary[ $tx ] ) ? absint( $primary[ $tx ] ) : 0;
			if ( $tid && has_term( $tid, $tx, $post_id ) ) {
				update_post_meta( $post_id, SSG_Meta::PRIMARY . $tx, $tid );
			} elseif ( isset( $primary[ $tx ] ) ) {
				delete_post_meta( $post_id, SSG_Meta::PRIMARY . $tx );
			}
		}
	}

	/** The posted fields, validated. */
	private static function posted(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the callers.
		$title  = isset( $_POST['ssg_title'] ) ? mb_substr( SSG_Settings::clean( wp_unslash( (string) $_POST['ssg_title'] ) ), 0, 300 ) : '';
		$desc   = isset( $_POST['ssg_desc'] ) ? mb_substr( SSG_Settings::clean( wp_unslash( (string) $_POST['ssg_desc'] ) ), 0, 300 ) : '';
		$canon  = isset( $_POST['ssg_canonical'] ) ? esc_url_raw( trim( wp_unslash( (string) $_POST['ssg_canonical'] ) ), array( 'http', 'https' ) ) : '';
		$image  = isset( $_POST['ssg_image'] ) ? absint( $_POST['ssg_image'] ) : 0;
		$robots = array();
		if ( isset( $_POST['ssg_robots'] ) && is_array( $_POST['ssg_robots'] ) ) {
			foreach ( wp_unslash( $_POST['ssg_robots'] ) as $r ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- whitelisted below.
				if ( in_array( $r, array( 'noindex', 'nofollow' ), true ) && ! in_array( $r, $robots, true ) ) {
					$robots[] = $r;
				}
			}
		}
		// phpcs:enable
		return array(
			'title'     => trim( $title ),
			'desc'      => trim( $desc ),
			'canonical' => strlen( $canon ) <= 500 ? $canon : '',
			'image'     => $image && wp_attachment_is_image( $image ) ? $image : 0,
			'robots'    => $robots,
		);
	}

	/**
	 * Writes the fields of a post or a term ('' / [] / 0 = delete). Also
	 * used by the import and the set-meta ability.
	 *
	 * @param string $type post | term
	 */
	public static function store( string $type, int $id, array $v ): void {
		$map = array(
			'title'     => SSG_Meta::TITLE,
			'desc'      => SSG_Meta::DESC,
			'canonical' => SSG_Meta::CANON,
			'image'     => SSG_Meta::IMAGE,
			'robots'    => SSG_Meta::ROBOTS,
		);
		foreach ( $map as $field => $key ) {
			if ( ! array_key_exists( $field, $v ) ) {
				continue;
			}
			$val = $v[ $field ];
			if ( '' === $val || 0 === $val || array() === $val || null === $val ) {
				'post' === $type ? delete_post_meta( $id, $key ) : delete_term_meta( $id, $key );
			} else {
				'post' === $type ? update_post_meta( $id, $key, is_string( $val ) ? wp_slash( $val ) : $val ) : update_term_meta( $id, $key, is_string( $val ) ? wp_slash( $val ) : $val );
			}
		}
	}

	/* =====================================================================
	 * Terms
	 * =================================================================== */

	public static function term_hooks(): void {
		foreach ( SSG_Settings::public_taxes() as $tx ) {
			add_action( $tx . '_edit_form_fields', array( __CLASS__, 'render_term' ), 20, 2 );
			add_action( 'edited_' . $tx, array( __CLASS__, 'save_term' ), 10, 2 );
		}
	}

	/** @param WP_Term $term */
	public static function render_term( $term, $taxonomy = '' ): void {
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		$robots = get_term_meta( $term->term_id, SSG_Meta::ROBOTS, true );
		echo '<tr class="form-field ssg-term"><th scope="row">Search Signals</th><td>';
		wp_nonce_field( self::NONCE, self::FIELD );
		self::fields(
			array(
				'title'     => (string) get_term_meta( $term->term_id, SSG_Meta::TITLE, true ),
				'desc'      => (string) get_term_meta( $term->term_id, SSG_Meta::DESC, true ),
				'canonical' => (string) get_term_meta( $term->term_id, SSG_Meta::CANON, true ),
				'image'     => (int) get_term_meta( $term->term_id, SSG_Meta::IMAGE, true ),
				'robots'    => is_array( $robots ) ? $robots : array(),
			),
			SSG_Context::from_term( (int) $term->term_id, (string) $term->taxonomy ),
			array(),
			0
		);
		echo '</td></tr>';
	}

	public static function save_term( $term_id, $tt_id = 0 ): void {
		if ( ! isset( $_POST[ self::FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ), self::NONCE ) || ! current_user_can( 'edit_term', (int) $term_id ) ) {
			return;
		}
		self::store( 'term', (int) $term_id, self::posted() );
	}
}
