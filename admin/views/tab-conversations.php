<?php
/**
 * Conversations tab — read what visitors actually asked.
 *
 * Storage has existed since 0.5.0 and there has never been anywhere to read it,
 * which made the setting close to pointless: a site could accumulate a month of
 * transcripts and see only a row count on Service Status.
 *
 * Two things this view is careful about. It shows what real people typed, so it
 * says so plainly rather than presenting the data as neutral analytics. And it
 * never renders a stored message as markup — a transcript is visitor-supplied
 * text being shown to an administrator, which is the classic stored-XSS shape.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/** @var NPA_Admin $npa_admin */
/** @var NPA_Settings $settings */

$npa_store     = NPA_Plugin::instance()->store;
$npa_storing   = (bool) $settings->get( 'store_transcripts' );
$npa_retention = (int) $settings->get( 'transcript_retention_days', 30 );
$npa_max       = (int) $settings->get( 'transcript_max_conversations', 0 );

// Read-only listing state. Nonce-free by design: nothing here changes anything,
// and a filter is not a privileged action. Every mutation on this page is a
// separate nonce-checked admin-post.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$npa_search   = isset( $_GET['npa_s'] ) ? sanitize_text_field( wp_unslash( $_GET['npa_s'] ) ) : '';
$npa_agent    = isset( $_GET['npa_agent'] ) ? sanitize_text_field( wp_unslash( $_GET['npa_agent'] ) ) : '';
$npa_page_num = isset( $_GET['npa_p'] ) ? max( 1, absint( $_GET['npa_p'] ) ) : 1;
$npa_open     = isset( $_GET['npa_c'] ) ? sanitize_text_field( wp_unslash( $_GET['npa_c'] ) ) : '';
$npa_deleted  = isset( $_GET['npa_deleted'] ) ? absint( $_GET['npa_deleted'] ) : null;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$npa_per_page = 20;
$npa_args     = array(
	'search'   => $npa_search,
	'agent'    => $npa_agent,
	'page'     => $npa_page_num,
	'per_page' => $npa_per_page,
);

$npa_total    = $npa_store->count_conversations( $npa_args );
$npa_pages    = (int) ceil( $npa_total / $npa_per_page );
$npa_rows     = $npa_total ? $npa_store->conversations( $npa_args ) : array();
$npa_agent_id = $npa_store->transcript_agent_ids();
$npa_stats    = $npa_store->transcript_stats();

/**
 * The label to show for an agent id — the configured name where there is one.
 *
 * @param string       $id       Agent id.
 * @param NPA_Settings $settings Settings.
 * @return string
 */
$npa_agent_label = static function ( $id, $settings ) {
	foreach ( (array) $settings->get( 'agents', array() ) as $row ) {
		if ( ! empty( $row['agent_id'] ) && $row['agent_id'] === $id && ! empty( $row['name'] ) ) {
			return (string) $row['name'];
		}
	}

	return '' !== $id ? $id : __( 'Default agent', 'newtide-public-agent' );
};

$npa_base_url = admin_url( 'options-general.php?page=' . NPA_Admin::SLUG . '&tab=conversations' );
?>

<?php if ( null !== $npa_deleted ) : ?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			printf(
				/* translators: %d: number of messages deleted. */
				esc_html( _n( 'Conversation deleted (%d message).', 'Conversation deleted (%d messages).', $npa_deleted, 'newtide-public-agent' ) ),
				(int) $npa_deleted
			);
			?>
		</p>
	</div>
<?php endif; ?>

<?php if ( ! $npa_storing ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'Conversations are not being stored.', 'newtide-public-agent' ); ?></strong>
			<?php esc_html_e( 'Nothing new is being recorded, and nothing was recorded while this was switched off — there is no history to recover for that period.', 'newtide-public-agent' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . NPA_Admin::SLUG . '&tab=agent' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Turn on conversation storage', 'newtide-public-agent' ); ?>
			</a>
		</p>
	</div>
<?php endif; ?>

<?php if ( '' !== $npa_open ) : ?>

	<?php
	$npa_turns = $npa_store->conversation( $npa_open );
	$npa_agent_of = '';
	if ( ! empty( $npa_turns[0]['agent_id'] ) ) {
		$npa_agent_of = (string) $npa_turns[0]['agent_id'];
	}
	?>

	<p>
		<a href="<?php echo esc_url( $npa_base_url ); ?>">&larr; <?php esc_html_e( 'Back to all conversations', 'newtide-public-agent' ); ?></a>
	</p>

	<?php
	$npa_admin->card_open(
		__( 'Conversation', 'newtide-public-agent' ),
		sprintf(
			/* translators: 1: agent name, 2: number of messages. */
			__( '%1$s — %2$d messages', 'newtide-public-agent' ),
			$npa_agent_label( $npa_agent_of, $settings ),
			count( $npa_turns )
		)
	);
	?>

	<?php if ( empty( $npa_turns ) ) : ?>
		<p><?php esc_html_e( 'This conversation is no longer stored. It may have passed the retention window.', 'newtide-public-agent' ); ?></p>
	<?php else : ?>
		<div class="npa-convo">
			<?php foreach ( $npa_turns as $npa_turn ) : ?>
				<?php $npa_is_visitor = ( 'visitor' === $npa_turn['role'] ); ?>
				<div class="npa-convo__turn npa-convo__turn--<?php echo $npa_is_visitor ? 'visitor' : 'agent'; ?>">
					<div class="npa-convo__who">
						<?php echo esc_html( $npa_is_visitor ? __( 'Visitor', 'newtide-public-agent' ) : __( 'Agent', 'newtide-public-agent' ) ); ?>
						<span class="npa-convo__when"><?php echo esc_html( mysql2date( 'M j, Y H:i', $npa_turn['created_at'] ) ); ?></span>
					</div>
					<?php /* esc_html, never the rendered Markdown: this is visitor-supplied text shown to an admin. */ ?>
					<div class="npa-convo__text"><?php echo esc_html( (string) $npa_turn['content'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<p>
			<a
				class="button button-link-delete"
				href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=npa_delete_conversation&npa_c=' . rawurlencode( $npa_open ) ), 'npa_delete_conversation' ) ); ?>"
				onclick="return confirm( '<?php echo esc_js( __( 'Delete this conversation permanently?', 'newtide-public-agent' ) ); ?>' );"
			>
				<?php esc_html_e( 'Delete this conversation', 'newtide-public-agent' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<?php $npa_admin->card_close(); ?>

<?php else : ?>

	<?php
	$npa_admin->card_open(
		__( 'Conversations', 'newtide-public-agent' ),
		__( 'What visitors asked, and what the agent answered.', 'newtide-public-agent' )
	);
	?>

	<p class="description">
		<?php
		if ( $npa_max > 0 ) {
			printf(
				/* translators: 1: retention days, 2: maximum conversations kept. */
				esc_html__( 'These are real messages from real visitors. Kept for %1$d days, and only the most recent %2$s conversations are retained.', 'newtide-public-agent' ),
				(int) $npa_retention,
				esc_html( number_format_i18n( $npa_max ) )
			);
		} else {
			printf(
				/* translators: %d: retention days. */
				esc_html__( 'These are real messages from real visitors. Kept for %d days, then deleted automatically.', 'newtide-public-agent' ),
				(int) $npa_retention
			);
		}
		?>
	</p>

	<form method="get" class="npa-convo-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( NPA_Admin::SLUG ); ?>" />
		<input type="hidden" name="tab" value="conversations" />

		<label class="screen-reader-text" for="npa-convo-search"><?php esc_html_e( 'Search conversations', 'newtide-public-agent' ); ?></label>
		<input type="search" id="npa-convo-search" name="npa_s" value="<?php echo esc_attr( $npa_search ); ?>" placeholder="<?php esc_attr_e( 'Search what was said…', 'newtide-public-agent' ); ?>" />

		<?php if ( count( $npa_agent_id ) > 1 ) : ?>
			<label class="screen-reader-text" for="npa-convo-agent"><?php esc_html_e( 'Filter by agent', 'newtide-public-agent' ); ?></label>
			<select id="npa-convo-agent" name="npa_agent">
				<option value=""><?php esc_html_e( 'All agents', 'newtide-public-agent' ); ?></option>
				<?php foreach ( $npa_agent_id as $npa_id ) : ?>
					<option value="<?php echo esc_attr( $npa_id ); ?>" <?php selected( $npa_agent, $npa_id ); ?>>
						<?php echo esc_html( $npa_agent_label( $npa_id, $settings ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'newtide-public-agent' ); ?></button>

		<?php if ( '' !== $npa_search || '' !== $npa_agent ) : ?>
			<a href="<?php echo esc_url( $npa_base_url ); ?>" class="button button-link"><?php esc_html_e( 'Clear', 'newtide-public-agent' ); ?></a>
		<?php endif; ?>

		<?php if ( $npa_total > 0 ) : ?>
			<a
				class="button"
				href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=npa_export_conversations&npa_s=' . rawurlencode( $npa_search ) . '&npa_agent=' . rawurlencode( $npa_agent ) ), 'npa_export_conversations' ) ); ?>"
			>
				<?php esc_html_e( 'Download CSV', 'newtide-public-agent' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $npa_rows ) ) : ?>
		<p>
			<?php
			if ( '' !== $npa_search || '' !== $npa_agent ) {
				esc_html_e( 'No conversations match that search.', 'newtide-public-agent' );
			} elseif ( $npa_storing ) {
				esc_html_e( 'No conversations stored yet. They will appear here once visitors start chatting.', 'newtide-public-agent' );
			} else {
				esc_html_e( 'No conversations stored.', 'newtide-public-agent' );
			}
			?>
		</p>
	<?php else : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Started', 'newtide-public-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Agent', 'newtide-public-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Messages', 'newtide-public-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Opened with', 'newtide-public-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $npa_rows as $npa_row ) : ?>
					<?php $npa_link = add_query_arg( 'npa_c', rawurlencode( $npa_row['conversation_id'] ), $npa_base_url ); ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $npa_link ); ?>">
								<?php echo esc_html( mysql2date( 'M j, Y H:i', $npa_row['started'] ) ); ?>
							</a>
						</td>
						<td><?php echo esc_html( $npa_agent_label( (string) $npa_row['agent_id'], $settings ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $npa_row['turns'] ) ); ?></td>
						<td>
							<?php
							$npa_preview = (string) $npa_row['preview'];
							echo esc_html( '' !== $npa_preview ? wp_trim_words( $npa_preview, 18, '…' ) : __( '(no visitor message)', 'newtide-public-agent' ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $npa_pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of conversations. */
						esc_html( _n( '%s conversation', '%s conversations', $npa_total, 'newtide-public-agent' ) ),
						esc_html( number_format_i18n( $npa_total ) )
					);
					?>
				</span>
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'npa_p', '%#%', $npa_base_url . ( '' !== $npa_search ? '&npa_s=' . rawurlencode( $npa_search ) : '' ) . ( '' !== $npa_agent ? '&npa_agent=' . rawurlencode( $npa_agent ) : '' ) ),
							'format'    => '',
							'current'   => $npa_page_num,
							'total'     => $npa_pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>

	<?php $npa_admin->card_close(); ?>

	<?php if ( $npa_stats['count'] > 0 ) : ?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: message count, 2: conversation count, 3: date of the oldest. */
				esc_html__( 'Holding %1$s messages across %2$s conversations. Oldest: %3$s.', 'newtide-public-agent' ),
				esc_html( number_format_i18n( $npa_stats['count'] ) ),
				esc_html( number_format_i18n( $npa_stats['conversations'] ) ),
				esc_html( $npa_stats['oldest'] ? mysql2date( 'M j, Y', $npa_stats['oldest'] ) : '—' )
			);
			?>
			<?php esc_html_e( 'Retention limits are set on the Agent tab; bulk deletion is on Service Status.', 'newtide-public-agent' ); ?>
		</p>
	<?php endif; ?>

<?php endif; ?>
