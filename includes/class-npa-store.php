<?php
/**
 * Durable usage persistence (ADR-007 dual-write).
 *
 * Every gateway call writes BOTH a transient (fast, for the status panel) AND a
 * row in a custom table (durable history for Service Status, the Tests tab, and
 * budget metering). Rows are METADATA ONLY — no message content, zero PII.
 * Transcript storage is a separate, opt-in, retention-bound concern added later.
 *
 * Schema is version-gated and applied on a normal hook (not just activation),
 * because git-as-deploy updates (ADR-001) never fire the activation hook.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Store
 */
class NPA_Store {

	/**
	 * Bumped whenever the schema changes; triggers a dbDelta on the next load.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 2;

	/**
	 * Option storing the installed schema version.
	 *
	 * @var string
	 */
	const SCHEMA_OPTION = 'npa_schema_version';

	/**
	 * Transient holding the most recent usage row (fast-path render).
	 *
	 * @var string
	 */
	const LAST_TRANSIENT = 'npa_last_usage';

	/**
	 * Fully-qualified usage table name.
	 *
	 * @return string
	 */
	public function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'npa_usage';
	}

	/**
	 * Create or upgrade the schema when the installed version is behind.
	 * Safe to call on every request — dbDelta only acts on differences.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return;
		}
		$this->install();
	}

	/**
	 * Create the usage table (idempotent via dbDelta) and record the version.
	 *
	 * @return void
	 */
	public function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $this->table_name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			agent_id varchar(64) NOT NULL DEFAULT '',
			conversation_id varchar(64) NOT NULL DEFAULT '',
			status smallint(5) unsigned NOT NULL DEFAULT 0,
			finish_reason varchar(20) NOT NULL DEFAULT '',
			latency_ms int(10) unsigned NOT NULL DEFAULT 0,
			input_tokens int(10) unsigned NOT NULL DEFAULT 0,
			output_tokens int(10) unsigned NOT NULL DEFAULT 0,
			error_code varchar(40) NOT NULL DEFAULT '',
			is_mock tinyint(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY agent_id (agent_id)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Drop the table and forget the schema version (used by uninstall).
	 *
	 * @return void
	 */
	public function drop() {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::SCHEMA_OPTION );
	}

	/**
	 * Dual-write a usage record: a durable row plus a fast transient.
	 *
	 * @param array $data Metadata only: agent_id, conversation_id, status,
	 *                    finish_reason, latency_ms, input_tokens, output_tokens,
	 *                    error_code.
	 * @return int|false Inserted row id, or false on failure.
	 */
	public function record( array $data ) {
		global $wpdb;

		$row = array(
			'created_at'      => current_time( 'mysql' ),
			'agent_id'        => isset( $data['agent_id'] ) ? substr( (string) $data['agent_id'], 0, 64 ) : '',
			'conversation_id' => isset( $data['conversation_id'] ) ? substr( (string) $data['conversation_id'], 0, 64 ) : '',
			'status'          => isset( $data['status'] ) ? (int) $data['status'] : 0,
			'finish_reason'   => isset( $data['finish_reason'] ) ? substr( (string) $data['finish_reason'], 0, 20 ) : '',
			'latency_ms'      => isset( $data['latency_ms'] ) ? max( 0, (int) $data['latency_ms'] ) : 0,
			'input_tokens'    => isset( $data['input_tokens'] ) ? max( 0, (int) $data['input_tokens'] ) : 0,
			'output_tokens'   => isset( $data['output_tokens'] ) ? max( 0, (int) $data['output_tokens'] ) : 0,
			'error_code'      => isset( $data['error_code'] ) ? substr( (string) $data['error_code'], 0, 40 ) : '',
			'is_mock'         => ! empty( $data['is_mock'] ) ? 1 : 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$this->table_name(),
			$row,
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%d' )
		);

		if ( false === $ok ) {
			return false;
		}

		set_transient( self::LAST_TRANSIENT, $row, 5 * MINUTE_IN_SECONDS );

		return (int) $wpdb->insert_id;
	}

	/**
	 * The most recent N usage rows (newest first).
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array>
	 */
	public function recent( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, (int) $limit );
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of calls recorded since local midnight today.
	 *
	 * @return int
	 */
	public function count_today() {
		global $wpdb;
		$table = $this->table_name();
		$start = current_time( 'Y-m-d' ) . ' 00:00:00';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $start ) );
	}

	/**
	 * Simple aggregates over the most recent N rows for the status panel.
	 *
	 * @param int $window Number of recent rows to summarise.
	 * @return array { count, error_rate, avg_latency_ms }
	 */
	public function aggregates( $window = 50 ) {
		$rows  = $this->recent( $window );
		$count = count( $rows );

		if ( 0 === $count ) {
			return array(
				'count'          => 0,
				'error_rate'     => 0.0,
				'avg_latency_ms' => 0,
				'live_count'     => 0,
				'mock_count'     => 0,
			);
		}

		/*
		 * Latency averages LIVE calls only. The in-process mock answers in about
		 * a millisecond, so folding it in drags the average toward zero and the
		 * panel reports a number that describes nothing — the "0 ms avg" seen on
		 * a mock-only site. Errors and volume still count every call.
		 */
		$errors     = 0;
		$latency    = 0;
		$live_count = 0;
		$mock_count = 0;

		foreach ( $rows as $row ) {
			$status = (int) $row['status'];
			if ( '' !== (string) $row['error_code'] || $status >= 400 ) {
				++$errors;
			}

			if ( ! empty( $row['is_mock'] ) ) {
				++$mock_count;
				continue;
			}

			++$live_count;
			$latency += (int) $row['latency_ms'];
		}

		return array(
			'count'          => $count,
			'error_rate'     => round( $errors / $count, 4 ),
			'avg_latency_ms' => $live_count > 0 ? (int) round( $latency / $live_count ) : 0,
			'live_count'     => $live_count,
			'mock_count'     => $mock_count,
		);
	}

	/**
	 * Per-day usage for the last N days (oldest first), gaps filled with zeros —
	 * a ready-to-plot series for the analytics dashboard.
	 *
	 * @param int $days Number of days back, including today.
	 * @return array<int,array{date:string,count:int,errors:int,avg_latency_ms:int}>
	 */
	public function daily_series( $days = 14 ) {
		global $wpdb;

		$days  = max( 1, min( 90, (int) $days ) );
		$table = $this->table_name();
		$start = current_time( 'Y-m-d', false );
		$from  = gmdate( 'Y-m-d', strtotime( $start . ' -' . ( $days - 1 ) . ' days' ) ) . ' 00:00:00';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d,
					COUNT(*) AS c,
					SUM( CASE WHEN status >= 400 OR error_code <> '' THEN 1 ELSE 0 END ) AS e,
					AVG(latency_ms) AS l
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY DATE(created_at)",
				$from
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_date = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$by_date[ (string) $r['d'] ] = array(
					'count'          => (int) $r['c'],
					'errors'         => (int) $r['e'],
					'avg_latency_ms' => (int) round( (float) $r['l'] ),
				);
			}
		}

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date     = gmdate( 'Y-m-d', strtotime( $start . ' -' . $i . ' days' ) );
			$found    = isset( $by_date[ $date ] ) ? $by_date[ $date ] : array(
				'count'          => 0,
				'errors'         => 0,
				'avg_latency_ms' => 0,
			);
			$series[] = array(
				'date'           => $date,
				'count'          => $found['count'],
				'errors'         => $found['errors'],
				'avg_latency_ms' => $found['avg_latency_ms'],
			);
		}

		return $series;
	}
}
