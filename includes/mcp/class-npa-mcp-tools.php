<?php
/**
 * MCP tools — what the agent can ask about the site it is deployed on.
 *
 * Deliberately site-agnostic. Nothing here knows what the site is about: post
 * types, taxonomies and terms are enumerated at runtime, so a site with a
 * case_study type or a region taxonomy exposes them without anyone editing this
 * file.
 *
 * Two rules shaped the tool set:
 *
 *   A tool's description is the only thing the model sees when deciding whether
 *   to call it, so each says what it returns and when to reach for it.
 *
 *   Discovery comes first. An agent arriving at an unfamiliar site cannot guess
 *   its content model, so describe_site exists to be called before anything
 *   else. Without it the other tools are guesswork.
 *
 * Sites that want domain tools on top register them through the npa_mcp_tools
 * filter rather than by editing this class.
 *
 * Everything is read-only. There is no write path, and every tool is annotated
 * readOnlyHint so a client can call it without prompting.
 *
 * @package NewTide_Public_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry and handlers for the MCP tool surface.
 */
class NPA_MCP_Tools {

	/**
	 * Hard cap on rows returned by any single call.
	 *
	 * @var int
	 */
	const MAX_ROWS = 100;

	/**
	 * Ceiling on an encoded tool result, in bytes.
	 *
	 * Tool output lands directly in the agent's context, so an unbounded result
	 * is not merely slow — it is billed to the conversation and crowds out the
	 * answer the visitor asked for.
	 *
	 * @var int
	 */
	const MAX_RESULT_BYTES = 49152;

	/**
	 * Minimum words before a page counts as readable prose.
	 *
	 * Sites routinely have pages that are pure layout — a shortcode, a block
	 * pattern, a landing shell. Returning them gives the agent a title and an
	 * empty body, which reads as "nothing was published about this" rather than
	 * "this is an interactive page over there".
	 *
	 * @var int
	 */
	const MIN_WORDS = 20;

	/**
	 * Cached tool table.
	 *
	 * @var array|null
	 */
	private $registry = null;

	/**
	 * Build the tool table, including anything contributed by the site.
	 *
	 * @return array Tool name => definition.
	 */
	private function registry() {
		if ( null !== $this->registry ) {
			return $this->registry;
		}

		$tools = array(

			'describe_site' => array(
				'title'       => __( 'Describe this website', 'newtide-public-agent' ),
				'group'       => __( 'Discovery', 'newtide-public-agent' ),
				'description' => 'Describe the website you are answering for: its name, tagline, what kinds '
					. 'of content it publishes, how much of each, the date range covered, and the '
					. 'categories and other taxonomies available for filtering. CALL THIS FIRST, before '
					. 'any other content tool. Content types and taxonomy names vary from site to site '
					. 'and cannot be guessed — this tells you what actually exists here and what values '
					. 'the other tools will accept.',
				'schema'      => array( 'type' => 'object', 'properties' => new stdClass() ),
				'handler'     => 'describe_site',
			),

			'search_content' => array(
				'title'       => __( 'Search this site', 'newtide-public-agent' ),
				'group'       => __( 'Content', 'newtide-public-agent' ),
				'description' => 'Search the site\'s published pages, posts and other public content by free '
					. 'text, content type, taxonomy term or date. Returns titles, dates, content type, '
					. 'links and short excerpts — not full bodies. Use it to find what the site says '
					. 'about a subject, then get_content for the piece you need in full. Omit the query '
					. 'to list the most recent content. Search matches words rather than meaning, so if '
					. 'nothing comes back, try a synonym or browse by taxonomy term before concluding '
					. 'the site does not cover the topic.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'query'     => array( 'type' => 'string', 'description' => 'Free text matched against titles and body. Omit to list the most recent content.' ),
						'type'      => array( 'type' => 'string', 'description' => 'Content type to restrict to, from describe_site (e.g. post, page).' ),
						'taxonomy'  => array( 'type' => 'string', 'description' => 'Taxonomy to filter on, from describe_site (e.g. category).' ),
						'term'      => array( 'type' => 'string', 'description' => 'Term name or slug within that taxonomy.' ),
						'days'      => array( 'type' => 'integer', 'description' => 'Only content published in the last N days.' ),
						'limit'     => array( 'type' => 'integer', 'description' => 'Maximum results (default 10, max 100).' ),
					),
				),
				'handler'     => 'search_content',
			),

			'get_content' => array(
				'title'       => __( 'Read one page or post in full', 'newtide-public-agent' ),
				'group'       => __( 'Content', 'newtide-public-agent' ),
				'description' => 'Return the full text of a single page or post, with its title, publication '
					. 'date, content type, taxonomy terms and permalink. Use after search_content when an '
					. 'excerpt is not enough to answer properly. Always link the page when you use it, and '
					. 'give its date — anything time-sensitive in the text was true when written.',
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'identifier' ),
					'properties' => array(
						'identifier' => array( 'type' => 'string', 'description' => 'Slug, numeric ID or full URL, as returned by search_content.' ),
					),
				),
				'handler'     => 'get_content',
			),

			'list_taxonomy_terms' => array(
				'title'       => __( 'List the terms in a taxonomy', 'newtide-public-agent' ),
				'group'       => __( 'Discovery', 'newtide-public-agent' ),
				'description' => 'List the available terms in one of the site\'s taxonomies — its categories, '
					. 'tags or any custom grouping — with how much content sits under each. Use it to '
					. 'browse when a text search misses, or to offer a visitor the subjects this site '
					. 'actually covers. Taxonomy names come from describe_site.',
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'taxonomy' ),
					'properties' => array(
						'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy name from describe_site, e.g. category or post_tag.' ),
						'limit'    => array( 'type' => 'integer', 'description' => 'Maximum terms (default 50, max 100).' ),
					),
				),
				'handler'     => 'list_taxonomy_terms',
			),
		);

		/**
		 * Filter the MCP tool table.
		 *
		 * The extension point for domain tools — a site whose plugin exposes
		 * market data, product availability or booking state contributes them
		 * here rather than forking this class. A contributed tool supplies a
		 * 'callback' instead of a 'handler':
		 *
		 *     $tools['get_stock_level'] = array(
		 *         'title'       => 'Check stock',
		 *         'group'       => 'Inventory',
		 *         'description' => 'Say what the model needs to decide to call this.',
		 *         'schema'      => array( 'type' => 'object', 'properties' => array( ... ) ),
		 *         'callback'    => function ( array $args ) { return array( ... ); },
		 *     );
		 *
		 * Callbacks must read only, and should throw InvalidArgumentException
		 * with a message aimed at the model when arguments are unusable.
		 *
		 * @param array $tools Tool name => definition.
		 */
		$tools = (array) apply_filters( 'npa_mcp_tools', $tools );

		$this->registry = $this->validate( $tools );

		return $this->registry;
	}

	/**
	 * Drop malformed contributions rather than letting them break tools/list.
	 *
	 * A site registering a broken tool should lose that tool, not the whole
	 * server — an agent with fifteen working tools and one missing is far more
	 * useful than one that cannot enumerate anything.
	 *
	 * @param array $tools Candidate tool table.
	 * @return array
	 */
	private function validate( array $tools ) {
		$valid = array();

		foreach ( $tools as $name => $tool ) {
			$has_runner = ( isset( $tool['handler'] ) && method_exists( $this, 'tool_' . $tool['handler'] ) )
				|| ( isset( $tool['callback'] ) && is_callable( $tool['callback'] ) );

			if ( ! is_string( $name ) || '' === $name || ! is_array( $tool ) ) {
				continue;
			}

			if ( empty( $tool['description'] ) || empty( $tool['schema'] ) || ! $has_runner ) {
				/*
				 * Dropped silently from the caller's point of view, but recorded:
				 * a site that registers a broken tool needs to find out somewhere,
				 * and the agent needs a working tools/list more than it needs this
				 * one entry.
				 */
				$plugin = NPA_Plugin::instance();

				if ( isset( $plugin->logger ) ) {
					$plugin->logger->log(
						array(
							'error_code' => 'mcp_bad_tool',
							'note'       => 'ignored malformed tool: ' . $name,
						)
					);
				}

				continue;
			}

			$valid[ $name ] = $tool;
		}

		return $valid;
	}

	/**
	 * The tools/list payload.
	 *
	 * @return array
	 */
	public function definitions() {
		$out = array();

		foreach ( $this->registry() as $name => $tool ) {
			$out[] = array(
				'name'        => $name,
				'title'       => isset( $tool['title'] ) ? $tool['title'] : $name,
				'description' => $tool['description'],
				'inputSchema' => $tool['schema'],
				'annotations' => array(
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			);
		}

		return $out;
	}

	/**
	 * Tool names and titles, including any contributed by the site.
	 *
	 * @return array
	 */
	public function names() {
		$out = array();

		foreach ( $this->registry() as $name => $tool ) {
			$out[ $name ] = isset( $tool['title'] ) ? $tool['title'] : $name;
		}

		return $out;
	}

	/**
	 * Whether a tool exists.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public function exists( $name ) {
		$registry = $this->registry();

		return isset( $registry[ $name ] );
	}

	/**
	 * Run a tool and bound its result.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Arguments from the caller.
	 * @return mixed
	 * @throws InvalidArgumentException When the tool is unknown or the arguments are unusable.
	 */
	public function call( $name, array $args ) {
		$registry = $this->registry();

		if ( ! isset( $registry[ $name ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown tool: %s', $name ) );
		}

		$tool = $registry[ $name ];

		if ( isset( $tool['callback'] ) && is_callable( $tool['callback'] ) ) {
			$result = call_user_func( $tool['callback'], $args );
		} else {
			$method = 'tool_' . $tool['handler'];
			$result = $this->$method( $args );
		}

		return $this->bound( $result );
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	/**
	 * Describe the site's identity and content model.
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	private function tool_describe_site( array $args ) {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue; // Media is not readable content for these purposes.
			}

			$counts     = wp_count_posts( $type->name );
			$published  = isset( $counts->publish ) ? (int) $counts->publish : 0;

			if ( 0 === $published ) {
				continue; // An empty type is noise the agent would waste a call on.
			}

			$types[] = array(
				'name'        => $type->name,
				'label'       => $type->label,
				'published'   => $published,
				'taxonomies'  => array_values( get_object_taxonomies( $type->name ) ),
			);
		}

		$taxonomies = array();

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$count = wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => true ) );

			if ( is_wp_error( $count ) || 0 === (int) $count ) {
				continue;
			}

			$taxonomies[] = array(
				'name'  => $tax->name,
				'label' => $tax->label,
				'terms' => (int) $count,
			);
		}

		global $wpdb;

		$range = $wpdb->get_row(
			"SELECT MIN(post_date) AS first_published, MAX(post_date) AS last_published
			 FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = ''",
			ARRAY_A
		);

		return array(
			'name'            => get_bloginfo( 'name' ),
			'tagline'         => get_bloginfo( 'description' ),
			'url'             => home_url( '/' ),
			'language'        => get_bloginfo( 'language' ),
			'content_types'   => $types,
			'taxonomies'      => $taxonomies,
			'first_published' => isset( $range['first_published'] ) ? substr( (string) $range['first_published'], 0, 10 ) : null,
			'last_published'  => isset( $range['last_published'] ) ? substr( (string) $range['last_published'], 0, 10 ) : null,
			'note'            => 'Use these content type and taxonomy names as the type, taxonomy and term arguments to search_content and list_taxonomy_terms.',
		);
	}

	/**
	 * Search public content.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 * @throws InvalidArgumentException When a filter names something that does not exist.
	 */
	private function tool_search_content( array $args ) {
		$limit = $this->clamp( isset( $args['limit'] ) ? $args['limit'] : 10, 1, self::MAX_ROWS );

		$query_args = $this->public_content_args();

		/*
		 * IDs only, hydrated one at a time below. Loading the matched bodies up
		 * front is what exhausts memory on a content-heavy site: long-form posts
		 * run to tens of kilobytes each and WP_Query loads every one whether or
		 * not it is ultimately returned.
		 *
		 * The window has a floor rather than being a plain multiple of $limit,
		 * because layout-only pages are dropped below: a small limit with a
		 * small window can filter everything out and return nothing at all.
		 */
		$query_args['fields']         = 'ids';
		$query_args['posts_per_page'] = min( 200, max( 40, $limit * 4 ) );
		$query_args['orderby']        = empty( $args['query'] ) ? 'date' : 'relevance';
		$query_args['order']          = 'DESC';

		if ( ! empty( $args['query'] ) ) {
			$query_args['s'] = (string) $args['query'];
		}

		if ( ! empty( $args['type'] ) ) {
			$type = sanitize_key( (string) $args['type'] );

			if ( ! in_array( $type, $this->public_types(), true ) ) {
				throw new InvalidArgumentException(
					sprintf( 'This site has no public content type "%s". Call describe_site for the types it does have.', $type )
				);
			}

			$query_args['post_type'] = $type;
		}

		if ( ! empty( $args['taxonomy'] ) && ! empty( $args['term'] ) ) {
			$taxonomy = sanitize_key( (string) $args['taxonomy'] );

			if ( ! taxonomy_exists( $taxonomy ) ) {
				throw new InvalidArgumentException(
					sprintf( 'This site has no taxonomy "%s". Call describe_site for the taxonomies it does have.', $taxonomy )
				);
			}

			$term = get_term_by( 'name', (string) $args['term'], $taxonomy );

			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( (string) $args['term'] ), $taxonomy );
			}

			if ( ! $term ) {
				throw new InvalidArgumentException(
					sprintf( 'No term "%s" in %s. Call list_taxonomy_terms for the available terms.', $args['term'], $taxonomy )
				);
			}

			$query_args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			);
		}

		if ( ! empty( $args['days'] ) ) {
			$query_args['date_query'] = array(
				array( 'after' => $this->clamp( $args['days'], 1, 20000 ) . ' days ago' ),
			);
		}

		$query   = new WP_Query( $query_args );
		$results = array();

		foreach ( $query->posts as $post_id ) {
			$post = get_post( (int) $post_id );

			if ( ! $post ) {
				continue;
			}

			$text = $this->post_text( $post );

			if ( str_word_count( $text ) < self::MIN_WORDS ) {
				continue;
			}

			$meta               = $this->post_meta( $post );
			$meta['excerpt']    = $this->excerpt( $text, 400 );
			$meta['word_count'] = str_word_count( $text );

			$results[] = $meta;

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return array(
			'results' => $results,
			'count'   => count( $results ),
			'note'    => $results
				? 'Excerpts only — call get_content with a slug or URL for the full text.'
				: 'Nothing matched. Search matches words, not meaning: try a synonym, or browse with list_taxonomy_terms before concluding the site does not cover this.',
		);
	}

	/**
	 * Return one piece of content in full.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 * @throws InvalidArgumentException When nothing public matches.
	 */
	private function tool_get_content( array $args ) {
		$identifier = isset( $args['identifier'] ) ? trim( (string) $args['identifier'] ) : '';

		if ( '' === $identifier ) {
			throw new InvalidArgumentException( 'identifier is required — pass a slug, numeric ID or URL from search_content.' );
		}

		$post = null;

		if ( ctype_digit( $identifier ) ) {
			$post = get_post( (int) $identifier );
		} elseif ( false !== strpos( $identifier, '://' ) ) {
			$post_id = url_to_postid( $identifier );
			$post    = $post_id ? get_post( $post_id ) : null;
		}

		if ( ! $post ) {
			$found = get_posts(
				array_merge(
					$this->public_content_args(),
					array(
						'name'           => sanitize_title( $identifier ),
						'posts_per_page' => 1,
					)
				)
			);

			$post = $found ? $found[0] : null;
		}

		/*
		 * Re-check visibility rather than trusting the lookup. A numeric ID or a
		 * URL can name a draft, a private post or a password-protected one, none
		 * of which a visitor could read — and the agent speaks with the site's
		 * voice, so it must not see more than the site shows.
		 */
		if ( ! $post
			|| 'publish' !== $post->post_status
			|| '' !== $post->post_password
			|| ! in_array( $post->post_type, $this->public_types(), true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'No published content matching "%s". Use search_content to find it.', $identifier )
			);
		}

		$text  = $this->post_text( $post );
		$words = str_word_count( $text );

		if ( $words < self::MIN_WORDS ) {
			return array_merge(
				$this->post_meta( $post ),
				array(
					'word_count' => $words,
					'content'    => '',
					'note'       => 'This page carries no readable text — it is built from layout blocks or an '
						. 'embedded application rather than written content. Point the visitor to the URL '
						. 'rather than describing it as empty.',
				)
			);
		}

		return array_merge(
			$this->post_meta( $post ),
			array(
				'word_count' => $words,
				'content'    => $text,
				'note'       => sprintf(
					'Published %s. Anything time-sensitive in this text was accurate then — cite the page and its date.',
					get_the_date( 'j F Y', $post )
				),
			)
		);
	}

	/**
	 * List the terms in one taxonomy.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 * @throws InvalidArgumentException When the taxonomy does not exist.
	 */
	private function tool_list_taxonomy_terms( array $args ) {
		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( (string) $args['taxonomy'] ) : '';

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			throw new InvalidArgumentException(
				sprintf( 'No taxonomy "%s" on this site. Call describe_site for the taxonomies it has.', $taxonomy )
			);
		}

		$limit = $this->clamp( isset( $args['limit'] ) ? $args['limit'] : 50, 1, self::MAX_ROWS );

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => $limit,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			throw new InvalidArgumentException( $terms->get_error_message() );
		}

		$out = array();

		foreach ( $terms as $term ) {
			$out[] = array(
				'name'  => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'slug'  => $term->slug,
				'count' => (int) $term->count,
			);
		}

		return array(
			'taxonomy' => $taxonomy,
			'terms'    => $out,
			'count'    => count( $out ),
			'note'     => 'Pass a name or slug as the "term" argument to search_content, with "taxonomy" set to ' . $taxonomy . '.',
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Public post types this server will serve.
	 *
	 * @return array
	 */
	private function public_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );

		unset( $types['attachment'] );

		return array_values( $types );
	}

	/**
	 * Query arguments restricting results to what a logged-out visitor can see.
	 *
	 * Deliberately independent of who is calling. The agent answers in public,
	 * so it must see exactly what the public sees — never drafts, never private
	 * posts, never password-protected content.
	 *
	 * @return array
	 */
	private function public_content_args() {
		return array(
			'post_type'           => $this->public_types(),
			'post_status'         => 'publish',
			'has_password'        => false,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'suppress_filters'    => false,
		);
	}

	/**
	 * Reduce a post to readable prose.
	 *
	 * strip_shortcodes() runs first and do_shortcode() is never called. Executing
	 * shortcodes would render whole embedded applications — galleries, forms,
	 * dashboards — into a payload the agent expects to hold sentences, and
	 * leaving them unexpanded would hand it raw [shortcode] syntax to puzzle
	 * over. Neither is text worth reading.
	 *
	 * @param WP_Post $post Post to render.
	 * @return string
	 */
	private function post_text( WP_Post $post ) {
		$text = strip_shortcodes( $post->post_content );
		$text = wp_strip_all_tags( $text, false );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Shared metadata shape, so search and fetch describe content identically.
	 *
	 * @param WP_Post $post Post to describe.
	 * @return array
	 */
	private function post_meta( WP_Post $post ) {
		$terms = array();

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$names = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );

			if ( ! is_wp_error( $names ) && $names ) {
				$terms[ $taxonomy ] = array_values( $names );
			}
		}

		return array(
			'id'        => $post->ID,
			'slug'      => $post->post_name,
			/*
			 * Decoded: get_the_title() returns HTML entities, so a curly
			 * apostrophe reaches the agent as "Can&#8217;t" — which it will
			 * repeat to the visitor verbatim.
			 */
			'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'url'       => get_permalink( $post ),
			'type'      => $post->post_type,
			'published' => get_the_date( 'Y-m-d', $post ),
			'terms'     => $terms,
		);
	}

	/**
	 * Trim to a word boundary.
	 *
	 * @param string $text  Source text.
	 * @param int    $chars Maximum characters.
	 * @return string
	 */
	private function excerpt( $text, $chars ) {
		if ( strlen( $text ) <= $chars ) {
			return $text;
		}

		$cut   = substr( $text, 0, $chars );
		$space = strrpos( $cut, ' ' );

		return rtrim( false === $space ? $cut : substr( $cut, 0, $space ), " ,.;:" ) . '…';
	}

	/**
	 * Keep a result inside MAX_RESULT_BYTES by trimming its largest list.
	 *
	 * Applied to every tool, including contributed ones, because the expensive
	 * results are the ones nobody predicts. Trimming degrades gracefully: the
	 * shape survives and the agent is told what was dropped, so it can narrow
	 * the request rather than reasoning from a silent partial answer.
	 *
	 * @param mixed $payload Tool result.
	 * @return mixed
	 */
	private function bound( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}

		$encoded = wp_json_encode( $payload );

		if ( false === $encoded || strlen( $encoded ) <= self::MAX_RESULT_BYTES ) {
			return $payload;
		}

		$dropped = array();

		for ( $pass = 0; $pass < 12; $pass++ ) {
			$key = $this->largest_list_key( $payload );

			if ( null === $key ) {
				break;
			}

			$total = count( $payload[ $key ] );
			$keep  = (int) max( 1, floor( $total / 2 ) );

			$payload[ $key ]  = array_slice( $payload[ $key ], 0, $keep );
			$dropped[ $key ]  = array( 'kept' => $keep, 'of' => $total );

			$encoded = wp_json_encode( $payload );

			if ( false === $encoded || strlen( $encoded ) <= self::MAX_RESULT_BYTES ) {
				break;
			}
		}

		if ( $dropped ) {
			$parts = array();

			foreach ( $dropped as $where => $info ) {
				$parts[] = sprintf( '%s (kept %d of %d)', $where, $info['kept'], $info['of'] );
			}

			$payload['truncated']         = true;
			$payload['truncation_notice'] = 'Result exceeded the size limit and was trimmed: '
				. implode( ', ', $parts )
				. '. Narrow the request to see the full set.';
		}

		return $payload;
	}

	/**
	 * Key of the longest top-level list in a payload.
	 *
	 * @param array $payload Payload to inspect.
	 * @return string|int|null
	 */
	private function largest_list_key( array $payload ) {
		$best  = null;
		$count = 1;

		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) && count( $value ) > $count && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
				$best  = $key;
				$count = count( $value );
			}
		}

		return $best;
	}

	/**
	 * Clamp an integer argument.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	private function clamp( $value, $min, $max ) {
		return max( $min, min( $max, (int) $value ) );
	}
}
