<?php
/**
 * Implementation guide for agent builders.
 *
 * The person wiring an agent to this site is usually not the person who runs
 * it: a site owner installs the plugin, and someone on the agent platform side
 * has to connect to it. Before this existed the only description of the
 * endpoint was a commit message, so the hand-off was a conversation.
 *
 * Generated rather than written, for two reasons. The endpoint URL and the tool
 * set differ per site — a site contributing tools through npa_mcp_tools gets
 * them documented without anyone editing a file — and a hand-kept document
 * drifts from the code the first time a tool changes. One section list feeds
 * both the admin screen and the downloadable Markdown, so the two cannot
 * disagree.
 *
 * The guide never contains the live key. It is meant to be forwarded, and a key
 * inside a forwarded document ends up in inboxes, tickets and chat logs. The
 * admin sends the key separately; the guide says so.
 *
 * @package NewTide_Public_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the agent-builder guide as HTML (admin) or Markdown (download).
 */
class NPA_MCP_Guide {

	/**
	 * Placeholder standing in for the key everywhere the guide shows a request.
	 *
	 * @var string
	 */
	const KEY_PLACEHOLDER = 'YOUR_SITE_KEY';

	/**
	 * Tool registry.
	 *
	 * @var NPA_MCP_Tools
	 */
	private $tools;

	/**
	 * Constructor.
	 *
	 * @param NPA_MCP_Tools $tools Tool registry.
	 */
	public function __construct( NPA_MCP_Tools $tools ) {
		$this->tools = $tools;
	}

	/**
	 * The guide's sections.
	 *
	 * Each section is a title and a list of blocks. Block types: `p` (a
	 * paragraph), `list`, `steps` (ordered), `code`, `table`. Inline text may use
	 * `backticks` for code and **double asterisks** for emphasis; both renderers
	 * understand them.
	 *
	 * Written in English and not translated: the reader is a developer on the
	 * agent platform side, and the tool descriptions quoted inside it are
	 * English because that is what the model reads.
	 *
	 * @return array<int,array{id:string,title:string,blocks:array}>
	 */
	public function sections() {
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$home     = home_url( '/' );
		$endpoint = NPA_MCP_Server::endpoint_url();
		$key      = self::KEY_PLACEHOLDER;

		$sections = array();

		$sections[] = array(
			'id'     => 'overview',
			'title'  => 'What this is',
			'blocks' => array(
				array( 'p', sprintf( '**%1$s** (%2$s) runs the NewTide Public Agent plugin, which serves the site\'s published content to an AI agent over the Model Context Protocol (MCP). Connect your agent to it and the agent can answer from what this site actually says, rather than from whatever it was trained on.', $site, $home ) ),
				array(
					'list',
					array(
						'**Read-only.** No tool can change anything on the site.',
						'**Visitor-equivalent.** The agent sees exactly what a logged-out visitor sees: published, public, non-password-protected content. Never drafts, private pages or anything behind a login.',
						'**Self-describing.** Content types and taxonomies differ between sites, so the server reports them at runtime through `describe_site` rather than assuming a fixed content model.',
					),
				),
			),
		);

		$sections[] = array(
			'id'     => 'connect',
			'title'  => 'Connection details',
			'blocks' => array(
				array(
					'table',
					array( 'Setting', 'Value' ),
					array(
						array( 'Server URL', '`' . $endpoint . '`' ),
						array( 'Transport', 'MCP Streamable HTTP — JSON-RPC 2.0 over `POST`' ),
						array( 'Responses', '`application/json` only; no SSE stream' ),
						array( 'Sessions', 'None. The server is stateless and issues no session ID.' ),
						array( 'Protocol version', 'Echoes the version the client requests; `' . NPA_MCP_Server::DEFAULT_PROTOCOL_VERSION . '` when none is given' ),
						array( 'Authentication', 'A per-site key, supplied by the site administrator' ),
					),
				),
				array( 'p', 'Most platforms that support remote MCP servers ask for a **server URL** and, optionally, **custom headers**. Use the server URL above and send the key in a header. If your platform accepts only a URL, see the last option under Authentication.' ),
			),
		);

		$sections[] = array(
			'id'     => 'auth',
			'title'  => 'Authentication',
			'blocks' => array(
				array( 'p', 'Every request must carry the site key. The site administrator sends it to you separately — it is deliberately not included in this guide. Any one of these works, in order of preference:' ),
				array(
					'steps',
					array(
						'`Authorization: Bearer ' . $key . '`',
						'`X-NPA-MCP-Key: ' . $key . '` — use this if a proxy or the web server strips the `Authorization` header, which some Apache/FastCGI hosts do.',
						'`?api_key=' . $key . '` appended to the server URL — only for platforms that take a URL and nothing else. Keys in URLs tend to end up in logs, so prefer a header wherever one is available.',
					),
				),
				array( 'p', 'The administrator can rotate the key at any time. The old key stops working immediately, so a sudden run of `401` responses after a working period usually means the key was rotated and needs updating on your side.' ),
			),
		);

		$sections[] = array(
			'id'     => 'check',
			'title'  => 'Check the connection',
			'blocks' => array(
				array( 'p', 'Before configuring your agent, confirm the key and URL from a terminal. Replace `' . $key . '` with the real key.' ),
				array( 'p', '**1. Initialize** — should return the server name and a short `instructions` text:' ),
				array( 'code', $this->curl( $endpoint, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"' . NPA_MCP_Server::DEFAULT_PROTOCOL_VERSION . '","capabilities":{},"clientInfo":{"name":"check","version":"1"}}}' ), 'bash' ),
				array( 'p', '**2. List the tools:**' ),
				array( 'code', $this->curl( $endpoint, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' ), 'bash' ),
				array( 'p', '**3. Call `describe_site`** — should describe this site\'s content:' ),
				array( 'code', $this->curl( $endpoint, '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"describe_site","arguments":{}}}' ), 'bash' ),
				array( 'p', 'Once your agent is connected, the site administrator can see its calls on the plugin\'s **Site knowledge** screen, which is the quickest way to confirm from the site side that it is working.' ),
			),
		);

		$sections[] = array(
			'id'     => 'tools',
			'title'  => 'Tools',
			'blocks' => array_merge(
				array( array( 'p', 'These are the tools this site currently offers. The list is generated from the running server, so it includes any tools the site has added.' ) ),
				$this->tool_blocks()
			),
		);

		$sections[] = array(
			'id'     => 'prompt',
			'title'  => 'Recommended agent instructions',
			'blocks' => array(
				array( 'p', 'The server sends orientation text in its `initialize` response, but not every platform passes that to the model. Adding something like this to your agent\'s system prompt makes the tools noticeably more reliable:' ),
				array( 'code', $this->prompt_snippet( $site ), 'text' ),
				array( 'p', 'The most common failure is an agent that guesses a content type or category name instead of calling `describe_site`. On the site side this shows up as failed calls; the fix is in the prompt.' ),
			),
		);

		$sections[] = array(
			'id'     => 'limits',
			'title'  => 'What the agent will and will not see',
			'blocks' => array(
				array(
					'list',
					array(
						'Only published, public, non-password-protected content. A draft or private page cannot be read, even by ID.',
						'Shortcodes are stripped, never executed, so embedded galleries, forms and widgets do not appear in the text.',
						'Pages that are almost all layout and very little prose are left out of search results, so they do not read as empty.',
						sprintf( 'Results are bounded: at most %1$d rows per call, and about %2$d KB per result.', NPA_MCP_Tools::MAX_ROWS, (int) round( NPA_MCP_Tools::MAX_RESULT_BYTES / 1024 ) ),
						'Search matches words, not meaning. One empty result does not mean the site lacks the subject; try a synonym or browse by taxonomy term.',
					),
				),
			),
		);

		$sections[] = array(
			'id'     => 'troubleshooting',
			'title'  => 'Errors and troubleshooting',
			'blocks' => array(
				array(
					'table',
					array( 'Response', 'Meaning', 'What to do' ),
					array(
						array( '`401` `npa_mcp_missing_key`', 'No key reached the server', 'Check the header name. If you are sending `Authorization`, the host may be stripping it; switch to `X-NPA-MCP-Key`.' ),
						array( '`401` `npa_mcp_invalid_key`', 'A key arrived but is wrong', 'Ask the site administrator for the current key; it may have been rotated.' ),
						array( '`403` `npa_mcp_disabled`', 'The site has switched site knowledge off', 'Only the site administrator can turn it back on.' ),
						array( '`404`', 'The route is missing', 'The plugin is not active, or the site blocks `/wp-json/`. A security plugin or firewall rule is the usual cause.' ),
						array( '`405` on `GET`', 'Expected', 'The server is stateless and serves `POST` only. Clients that probe with `GET` should carry on with `POST`.' ),
						array( 'JSON-RPC `-32700`', 'The request body was not valid JSON', 'Check quoting — shell escaping is the usual culprit when testing with curl.' ),
						array( 'A result with `isError: true`', 'The tool ran and refused the arguments', 'Read the message; it is written for the model and says what to change, typically a content type or taxonomy name that does not exist on this site.' ),
					),
				),
			),
		);

		$sections[] = array(
			'id'     => 'developers',
			'title'  => 'For WordPress developers on this site',
			'blocks' => array(
				array(
					'list',
					array(
						'**Add domain tools** — product stock, bookings, market data — with the `npa_mcp_tools` filter. Each tool needs a description, a JSON schema and a read-only callback; a malformed tool is dropped and logged rather than breaking the tool list.',
						'**Change the orientation text** sent on `initialize` with the `npa_mcp_instructions` filter.',
						'**Keep the key out of the database** by defining `NPA_MCP_KEY` in `wp-config.php`. It then overrides the stored key and cannot be rotated from the admin screen.',
					),
				),
			),
		);

		/**
		 * Filter the agent-builder guide before it is rendered.
		 *
		 * @param array $sections Guide sections.
		 */
		return (array) apply_filters( 'npa_mcp_guide_sections', $sections );
	}

	/**
	 * One block group per tool, generated from the live registry.
	 *
	 * @return array
	 */
	private function tool_blocks() {
		$blocks = array();

		foreach ( $this->tools->definitions() as $tool ) {
			$schema   = isset( $tool['inputSchema'] ) ? (array) $tool['inputSchema'] : array();
			$props    = isset( $schema['properties'] ) ? (array) $schema['properties'] : array();
			$required = isset( $schema['required'] ) ? (array) $schema['required'] : array();

			$blocks[] = array( 'h', '`' . $tool['name'] . '` — ' . $tool['title'] );
			$blocks[] = array( 'p', $tool['description'] );

			if ( empty( $props ) ) {
				$blocks[] = array( 'p', 'No arguments.' );
				continue;
			}

			$rows = array();

			foreach ( $props as $name => $prop ) {
				$prop   = (array) $prop;
				$rows[] = array(
					'`' . $name . '`',
					isset( $prop['type'] ) ? (string) $prop['type'] : '',
					in_array( $name, $required, true ) ? 'yes' : '',
					isset( $prop['description'] ) ? (string) $prop['description'] : '',
				);
			}

			$blocks[] = array( 'table', array( 'Argument', 'Type', 'Required', 'Description' ), $rows );
		}

		return $blocks;
	}

	/**
	 * A curl command posting one JSON-RPC message.
	 *
	 * @param string $endpoint Server URL.
	 * @param string $body     JSON body.
	 * @return string
	 */
	private function curl( $endpoint, $body ) {
		return "curl -s -X POST '" . $endpoint . "' \\\n"
			. "  -H 'Authorization: Bearer " . self::KEY_PLACEHOLDER . "' \\\n"
			. "  -H 'Content-Type: application/json' \\\n"
			. "  -H 'Accept: application/json, text/event-stream' \\\n"
			. "  -d '" . $body . "'";
	}

	/**
	 * System-prompt text for the agent.
	 *
	 * @param string $site Site name.
	 * @return string
	 */
	private function prompt_snippet( $site ) {
		return 'You answer questions for visitors to ' . $site . '. You have tools that read this website\'s published content.' . "\n\n"
			. '- At the start of a conversation that needs site content, call describe_site to learn which content types and categories this site has. Never guess their names.' . "\n"
			. '- Use search_content to find material, then get_content to read a page in full before relying on it.' . "\n"
			. '- If a search returns nothing, try a synonym or browse with list_taxonomy_terms before telling the visitor the site does not cover the subject.' . "\n"
			. '- When you use a page, link to it and mention its date if the information could be time-sensitive.' . "\n"
			. '- Only say the site covers something if the tools returned it.';
	}

	// ── Rendering ───────────────────────────────────────────────────────────

	/**
	 * The guide as Markdown, for download.
	 *
	 * @return string
	 */
	public function to_markdown() {
		$out  = '# ' . $this->heading() . "\n\n";
		$out .= '_' . $this->generated_note() . "_\n\n";

		foreach ( $this->sections() as $section ) {
			$out .= '## ' . $section['title'] . "\n\n";

			foreach ( $section['blocks'] as $block ) {
				$out .= $this->block_markdown( $block ) . "\n\n";
			}
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * The guide's title.
	 *
	 * @return string
	 */
	private function heading() {
		return 'Connecting an agent to ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Provenance line under the title: which plugin version wrote it, and when.
	 *
	 * @return string
	 */
	private function generated_note() {
		return 'Generated by NewTide Public Agent ' . ( defined( 'NPA_VERSION' ) ? NPA_VERSION : '' ) . ' on ' . gmdate( 'Y-m-d' )
			. ". Regenerate it from the site's Site knowledge screen if the site changes.";
	}

	/**
	 * One block as Markdown.
	 *
	 * @param array $block Block.
	 * @return string
	 */
	private function block_markdown( array $block ) {
		switch ( $block[0] ) {
			case 'h':
				return '### ' . $block[1];

			case 'list':
				return '- ' . implode( "\n- ", $block[1] );

			case 'steps':
				$lines = array();
				foreach ( array_values( $block[1] ) as $i => $item ) {
					$lines[] = ( $i + 1 ) . '. ' . $item;
				}
				return implode( "\n", $lines );

			case 'code':
				return '```' . ( isset( $block[2] ) ? $block[2] : '' ) . "\n" . $block[1] . "\n```";

			case 'table':
				$cell  = static function ( $text ) {
					return str_replace( array( '|', "\n" ), array( '\\|', ' ' ), (string) $text );
				};
				$lines = array(
					'| ' . implode( ' | ', array_map( $cell, $block[1] ) ) . ' |',
					'|' . str_repeat( ' --- |', count( $block[1] ) ),
				);
				foreach ( $block[2] as $row ) {
					$lines[] = '| ' . implode( ' | ', array_map( $cell, $row ) ) . ' |';
				}
				return implode( "\n", $lines );

			default:
				return (string) $block[1];
		}
	}

	/**
	 * The guide as HTML, for the admin screen. Escaped throughout.
	 *
	 * @return string
	 */
	public function to_html() {
		$out = '<div class="npa-guide">';

		foreach ( $this->sections() as $section ) {
			$out .= '<section class="npa-guide__section" id="npa-guide-' . esc_attr( $section['id'] ) . '">';
			$out .= '<h3 class="npa-guide__title">' . esc_html( $section['title'] ) . '</h3>';

			foreach ( $section['blocks'] as $block ) {
				$out .= $this->block_html( $block );
			}

			$out .= '</section>';
		}

		return $out . '</div>';
	}

	/**
	 * The guide as a standalone, print-ready page, for saving as PDF.
	 *
	 * The PDF route goes through the browser's own print dialog rather than a
	 * generated file. Some browsers and managed-device policies refuse to
	 * download a .md file at all, and producing a PDF server-side would mean
	 * bundling a PDF library of several megabytes into every install for one
	 * button. Every current browser can save a page as PDF, and nothing is
	 * downloaded until the admin chooses to.
	 *
	 * Deliberately self-contained -- its own styles, no admin chrome, no theme --
	 * so the printout is the guide and nothing else. The one script opens the
	 * print dialog once the page has rendered.
	 *
	 * @return string
	 */
	public function to_document() {
		$title = $this->heading();

		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<style>' . self::DOCUMENT_CSS . '</style></head><body>'
			. '<div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button>'
			. '<span>In the print dialog, choose <strong>Save as PDF</strong> as the destination.</span></div>'
			. '<main><h1>' . esc_html( $title ) . '</h1>'
			. '<p class="note">' . esc_html( $this->generated_note() ) . '</p>'
			. $this->to_html()
			. '</main><script>window.addEventListener("load",function(){window.print();});</script></body></html>';
	}

	/**
	 * Styles for the print-ready page. Plain, legible on screen and on paper,
	 * with code blocks that wrap rather than run off the page edge.
	 *
	 * @var string
	 */
	const DOCUMENT_CSS = '
		body{margin:0;background:#fff;color:#1d2327;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
		main{max-width:52rem;margin:0 auto;padding:1.5rem 1.5rem 3rem}
		h1{margin:0 0 .25rem;font-size:1.6rem;color:#0a348b}
		.note{margin:0 0 1.5rem;color:#646970;font-size:.85rem}
		.npa-guide__section{margin-top:1.75rem;padding-top:1.25rem;border-top:1px solid #dcdcde}
		.npa-guide__title{margin:0 0 .5rem;font-size:1.15rem;color:#0a348b}
		.npa-guide__tool{margin:1.25rem 0 .35rem;font-size:1rem}
		code{font-family:Consolas,Monaco,monospace;font-size:.88em;background:#f0f0f1;padding:.05em .3em;border-radius:3px}
		.npa-guide__code{margin:.5rem 0 1rem;padding:.8rem 1rem;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;font-size:.82rem;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}
		.npa-guide__code code{background:none;padding:0;font-size:inherit}
		table{width:100%;border-collapse:collapse;margin:.5rem 0 1rem;font-size:.88rem}
		th,td{text-align:left;vertical-align:top;padding:.45rem .6rem;border:1px solid #dcdcde}
		th{background:#f6f7f7}
		li{margin-bottom:.35rem}
		.toolbar{position:sticky;top:0;display:flex;gap:1rem;align-items:center;padding:.75rem 1.5rem;background:#eef5ff;border-bottom:1px solid #c6dbf3;font-size:.9rem}
		.toolbar button{font:inherit;font-weight:600;padding:.4rem .9rem;border:0;border-radius:4px;background:#0a348b;color:#fff;cursor:pointer}
		@media print{.toolbar{display:none}main{max-width:none;padding:0}.npa-guide__section{break-inside:auto}tr,.npa-guide__code{break-inside:avoid}h3,h4,p:has(+ .npa-guide__code){break-after:avoid}}
	';

	/**
	 * One block as HTML.
	 *
	 * @param array $block Block.
	 * @return string
	 */
	private function block_html( array $block ) {
		switch ( $block[0] ) {
			case 'h':
				return '<h4 class="npa-guide__tool">' . $this->inline( $block[1] ) . '</h4>';

			case 'list':
			case 'steps':
				$tag   = 'steps' === $block[0] ? 'ol' : 'ul';
				$items = '';
				foreach ( $block[1] as $item ) {
					$items .= '<li>' . $this->inline( $item ) . '</li>';
				}
				return '<' . $tag . '>' . $items . '</' . $tag . '>';

			case 'code':
				return '<pre class="npa-guide__code"><code>' . esc_html( $block[1] ) . '</code></pre>';

			case 'table':
				$head = '';
				foreach ( $block[1] as $h ) {
					$head .= '<th scope="col">' . esc_html( $h ) . '</th>';
				}
				$body = '';
				foreach ( $block[2] as $row ) {
					$body .= '<tr>';
					foreach ( $row as $cell ) {
						$body .= '<td>' . $this->inline( $cell ) . '</td>';
					}
					$body .= '</tr>';
				}
				return '<div class="npa-guide__table"><table class="widefat striped"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';

			default:
				return '<p>' . $this->inline( $block[1] ) . '</p>';
		}
	}

	/**
	 * Escape text, then apply the two inline marks the guide uses.
	 *
	 * Escaping first means the marks can only ever produce <code> and <strong>;
	 * nothing in a tool description contributed by a site can inject markup.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function inline( $text ) {
		$html = esc_html( (string) $text );
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

		return preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
	}
}
