<?php
/**
 * RS_CLI — WP-CLI commands (v1.3.0).
 *
 * Διαθέσιμες εντολές:
 *
 *   wp rs report [--start=Y-m-d] [--end=Y-m-d] [--product=<id>]
 *               [--ben=<name>] [--format=table|json]
 *   wp rs ledger-add --type=income|payment --ben=<name> --amount=<n>
 *                   --note="<reason>" [--date=Y-m-d]
 *   wp rs ledger-list [--ben=<name>] [--type=income|payment] [--format=table|json]
 *   wp rs ledger-delete --id=<id>
 *   wp rs balance --ben=<name>
 *   wp rs backup
 *
 * Σχέδιο: οι εντολές δεν έχουν δικό τους state — βάζουν απλώς κλήσεις
 * στα ίδια PUBLIC APIs που χρησιμοποιεί το web UI (RS_Reports,
 * RS_Ledger). Μία λογική, μηδέν drift.
 *
 * v1.3.1 (#19): required-args check με isset() + ''!== (όχι empty())
 * ώστε --amount="0" / --note="0" να φτάνουν στο RS_Ledger::add() και να
 * παίρνουν το σωστό (επιχειρησιακό) μήνυμα απόκρισης. Cleanup: αφαίρεση
 * αχρησιμοποίητου stamp() helper.
 *
 * Note: η έξοδος είναι ΣΤΟΙΧΗΜΕΝΗ στα Αγγλικά (CLI convention) — τα
 * επιχειρησιακά μηνύματα των classes μένουν όμως μεταφρασμένα ως
 * δύναται (gettext), οπότε η ροή είναι σταθερή.
 */

defined( 'ABSPATH' ) || exit;

final class RS_CLI {

	public static function init(): void {
		WP_CLI::add_command( 'rs report', array( __CLASS__, 'report' ) );
		WP_CLI::add_command( 'rs ledger-add', array( __CLASS__, 'ledger_add' ) );
		WP_CLI::add_command( 'rs ledger-list', array( __CLASS__, 'ledger_list' ) );
		WP_CLI::add_command( 'rs ledger-delete', array( __CLASS__, 'ledger_delete' ) );
		WP_CLI::add_command( 'rs balance', array( __CLASS__, 'balance' ) );
		WP_CLI::add_command( 'rs backup', array( __CLASS__, 'backup' ) );
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/** Αριθμός 2 δεκαδικών, dot decimal (CLI-friendly, όχι i18n). */
	private static function fmt( $n ): string {
		return number_format( (float) $n, 2, '.', '' );
	}

	/** Έλεγχος 'Y-m-d'. */
	private static function is_date( string $d ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/* =====================================================================
	 * wp rs report
	 * =================================================================== */

	/**
	 * Show the sales report for a period.
	 *
	 * ## OPTIONS
	 *
	 * [--start=<date>]
	 * : Period start (Y-m-d). Default: 30 days ago.
	 *
	 * [--end=<date>]
	 * : Period end (Y-m-d). Default: today.
	 *
	 * [--product=<id>]
	 * : Limit to a single product ID.
	 *
	 * [--ben=<name>]
	 * : Limit to a single beneficiary (splits filtered accordingly).
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs report
	 *     wp rs report --start=2026-08-01 --end=2026-08-31 --format=json
	 *     wp rs report --product=128 --ben="Christos Koulaxizis"
	 */
	public static function report( array $args, array $assoc ): void {

		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			WP_CLI::error( 'Insufficient permissions.' );
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$start = isset( $assoc['start'] ) ? (string) $assoc['start'] : $now->modify( '-29 days' )->format( 'Y-m-d' );
		$end   = isset( $assoc['end'] ) ? (string) $assoc['end'] : $now->format( 'Y-m-d' );

		if ( ! self::is_date( $start ) || ! self::is_date( $end ) || $start > $end ) {
			WP_CLI::error( 'Invalid --start/--end (expected Y-m-d, start <= end).' );
		}

		$run = array(
			'date_start' => $start,
			'date_end'   => $end,
		);

		if ( ! empty( $assoc['product'] ) ) {
			$pid = absint( $assoc['product'] );
			if ( $pid > 0 ) {
				$run['product_ids'] = array( $pid );
			}
		}

		$report = RS_Reports::run( $run );

		$ben = isset( $assoc['ben'] ) ? trim( (string) $assoc['ben'] ) : '';

		// Ίδιο semantics με το dashboard: φίλτρο δικαιούχου →
		// επανυπολογισμός totals/beneficiaries (μία υλοποίηση).
		if ( '' !== $ben ) {
			$report = RS_Admin_UI::apply_ben_filter( $report, $ben );
		}

		$items = array();
		foreach ( $report['products'] as $p ) {

			$splits = $p['splits'];
			if ( '' !== $ben ) {
				$splits = array_values(
					array_filter(
						$splits,
						static function ( $s ) use ( $ben ) {
							return (string) $s['name'] === $ben;
						}
					)
				);
				if ( empty( $splits ) ) {
					continue;
				}
			}

			$items[] = array(
				'id'      => (int) $p['product_id'],
				'product' => (string) $p['title'],
				'qty'     => (int) $p['qty'],
				'gross'   => self::fmt( $p['gross'] ),
				'vat'     => self::fmt( $p['vat'] ),
				'net'     => self::fmt( $p['net'] ),
				'splits'  => implode(
					'; ',
					array_map(
						static function ( $s ) {
							return $s['name'] . ' ' . self::fmt( $s['amount'] ) . ' (' . $s['percent'] . '%)';
						},
						$splits
					)
				),
			);
		}

		WP_CLI::line( 'Revenue Splitter — period ' . $start . ' → ' . $end . ' (' . (int) $report['order_count'] . ' orders)' );

		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';

		if ( 'json' === $format ) {
			WP_CLI::line(
				wp_json_encode(
					array(
						'period'        => $report['period'],
						'order_count'  => $report['order_count'],
						'products'     => $items,
						'totals'       => $report['totals'],
						'beneficiaries' => $report['beneficiaries'],
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
				)
			);
		} else {
			WP_CLI\Utils\format_items( $format, $items, array( 'id', 'product', 'qty', 'gross', 'vat', 'net', 'splits' ) );

			$t = $report['totals'];
			WP_CLI::line( 'TOTALS: gross ' . self::fmt( $t['gross'] ) . ' | vat ' . self::fmt( $t['vat'] ) . ' | net ' . self::fmt( $t['net'] ) );
		}
	}

	/* =====================================================================
	 * wp rs ledger-add
	 * =================================================================== */

	/**
	 * Add a ledger entry (non-sales income or payment).
	 *
	 * ## OPTIONS
	 *
	 * --type=<type>
	 * : Entry type: income or payment.
	 *
	 * --ben=<name>
	 * : Beneficiary name (must exist in Settings).
	 *
	 * --amount=<amount>
	 * : Amount (payments: positive only; income: + or -, non-zero).
	 *
	 * --note=<note>
	 * : Mandatory reason.
	 *
	 * [--date=<date>]
	 * : Entry date (Y-m-d). Default: today.
	 *
	 * [--channel=<channel>]
	 * : Optional sales channel (must exist in the Settings channel list).
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs ledger-add --type=income --ben="Maria Papadopoulou" --amount=-40 --note="correction: double entry June"
	 *     wp rs ledger-add --type=payment --ben="Maria Papadopoulou" --amount=250 --note="Q3 payout" --date=2026-09-30
	 */
	public static function ledger_add( array $args, array $assoc ): void {

		// Audit fix: capability check — WP-CLI τρέχει ως WP user,
		// άρα χρειάζεται ρητό cap για defense-in-depth (CI pipelines,
		// scheduled tasks, non-root contexts).
		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			WP_CLI::error( 'Insufficient permissions.' );
		}

		// v1.3.1 (#19): isset() + ''!== — ΟΧΙ empty().
		$required = array( 'type', 'ben', 'amount', 'note' );
		foreach ( $required as $r ) {
			if ( ! isset( $assoc[ $r ] ) || '' === (string) $assoc[ $r ] ) {
				WP_CLI::error( 'Missing required --' . $r . '.' );
			}
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$entry = array(
			'type'        => (string) $assoc['type'],
			'date'        => isset( $assoc['date'] ) ? (string) $assoc['date'] : $now->format( 'Y-m-d' ),
			'beneficiary' => (string) $assoc['ben'],
			'amount'      => (string) $assoc['amount'],
			'note'        => (string) $assoc['note'],
			'channel'     => isset( $assoc['channel'] ) ? (string) $assoc['channel'] : '',
		);

		$res = RS_Ledger::add( $entry );

		if ( true === $res ) {
			do_action( 'rs_invalidate_cache' ); // Invalidate reports/portal caches.
			WP_CLI::success( 'Ledger entry added: ' . $entry['type'] . ' ' . $entry['amount'] . ' → ' . $entry['beneficiary'] . ' (' . $entry['date'] . ')' );
		} else {
			WP_CLI::error( 'Rejected by RS_Ledger::add(): ' . (string) $res );
		}
	}

	/* =====================================================================
	 * wp rs ledger-list
	 * =================================================================== */

	/**
	 * List ledger entries.
	 *
	 * ## OPTIONS
	 *
	 * [--ben=<name>]
	 * : Filter by beneficiary.
	 *
	 * [--type=<type>]
	 * : Filter by type: income or payment.
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs ledger-list
	 *     wp rs ledger-list --ben="Maria Papadopoulou" --type=payment
	 */
	public static function ledger_list( array $args, array $assoc ): void {

		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			WP_CLI::error( 'Insufficient permissions.' );
		}

		$ben  = isset( $assoc['ben'] ) ? (string) $assoc['ben'] : null;
		$type = isset( $assoc['type'] ) ? (string) $assoc['type'] : null;

		if ( null !== $type && ! in_array( $type, array( 'income', 'payment' ), true ) ) {
			WP_CLI::error( 'Invalid --type (income|payment).' );
		}

		$entries = RS_Ledger::all();

		// Uniform filter path: ben και/ή type, πάντα με τον ίδιο τρόπο.
		if ( null !== $ben ) {
			$entries = array_values(
				array_filter(
					$entries,
					static function ( $e ) use ( $ben ) {
						return $e['beneficiary'] === $ben;
					}
				)
			);
		}

		if ( null !== $type ) {
			$entries = array_values(
				array_filter(
					$entries,
					static function ( $e ) use ( $type ) {
						return $e['type'] === $type;
					}
				)
			);
		}

		if ( empty( $entries ) ) {
			WP_CLI::log( 'No ledger entries found.' );
			return;
		}

		$items = array_map(
			static function ( array $e ) {
				return array(
					'id'     => (int) $e['id'],
					'date'   => $e['date'],
					'type'   => $e['type'],
					'ben'    => $e['beneficiary'],
					'amount' => self::fmt( $e['amount'] ),
					'note'   => $e['note'],
				);
			},
			$entries
		);

		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			WP_CLI\Utils\format_items( $format, $items, array( 'id', 'date', 'type', 'ben', 'amount', 'note' ) );
		}
	}

	/* =====================================================================
	 * wp rs ledger-delete
	 * =================================================================== */

	/**
	 * Delete a ledger entry by ID.
	 *
	 * ## OPTIONS
	 *
	 * --id=<id>
	 * : Entry ID (see: wp rs ledger-list).
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs ledger-delete --id=7
	 */
	public static function ledger_delete( array $args, array $assoc ): void {

		// Audit fix: capability check — same as ledger_add.
		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			WP_CLI::error( 'Insufficient permissions.' );
		}

		$id = isset( $assoc['id'] ) ? absint( $assoc['id'] ) : 0;
		if ( $id <= 0 ) {
			WP_CLI::error( 'Missing or invalid --id.' );
		}

		if ( RS_Ledger::delete( $id ) ) {
			do_action( 'rs_invalidate_cache' ); // Invalidate reports/portal caches.
			WP_CLI::success( 'Ledger entry #' . $id . ' deleted.' );
		} else {
			WP_CLI::error( 'Entry #' . $id . ' not found.' );
		}
	}

	/* =====================================================================
	 * wp rs balance
	 * =================================================================== */

	/**
	 * Show the LIFETIME balance of a beneficiary (all-time sales +
	 * non-sales income − payments).
	 *
	 * ## OPTIONS
	 *
	 * --ben=<name>
	 * : Beneficiary name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs balance --ben="Maria Papadopoulou"
	 */
	public static function balance( array $args, array $assoc ): void {

		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			WP_CLI::error( 'Insufficient permissions.' );
		}

		$ben = isset( $assoc['ben'] ) ? trim( (string) $assoc['ben'] ) : '';
		if ( '' === $ben ) {
			WP_CLI::error( 'Missing --ben.' );
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$sales  = RS_Reports::lifetime_beneficiaries()[ $ben ] ?? 0.0;
		$income = RS_Ledger::sum( $ben, '2000-01-01', $now->format( 'Y-m-d' ), 'income' );
		$paid   = RS_Ledger::sum( $ben, '2000-01-01', $now->format( 'Y-m-d' ), 'payment' );

		// Audit fix: lifetime_beneficiaries() κάνει caching 5' —
		// ο χρήστης πρέπει να ξέρει ότι το balance μπορεί να είναι
		// stale μετά από ledger changes μέχρι το cache να expire.
		WP_CLI::line( 'Lifetime balance for "' . $ben . '"' );
		WP_CLI::line( '  All-time sales:      ' . self::fmt( $sales ) );
		WP_CLI::line( '  Non-sales income:    ' . self::fmt( $income ) );
		WP_CLI::line( '  Payments:            ' . self::fmt( $paid ) );
		WP_CLI::line( '  Remaining payable:   ' . self::fmt( $sales + $income - $paid ) );
	}

	/* =====================================================================
	 * wp rs backup
	 * =================================================================== */

	/**
	 * Dump the full plugin state as JSON (stdout — pipe to file to save).
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs backup > rs-backup-2026-09-04.json
	 */
	public static function backup( array $args, array $assoc ): void {
		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			WP_CLI::error( 'Insufficient permissions.' );
		}

		WP_CLI::warning( 'Backup includes hashed portal keys, ledger, product overrides. Handle securely.' );

		WP_CLI::line(
			wp_json_encode(
				RS_Admin_UI::export_state(),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}
}

/* ===== SECTION 3c: RENDER ===== */

var cellRefs = [];
var headRow = null;
var rowHeads = [];
var colHeads = [];
var DISP = {};
var lastPainted = [];

function buildGrid() {
  var tbl = $("grid");
  if (!tbl) { console.error("[SS] CRITICAL: #grid element missing!"); return; }

  var sheet = getActiveSheet();
  if (!sheet) { console.error("[SS] CRITICAL: no active sheet!"); return; }

  /* Safety net: repair invalid dimensions before building */
  if (typeof sheet.rows !== "number" || sheet.rows < 1) sheet.rows = ROWS;
  if (typeof sheet.cols !== "number" || sheet.cols < 1) sheet.cols = COLS;

  var r, c, tr, th, td;

  tbl.innerHTML = ""; /* rebuild on sheet switch */

  var thead = document.createElement("thead");
  headRow = document.createElement("tr");
  th = document.createElement("th");
  th.className = "corner";
  headRow.appendChild(th);
  colHeads = [th];
  for (c = 0; c < sheet.cols; c++) {
    th = document.createElement("th");
    th.textContent = colName(c);
    headRow.appendChild(th);
    colHeads.push(th);
  }
  thead.appendChild(headRow);
  tbl.appendChild(thead);

  var tbody = document.createElement("tbody");
  cellRefs = []; rowHeads = []; lastPainted = [];
  for (r = 0; r < sheet.rows; r++) {
    tr = document.createElement("tr");
    th = document.createElement("th");
    th.className = "rowh";
    th.textContent = String(r + 1);
    tr.appendChild(th);
    rowHeads.push(th);
    cellRefs[r] = []; lastPainted[r] = [];
    for (c = 0; c < sheet.cols; c++) {
      td = document.createElement("td");
      tr.appendChild(td);
      cellRefs[r][c] = td;
      lastPainted[r][c] = null;
    }
    tbody.appendChild(tr);
  }
  tbl.appendChild(tbody);
}

function displayVal(r, c) {
  var k = r + "," + c;
  if (DISP.hasOwnProperty(k)) return DISP[k];
  var v = computeCellValue(r, c);
  DISP[k] = v;
  return v;
}

function paintCell(r, c) {
  if (editing && editingR === r && editingC === c) return;
  var td = cellRefs[r] && cellRefs[r][c];
  if (!td) return;
  var disp = displayVal(r, c);
  if (lastPainted[r][c] === disp) return;
  lastPainted[r][c] = disp;
  td.textContent = disp;
  var cls = "";
  if (disp !== "" && disp.charAt(0) === "#") cls = "err";
  else if (disp !== "" && isFinite(parseFloat(disp))) cls = "num";
  td.className = cls;
}

function renderGrid() {
  DISP = {};
  var sheet = getActiveSheet(), r, c;
  if (!sheet) return;
  for (r = 0; r < sheet.rows; r++)
    for (c = 0; c < sheet.cols; c++) paintCell(r, c);
}

function refreshCell(r, c) { DISP = {}; paintCell(r, c); }

function renderSelection() {
  var i;
  for (i = 0; i < rowHeads.length; i++)
    rowHeads[i].classList.remove("hl");
  for (i = 0; i < colHeads.length; i++)
    colHeads[i].classList.remove("hl");

  var prev = document.querySelectorAll("#grid td.sel");
  for (i = 0; i < prev.length; i++) prev[i].classList.remove("sel");

  if (selR < 0 || selC < 0) return;
  var td = cellRefs[selR] && cellRefs[selR][selC];
  if (td) td.classList.add("sel");
  if (rowHeads[selR]) rowHeads[selR].classList.add("hl");
  if (colHeads[selC + 1]) colHeads[selC + 1].classList.add("hl");

  var refTxt = colName(selC) + (selR + 1);
  $("st-sel").textContent = refTxt;
  $("fx-ref").textContent = refTxt;
  if (!fxFocused) {
    var cel = getCell(selR, selC);
    $("fx-input").value = cel ? cel.v : "";
  }
}

/* ===== SECTION 3d: EDITING FLOW ===== */

function beginEdit(initialText) {
  if (editing) return;
  editing = true; editingR = selR; editingC = selC;
  var td = cellRefs[selR] && cellRefs[selR][selC];
  if (!td) { editing = false; return; }

  editInput = document.createElement("input");
  editInput.type = "text";
  editInput.autocomplete = "off";
  editInput.spellcheck = false;
  editInput.value =
    (initialText !== undefined && initialText !== null)
      ? initialText
      : (function () {
          var cel = getCell(selR, selC);
          return cel ? cel.v : "";
        })();
  td.textContent = "";
  td.className = "cell-editor";
  td.appendChild(editInput);
  editInput.focus();
  if (initialText !== undefined) editInput.setSelectionRange(
    editInput.value.length, editInput.value.length);

  editInput.addEventListener("keydown", function (e) {
    if (e.key === "Enter") { e.preventDefault(); commitEdit(1, 0); }
    else if (e.key === "Tab") { e.preventDefault(); commitEdit(0, 1); }
    else if (e.key === "Escape") { e.preventDefault(); cancelEdit(); }
    e.stopPropagation();
  });
  editInput.addEventListener("input", function () {
    $("fx-input").value = editInput.value;
  });
}

function endEditDom() {
  var td = cellRefs[editingR] && cellRefs[editingR][editingC];
  if (td && editInput && td.contains(editInput)) td.removeChild(editInput);
  editInput = null;
  editing = false;
  lastPainted[editingR][editingC] = null;
  editingR = -1; editingC = -1;
}

function commitEdit(dr, dc) {
  if (!editing) return;
  var val = editInput ? editInput.value : "";
  val = val.replace(/^\s+|\s+$/g, "");
  var r = editingR, c = editingC;
  if (val === "") {
    if (getCell(r, c)) deleteCell(r, c);
  } else {
    setCell(r, c, val);
  }
  endEditDom();
  queueSave();
  refreshCell(r, c);
  if (dr || dc) navigate(dr, dc);
  renderSelection();
}

function cancelEdit() {
  if (!editing) return;
  var r = editingR, c = editingC;
  endEditDom();
  refreshCell(r, c);
  renderSelection();
  $("grid-wrap").focus();
}

function navigate(dr, dc) {
  var sheet = getActiveSheet();
  if (!sheet) return;
  var nr = Math.min(Math.max(selR + dr, 0), sheet.rows - 1);
  var nc = Math.min(Math.max(selC + dc, 0), sheet.cols - 1);
  if (nr === selR && nc === selC) return;
  selR = nr; selC = nc;
  renderSelection();
  var td = cellRefs[selR] && cellRefs[selR][selC];
  if (td) {
    try { td.scrollIntoView({ block: "nearest", inline: "nearest" }); }
    catch (e) {}
  }
}

function clearSelected() {
  if (editing) return;
  if (getCell(selR, selC)) {
    deleteCell(selR, selC);
    queueSave();
    refreshCell(selR, selC);
  }
}

/* ===== SECTION 3e: SHEET TABS ===== */

function displayName(sh) {
  if (!sh) return "";
  if (sh.name !== null && sh.name !== undefined) return sh.name;
  return sh.bi ? (sh.bi[LANG] || sh.bi.en || sh.id) : sh.id;
}

function nextSheetNumber() {
  var n = 1, i;
  for (i = 0; i < state.sheets.length; i++) {
    var m = displayName(state.sheets[i]).match(/(\d+)\s*$/);
    if (m) n = Math.max(n, parseInt(m[1], 10));
  }
  return n + 1;
}

function maxPos() {
  var p = 0, i;
  for (i = 0; i < state.sheets.length; i++)
    if ((state.sheets[i].pos || 0) > p) p = state.sheets[i].pos;
  return p;
}

var armX = { id: null, timer: null }; /* armed-delete confirmation */

function renderTabs() {
  var nav = $("stabs");
  if (!nav) return;
  nav.innerHTML = "";

  var sheets = state.sheets.slice().sort(function (a, b) {
    return (a.pos || 0) - (b.pos || 0);
  });

  var i;
  for (i = 0; i < sheets.length; i++) {
    (function (sh) {
      var tab = document.createElement("button");
      tab.type = "button";
      tab.className = "stab" + (sh.id === actSID ? " active" : "");

      var label = document.createElement("span");
      label.className = "stab-label";
      label.textContent = displayName(sh);
      tab.appendChild(label);

      var x = document.createElement("span");
      x.className = "stab-x";
      x.textContent = "\u2715"; /* ✕ */
      x.title = t("tab.confirm");
      if (armX.id === sh.id) x.classList.add("armed");
      x.addEventListener("click", function (e) {
        e.stopPropagation();
        tryDeleteSheet(sh.id);
      });
      tab.appendChild(x);

      tab.addEventListener("click", function (e) {
        if (e.target.classList && e.target.classList.contains("stab-x")) return;
        if (editing) commitEdit(0, 0);
        switchTo(sh.id);
      });
      tab.addEventListener("dblclick", function (e) {
        if (e.target.classList && e.target.classList.contains("stab-x")) return;
        beginRename(tab, label, sh);
      });

      nav.appendChild(tab);
    })(sheets[i]);
  }

  var add = document.createElement("button");
  add.type = "button";
  add.className = "stab-add";
  add.textContent = "+";
  add.title = t("sheet.default");
  add.addEventListener("click", function () {
    if (editing) commitEdit(0, 0);
    addSheet();
  });
  nav.appendChild(add);
}

function switchTo(id) {
  if (id === actSID) return;
  if (!getSheetById(id)) return;
  actSID = id;
  saveActive();
  selR = 0; selC = 0;
  buildGrid();
  renderTabs();
  renderGrid();
  renderSelection();
  $("grid-wrap").focus();
}

function addSheet() {
  var n = nextSheetNumber();
  var sh = {
    id: uid(),
    name: null,
    bi: { en: "Sheet" + n, el: "\u03A6\u03CD\u03BB\u03BB\u03BF" + n },
    rows: ROWS, cols: COLS,
    pos: maxPos() + 1,
    mtime: now() /* user-created: mtime > 0 → participates in LWW merge */
  };
  state.sheets.push(sh);
  markDirty();
  queueSave();
  switchTo(sh.id);
}

function tryDeleteSheet(id) {
  if (state.sheets.length <= 1) { toast(t("sheet.last")); return; }

  if (armX.id !== id) {
    /* first tap: arm */
    if (armX.timer) clearTimeout(armX.timer);
    armX.id = id;
    armX.timer = setTimeout(function () {
      armX.id = null; renderTabs();
    }, 3000);
    toast(t("tab.confirm"));
    renderTabs();
    return;
  }
  /* second tap: delete for real */
  if (armX.timer) { clearTimeout(armX.timer); armX = { id: null, timer: null }; }

  deleteSheet(id);
}

function deleteSheet(id) {
  var i, k;

  /* 1. Remove from sheets array */
  for (i = 0; i < state.sheets.length; i++) {
    if (state.sheets[i] && state.sheets[i].id === id) {
      state.sheets.splice(i, 1); break;
    }
  }

  /* 2. CASCADE: sheet tombstone + purge all its cells */
  var ts = now();
  state.deleted[id] = ts;
  var pref = id + "|";
  for (k in state.cells)
    if (k.indexOf(pref) === 0) delete state.cells[k];
  /* per-cell tombstones not needed — sheet tombstone cascades (R17),
     but keep any existing cell tombstones (already in deleted). */

  /* 3. If the deleted sheet was active, fall back to first */
  if (actSID === id) {
    actSID = state.sheets[0] ? state.sheets[0].id : SID;
    selR = 0; selC = 0;
    buildGrid();
  }

  markDirty();
  queueSave();
  renderTabs();
  renderGrid();
  renderSelection();
  $("grid-wrap").focus();
}

function beginRename(tabEl, labelEl, sh) {
  var inp = document.createElement("input");
  inp.type = "text";
  inp.value = displayName(sh);
  inp.maxLength = 24;
  inp.autocomplete = "off";
  inp.spellcheck = false;
  labelEl.style.display = "none";
  tabEl.insertBefore(inp, labelEl);
  inp.focus();
  inp.setSelectionRange(inp.value.length, inp.value.length);

  var done = false;
  function finish(commit) {
    if (done) return;
    done = true;
    if (commit) {
      var nv = inp.value.replace(/^\s+|\s+$/g, "");
      if (nv && nv !== displayName(sh)) {
        sh.name = nv;      /* hand rename kills bi permanently (R15) */
        sh.bi = undefined;
        sh.mtime = now();
        markDirty();
        queueSave();
      }
    }
    tabEl.removeChild(inp);
    labelEl.style.display = "";
    renderTabs();
    renderGrid(); /* cross-sheet refs by old name → #REF! */
    $("grid-wrap").focus();
  }

  inp.addEventListener("keydown", function (e) {
    if (e.key === "Enter") { e.preventDefault(); finish(true); }
    else if (e.key === "Escape") { e.preventDefault(); finish(false); }
    e.stopPropagation();
  });
  inp.addEventListener("blur", function () { finish(true); });
  inp.addEventListener("click", function (e) { e.stopPropagation(); });
}

/* ===== SECTION 3f: CSV IMPORT / EXPORT ===== */

function csvEscape(v) {
  v = String(v);
  if (/[",;\n]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
  return v;
}

function csvExport() {
  var sheet = getActiveSheet();
  if (!sheet) return;

  /* find bounding box of actual data */
  var maxR = -1, maxC = -1, pref = actSID + "|", k;
  for (k in state.cells) {
    if (k.indexOf(pref) !== 0) continue;
    var p = k.split("|");
    var r = parseInt(p[1], 10), c = parseInt(p[2], 10);
    if (r > maxR) maxR = r;
    if (c > maxC) maxC = c;
  }
  if (maxR < 0) { toast(t("err.corrupt")); return; }

  var lines = [], r, c, row;
  for (r = 0; r <= maxR; r++) {
    row = [];
    for (c = 0; c <= maxC; c++) {
      var cel = state.cells[cellKey(actSID, r, c)];
      row.push(cel ? cel.v : "");
    }
    lines.push(row.join(","));
  }
  var csv = lines.join("\r\n");

  var safeName = displayName(sheet).replace(/[^\w\- ]+/g, "_") || "sheet";
  var blob = new Blob(["\uFEFF" + csv], { type: "text/csv;charset=utf-8" });
  var a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = safeName + ".csv";
  document.body.appendChild(a);
  a.click();
  setTimeout(function () {
    URL.revokeObjectURL(a.href);
    document.body.removeChild(a);
  }, 500);
  toast(t("csv.exported"));
}

/* RFC4180-ish state machine parser */
function csvParse(text) {
  var rows = [], row = [], field = "", inQ = false, i, ch;
  for (i = 0; i < text.length; i++) {
    ch = text.charAt(i);
    if (inQ) {
      if (ch === '"') {
        if (text.charAt(i + 1) === '"') { field += '"'; i++; }
        else inQ = false;
      } else field += ch;
    } else {
      if (ch === '"') { inQ = true; }
      else if (ch === ",") { row.push(field); field = ""; }
      else if (ch === "\n") { row.push(field); field = ""; rows.push(row); row = []; }
      else if (ch === "\r") { /* skip — handle \r\n */ }
      else field += ch;
    }
  }
  if (field !== "" || row.length) { row.push(field); rows.push(row); }
  return rows;
}

function csvImport(file) {
  var reader = new FileReader();
  reader.onload = function () {
    try {
      var rows = csvParse(String(reader.result));
      if (!rows.length) { toast(t("err.corrupt")); return; }

      var n = nextSheetNumber();
      var sh = {
        id: uid(),
        name: null,
        bi: { en: "Sheet" + n, el: "\u03A6\u03CD\u03BB\u03BB\u03BF" + n },
        rows: Math.max(ROWS, rows.length + 5),
        cols: Math.max(COLS,
          (function () {
            var mc = 0, i;
            for (i = 0; i < rows.length; i++)
              if (rows[i].length > mc) mc = rows[i].length;
            return mc;
          })()),
        pos: maxPos() + 1,
        mtime: now()
      };
      state.sheets.push(sh);

      var r, c, ts = now();
      for (r = 0; r < rows.length; r++)
        for (c = 0; c < rows[r].length; c++)
          if (rows[r][c] !== "")
            state.cells[cellKey(sh.id, r, c)] = { v: rows[r][c], mtime: ts };

      markDirty();
      queueSave();
      switchTo(sh.id);
      toast(t("csv.imported"));
    } catch (e) {
      console.error("[SS] CSV import failed:", e);
      toast(t("err.corrupt"));
    }
  };
  reader.readAsText(file, "utf-8");
}

/* ===== SECTION 4: SYNC SLICE + PALETTE + NOTIFICATIONS ===== */

var syncApi = null;

var __ss = { _suppress: false,
  dirty: function () {
    if (this._suppress) return;
    var api = (window.parent && window.parent.orosSync) || window.orosSync;
    if (api && typeof api.markDirty === "function") api.markDirty();
  } };

syncApi = __ss;

function sliceGet() { return JSON.parse(JSON.stringify(state)); }

function sliceSet(data, info) {
  __ss._suppress = true;
  try {
    var merged = normalizeState(mergeState(sliceGet(), data));
    if (merged) state = merged;
  } catch (e) { /* defensive: keep local state on malformed payload */ }
  __ss._suppress = false;

  /* Active-sheet hygiene: if it vanished or is tombstoned, fall back */
  var act = getSheetById(actSID);
  if (!act || state.deleted[actSID]) {
    actSID = state.sheets[0] ? state.sheets[0].id : SID;
    selR = 0; selC = 0;
    buildGrid();
  }

  saveNow();
  if (editing) cancelEdit();
  renderTabs();
  renderGrid();
  renderSelection();
}

function mergeFn(local, remote) {
  return mergeState(local, remote);
}

function registerSync() {
  var api = (window.parent && window.parent.orosSync) || window.orosSync;
  if (!api || typeof api.registerSlice !== "function") return;
  api.registerSlice("spreadsheet", sliceGet, sliceSet,
    STORAGE_KEY, mergeFn);
}

/* IFRAME PALETTE CONTRACT (G3) */
var PAL_VARS = ["--bg","--bg-desktop","--bar-bg","--text","--text-dim",
  "--accent","--accent-hover","--accent-soft","--panel-bg","--border",
  "--shadow","--danger","--ok","--warn","--font-stack","--mono"];

function inheritPalette() {
  var pd = null;
  try { pd = window.parent && window.parent.document; } catch (e) {}
  if (!pd || !pd.documentElement) return;
  var de = document.documentElement;   /* element — for attributes */
  var rs = de.style;                     /* CSSStyleDeclaration — for props */
  try {
    de.setAttribute("data-theme", pd.documentElement.getAttribute("data-theme") || "");
    de.setAttribute("data-skin", pd.documentElement.getAttribute("data-skin") || "");
  } catch (e) { /* standalone run — attribute inheritance skipped */ }
  for (var i = 0; i < PAL_VARS.length; i++) {
    var v = pd.documentElement.style.getPropertyValue(PAL_VARS[i]);
    if (v) rs.setProperty(PAL_VARS[i], v);
  }
}

function watchPalette() {
  var pd = null;
  try { pd = window.parent && window.parent.document; } catch (e) {}
  if (!pd || !pd.documentElement || typeof MutationObserver === "undefined")
    return;
  new MutationObserver(function () { inheritPalette(); })
    .observe(pd.documentElement, { attributes: true,
      attributeFilter: ["data-skin", "data-theme"] });
}

function notifyTransient(text) {
  var nm = null;
  try { nm = (window.parent && window.parent.orosNotifs) || window.orosNotifs; }
  catch (e) {}
  if (nm && typeof nm.transient === "function") {
    try { nm.transient({ ns: "spreadsheet", title: text }); return; }
    catch (e) {}
  }
  toast(text);
}

function toast(text) {
  var el = document.getElementById("ss-toast");
  if (!el) {
    el = document.createElement("div");
    el.id = "ss-toast";
    el.className = "ss-toast";
    document.body.appendChild(el);
  }
  el.textContent = text;
  el.classList.add("on");
  clearTimeout(toast._t);
  toast._t = setTimeout(function () { el.classList.remove("on"); }, 5000);
}

/* ===== SECTION 5: WIRING + BOOT ===== */

function applyI18n() {
  document.title = t("title") + " — orOS";
  var el = $("doc-title");
  if (el) el.textContent = t("title");
  document.documentElement.lang = LANG;
}

function wire() {
  var wrap = $("grid-wrap");

  $("grid").addEventListener("click", function (e) {
    var td = e.target;
    while (td && td.tagName !== "TD") td = td.parentElement;
    if (!td || td.tagName !== "TD") return;
    var r = td.parentElement.rowIndex - 1;
    var c = td.cellIndex - 1;
    if (r < 0 || c < 0) return;
    if (editing) commitEdit(0, 0);
    if (selR === r && selC === c && !editing) beginEdit();
    else { selR = r; selC = c; renderSelection(); }
  });

  $("grid").addEventListener("dblclick", function (e) {
    var td = e.target;
    while (td && td.tagName !== "TD") td = td.parentElement;
    if (!td) return;
    if (!editing) beginEdit();
  });

  wrap.addEventListener("keydown", function (e) {
    if (editing) return;
    var k = e.key;
    if (k === "ArrowUp") { e.preventDefault(); navigate(-1, 0); }
    else if (k === "ArrowDown") { e.preventDefault(); navigate(1, 0); }
    else if (k === "ArrowLeft") { e.preventDefault(); navigate(0, -1); }
    else if (k === "ArrowRight") { e.preventDefault(); navigate(0, 1); }
    else if (k === "Enter" || k === "F2") { e.preventDefault(); beginEdit(); }
    else if (k === "Tab") { e.preventDefault(); navigate(0, 1); }
    else if (k === "Delete" || k === "Backspace") {
      e.preventDefault(); clearSelected();
    }
    else if (k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault(); beginEdit(k);
    }
  });

  var fx = $("fx-input");
  fx.addEventListener("focus", function () { fxFocused = true; });
  fx.addEventListener("blur", function () {
    fxFocused = false;
    renderSelection();
  });
  fx.addEventListener("keydown", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      var val = fx.value.replace(/^\s+|\s+$/g, "");
      if (val === "") { if (getCell(selR, selC)) deleteCell(selR, selC); }
      else setCell(selR, selC, val);
      queueSave(); refreshCell(selR, selC);
      navigate(1, 0);
      fx.blur();
    } else if (e.key === "Escape") {
      e.preventDefault();
      var cel = getCell(selR, selC);
      fx.value = cel ? cel.v : "";
      fx.blur();
    }
    e.stopPropagation();
  });

  /* CSV buttons */
  var bi = $("btn-csv-imp"), be = $("btn-csv-exp");
  if (be) be.addEventListener("click", csvExport);
  if (bi) bi.addEventListener("click", function () {
    var inp = document.createElement("input");
    inp.type = "file";
    inp.accept = ".csv,text/csv";
    inp.addEventListener("change", function () {
      if (inp.files && inp.files[0]) csvImport(inp.files[0]);
    });
    inp.click();
  });

  window.addEventListener("beforeunload", function () {
    if (editing) commitEdit(0, 0);
    saveNow();
  });
}

/* SHELL SHORTCUT FORWARDING (Contract Β — capture phase) */
document.addEventListener("keydown", function (e) {
  if (!(e.ctrlKey || e.metaKey) || !e.altKey || !e.shiftKey) return;
  var p = window.parent;
  if (!(p && p.orosShortcuts &&
      typeof p.orosShortcuts.handle === "function")) return;
  if (p.orosShortcuts.handle(e)) e.stopPropagation();
}, true);

function boot() {
  console.log("[SS] boot start, LANG =", LANG);
  loadState();
  console.log("[SS] loadState ok, sheets:",
    state.sheets.length, "| cells:", Object.keys(state.cells).length);
  applyI18n();
  wire();
  registerSync();
  inheritPalette();
  watchPalette();
  buildGrid();
  renderTabs();
  selR = 0; selC = 0;
  renderGrid();
  renderSelection();
  console.log("[SS] boot COMPLETE [Wave 2]");
  $("grid-wrap").focus();
}

boot();

})();