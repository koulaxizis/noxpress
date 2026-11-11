<?php
/**
 * TRA_Consign_UI — the «Παρακαταθήκες» tab of Trade Accounts (module
 * "consign"): list, new consignment, one consignment (settle, collected,
 * new date, note), the printable delivery note and the module's settings.
 *
 * Called by TRA_Admin_UI: render() for the tab, handle() for its POST
 * (nonce and capability already checked there, PRG), print_note() on
 * admin_init for the printable note (its own nonce).
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Consign_UI {

	const ROWS        = 12;
	const NONCE_PRINT = 'tra_consign_print';

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => TRA_Admin_UI::SLUG, 'tab' => 'consign' ), $args ), admin_url( 'admin.php' ) );
	}

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by TRA_Admin_UI::route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array of id => integer. */
	private static function post_counts( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by TRA_Admin_UI::route().
		$raw = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast below.
		$out = array();
		foreach ( $raw as $k => $v ) {
			if ( is_numeric( $k ) && is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				$out[ (int) $k ] = (int) $v;
			}
		}
		return $out;
	}

	private static function badge( string $state ): string {
		$map = array(
			'open'    => array( __( 'Ανοιχτή', 'trade-accounts' ), 'ok' ),
			'overdue' => array( __( 'Ληξιπρόθεσμη', 'trade-accounts' ), 'danger' ),
			'closed'  => array( __( 'Κλειστή', 'trade-accounts' ), 'muted' ),
		);
		$b = isset( $map[ $state ] ) ? $map[ $state ] : $map['open'];
		return '<span class="tra-badge tra-badge--' . esc_attr( $b[1] ) . '">' . esc_html( $b[0] ) . '</span>';
	}

	private static function day( string $d ): string {
		if ( '' === $d ) {
			return '—';
		}
		$t = strtotime( $d . ' 12:00:00' );
		return false === $t ? $d : wp_date( get_option( 'date_format' ), $t );
	}

	private static function eur( string $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** Eligible simple products for the picker: id => name. */
	private static function products(): array {
		if ( empty( TRA_Settings::get()['cats'] ) ) {
			return array();
		}
		$args                   = TRA_Scope::query_args();
		$args['fields']         = 'ids';
		$args['posts_per_page'] = 500;
		$args['orderby']        = 'title';
		$args['order']          = 'ASC';
		$args['no_found_rows']  = true;
		$out                    = array();
		foreach ( get_posts( $args ) as $pid ) {
			$p = wc_get_product( (int) $pid );
			if ( $p instanceof WC_Product ) {
				$stock       = $p->managing_stock() ? ' · ' . sprintf( /* translators: %d: copies in stock */ __( 'απόθεμα %d', 'trade-accounts' ), (int) $p->get_stock_quantity() ) : '';
				$out[ $pid ] = $p->get_name() . $stock;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Tab
	 * =================================================================== */

	public static function render(): void {
		$id = (int) self::q( 'id' );
		if ( $id > 0 ) {
			self::render_one( $id );
			return;
		}
		if ( 'new' === self::q( 'view' ) ) {
			self::render_new();
			return;
		}
		self::render_list();
	}

	private static function render_list(): void {
		$state = self::q( 'state' );
		$state = in_array( $state, array( 'open', 'overdue', 'closed', 'all' ), true ) ? $state : 'open';
		$sum   = TRA_Consign::summary();
		$list  = TRA_Consign::query( array( 'state' => $state ) );
		?>
		<h2 class="tra-h2"><?php esc_html_e( 'Παρακαταθήκες σε βιβλιοπωλεία', 'trade-accounts' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Η παράδοση δεν είναι πώληση: τα τεμάχια φεύγουν από το απόθεμα, αλλά τίποτα δεν μετράει στις πωλήσεις ή στο Revenue Splitter. Στην εκκαθάριση γίνεται παραγγελία μόνο για όσα πουλήθηκαν, σε αναμονή, και μετράει όταν σημειώσεις την είσπραξη.', 'trade-accounts' ); ?></p>

		<div class="tra-mods">
			<div class="tra-mod">
				<h3><?php esc_html_e( 'Ανοιχτές', 'trade-accounts' ); ?></h3>
				<p><strong><?php echo (int) $sum['consignments']; ?></strong> · <?php echo esc_html( sprintf( /* translators: %d: copies */ __( '%d τεμάχια σε βιβλιοπωλεία', 'trade-accounts' ), (int) $sum['copies'] ) ); ?></p>
			</div>
			<div class="tra-mod">
				<h3><?php esc_html_e( 'Αξία στα βιβλιοπωλεία', 'trade-accounts' ); ?></h3>
				<p><strong><?php echo esc_html( self::eur( $sum['open_value'] ) ); ?></strong></p>
			</div>
			<div class="tra-mod">
				<h3><?php esc_html_e( 'Προς είσπραξη', 'trade-accounts' ); ?></h3>
				<p><strong><?php echo esc_html( self::eur( $sum['to_collect'] ) ); ?></strong> <span class="tra-muted"><?php esc_html_e( '(εκκαθαρισμένα, όχι ακόμα πληρωμένα)', 'trade-accounts' ); ?></span></p>
			</div>
			<div class="tra-mod">
				<h3><?php esc_html_e( 'Ληξιπρόθεσμες', 'trade-accounts' ); ?></h3>
				<p><strong><?php echo (int) $sum['overdue']; ?></strong></p>
			</div>
		</div>

		<p>
			<a class="button button-primary" href="<?php echo esc_url( self::url( array( 'view' => 'new' ) ) ); ?>"><?php esc_html_e( 'Νέα παρακαταθήκη', 'trade-accounts' ); ?></a>
			<?php
			$filters = array(
				'open'    => __( 'Ανοιχτές', 'trade-accounts' ),
				'overdue' => __( 'Ληξιπρόθεσμες', 'trade-accounts' ),
				'closed'  => __( 'Κλειστές', 'trade-accounts' ),
				'all'     => __( 'Όλες', 'trade-accounts' ),
			);
			foreach ( $filters as $k => $label ) {
				printf(
					' <a class="button%1$s" href="%2$s">%3$s</a>',
					$k === $state ? ' button-primary' : '',
					esc_url( self::url( array( 'state' => $k ) ) ),
					esc_html( $label )
				);
			}
			?>
		</p>

		<table class="widefat tra-table">
			<thead><tr>
				<th><?php esc_html_e( 'Βιβλιοπωλείο', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Παράδοση', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Προθεσμία', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Τεμάχια (δόθηκαν / πουλήθηκαν / επιστράφηκαν / εκεί)', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Προς είσπραξη', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Κατάσταση', 'trade-accounts' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $list ) : ?>
				<tr><td colspan="6" class="tra-empty"><?php esc_html_e( 'Καμία παρακαταθήκη εδώ.', 'trade-accounts' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $list as $c ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( self::url( array( 'id' => $c['id'] ) ) ); ?>"><strong><?php echo esc_html( $c['account']['name'] ); ?></strong></a>
							<?php if ( '' !== $c['account']['city'] ) : ?><br /><span class="tra-muted"><?php echo esc_html( $c['account']['city'] ); ?></span><?php endif; ?></td>
						<td><?php echo esc_html( self::day( $c['given'] ) ); ?><?php if ( '' !== $c['doc'] ) : ?><br /><span class="tra-muted"><?php echo esc_html( $c['doc'] ); ?></span><?php endif; ?></td>
						<td><?php echo esc_html( self::day( $c['due'] ) ); ?>
							<?php if ( '' !== $c['next_check'] && 'closed' !== $c['state'] ) : ?><br /><span class="tra-muted"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'έλεγχος %s', 'trade-accounts' ), self::day( $c['next_check'] ) ) ); ?></span><?php endif; ?></td>
						<td><?php echo esc_html( $c['totals']['given'] . ' / ' . $c['totals']['sold'] . ' / ' . $c['totals']['returned'] . ' / ' . $c['totals']['open'] ); ?></td>
						<td><?php echo esc_html( self::eur( $c['totals']['to_collect'] ) ); ?></td>
						<td><?php echo self::badge( $c['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php self::render_settings(); ?>
		<?php
	}

	private static function render_settings(): void {
		$s = TRA_Settings::get();
		?>
		<h2 class="tra-h2"><?php esc_html_e( 'Ρυθμίσεις παρακαταθηκών', 'trade-accounts' ); ?></h2>
		<div class="tra-zone">
			<form method="post">
				<?php wp_nonce_field( TRA_Admin_UI::NONCE, TRA_Admin_UI::NONCE_FIELD ); ?>
				<input type="hidden" name="tra_action" value="consign" />
				<input type="hidden" name="tra_c_do" value="settings" />
				<div class="tra-grid">
					<label><span class="tra-label"><?php esc_html_e( 'Προθεσμία εκκαθάρισης (ημέρες μετά την παράδοση)', 'trade-accounts' ); ?></span>
						<input type="number" name="tra_c_days" min="7" max="730" value="<?php echo (int) $s['c_days']; ?>" /></label>
					<label><span class="tra-label"><?php esc_html_e( 'Έλεγχος με το βιβλιοπωλείο κάθε (ημέρες, 0 = ποτέ)', 'trade-accounts' ); ?></span>
						<input type="number" name="tra_c_check" min="0" max="180" value="<?php echo (int) $s['c_check']; ?>" /></label>
				</div>
				<label class="tra-check"><input type="checkbox" name="tra_c_mail" value="1"<?php checked( $s['c_mail'] ); ?> /> <?php esc_html_e( 'Email «Η παραγγελία ολοκληρώθηκε» στο βιβλιοπωλείο όταν σημειώνεται η είσπραξη', 'trade-accounts' ); ?></label>
				<p class="description"><?php esc_html_e( 'Οι παραγγελίες εκκαθάρισης δεν στέλνουν ποτέ άλλο email (ούτε στο κατάστημα ούτε στο βιβλιοπωλείο).', 'trade-accounts' ); ?></p>
				<p class="tra-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'trade-accounts' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private static function render_new(): void {
		$accounts = TRA_Accounts::all();
		$products = self::products();
		$days     = (int) TRA_Settings::get()['c_days'];
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← <?php esc_html_e( 'Όλες οι παρακαταθήκες', 'trade-accounts' ); ?></a></p>
		<h2 class="tra-h2"><?php esc_html_e( 'Νέα παρακαταθήκη', 'trade-accounts' ); ?></h2>
		<?php if ( ! $accounts ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Δεν υπάρχει λογαριασμός χονδρικής: φτιάξε πρώτα το βιβλιοπωλείο στην καρτέλα Λογαριασμοί.', 'trade-accounts' ); ?></p></div>
			<?php
			return;
		endif;
		if ( ! $products ) :
			?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Κανένα προϊόν δεν είναι επιλέξιμο: διάλεξε κατηγορίες στις Ρυθμίσεις.', 'trade-accounts' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<form method="post">
			<?php wp_nonce_field( TRA_Admin_UI::NONCE, TRA_Admin_UI::NONCE_FIELD ); ?>
			<input type="hidden" name="tra_action" value="consign" />
			<input type="hidden" name="tra_c_do" value="create" />
			<div class="tra-zone">
				<div class="tra-grid">
					<label><span class="tra-label"><?php esc_html_e( 'Βιβλιοπωλείο (λογαριασμός χονδρικής)', 'trade-accounts' ); ?></span>
						<select name="tra_c_account" required>
							<option value=""><?php esc_html_e( '— διάλεξε —', 'trade-accounts' ); ?></option>
							<?php foreach ( $accounts as $u ) : ?>
								<option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html( TRA_Consign::account_name( (int) $u->ID ) . ' · ' . $u->user_email ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label><span class="tra-label"><?php esc_html_e( 'Ημερομηνία παράδοσης', 'trade-accounts' ); ?></span>
						<input type="date" name="tra_c_given" value="<?php echo esc_attr( TRA_Consign::today() ); ?>" required /></label>
					<label><span class="tra-label"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Προθεσμία εκκαθάρισης (κενό = σε %d ημέρες)', 'trade-accounts' ), $days ) ); ?></span>
						<input type="date" name="tra_c_due" value="" /></label>
					<label><span class="tra-label"><?php esc_html_e( 'Αριθμός δελτίου αποστολής', 'trade-accounts' ); ?></span>
						<input type="text" name="tra_c_doc" maxlength="60" /></label>
				</div>
				<label><span class="tra-label"><?php esc_html_e( 'Σημείωση (συμφωνία, επαφή…)', 'trade-accounts' ); ?></span>
					<textarea name="tra_c_note" rows="2" class="large-text" maxlength="2000"></textarea></label>
			</div>

			<h3 class="tra-h3"><?php esc_html_e( 'Τίτλοι', 'trade-accounts' ); ?></h3>
			<table class="widefat tra-table">
				<thead><tr>
					<th><?php esc_html_e( 'Τίτλος', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Τεμάχια', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Τιμή μονάδας με ΦΠΑ (κενό = τιμή χονδρικής του λογαριασμού)', 'trade-accounts' ); ?></th>
				</tr></thead>
				<tbody>
				<?php for ( $i = 0; $i < self::ROWS; $i++ ) : ?>
					<tr>
						<td><select name="tra_c_p[<?php echo (int) $i; ?>]">
							<option value=""></option>
							<?php foreach ( $products as $pid => $name ) : ?>
								<option value="<?php echo (int) $pid; ?>"><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select></td>
						<td><input type="number" name="tra_c_q[<?php echo (int) $i; ?>]" min="1" max="9999" size="5" /></td>
						<td><input type="text" name="tra_c_price[<?php echo (int) $i; ?>]" size="8" inputmode="decimal" /></td>
					</tr>
				<?php endfor; ?>
				</tbody>
			</table>
			<p class="description tra-mt-8"><?php echo esc_html( sprintf( /* translators: %d: rows */ __( 'Έως %d τίτλοι ανά φόρμα. Τα τεμάχια φεύγουν από το απόθεμα όσων προϊόντων έχουν διαχείριση αποθέματος.', 'trade-accounts' ), self::ROWS ) ); ?></p>
			<p class="tra-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Καταχώρηση παρακαταθήκης', 'trade-accounts' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_one( int $id ): void {
		$c = TRA_Consign::get( $id );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← <?php esc_html_e( 'Όλες οι παρακαταθήκες', 'trade-accounts' ); ?></a></p>
		<?php
		if ( null === $c ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Η παρακαταθήκη δεν βρέθηκε.', 'trade-accounts' ) . '</p></div>';
			return;
		}
		$print = wp_nonce_url( self::url( array( 'tra_print' => $id ) ), self::NONCE_PRINT );
		?>
		<h2 class="tra-h2"><?php echo esc_html( sprintf( /* translators: 1: id, 2: bookstore */ __( 'Παρακαταθήκη #%1$d · %2$s', 'trade-accounts' ), $id, $c['account']['name'] ) ); ?> <?php echo self::badge( $c['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: delivery date, 2: settle-by date, 3: next check date */
					__( 'Παράδοση %1$s · προθεσμία %2$s · επόμενος έλεγχος %3$s', 'trade-accounts' ),
					self::day( $c['given'] ),
					self::day( $c['due'] ),
					self::day( $c['next_check'] )
				)
			);
			if ( '' !== $c['doc'] ) {
				echo ' · ' . esc_html( sprintf( /* translators: %s: delivery note number */ __( 'δελτίο %s', 'trade-accounts' ), $c['doc'] ) );
			}
			?>
			· <a href="<?php echo esc_url( $print ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Δελτίο παράδοσης για εκτύπωση / PDF', 'trade-accounts' ); ?></a>
		</p>
		<?php if ( '' !== $c['note'] ) : ?>
			<p class="description"><?php echo esc_html( $c['note'] ); ?></p>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( TRA_Admin_UI::NONCE, TRA_Admin_UI::NONCE_FIELD ); ?>
			<input type="hidden" name="tra_action" value="consign" />
			<input type="hidden" name="tra_c_do" value="settle" />
			<input type="hidden" name="tra_c_id" value="<?php echo (int) $id; ?>" />
			<table class="widefat tra-table">
				<thead><tr>
					<th><?php esc_html_e( 'Τίτλος', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Τιμή', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Δόθηκαν', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Πουλήθηκαν', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Επιστράφηκαν', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Στο βιβλιοπωλείο', 'trade-accounts' ); ?></th>
					<?php if ( $c['totals']['open'] > 0 ) : ?>
						<th><?php esc_html_e( 'Πουλήθηκαν τώρα', 'trade-accounts' ); ?></th>
						<th><?php esc_html_e( 'Επιστρέφονται τώρα', 'trade-accounts' ); ?></th>
					<?php endif; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $c['lines'] as $l ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $l['product'] ) ); ?>"><?php echo esc_html( $l['name'] ); ?></a><?php if ( '' !== $l['isbn'] ) : ?><br /><span class="tra-muted"><?php echo esc_html( $l['isbn'] ); ?></span><?php endif; ?></td>
						<td><?php echo esc_html( self::eur( $l['price'] ) ); ?></td>
						<td><?php echo (int) $l['given']; ?></td>
						<td><?php echo (int) $l['sold']; ?></td>
						<td><?php echo (int) $l['returned']; ?></td>
						<td><strong><?php echo (int) $l['open']; ?></strong></td>
						<?php if ( $c['totals']['open'] > 0 ) : ?>
							<td><?php if ( $l['open'] > 0 ) : ?><input type="number" name="tra_c_sold[<?php echo (int) $l['product']; ?>]" min="0" max="<?php echo (int) $l['open']; ?>" size="4" /><?php endif; ?></td>
							<td><?php if ( $l['open'] > 0 ) : ?><input type="number" name="tra_c_ret[<?php echo (int) $l['product']; ?>]" min="0" max="<?php echo (int) $l['open']; ?>" size="4" /><?php endif; ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $c['totals']['open'] > 0 ) : ?>
				<div class="tra-zone">
					<label><span class="tra-label"><?php esc_html_e( 'Ημερομηνία εκκαθάρισης', 'trade-accounts' ); ?></span>
						<input type="date" name="tra_c_date" value="<?php echo esc_attr( TRA_Consign::today() ); ?>" /></label>
					<p class="description"><?php esc_html_e( 'Τα πουλημένα γίνονται μία παραγγελία σε αναμονή (χωρίς email, χωρίς νέα αλλαγή αποθέματος). Οι επιστροφές μπαίνουν πάλι στο απόθεμα.', 'trade-accounts' ); ?></p>
					<p class="tra-submit"><button type="submit" class="button button-primary" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Καταχώρηση εκκαθάρισης: δημιουργείται παραγγελία για τα πουλημένα και οι επιστροφές μπαίνουν στο απόθεμα. Συνέχεια;', 'trade-accounts' ) ) ); ?>);"><?php esc_html_e( 'Εκκαθάριση', 'trade-accounts' ); ?></button></p>
				</div>
			<?php endif; ?>
		</form>

		<h3 class="tra-h3"><?php esc_html_e( 'Παραγγελίες εκκαθάρισης', 'trade-accounts' ); ?></h3>
		<table class="widefat tra-table">
			<thead><tr>
				<th><?php esc_html_e( 'Παραγγελία', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Εκκαθάριση', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Τεμάχια', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Ποσό', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Είσπραξη', 'trade-accounts' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $c['orders'] ) : ?>
				<tr><td colspan="5" class="tra-empty"><?php esc_html_e( 'Καμία εκκαθάριση ακόμα.', 'trade-accounts' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $c['orders'] as $o ) : ?>
					<tr>
						<td><?php if ( '' !== $o['edit_url'] ) : ?><a href="<?php echo esc_url( $o['edit_url'] ); ?>">#<?php echo (int) $o['id']; ?></a><?php else : ?>#<?php echo (int) $o['id']; ?><?php endif; ?>
							<br /><span class="tra-muted"><?php echo esc_html( wc_get_order_status_name( $o['status'] ) ); ?></span></td>
						<td><?php echo esc_html( self::day( $o['date'] ) ); ?></td>
						<td><?php echo (int) $o['copies']; ?></td>
						<td><?php echo esc_html( self::eur( $o['total'] ) ); ?></td>
						<td>
							<?php if ( $o['cancelled'] ) : ?>
								<span class="tra-badge tra-badge--muted"><?php esc_html_e( 'Ακυρώθηκε', 'trade-accounts' ); ?></span>
							<?php elseif ( '' !== $o['paid'] ) : ?>
								<span class="tra-badge tra-badge--ok"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Εισπράχθηκε %s', 'trade-accounts' ), self::day( $o['paid'] ) ) ); ?></span>
							<?php else : ?>
								<form method="post" class="tra-inline">
									<?php wp_nonce_field( TRA_Admin_UI::NONCE, TRA_Admin_UI::NONCE_FIELD ); ?>
									<input type="hidden" name="tra_action" value="consign" />
									<input type="hidden" name="tra_c_do" value="paid" />
									<input type="hidden" name="tra_c_id" value="<?php echo (int) $id; ?>" />
									<input type="hidden" name="tra_c_order" value="<?php echo (int) $o['id']; ?>" />
									<input type="date" name="tra_c_date" value="<?php echo esc_attr( TRA_Consign::today() ); ?>" />
									<button type="submit" class="button button-primary" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Η παραγγελία ολοκληρώνεται και από τώρα μετράει στις πωλήσεις και στο Revenue Splitter. Συνέχεια;', 'trade-accounts' ) ) ); ?>);"><?php esc_html_e( 'Εισπράχθηκε', 'trade-accounts' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h3 class="tra-h3"><?php esc_html_e( 'Προθεσμία και σημειώσεις', 'trade-accounts' ); ?></h3>
		<div class="tra-zone">
			<form method="post">
				<?php wp_nonce_field( TRA_Admin_UI::NONCE, TRA_Admin_UI::NONCE_FIELD ); ?>
				<input type="hidden" name="tra_action" value="consign" />
				<input type="hidden" name="tra_c_do" value="extend" />
				<input type="hidden" name="tra_c_id" value="<?php echo (int) $id; ?>" />
				<div class="tra-grid">
					<label><span class="tra-label"><?php esc_html_e( 'Προθεσμία εκκαθάρισης', 'trade-accounts' ); ?></span>
						<input type="date" name="tra_c_due" value="<?php echo esc_attr( $c['due'] ); ?>" /></label>
					<label><span class="tra-label"><?php esc_html_e( 'Αριθμός δελτίου αποστολής', 'trade-accounts' ); ?></span>
						<input type="text" name="tra_c_doc" maxlength="60" value="<?php echo esc_attr( $c['doc'] ); ?>" /></label>
				</div>
				<label><span class="tra-label"><?php esc_html_e( 'Νέα σημείωση στο ιστορικό', 'trade-accounts' ); ?></span>
					<textarea name="tra_c_note" rows="2" class="large-text" maxlength="2000"></textarea></label>
				<p class="tra-submit"><button type="submit" class="button"><?php esc_html_e( 'Αποθήκευση', 'trade-accounts' ); ?></button></p>
			</form>
		</div>

		<h3 class="tra-h3"><?php esc_html_e( 'Ιστορικό', 'trade-accounts' ); ?></h3>
		<table class="widefat tra-table">
			<tbody>
			<?php foreach ( $c['history'] as $h ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $h['time'] ) ) ); ?><br /><span class="tra-muted"><?php echo esc_html( $h['user'] ); ?></span></td>
					<td><?php echo esc_html( $h['text'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* =====================================================================
	 * POST (from TRA_Admin_UI::route, nonce + capability checked)
	 * =================================================================== */

	/** @return array{0: array, 1: array} Messages and extra query args for the redirect. */
	public static function handle(): array {
		$id = (int) self::post( 'tra_c_id' );
		switch ( sanitize_key( self::post( 'tra_c_do' ) ) ) {
			case 'settings':
				$s            = TRA_Settings::get();
				$s['c_days']  = (int) self::post( 'tra_c_days' );
				$s['c_check'] = (int) self::post( 'tra_c_check' );
				$s['c_mail']  = '1' === self::post( 'tra_c_mail' );
				TRA_Settings::save( $s );
				return array( array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'trade-accounts' ) ) ), array() );

			case 'create':
				$lines  = array();
				$p      = self::post_counts( 'tra_c_p' );
				$q      = self::post_counts( 'tra_c_q' );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by TRA_Admin_UI::route().
				$prices = isset( $_POST['tra_c_price'] ) && is_array( $_POST['tra_c_price'] ) ? wp_unslash( $_POST['tra_c_price'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed by TRA_Consign::cents().
				for ( $i = 0; $i < self::ROWS; $i++ ) {
					if ( empty( $p[ $i ] ) && empty( $q[ $i ] ) ) {
						continue;
					}
					$lines[] = array(
						'product' => isset( $p[ $i ] ) ? $p[ $i ] : 0,
						'qty'     => isset( $q[ $i ] ) ? $q[ $i ] : 0,
						'price'   => isset( $prices[ $i ] ) && is_scalar( $prices[ $i ] ) ? sanitize_text_field( (string) $prices[ $i ] ) : '',
					);
				}
				$new = TRA_Consign::create(
					array(
						'account' => (int) self::post( 'tra_c_account' ),
						'given'   => self::post( 'tra_c_given' ),
						'due'     => self::post( 'tra_c_due' ),
						'doc'     => self::post( 'tra_c_doc' ),
						'note'    => self::post( 'tra_c_note' ),
						'lines'   => $lines,
					)
				);
				if ( is_wp_error( $new ) ) {
					return array( array( self::msg( 'error', $new->get_error_message() ) ), array( 'view' => 'new' ) );
				}
				return array( array( self::msg( 'success', __( 'Η παρακαταθήκη καταχωρήθηκε.', 'trade-accounts' ) ) ), array( 'id' => (int) $new ) );

			case 'settle':
				$res = TRA_Consign::settle( $id, self::post_counts( 'tra_c_sold' ), self::post_counts( 'tra_c_ret' ), self::post( 'tra_c_date' ) );
				if ( is_wp_error( $res ) ) {
					return array( array( self::msg( 'error', $res->get_error_message() ) ), array( 'id' => $id ) );
				}
				$text = $res['order'] > 0
					/* translators: %d: order number */
					? sprintf( __( 'Η εκκαθάριση καταχωρήθηκε: παραγγελία #%d σε αναμονή. Πάτα «Εισπράχθηκε» όταν μπουν τα χρήματα.', 'trade-accounts' ), $res['order'] )
					: __( 'Οι επιστροφές καταχωρήθηκαν.', 'trade-accounts' );
				return array( array( self::msg( 'success', $text ) ), array( 'id' => $id ) );

			case 'paid':
				$res = TRA_Consign::paid( $id, (int) self::post( 'tra_c_order' ), self::post( 'tra_c_date' ) );
				if ( is_wp_error( $res ) ) {
					return array( array( self::msg( 'error', $res->get_error_message() ) ), array( 'id' => $id ) );
				}
				return array( array( self::msg( 'success', __( 'Η είσπραξη σημειώθηκε: η παραγγελία ολοκληρώθηκε και μετράει στις πωλήσεις.', 'trade-accounts' ) ) ), array( 'id' => $id ) );

			case 'extend':
				$c = TRA_Consign::get( $id );
				if ( null === $c ) {
					return array( array( self::msg( 'error', __( 'Η παρακαταθήκη δεν βρέθηκε.', 'trade-accounts' ) ) ), array() );
				}
				$due = self::post( 'tra_c_due' );
				if ( '' !== $due && $due !== $c['due'] ) {
					$res = TRA_Consign::extend( $id, $due );
					if ( is_wp_error( $res ) ) {
						return array( array( self::msg( 'error', $res->get_error_message() ) ), array( 'id' => $id ) );
					}
				}
				TRA_Consign::note( $id, self::post( 'tra_c_note' ), self::post( 'tra_c_doc' ) );
				return array( array( self::msg( 'success', __( 'Αποθηκεύτηκε.', 'trade-accounts' ) ) ), array( 'id' => $id ) );
		}
		return array( array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'trade-accounts' ) ) ), array() );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	/* =====================================================================
	 * Printable delivery note (admin_init, GET with nonce)
	 * =================================================================== */

	public static function print_note(): void {
		$id = (int) self::q( 'tra_print' );
		if ( $id <= 0 || TRA_Admin_UI::SLUG !== self::q( 'page' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_PRINT ) || ! current_user_can( Trade_Accounts::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'trade-accounts' ) );
		}
		$c = TRA_Consign::get( $id );
		if ( null === $c ) {
			wp_die( esc_html__( 'Η παρακαταθήκη δεν βρέθηκε.', 'trade-accounts' ) );
		}
		$shop  = get_bloginfo( 'name' );
		$addr  = array_filter(
			array(
				(string) get_option( 'woocommerce_store_address' ),
				trim( (string) get_option( 'woocommerce_store_postcode' ) . ' ' . (string) get_option( 'woocommerce_store_city' ) ),
			)
		);
		$uid   = (int) $c['account']['id'];
		$vat   = (string) get_user_meta( $uid, 'billing_vat', true );
		$baddr = array_filter(
			array(
				(string) get_user_meta( $uid, 'billing_address_1', true ),
				trim( (string) get_user_meta( $uid, 'billing_postcode', true ) . ' ' . (string) get_user_meta( $uid, 'billing_city', true ) ),
			)
		);
		$total = 0.0;
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( sprintf( /* translators: %d: id */ __( 'Δελτίο παράδοσης σε παρακαταθήκη #%d', 'trade-accounts' ), $id ) ); ?></title>
<style>
body{font:14px/1.45 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111;background:#fff;margin:32px auto;max-width:820px;padding:0 16px}
h1{font-size:20px;margin:0 0 4px}
.tra-p-meta{color:#444;margin:0 0 20px}
.tra-p-cols{display:flex;gap:32px;flex-wrap:wrap;margin-bottom:20px}
.tra-p-cols div{flex:1 1 300px}
.tra-p-cols h2{font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#555;margin:0 0 4px}
table{width:100%;border-collapse:collapse;margin:8px 0 16px}
th,td{border-bottom:1px solid #ccc;padding:6px 8px;text-align:left;vertical-align:top}
th{font-size:12px;color:#444}
td.n,th.n{text-align:right;white-space:nowrap}
.tra-p-terms{font-size:13px;color:#333;margin:16px 0 40px}
.tra-p-sign{display:flex;gap:40px;margin-top:48px}
.tra-p-sign div{flex:1;border-top:1px solid #333;padding-top:6px;font-size:12px;color:#444}
.tra-p-btn{margin:0 0 24px}
@media print{.tra-p-btn{display:none}body{margin:0 auto}}
</style>
</head>
<body>
<p class="tra-p-btn"><button type="button" onclick="window.print()"><?php esc_html_e( 'Εκτύπωση / αποθήκευση ως PDF', 'trade-accounts' ); ?></button></p>
<h1><?php esc_html_e( 'Δελτίο παράδοσης βιβλίων σε παρακαταθήκη', 'trade-accounts' ); ?></h1>
<p class="tra-p-meta"><?php echo esc_html( sprintf( /* translators: 1: id, 2: date */ __( 'Αρ. %1$d · Ημερομηνία παράδοσης %2$s', 'trade-accounts' ), $id, self::day( $c['given'] ) ) ); ?><?php if ( '' !== $c['doc'] ) : ?> · <?php echo esc_html( sprintf( /* translators: %s: number */ __( 'Δελτίο αποστολής %s', 'trade-accounts' ), $c['doc'] ) ); ?><?php endif; ?></p>
<div class="tra-p-cols">
	<div><h2><?php esc_html_e( 'Παραδίδει', 'trade-accounts' ); ?></h2><strong><?php echo esc_html( $shop ); ?></strong><br /><?php echo esc_html( implode( ', ', $addr ) ); ?></div>
	<div><h2><?php esc_html_e( 'Παραλαμβάνει', 'trade-accounts' ); ?></h2><strong><?php echo esc_html( $c['account']['name'] ); ?></strong><br /><?php echo esc_html( implode( ', ', $baddr ) ); ?><?php if ( '' !== $vat ) : ?><br /><?php echo esc_html( sprintf( /* translators: %s: VAT number */ __( 'ΑΦΜ %s', 'trade-accounts' ), $vat ) ); ?><?php endif; ?></div>
</div>
<table>
	<thead><tr><th><?php esc_html_e( 'Τίτλος', 'trade-accounts' ); ?></th><th><?php esc_html_e( 'ISBN', 'trade-accounts' ); ?></th><th class="n"><?php esc_html_e( 'Τεμάχια', 'trade-accounts' ); ?></th><th class="n"><?php esc_html_e( 'Τιμή μονάδας', 'trade-accounts' ); ?></th><th class="n"><?php esc_html_e( 'Αξία', 'trade-accounts' ); ?></th></tr></thead>
	<tbody>
	<?php foreach ( $c['lines'] as $l ) : ?>
		<?php $v = (float) $l['price'] * (int) $l['given']; $total += $v; ?>
		<tr><td><?php echo esc_html( $l['name'] ); ?></td><td><?php echo esc_html( $l['isbn'] ); ?></td><td class="n"><?php echo (int) $l['given']; ?></td><td class="n"><?php echo esc_html( self::eur( $l['price'] ) ); ?></td><td class="n"><?php echo esc_html( self::eur( (string) $v ) ); ?></td></tr>
	<?php endforeach; ?>
	<tr><td colspan="2"><strong><?php esc_html_e( 'Σύνολο', 'trade-accounts' ); ?></strong></td><td class="n"><strong><?php echo (int) $c['totals']['given']; ?></strong></td><td></td><td class="n"><strong><?php echo esc_html( self::eur( (string) $total ) ); ?></strong></td></tr>
	</tbody>
</table>
<p class="tra-p-terms"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Τα βιβλία παραδίδονται σε παρακαταθήκη και παραμένουν ιδιοκτησία του εκδότη μέχρι την εξόφλησή τους. Οι τιμές περιλαμβάνουν ΦΠΑ. Εκκαθάριση (πουλημένα και επιστροφές) έως %s.', 'trade-accounts' ), self::day( $c['due'] ) ) ); ?></p>
<div class="tra-p-sign"><div><?php esc_html_e( 'Για τον εκδότη', 'trade-accounts' ); ?></div><div><?php esc_html_e( 'Για το βιβλιοπωλείο', 'trade-accounts' ); ?></div></div>
</body>
</html>
		<?php
		exit;
	}
}
