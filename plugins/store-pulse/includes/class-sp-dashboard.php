<?php
/**
 * SP_Dashboard — Το rendering: full dashboard, Quick View widget,
 * κάρτες, πίνακες, footer. Όλα τα νούμερα έρχονται από SP_Data
 * (Part 3) — εδώ δεν υπάρχει κανένας υπολογισμός, μόνο παρουσίαση.
 *
 * Δομή σελίδας (full dashboard):
 *   [Header: τίτλος + active period chips]
 *   [Grid 4 στηλών: εκκρεμείς | παλιές εκκρεμείς | πελάτες | AOV]
 *   [Grid: εξυπηρετημένες | επιστροφές | ακυρωμένες]
 *   [Money section: κέρδος εκδότη (share + καθαρό) | οφειλές προς άλλους
 *    + πίνακας ανά δικαιούχο | placeholder χωρίς Revenue Splitter]
 *   [Στοκ: πίνακες χαμηλού/εξαντλημένου]
 *   [Top sellers: πίνακας 10 καλύτερων]
 *   [Footer: Made with ❤ / glarolykoi.net / noxpress.tech]
 *
 * Render helpers: οι κάρτες είναι €-agnostic — η ίδια sp-card()
 * δεν ξέρει αν δείχνει κομμάτια ή ευρώ. Το formatting γίνεται από
 * sp_money()/sp_int() πριν την κλήση.
 */

defined( 'ABSPATH' ) || exit;

final class SP_Dashboard {

	public static function init(): void {
		// Δεν χρειάζεται hook — καλείται στατικά από SP_Admin.
	}

	/* =====================================================================
	 * Full dashboard page
	 * =================================================================== */

	public static function render_page(): void {

		// ---- Defaults από τις Ρυθμίσεις ----
		$p_orders  = SP_Data::safe_preset( 'sp_default_period_orders', '7d' );
		$p_money   = SP_Data::safe_preset( 'sp_default_period_money', 'month' );
		$p_refunds = SP_Data::safe_preset( 'sp_default_period_refunds', 'month' );
		$p_cancel  = SP_Data::safe_preset( 'sp_default_period_cancelled', 'month' );

		$r_orders  = SP_Data::period_range( $p_orders );
		$r_money   = SP_Data::period_range( $p_money );
		$r_refunds = SP_Data::period_range( $p_refunds );
		$r_cancel  = SP_Data::period_range( $p_cancel );

		$pending = SP_Data::pending();
		$done    = SP_Data::completed( $r_orders[0], $r_orders[1] );
		$refunds = SP_Data::refunds( $r_refunds[0], $r_refunds[1] );
		$cancels = SP_Data::cancelled( $r_cancel[0], $r_cancel[1] );
		$stock   = SP_Data::stock( SP_Data::safe_threshold() );

		$money     = null;
		$publisher = SP_Data::safe_publisher();

		if ( sp_rs_active() ) {
			$money = SP_Data::money( $r_money[0], $r_money[1], $publisher );
		}
		?>
		<div class="wrap sp-wrap">

			<h1 class="sp-h1"><?php esc_html_e( 'Store Pulse — Dashboard', 'store-pulse' ); ?></h1>

			<?php if ( '' === $publisher && sp_rs_active() ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: settings URL */
								'%s <a href="%s">%s</a>',
								esc_html__( 'Δεν έχεις επιλέξει εκδότη — πήγαινε στις Ρυθμίσεις.', 'store-pulse' ),
								esc_url( SP_Admin::settings_url() ),
								esc_html__( 'Ρυθμίσεις', 'store-pulse' )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<!-- ================= Row 1: τάξη παραγγελιών ================= -->
			<div class="sp-grid sp-grid-4">
				<?php
				self::card(
					'pending',
					__( 'Εκκρεμείς παραγγελίες', 'store-pulse' ),
					number_format_i18n( $pending['count'] ),
					( $pending['count'] > 0 ) ? 'sp-warn' : ''
				);
				self::card(
					'old_pending',
					__( 'Παλιές εκκρεμείς (>7 ημέρες)', 'store-pulse' ),
					number_format_i18n( $pending['old'] ),
					( $pending['old'] > 0 ) ? 'sp-alert' : ''
				);
				self::card(
					'customers',
					__( 'Πελάτες (περίοδος)', 'store-pulse' ),
					number_format_i18n( $done['customers'] )
				);
				self::card(
					'aov',
					__( 'Μέση αξία παραγγελίας', 'store-pulse' ),
					sp_money( $done['aov'] )
				);
				?>
			</div>

			<!-- ================= Row 2: περίοδοι ================= -->
			<div class="sp-grid sp-grid-3">
				<?php
				self::card(
					'completed',
					sprintf(
						/* translators: %s: period label */
						__( 'Εξυπηρετημένες παραγγελίες (%s)', 'store-pulse' ),
						__( SP_Admin::PRESET_LABELS[ $p_orders ], 'store-pulse' )
					),
					number_format_i18n( $done['count'] ),
					'',
					sprintf(
						'%s → %s',
						esc_html( SP_Lang::fmt_date( $r_orders[0] ) ),
						esc_html( SP_Lang::fmt_date( $r_orders[1] ) )
					)
				);
				self::card(
					'refunds',
					__( 'Επιστροφές', 'store-pulse' ),
					number_format_i18n( $refunds['count'] ),
					( $refunds['count'] > 0 ) ? 'sp-warn' : '',
					sp_money( $refunds['amount'] ) . ' · '
						. esc_html( SP_Lang::fmt_date( $r_refunds[0] ) ) . ' → '
						. esc_html( SP_Lang::fmt_date( $r_refunds[1] ) )
				);
				self::card(
					'cancelled',
					__( 'Ακυρωμένες', 'store-pulse' ),
					number_format_i18n( $cancels['count'] ),
					( $cancels['count'] > 0 ) ? 'sp-warn' : '',
					esc_html( SP_Lang::fmt_date( $r_cancel[0] ) ) . ' → '
						. esc_html( SP_Lang::fmt_date( $r_cancel[1] ) )
				);
				?>
			</div>

			<!-- ================= Money section ================= -->
			<?php if ( null === $money ) : ?>

				<div class="sp-money-placeholder">
					<p class="sp-money-title">
						<?php esc_html_e( 'Χρειάζεται το Revenue Splitter', 'store-pulse' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'Τα χρηματικά μεγέθη χρειάζονται το Revenue Splitter. Κάνε ενεργό και τα δύο plugins για εδώ.', 'store-pulse' ); ?>
					</p>
				</div>

			<?php elseif ( ! $money['publisher_known'] ) : ?>

				<div class="sp-money-placeholder">
					<p class="sp-money-title">
						<?php esc_html_e( 'Κέρδος εκδότη', 'store-pulse' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'Ο εκδότης δεν είναι γνωστός δικαιούχος του Revenue Splitter.', 'store-pulse' ); ?>
					</p>
				</div>

			<?php else : ?>

				<div class="sp-grid sp-grid-3">
					<?php
					self::card(
						'publisher_share',
						sprintf(
							'%s (%s)',
							__( 'Κέρδος εκδότη (περίοδος)', 'store-pulse' ),
							__( SP_Admin::PRESET_LABELS[ $p_money ], 'store-pulse' )
						),
						sp_money( $money['share'] ),
						'',
						esc_html( SP_Lang::fmt_date( $r_money[0] ) ) . ' → '
							. esc_html( SP_Lang::fmt_date( $r_money[1] ) )
					);
					self::card(
						'publisher_net',
						__( 'Καθαρό (μετά κρατήσεων)', 'store-pulse' ),
						sp_money( $money['net'] ),
						'',
						esc_html( SP_Lang::fmt_date( $r_money[0] ) ) . ' → '
							. esc_html( SP_Lang::fmt_date( $r_money[1] ) )
					);
					self::card(
						'others',
						__( 'Οφειλές προς άλλους', 'store-pulse' ),
						sp_money( $money['others_sum'] ),
						( $money['others_sum'] > 0 ) ? 'sp-info' : ''
					);
					?>
				</div>

				<p class="sp-note">
					<?php esc_html_e( 'Το μεταφορικό ΔΕΝ υπολογίζεται στα ποσά.', 'store-pulse' ); ?>
				</p>

				<?php if ( array() !== $money['others'] ) : ?>
					<h2 class="sp-h2"><?php esc_html_e( 'Οφειλές ανά δικαιούχο', 'store-pulse' ); ?></h2>
					<table class="sp-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Δικαιούχος', 'store-pulse' ); ?></th>
								<th class="sp-num"><?php esc_html_e( 'Μερίδιο', 'store-pulse' ); ?></th>
								<th class="sp-num"><?php esc_html_e( 'Καθαρό (μετά κρατήσεων)', 'store-pulse' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $money['others'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td class="sp-num"><?php echo esc_html( sp_money( $row['share'] ) ); ?></td>
									<td class="sp-num">
										<?php echo esc_html( sp_money( $row['owed'] ) ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr>
								<th><?php esc_html_e( 'ΣΥΝΟΛΑ', 'store-pulse' ); ?></th>
								<th class="sp-num">
									<?php echo esc_html( sp_money( $money['others_sum'] ) ); ?>
								</th>
								<th class="sp-num">&nbsp;</th>
							</tr>
						</tfoot>
					</table>
				<?php else : ?>
					<p class="sp-empty"><?php esc_html_e( 'Καμία οφειλή στην περίοδο.', 'store-pulse' ); ?></p>
				<?php endif; ?>

			<?php endif; ?>

			<!-- ================= Στοκ ================= -->
			<h2 class="sp-h2"><?php esc_html_e( 'Χαμηλό στοκ', 'store-pulse' ); ?></h2>
			<?php if ( $stock['low']['count'] > 0 ) : ?>
				<table class="sp-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Προϊόν', 'store-pulse' ); ?></th>
							<th class="sp-num"><?php esc_html_e( 'Στοκ', 'store-pulse' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stock['low']['items'] as $item ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>">
										<?php echo esc_html( $item['title'] ); ?>
									</a>
								</td>
								<td class="sp-num sp-stock-low"><?php echo esc_html( number_format_i18n( $item['stock'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="sp-empty"><?php esc_html_e( 'Όλα τα προϊόντα έχουν επαρκές απόθεμα.', 'store-pulse' ); ?></p>
			<?php endif; ?>

			<h2 class="sp-h2"><?php esc_html_e( 'Εξαντλημένα', 'store-pulse' ); ?></h2>
			<?php if ( $stock['out']['count'] > 0 ) : ?>
				<table class="sp-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Προϊόν', 'store-pulse' ); ?></th>
							<th class="sp-num"><?php esc_html_e( 'Στοκ', 'store-pulse' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stock['out']['items'] as $item ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>">
										<?php echo esc_html( $item['title'] ); ?>
									</a>
								</td>
								<td class="sp-num sp-stock-out"><?php echo esc_html( number_format_i18n( $item['stock'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="sp-empty"><?php esc_html_e( 'Κανένα προϊόν χωρίς απόθεμα.', 'store-pulse' ); ?></p>
			<?php endif; ?>

			<!-- ================= Top sellers ================= -->
			<h2 class="sp-h2">
				<?php
				printf(
					/* translators: %s: period label */
					__( 'Καλύτερες πωλήσεις (%s)', 'store-pulse' ),
					__( SP_Admin::PRESET_LABELS[ $p_orders ], 'store-pulse' )
				);
				?>
			</h2>
			<?php if ( array() !== $done['products'] ) : ?>
				<table class="sp-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Προϊόν', 'store-pulse' ); ?></th>
							<th class="sp-num"><?php esc_html_e( 'Τεμ.', 'store-pulse' ); ?></th>
							<th class="sp-num"><?php esc_html_e( 'Μικτό', 'store-pulse' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $done['products'] as $row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>">
										<?php echo esc_html( $row['title'] ); ?>
									</a>
								</td>
								<td class="sp-num"><?php echo esc_html( number_format_i18n( $row['qty'] ) ); ?></td>
								<td class="sp-num"><?php echo esc_html( sp_money( $row['gross'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="sp-empty"><?php esc_html_e( 'Καμία πώληση στην περίοδο.', 'store-pulse' ); ?></p>
			<?php endif; ?>

			<?php self::render_footer(); ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Quick View widget
	 * =================================================================== */

	public static function render_widget(): void {

		$cards   = SP_Data::safe_quick_cards();
		$p_orders = SP_Data::safe_preset( 'sp_default_period_orders', '7d' );
		$p_money  = SP_Data::safe_preset( 'sp_default_period_money', 'month' );

		$r_orders = SP_Data::period_range( $p_orders );
		$r_money  = SP_Data::period_range( $p_money );

		$pending = SP_Data::pending();
		$done    = SP_Data::completed( $r_orders[0], $r_orders[1] );
		$stock   = SP_Data::stock( SP_Data::safe_threshold() );

		$money     = null;
		$publisher = SP_Data::safe_publisher();

		if ( sp_rs_active() ) {
			$money = SP_Data::money( $r_money[0], $r_money[1], $publisher );
		}

		echo '<div class="sp-wrap sp-widget">';

		echo '<ul class="sp-widget-list">';

		if ( in_array( 'pending', $cards, true ) ) {
			self::widget_row(
				__( 'Εκκρεμείς παραγγελίες', 'store-pulse' ),
				number_format_i18n( $pending['count'] ),
				( $pending['count'] > 0 )
			);
		}

		if ( in_array( 'old_pending', $cards, true ) ) {
			self::widget_row(
				__( 'Παλιές εκκρεμείς (>7 ημέρες)', 'store-pulse' ),
				number_format_i18n( $pending['old'] ),
				( $pending['old'] > 0 )
			);
		}

		if ( in_array( 'completed', $cards, true ) ) {
			self::widget_row(
				sprintf(
					'%s (%s)',
					__( 'Εξυπηρετημένες παραγγελίες', 'store-pulse' ),
					__( SP_Admin::PRESET_LABELS[ $p_orders ], 'store-pulse' )
				),
				number_format_i18n( $done['count'] )
			);
		}

		if ( in_array( 'low', $cards, true ) ) {
			self::widget_row(
				__( 'Χαμηλό στοκ', 'store-pulse' ),
				number_format_i18n( $stock['low']['count'] ),
				( $stock['low']['count'] > 0 )
			);
		}

		if ( in_array( 'out', $cards, true ) ) {
			self::widget_row(
				__( 'Εξαντλημένα', 'store-pulse' ),
				number_format_i18n( $stock['out']['count'] ),
				( $stock['out']['count'] > 0 )
			);
		}

		if ( in_array( 'publisher', $cards, true ) ) {
			if ( null !== $money && $money['publisher_known'] ) {
				self::widget_row(
					__( 'Κέρδος εκδότη', 'store-pulse' ),
					sp_money( $money['net'] )
				);
			} else {
				self::widget_row(
					__( 'Κέρδος εκδότη', 'store-pulse' ),
					__( 'Χρειάζεται το Revenue Splitter', 'store-pulse' ),
					false
				);
			}
		}

		if ( in_array( 'others', $cards, true ) ) {
			if ( null !== $money ) {
				self::widget_row(
					__( 'Οφειλές προς άλλους', 'store-pulse' ),
					sp_money( $money['others_sum'] ),
					( $money['others_sum'] > 0 )
				);
			} else {
				self::widget_row(
					__( 'Οφειλές προς άλλους', 'store-pulse' ),
					__( 'Χρειάζεται το Revenue Splitter', 'store-pulse' ),
					false
				);
			}
		}

		echo '</ul>';

		echo '<p class="sp-widget-footer">';
		printf(
			'<a href="%s">%s</a>',
			esc_url( SP_Admin::dash_url() ),
			esc_html__( '🤓 Dashboard', 'store-pulse' )
		);
		echo ' <span class="sp-muted"> · </span> ';
		printf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			'https://ko-fi.com/koulaxizis',
			esc_html__( '☕ Ko-fi', 'store-pulse' )
		);
		echo '</p>';

		echo '</div>';
	}

	/* =====================================================================
	 * Footer (κοινό για dashboard)
	 * =================================================================== */

	private static function render_footer(): void {
		?>
		<hr class="sp-hr" />
		<p class="sp-footer">
			<?php
			printf(
				/* translators: %s: author name */
				'Made with <span aria-label="heart">❤</span> by %s',
				'Christos Koulaxizis'
			);
			?>
			<br />
			<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">koulaxizis.gr</a>
			&middot;
			<?php esc_html_e( 'Part of glarolykoi.net', 'store-pulse' ); ?>
			&middot;
			<?php
			echo wp_kses_post(
				sprintf(
					'%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_html__( 'More plugins at', 'store-pulse' ),
					'https://noxpress.tech',
					'noxpress.tech'
				)
			);
			?>
		</p>
		<p class="sp-footer">
			<a class="sp-footer-cta" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Στήριξε την ανάπτυξη στο Ko-Fi', 'store-pulse' ); ?>
			</a>
		</p>
		<?php
	}

	/* =====================================================================
	 * Render helpers
	 * =================================================================== */

	/**
	 * Μία κάρτα. Το $value είναι ήδη formatted string (sp_money /
	 * number_format_i18n) — εδώ μόνο escaping.
	 */
	private static function card(
		string $key,
		string $label,
		string $value,
		string $modifier = '',
		string $sub = ''
	): void {
		?>
		<div class="sp-card <?php echo esc_attr( trim( $modifier ) ); ?>" id="sp-card-<?php echo esc_attr( $key ); ?>">
			<div class="sp-card-label"><?php echo esc_html( $label ); ?></div>
			<div class="sp-card-value"><?php echo esc_html( $value ); ?></div>
			<?php if ( '' !== $sub ) : ?>
				<div class="sp-card-sub"><?php echo esc_html( $sub ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Μία γραμμή widget (label …… value). */
	private static function widget_row( string $label, string $value, bool $accent = false ): void {
		printf(
			'<li class="%1$s"><span class="sp-wl">%2$s</span><span class="sp-wv">%3$s</span></li>',
			esc_attr( $accent ? 'sp-wrow sp-accent' : 'sp-wrow' ),
			esc_html( $label ),
			esc_html( $value )
		);
	}
}

/* =====================================================================
 * Συνάρτηση formatting (namespaced με sp_ prefix — καμία επικάλυψη
 * με το Revenue Splitter).
 * ===================================================================== */

/** Ευρώ με i18n decimals — «12.345,67 €» σε el, «€12,345.67» σε en. */
function sp_money( float $amount ): string {
	return wp_strip_all_tags( html_entity_decode( wp_kses( wc_price( $amount ), array() ) ) );
}