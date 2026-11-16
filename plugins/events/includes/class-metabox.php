<?php
/**
 * EVT_Metabox — the event edit screen («Στοιχεία εκδήλωσης») and the venue
 * term fields (address, city, postal code, country, map link).
 *
 * Dates are typed in the site's time zone and stored as Unix time. A new
 * venue can be written right in the event box; it becomes a nox_venue term
 * that later events pick from the list. Products come from WooCommerce's
 * product search when WooCommerce is active; creators from Featured
 * Creators when it is.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Metabox {

	const NONCE = 'evt_meta';

	public static function init(): void {
		add_action( 'add_meta_boxes_' . EVT_CPT::PT, array( __CLASS__, 'add' ) );
		add_action( 'save_post_' . EVT_CPT::PT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( EVT_CPT::TAX . '_add_form_fields', array( __CLASS__, 'term_add' ) );
		add_action( EVT_CPT::TAX . '_edit_form_fields', array( __CLASS__, 'term_edit' ) );
		add_action( 'created_' . EVT_CPT::TAX, array( __CLASS__, 'term_save' ) );
		add_action( 'edited_' . EVT_CPT::TAX, array( __CLASS__, 'term_save' ) );
	}

	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( EVT_CPT::PT !== $screen->post_type ) ) {
			return;
		}
		wp_enqueue_style( 'evt-admin', EVT_URL . 'assets/admin.css', array(), EVT_VERSION );
		if ( Events_Plugin::woo() && wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	public static function add(): void {
		add_meta_box( 'evt_details', __( 'Στοιχεία εκδήλωσης', 'events' ), array( __CLASS__, 'render' ), EVT_CPT::PT, 'normal', 'high' );
	}

	private static function kinds(): array {
		return array(
			'Event'           => __( 'Γενική εκδήλωση', 'events' ),
			'LiteraryEvent'   => __( 'Λογοτεχνική (παρουσίαση, ανάγνωση, υπογραφές)', 'events' ),
			'MusicEvent'      => __( 'Μουσική (συναυλία)', 'events' ),
			'EducationEvent'  => __( 'Εκπαιδευτική (εργαστήριο, σεμινάριο)', 'events' ),
			'ExhibitionEvent' => __( 'Έκθεση', 'events' ),
			'TheaterEvent'    => __( 'Θέατρο', 'events' ),
			'ScreeningEvent'  => __( 'Προβολή', 'events' ),
			'ChildrensEvent'  => __( 'Για παιδιά', 'events' ),
			'Festival'        => __( 'Φεστιβάλ', 'events' ),
			'SocialEvent'     => __( 'Κοινωνική (πάρτι, γνωριμία)', 'events' ),
		);
	}

	private static function statuses(): array {
		return array(
			'scheduled'   => __( 'Προγραμματισμένη', 'events' ),
			'postponed'   => __( 'Αναβλήθηκε (χωρίς νέα ημερομηνία)', 'events' ),
			'rescheduled' => __( 'Νέα ημερομηνία', 'events' ),
			'cancelled'   => __( 'Ακυρώθηκε', 'events' ),
		);
	}

	public static function render( WP_Post $post ): void {
		$e     = EVT_CPT::event( (int) $post->ID, true );
		$start = $e && $e['start'] > 0 ? $e['start'] : 0;
		$end   = $e && $e['end'] > 0 ? $e['end'] : 0;
		$d     = static function ( int $ts, string $f ) {
			return $ts > 0 ? wp_date( $f, $ts ) : '';
		};
		$venue = $e && $e['venue'] ? (int) $e['venue']['id'] : 0;
		$terms = get_terms(
			array(
				'taxonomy'   => EVT_CPT::TAX,
				'hide_empty' => false,
				'orderby'    => 'name',
				'number'     => 500,
			)
		);
		wp_nonce_field( self::NONCE, 'evt_nonce' );
		?>
		<div class="evt-box">
			<div class="evt-row">
				<label><span><?php esc_html_e( 'Έναρξη', 'events' ); ?></span>
					<input type="date" name="evt_start_d" value="<?php echo esc_attr( $d( $start, 'Y-m-d' ) ); ?>" />
					<input type="time" name="evt_start_t" value="<?php echo esc_attr( $d( $start, 'H:i' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Λήξη', 'events' ); ?></span>
					<input type="date" name="evt_end_d" value="<?php echo esc_attr( $d( $end, 'Y-m-d' ) ); ?>" />
					<input type="time" name="evt_end_t" value="<?php echo esc_attr( $d( $end, 'H:i' ) ); ?>" /></label>
			</div>
			<p class="description">
				<?php
				/* translators: %s: time zone name */
				echo esc_html( sprintf( __( 'Ώρα του site (%s). Χωρίς λήξη, η εκδήλωση θεωρείται δίωρη.', 'events' ), wp_timezone_string() ) );
				?>
			</p>

			<div class="evt-row">
				<label><span><?php esc_html_e( 'Κατάσταση', 'events' ); ?></span>
					<select name="evt_status">
						<?php foreach ( self::statuses() as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $e ? $e['status'] : 'scheduled', $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></label>
				<label><span><?php esc_html_e( 'Είδος (για το schema)', 'events' ); ?></span>
					<select name="evt_kind">
						<?php foreach ( self::kinds() as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $e ? $e['kind'] : 'Event', $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></label>
			</div>
			<p class="description"><?php esc_html_e( 'Για «Νέα ημερομηνία» άλλαξε και την έναρξη: η παλιά κρατιέται για το schema.', 'events' ); ?></p>

			<h4><?php esc_html_e( 'Πού', 'events' ); ?></h4>
			<div class="evt-row">
				<label><span><?php esc_html_e( 'Χώρος', 'events' ); ?></span>
					<select name="evt_venue">
						<option value="0"><?php esc_html_e( '— Κανένας —', 'events' ); ?></option>
						<?php foreach ( is_array( $terms ) ? $terms : array() as $t ) : ?>
							<?php $city = (string) get_term_meta( $t->term_id, 'evt_city', true ); ?>
							<option value="<?php echo (int) $t->term_id; ?>" <?php selected( $venue, (int) $t->term_id ); ?>><?php echo esc_html( $t->name . ( '' !== $city ? ', ' . $city : '' ) ); ?></option>
						<?php endforeach; ?>
					</select></label>
				<label><span><?php esc_html_e( 'ή νέος χώρος: όνομα', 'events' ); ?></span>
					<input type="text" name="evt_new_venue" value="" maxlength="120" /></label>
			</div>
			<div class="evt-row evt-new-venue">
				<label><span><?php esc_html_e( 'Διεύθυνση', 'events' ); ?></span><input type="text" name="evt_nv_address" maxlength="200" /></label>
				<label><span><?php esc_html_e( 'Πόλη', 'events' ); ?></span><input type="text" name="evt_nv_city" maxlength="80" /></label>
				<label><span><?php esc_html_e( 'Σύνδεσμος χάρτη', 'events' ); ?></span><input type="url" name="evt_nv_map" maxlength="500" placeholder="https://" /></label>
			</div>
			<div class="evt-row">
				<label class="evt-inline"><input type="checkbox" name="evt_online" value="1" <?php checked( $e && $e['online'] ); ?> /> <?php esc_html_e( 'Online (ή και online)', 'events' ); ?></label>
				<label><span><?php esc_html_e( 'Σύνδεσμος της online εκδήλωσης', 'events' ); ?></span>
					<input type="url" name="evt_online_url" value="<?php echo esc_attr( $e ? $e['online_url'] : '' ); ?>" maxlength="500" placeholder="https://" /></label>
			</div>
			<p class="description"><?php esc_html_e( 'Ο σύνδεσμος της online εκδήλωσης φαίνεται στη σελίδα και κρύβεται όταν η εκδήλωση τελειώσει ή ακυρωθεί.', 'events' ); ?></p>

			<h4><?php esc_html_e( 'Είσοδος', 'events' ); ?></h4>
			<div class="evt-row">
				<label><span><?php esc_html_e( 'Είσοδος', 'events' ); ?></span>
					<select name="evt_entry">
						<option value="" <?php selected( $e ? $e['entry'] : '', '' ); ?>><?php esc_html_e( '— Δεν αναφέρεται —', 'events' ); ?></option>
						<option value="free" <?php selected( $e ? $e['entry'] : '', 'free' ); ?>><?php esc_html_e( 'Ελεύθερη', 'events' ); ?></option>
						<option value="ticket" <?php selected( $e ? $e['entry'] : '', 'ticket' ); ?>><?php esc_html_e( 'Με εισιτήριο', 'events' ); ?></option>
					</select></label>
				<label><span><?php esc_html_e( 'Τιμή (κείμενο, π.χ. «8 €, μειωμένο 5 €»)', 'events' ); ?></span>
					<input type="text" name="evt_price" value="<?php echo esc_attr( $e ? $e['price'] : '' ); ?>" maxlength="80" /></label>
			</div>
			<div class="evt-row">
				<label><span><?php esc_html_e( 'Σύνδεσμος εισιτηρίων ή δήλωσης συμμετοχής', 'events' ); ?></span>
					<input type="url" name="evt_link" value="<?php echo esc_attr( $e ? $e['link'] : '' ); ?>" maxlength="500" placeholder="https://" /></label>
				<label><span><?php esc_html_e( 'Κουμπί', 'events' ); ?></span>
					<select name="evt_link_kind">
						<option value="tickets" <?php selected( $e ? $e['link_kind'] : 'tickets', 'tickets' ); ?>><?php esc_html_e( 'Εισιτήρια', 'events' ); ?></option>
						<option value="rsvp" <?php selected( $e ? $e['link_kind'] : 'tickets', 'rsvp' ); ?>><?php esc_html_e( 'Δήλωση συμμετοχής', 'events' ); ?></option>
					</select></label>
			</div>

			<?php self::render_tickets( (int) $post->ID ); ?>

			<h4><?php esc_html_e( 'Σχετικά', 'events' ); ?></h4>
			<?php self::render_products( $e ? $e['products'] : array() ); ?>
			<?php self::render_creators( $e ? $e['creators'] : array() ); ?>
		</div>
		<?php
	}

	/** Ticket section (1.1.0): link a product or create one, sales start, attendee names. */
	private static function render_tickets( int $id ): void {
		if ( ! Events_Plugin::woo() || ! class_exists( 'EVT_Tickets' ) ) {
			return;
		}
		$pid   = (int) get_post_meta( $id, '_evt_ticket', true );
		$p     = $pid > 0 ? wc_get_product( $pid ) : null;
		$from  = (int) get_post_meta( $id, '_evt_sales_from', true );
		$names = '1' === (string) get_post_meta( $id, '_evt_names', true );
		?>
		<h4><?php esc_html_e( 'Εισιτήρια στο κατάστημα', 'events' ); ?></h4>
		<div class="evt-row">
			<label class="evt-block"><span><?php esc_html_e( 'Προϊόν εισιτηρίου (απλό ή με παραλλαγές για κανονικό, μειωμένο κ.λπ.)', 'events' ); ?></span>
				<select class="wc-product-search" style="width:100%;" name="evt_ticket_product" data-allow_clear="true" data-placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'events' ); ?>" data-action="woocommerce_json_search_products">
					<?php if ( $p ) : ?>
						<option value="<?php echo (int) $pid; ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				</select></label>
		</div>
		<?php if ( ! $p && current_user_can( 'publish_products' ) ) : ?>
			<div class="evt-row">
				<label class="evt-inline"><input type="checkbox" name="evt_tk_new" value="1" /> <?php esc_html_e( 'Φτιάξε εισιτήριο', 'events' ); ?></label>
				<label><span><?php esc_html_e( 'Τιμή (€)', 'events' ); ?></span><input type="text" name="evt_tk_price" inputmode="decimal" maxlength="12" placeholder="8" /></label>
				<label><span><?php esc_html_e( 'Θέσεις', 'events' ); ?></span><input type="number" name="evt_tk_seats" min="1" max="100000" step="1" placeholder="50" /></label>
			</div>
			<p class="description"><?php esc_html_e( 'Νέο κρυφό, εικονικό προϊόν με απόθεμα όσο οι θέσεις. Για τύπους εισιτηρίου, άλλαξέ το μετά σε προϊόν με παραλλαγές.', 'events' ); ?></p>
		<?php endif; ?>
		<div class="evt-row">
			<label><span><?php esc_html_e( 'Έναρξη πωλήσεων (κενό: αμέσως)', 'events' ); ?></span>
				<input type="date" name="evt_sales_d" value="<?php echo esc_attr( $from > 0 ? wp_date( 'Y-m-d', $from ) : '' ); ?>" />
				<input type="time" name="evt_sales_t" value="<?php echo esc_attr( $from > 0 ? wp_date( 'H:i', $from ) : '' ); ?>" /></label>
			<label class="evt-inline"><input type="checkbox" name="evt_names" value="1" <?php checked( $names ); ?> /> <?php esc_html_e( 'Ονόματα συμμετεχόντων στη σελίδα των εισιτηρίων', 'events' ); ?></label>
		</div>
		<p class="description">
			<?php
			/* translators: 1: hours before the start, 2: days after the event */
			echo esc_html( sprintf( __( 'Οι πωλήσεις κλείνουν %1$d ώρες πριν την έναρξη. Τα ονόματα σβήνονται %2$d ημέρες μετά την εκδήλωση.', 'events' ), (int) EVT_Settings::get()['close_h'], (int) EVT_Settings::get()['names_days'] ) );
			?>
		</p>
		<?php if ( $p && current_user_can( Events_Plugin::cap() ) ) : ?>
			<?php $c = EVT_Ticket_Store::counts( $id ); ?>
			<p>
				<?php
				/* translators: 1: valid tickets, 2: checked in */
				echo esc_html( sprintf( __( 'Εισιτήρια: %1$d · μέσα: %2$d', 'events' ), $c['valid'], $c['used'] ) );
				?>
				· <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . EVT_Checkin::SLUG . '&view=list&event=' . $id ) ); ?>"><?php esc_html_e( 'Λίστα συμμετεχόντων', 'events' ); ?></a>
				· <a href="<?php echo esc_url( (string) get_edit_post_link( $pid ) ); ?>"><?php esc_html_e( 'Το προϊόν', 'events' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function render_products( array $ids ): void {
		if ( Events_Plugin::woo() ) {
			?>
			<label class="evt-block"><span><?php esc_html_e( 'Προϊόντα (η εκδήλωση φαίνεται και στη σελίδα τους)', 'events' ); ?></span>
				<select class="wc-product-search" multiple="multiple" style="width:100%;" name="evt_products[]" data-placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'events' ); ?>" data-action="woocommerce_json_search_products">
					<?php
					foreach ( $ids as $pid ) {
						$p = wc_get_product( $pid );
						if ( $p ) {
							echo '<option value="' . (int) $pid . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
						}
					}
					?>
				</select></label>
			<?php
			return;
		}
		?>
		<label class="evt-block"><span><?php esc_html_e( 'Ids σχετικών σελίδων (με κόμμα)', 'events' ); ?></span>
			<input type="text" name="evt_products_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" /></label>
		<?php
	}

	private static function render_creators( array $ids ): void {
		if ( ! post_type_exists( 'nox_creator' ) ) {
			return;
		}
		$all = get_posts(
			array(
				'post_type'      => 'nox_creator',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		if ( ! $all ) {
			return;
		}
		?>
		<label class="evt-block"><span><?php esc_html_e( 'Δημιουργοί (Featured Creators: η εκδήλωση φαίνεται και στη σελίδα τους)', 'events' ); ?></span>
			<select name="evt_creators[]" multiple="multiple" size="6" class="<?php echo Events_Plugin::woo() ? 'wc-enhanced-select' : ''; ?>" style="width:100%;">
				<?php foreach ( $all as $c ) : ?>
					<option value="<?php echo (int) $c->ID; ?>" <?php selected( in_array( (int) $c->ID, $ids, true ) ); ?>><?php echo esc_html( wp_strip_all_tags( get_the_title( $c ) ) ); ?></option>
				<?php endforeach; ?>
			</select></label>
		<?php
	}

	/* =====================================================================
	 * Save
	 * =================================================================== */

	private static function post( string $k ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save().
		return isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) ? trim( (string) wp_unslash( $_POST[ $k ] ) ) : '';
	}

	private static function post_ids( string $k ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save().
		return isset( $_POST[ $k ] ) && is_array( $_POST[ $k ] ) ? EVT_CPT::ids( array_map( 'absint', wp_unslash( $_POST[ $k ] ) ) ) : array();
	}

	/** Unix time of a date + time typed in the site's time zone, or 0. */
	public static function ts( string $date, string $time ): int {
		return EVT_CPT::local_ts( $date, $time );
	}

	private static function url( string $v ): string {
		$v = esc_url_raw( $v, array( 'http', 'https' ) );
		return strlen( $v ) <= 500 ? $v : '';
	}

	public static function save( $post_id, $post ): void {
		if ( ! isset( $_POST['evt_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evt_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( (int) $post_id ) || ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		$id = (int) $post_id;

		$old_start = (int) get_post_meta( $id, '_evt_start', true );
		$start     = self::ts( self::post( 'evt_start_d' ), self::post( 'evt_start_t' ) );
		$end_d     = self::post( 'evt_end_d' );
		$end       = self::ts( '' !== $end_d ? $end_d : self::post( 'evt_start_d' ), self::post( 'evt_end_t' ) );
		if ( $start > 0 && ( $end <= $start ) ) {
			$end = $start + 2 * HOUR_IN_SECONDS;
		}
		if ( $start > 0 ) {
			update_post_meta( $id, '_evt_start', $start );
			update_post_meta( $id, '_evt_end', $end );
		} else {
			delete_post_meta( $id, '_evt_start' );
			delete_post_meta( $id, '_evt_end' );
		}

		$status = self::post( 'evt_status' );
		$status = in_array( $status, EVT_CPT::STATUSES, true ) ? $status : 'scheduled';
		update_post_meta( $id, '_evt_status', $status );
		if ( 'rescheduled' === $status && $old_start > 0 && $start > 0 && $old_start !== $start ) {
			update_post_meta( $id, '_evt_prev_start', $old_start );
		} elseif ( 'rescheduled' !== $status ) {
			delete_post_meta( $id, '_evt_prev_start' );
		}

		$kind = self::post( 'evt_kind' );
		update_post_meta( $id, '_evt_kind', in_array( $kind, EVT_CPT::KINDS, true ) ? $kind : 'Event' );

		// Venue: an existing term, or a new one written in the box.
		$venue = absint( self::post( 'evt_venue' ) );
		$new   = sanitize_text_field( self::post( 'evt_new_venue' ) );
		if ( '' !== $new && current_user_can( 'manage_categories' ) ) {
			$exists = term_exists( $new, EVT_CPT::TAX );
			$made   = $exists ? $exists : wp_insert_term( mb_substr( $new, 0, 120 ), EVT_CPT::TAX );
			if ( is_array( $made ) && isset( $made['term_id'] ) ) {
				$venue = (int) $made['term_id'];
				if ( ! $exists ) {
					update_term_meta( $venue, 'evt_address', mb_substr( sanitize_text_field( self::post( 'evt_nv_address' ) ), 0, 200 ) );
					update_term_meta( $venue, 'evt_city', mb_substr( sanitize_text_field( self::post( 'evt_nv_city' ) ), 0, 80 ) );
					update_term_meta( $venue, 'evt_map', self::url( self::post( 'evt_nv_map' ) ) );
					update_term_meta( $venue, 'evt_country', 'GR' );
				}
			}
		}
		wp_set_object_terms( $id, $venue > 0 ? array( $venue ) : array(), EVT_CPT::TAX, false );

		update_post_meta( $id, '_evt_online', '1' === self::post( 'evt_online' ) ? '1' : '' );
		update_post_meta( $id, '_evt_online_url', self::url( self::post( 'evt_online_url' ) ) );

		$entry = self::post( 'evt_entry' );
		update_post_meta( $id, '_evt_entry', in_array( $entry, array( 'free', 'ticket' ), true ) ? $entry : '' );
		update_post_meta( $id, '_evt_price', mb_substr( sanitize_text_field( self::post( 'evt_price' ) ), 0, 80 ) );
		update_post_meta( $id, '_evt_link', self::url( self::post( 'evt_link' ) ) );
		update_post_meta( $id, '_evt_link_kind', 'rsvp' === self::post( 'evt_link_kind' ) ? 'rsvp' : 'tickets' );

		if ( Events_Plugin::woo() && class_exists( 'EVT_Tickets' ) ) {
			self::save_tickets( $id );
		}

		$products = Events_Plugin::woo() ? self::post_ids( 'evt_products' ) : EVT_CPT::ids( self::post( 'evt_products_ids' ) );
		self::set_many( $id, '_evt_product', array_slice( $products, 0, 50 ) );
		if ( post_type_exists( 'nox_creator' ) ) {
			self::set_many( $id, '_evt_creator', array_slice( self::post_ids( 'evt_creators' ), 0, 50 ) );
		}
	}

	/** Ticket fields (1.1.0). */
	private static function save_tickets( int $id ): void {
		$from = self::ts( self::post( 'evt_sales_d' ), '' !== self::post( 'evt_sales_t' ) ? self::post( 'evt_sales_t' ) : '00:00' );
		if ( $from > 0 ) {
			update_post_meta( $id, '_evt_sales_from', $from );
		} else {
			delete_post_meta( $id, '_evt_sales_from' );
		}
		update_post_meta( $id, '_evt_names', '1' === self::post( 'evt_names' ) ? '1' : '' );
		if ( '1' !== self::post( 'evt_names' ) ) {
			EVT_Ticket_Store::clear_names_of_event( $id );
		}

		$old = (int) get_post_meta( $id, '_evt_ticket', true );
		if ( '1' === self::post( 'evt_tk_new' ) && $old <= 0 ) {
			$price = wc_format_decimal( str_replace( ',', '.', self::post( 'evt_tk_price' ) ) );
			$seats = absint( self::post( 'evt_tk_seats' ) );
			if ( '' !== $price && (float) $price >= 0 && $seats > 0 ) {
				EVT_Tickets::create_product( $id, (string) $price, min( 100000, $seats ) );
			}
			return;
		}
		$pid = absint( self::post( 'evt_ticket_product' ) );
		if ( $pid === $old ) {
			return;
		}
		if ( $pid > 0 && ! current_user_can( 'edit_post', $pid ) ) {
			return;
		}
		EVT_Tickets::link( $id, $pid );
	}

	/** One meta row per id. */
	public static function set_many( int $id, string $key, array $ids ): void {
		delete_post_meta( $id, $key );
		foreach ( $ids as $v ) {
			add_post_meta( $id, $key, (int) $v );
		}
	}

	/* =====================================================================
	 * Venue fields
	 * =================================================================== */

	private static function term_fields(): array {
		return array(
			'evt_address'  => __( 'Διεύθυνση', 'events' ),
			'evt_city'     => __( 'Πόλη', 'events' ),
			'evt_postcode' => __( 'Ταχυδρομικός κώδικας', 'events' ),
			'evt_country'  => __( 'Χώρα (κωδικός 2 γραμμάτων, π.χ. GR)', 'events' ),
			'evt_map'      => __( 'Σύνδεσμος χάρτη (π.χ. OpenStreetMap ή Google Maps)', 'events' ),
		);
	}

	public static function term_add(): void {
		wp_nonce_field( 'evt_term', 'evt_term_nonce' );
		foreach ( self::term_fields() as $k => $label ) {
			echo '<div class="form-field"><label for="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label><input type="text" name="' . esc_attr( $k ) . '" id="' . esc_attr( $k ) . '" value="" /></div>';
		}
	}

	public static function term_edit( $term ): void {
		wp_nonce_field( 'evt_term', 'evt_term_nonce' );
		foreach ( self::term_fields() as $k => $label ) {
			$v = $term instanceof WP_Term ? (string) get_term_meta( $term->term_id, $k, true ) : '';
			echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" name="' . esc_attr( $k ) . '" id="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" /></td></tr>';
		}
	}

	public static function term_save( $term_id ): void {
		if ( ! isset( $_POST['evt_term_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evt_term_nonce'] ) ), 'evt_term' ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		foreach ( array_keys( self::term_fields() ) as $k ) {
			$v = self::post( $k );
			if ( 'evt_map' === $k ) {
				$v = self::url( $v );
			} elseif ( 'evt_country' === $k ) {
				$v = preg_match( '/^[A-Za-z]{2}$/', $v ) ? strtoupper( $v ) : 'GR';
			} else {
				$v = mb_substr( sanitize_text_field( $v ), 0, 200 );
			}
			update_term_meta( (int) $term_id, $k, $v );
		}
	}
}
