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
	const SCHEMA_VERSION = 4;

	/**
	 * Roles a stored transcript row can carry.
	 *
	 * @var string[]
	 */
	const TRANSCRIPT_ROLES = array( 'visitor', 'agent' );

	/**
	 * Hard cap on a stored message, in characters. The proxy already caps an
	 * inbound message at 4000; this bounds an unexpectedly large agent reply so
	 * one response cannot bloat the table.
	 *
	 * @var int
	 */
	const TRANSCRIPT_MAX_CHARS = 20000;

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
	 * Fully-qualified transcript table name.
	 *
	 * Kept separate from the usage table on purpose: usage rows are metadata and
	 * carry no PII, so they can be retained indefinitely, while transcript rows
	 * hold visitor-authored content and are subject to a retention window. One
	 * table can be purged without touching the other's history.
	 *
	 * @return string
	 */
	public function transcripts_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'npa_transcripts';
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
			page_path varchar(190) NOT NULL DEFAULT '',
			page_title varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY agent_id (agent_id),
			KEY page_path (page_path)
		) {$collate};";

		dbDelta( $sql );

		$transcripts = $this->transcripts_table_name();

		$transcripts_sql = "CREATE TABLE {$transcripts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			conversation_id varchar(64) NOT NULL DEFAULT '',
			agent_id varchar(64) NOT NULL DEFAULT '',
			role varchar(10) NOT NULL DEFAULT '',
			content longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY conversation_id (conversation_id)
		) {$collate};";

		dbDelta( $transcripts_sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Drop the table and forget the schema version (used by uninstall).
	 *
	 * @return void
	 */
	public function drop() {
		global $wpdb;
		$table       = $this->table_name();
		$transcripts = $this->transcripts_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$transcripts}" );
		delete_option( self::SCHEMA_OPTION );
	}

	// Transcripts (opt-in, retention-bound, PII-bearing).

	/**
	 * Store one message of a conversation.
	 *
	 * Callers must gate on the store_transcripts setting; this method does not
	 * check it, so tests can exercise storage directly. Content is trimmed to
	 * TRANSCRIPT_MAX_CHARS and stripped of tags — a transcript is a record of
	 * what was said, never markup to be replayed into a page.
	 *
	 * @param array $data conversation_id, agent_id, role, content.
	 * @return int|false Inserted row id, or false on failure/empty content.
	 */
	public function record_transcript( array $data ) {
		global $wpdb;

		$role = isset( $data['role'] ) ? (string) $data['role'] : '';
		if ( ! in_array( $role, self::TRANSCRIPT_ROLES, true ) ) {
			return false;
		}

		$content = isset( $data['content'] ) ? (string) $data['content'] : '';
		$content = trim( wp_strip_all_tags( $content ) );
		if ( '' === $content ) {
			return false;
		}
		if ( function_exists( 'mb_substr' ) ) {
			$content = mb_substr( $content, 0, self::TRANSCRIPT_MAX_CHARS );
		} else {
			$content = substr( $content, 0, self::TRANSCRIPT_MAX_CHARS );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$this->transcripts_table_name(),
			array(
				'created_at'      => current_time( 'mysql' ),
				'conversation_id' => isset( $data['conversation_id'] ) ? substr( (string) $data['conversation_id'], 0, 64 ) : '',
				'agent_id'        => isset( $data['agent_id'] ) ? substr( (string) $data['agent_id'], 0, 64 ) : '',
				'role'            => $role,
				'content'         => $content,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return ( false === $ok ) ? false : (int) $wpdb->insert_id;
	}

	/**
	 * The most recent stored messages (newest first).
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array>
	 */
	public function recent_transcripts( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		$table = $this->transcripts_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Volume and age of the stored transcripts — what the status panel needs to
	 * show that a retention obligation is being met.
	 *
	 * @return array { count:int, conversations:int, oldest:string }
	 */
	public function transcript_stats() {
		global $wpdb;
		$table = $this->transcripts_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS c, COUNT(DISTINCT conversation_id) AS k, MIN(created_at) AS oldest FROM {$table}",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'count'         => isset( $row['c'] ) ? (int) $row['c'] : 0,
			'conversations' => isset( $row['k'] ) ? (int) $row['k'] : 0,
			'oldest'        => isset( $row['oldest'] ) ? (string) $row['oldest'] : '',
		);
	}

	/**
	 * Build the WHERE clause shared by the conversation list and its count.
	 *
	 * @param array $args { agent:string, search:string }.
	 * @return array { sql:string, params:array }
	 */
	private function transcript_where( array $args ) {
		global $wpdb;

		$where  = array();
		$params = array();

		if ( ! empty( $args['agent'] ) ) {
			$where[]  = 'agent_id = %s';
			$params[] = (string) $args['agent'];
		}

		if ( ! empty( $args['search'] ) ) {
			/*
			 * Match the conversation, not the message: someone searching for
			 * "refund" wants the exchange that mentions it, including the turns
			 * either side. The inner select finds the conversations containing a
			 * hit; the outer query returns them whole.
			 */
			$table    = $this->transcripts_table_name();
			$where[]  = "conversation_id IN ( SELECT conversation_id FROM {$table} WHERE content LIKE %s )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		return array(
			'sql'    => $where ? ( ' WHERE ' . implode( ' AND ', $where ) ) : '',
			'params' => $params,
		);
	}

	/**
	 * A page of conversations, most recent activity first.
	 *
	 * Grouped rather than listed message by message, because the unit a reader
	 * cares about is the exchange. The preview is the visitor's opening line,
	 * which is what makes a row recognisable in a list.
	 *
	 * @param array $args { agent:string, search:string, per_page:int, page:int }.
	 * @return array<int,array>
	 */
	public function conversations( array $args = array() ) {
		global $wpdb;

		$table    = $this->transcripts_table_name();
		$per_page = max( 1, min( 200, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$offset   = ( $page - 1 ) * $per_page;

		$where = $this->transcript_where( $args );

		$sql = "SELECT conversation_id, MAX( agent_id ) AS agent_id, MIN( created_at ) AS started,
				MAX( created_at ) AS ended, COUNT(*) AS turns
			FROM {$table}{$where['sql']}
			GROUP BY conversation_id
			ORDER BY ended DESC
			LIMIT %d OFFSET %d";

		$params = array_merge( $where['params'], array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		if ( empty( $rows ) ) {
			return array();
		}

		// One query for every preview on this page, rather than one per row.
		$ids          = wp_list_pluck( $rows, 'conversation_id' );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$previews = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.conversation_id, t.content
				FROM {$table} t
				INNER JOIN (
					SELECT conversation_id, MIN( id ) AS first_id
					FROM {$table}
					WHERE role = 'visitor' AND conversation_id IN ( {$placeholders} )
					GROUP BY conversation_id
				) f ON f.first_id = t.id",
				$ids
			),
			ARRAY_A
		);

		$by_id = array();
		foreach ( (array) $previews as $preview ) {
			$by_id[ $preview['conversation_id'] ] = (string) $preview['content'];
		}

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['preview'] = isset( $by_id[ $row['conversation_id'] ] ) ? $by_id[ $row['conversation_id'] ] : '';
		}

		return $rows;
	}

	/**
	 * How many conversations match, for paging.
	 *
	 * @param array $args Same shape as conversations().
	 * @return int
	 */
	public function count_conversations( array $args = array() ) {
		global $wpdb;

		$table = $this->transcripts_table_name();
		$where = $this->transcript_where( $args );

		$sql = "SELECT COUNT( DISTINCT conversation_id ) FROM {$table}{$where['sql']}";

		if ( empty( $where['params'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where['params'] ) );
	}

	/**
	 * The agent ids that actually appear in stored conversations.
	 *
	 * Used to offer a filter that only lists agents there is something to see
	 * for, rather than every agent ever configured.
	 *
	 * @return string[]
	 */
	public function transcript_agent_ids() {
		global $wpdb;

		$table = $this->transcripts_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT DISTINCT agent_id FROM {$table} WHERE agent_id <> '' ORDER BY agent_id ASC" );

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Every turn of one conversation, oldest first.
	 *
	 * @param string $conversation_id Conversation id.
	 * @return array<int,array>
	 */
	public function conversation( $conversation_id ) {
		global $wpdb;

		$table = $this->transcripts_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE conversation_id = %s ORDER BY id ASC", (string) $conversation_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every turn of several conversations at once, grouped by conversation.
	 *
	 * The list renders each row's transcript inline, so the alternative is one
	 * query per row. Twenty rows is twenty round trips for a screen that is
	 * mostly collapsed.
	 *
	 * @param string[] $conversation_ids Conversation ids.
	 * @return array<string,array<int,array>> Keyed by conversation id, oldest turn first.
	 */
	public function turns_for( array $conversation_ids ) {
		global $wpdb;

		/*
		 * Empty ids are kept. Messages recorded before 0.12.1 were filed under
		 * the empty string, and dropping them here is what made those rows
		 * expand to nothing at all.
		 */
		$ids = array_values( array_unique( array_map( 'strval', $conversation_ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $this->transcripts_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE conversation_id IN ( {$placeholders} ) ORDER BY id ASC", $ids ),
			ARRAY_A
		);

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$grouped[ $row['conversation_id'] ][] = $row;
		}

		return $grouped;
	}

	/**
	 * Delete one conversation outright.
	 *
	 * @param string $conversation_id Conversation id.
	 * @return int Rows deleted.
	 */
	public function delete_conversation( $conversation_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->delete( $this->transcripts_table_name(), array( 'conversation_id' => (string) $conversation_id ), array( '%s' ) );
	}

	/**
	 * Which conversations fall outside a ceiling of $max, least recent first.
	 *
	 * Split out from the deletion so the selection can be tested without
	 * deleting anything. The battery runs on live sites, where a test that
	 * trims the real table to prove trimming works would destroy the very
	 * records it is meant to be protecting.
	 *
	 * Whole conversations, never loose messages: a ceiling applied to rows
	 * would leave an exchange with its question deleted and the answer still
	 * present, which reads as the agent volunteering something nobody asked.
	 *
	 * @param int $max Maximum conversations to keep; 0 means no limit.
	 * @return string[] Conversation ids to remove.
	 */
	public function conversations_over_limit( $max ) {
		global $wpdb;

		$max = (int) $max;

		if ( $max < 1 ) {
			return array();
		}

		$table = $this->transcripts_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$doomed = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT conversation_id FROM (
					SELECT conversation_id, MAX( created_at ) AS ended
					FROM {$table}
					GROUP BY conversation_id
					ORDER BY ended DESC
					LIMIT %d, 18446744073709551615
				) AS keepers",
				$max
			)
		);

		return is_array( $doomed ) ? $doomed : array();
	}

	/**
	 * Keep at most $max conversations, dropping the least recently active.
	 *
	 * @param int $max Maximum conversations to keep; 0 means no limit.
	 * @return int Rows deleted.
	 */
	public function trim_conversations( $max ) {
		global $wpdb;

		$table  = $this->transcripts_table_name();
		$doomed = $this->conversations_over_limit( $max );

		if ( empty( $doomed ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $doomed ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE conversation_id IN ( {$placeholders} )", $doomed )
		);
	}

	/**
	 * Delete transcript rows older than the retention window.
	 *
	 * @param int $days Retention window in days; must be >= 1.
	 * @return int Rows deleted.
	 */
	public function purge_transcripts( $days ) {
		global $wpdb;

		$days = max( 1, (int) $days );
		// Cut-off in site-local time, matching how created_at is written.
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -' . $days . ' days' ) );
		$table  = $this->transcripts_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Delete every stored transcript. Used by the admin "delete all" control.
	 *
	 * @return int Rows deleted.
	 */
	public function purge_all_transcripts() {
		global $wpdb;
		$table = $this->transcripts_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( "DELETE FROM {$table}" );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Dual-write a usage record: a durable row plus a fast transient.
	 *
	 * @param array $data Metadata only: agent_id, conversation_id, status,
	 *                    finish_reason, latency_ms, input_tokens, output_tokens,
	 *                    error_code.
	 * @return int|false Inserted row id, or false on failure.
	 */
	/**
	 * Reduce a page URL to something worth keeping.
	 *
	 * Path only: the query string is dropped rather than truncated, because a
	 * URL a visitor arrived on can carry a reset token, an email address or a
	 * session id, and this table is the one place in the plugin that promises
	 * to hold no personal data. The path is site content, not a person.
	 *
	 * @param string $url A page URL.
	 * @return string Path, or '' when there is nothing usable.
	 */
	public static function page_path( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		if ( '' === $path ) {
			return '';
		}

		return substr( $path, 0, 190 );
	}

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
			'page_path'       => isset( $data['page_path'] ) ? self::page_path( $data['page_path'] ) : '',
			'page_title'      => isset( $data['page_title'] ) ? substr( sanitize_text_field( (string) $data['page_title'] ), 0, 190 ) : '',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$this->table_name(),
			$row,
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
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
	 * Where conversations start: the page each one's first message came from.
	 *
	 * The first row of a conversation, not every row — a visitor who opens the
	 * chat on the pricing page and keeps talking while they browse started on
	 * pricing, and counting every message would make a long conversation look
	 * like popularity for whatever page they drifted to.
	 *
	 * @param int $days  Window in days.
	 * @param int $limit Rows to return.
	 * @return array<int,array{page_path:string,page_title:string,starts:int}>
	 */
	public function conversation_start_pages( $days = 30, $limit = 10 ) {
		global $wpdb;

		$table = $this->table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		$limit = max( 1, min( 50, (int) $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.page_path, MAX( u.page_title ) AS page_title, COUNT(*) AS starts
				FROM {$table} u
				INNER JOIN (
					SELECT conversation_id, MIN( id ) AS first_id
					FROM {$table}
					WHERE conversation_id <> '' AND created_at >= %s
					GROUP BY conversation_id
				) f ON f.first_id = u.id
				WHERE u.page_path <> ''
				GROUP BY u.page_path
				ORDER BY starts DESC
				LIMIT %d",
				$since,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Conversations and how long they run.
	 *
	 * Messages alone cannot tell a hundred people asking one question from one
	 * person asking a hundred, and those want very different responses from a
	 * site owner.
	 *
	 * @param int $days Window in days.
	 * @return array{conversations:int,messages:int,messages_per:float}
	 */
	public function conversation_stats( $days = 30 ) {
		global $wpdb;

		$table = $this->table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT conversation_id ) AS conversations, COUNT(*) AS messages
				FROM {$table}
				WHERE created_at >= %s AND conversation_id <> ''",
				$since
			),
			ARRAY_A
		);

		$conversations = isset( $row['conversations'] ) ? (int) $row['conversations'] : 0;
		$messages      = isset( $row['messages'] ) ? (int) $row['messages'] : 0;

		return array(
			'conversations' => $conversations,
			'messages'      => $messages,
			'messages_per'  => $conversations > 0 ? round( $messages / $conversations, 1 ) : 0.0,
		);
	}

	/**
	 * What has been failing, most common first.
	 *
	 * @param int $days Window in days.
	 * @return array<int,array{error_code:string,hits:int,last_seen:string}>
	 */
	public function error_breakdown( $days = 30 ) {
		global $wpdb;

		$table = $this->table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT error_code, COUNT(*) AS hits, MAX( created_at ) AS last_seen
				FROM {$table}
				WHERE created_at >= %s AND error_code <> ''
				GROUP BY error_code
				ORDER BY hits DESC
				LIMIT 10",
				$since
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Tokens consumed in a window, for the transports that report them.
	 *
	 * @param int $days Window in days.
	 * @return array{input:int,output:int,messages:int}
	 */
	public function token_totals( $days = 30 ) {
		global $wpdb;

		$table = $this->table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM( input_tokens ) AS input, SUM( output_tokens ) AS output,
					SUM( CASE WHEN input_tokens > 0 OR output_tokens > 0 THEN 1 ELSE 0 END ) AS messages
				FROM {$table}
				WHERE created_at >= %s AND is_mock = 0",
				$since
			),
			ARRAY_A
		);

		return array(
			'input'    => isset( $row['input'] ) ? (int) $row['input'] : 0,
			'output'   => isset( $row['output'] ) ? (int) $row['output'] : 0,
			'messages' => isset( $row['messages'] ) ? (int) $row['messages'] : 0,
		);
	}

	/**
	 * The hour of day traffic arrives in, in the site's timezone.
	 *
	 * @param int $days Window in days.
	 * @return array<int,int> 24 counts, index 0 = midnight.
	 */
	public function busiest_hours( $days = 30 ) {
		global $wpdb;

		$table = $this->table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		// created_at is written with current_time( 'mysql' ), so it is already
		// the site's local time and needs no conversion here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT HOUR( created_at ) AS h, COUNT(*) AS hits
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY HOUR( created_at )",
				$since
			),
			ARRAY_A
		);

		$out = array_fill( 0, 24, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['h'] ] = (int) $row['hits'];
		}

		return $out;
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
