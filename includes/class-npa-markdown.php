<?php
/**
 * A deliberately small Markdown subset, rendered server-side.
 *
 * The agent writes Markdown — tables of prices, bolded figures, bulleted
 * findings — and the widget used to print it as literal pipes and asterisks.
 * This turns the common subset into HTML.
 *
 * **Why this runs in PHP.** The output is untrusted text from a language model
 * being turned into markup, which is the one place in this plugin where getting
 * it wrong is an XSS hole rather than a cosmetic bug. In PHP it goes through
 * wp_kses against an explicit allow-list, and it is reachable from the test
 * battery, so the injection cases are actually exercised on every run. The same
 * code in the widget's JavaScript would be covered by nothing.
 *
 * **The order matters and is the whole security argument.** Every character is
 * HTML-escaped *first*, so any markup the model emits — a `<script>`, an
 * `onerror=`, a stray `<` — is inert text before parsing starts. Only this
 * class's own tags are introduced afterwards, and wp_kses at the end is a second
 * fence rather than the first: nothing should ever reach it that needs removing.
 *
 * **What is left out, on purpose.** Images (the agent cannot produce them, and
 * an `<img src>` is an outbound request to an address a model chose), raw HTML
 * passthrough, iframes, ids and classes, and any attribute not listed below. A
 * link keeps only an http(s) href — `javascript:` and `data:` are dropped by
 * scheme check and again by wp_kses.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Markdown
 */
class NPA_Markdown {

	/**
	 * Tags and attributes permitted in the final output.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$link = array(
			'href'   => true,
			'title'  => true,
			'rel'    => true,
			'target' => true,
		);

		return array(
			'p'          => array(),
			'br'         => array(),
			'strong'     => array(),
			'em'         => array(),
			'code'       => array(),
			'pre'        => array(),
			'blockquote' => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'hr'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'a'          => $link,
			'table'      => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'th'         => array(),
			'td'         => array(),
		);
	}

	/**
	 * Render a Markdown subset to sanitized HTML.
	 *
	 * @param string $text Raw reply text.
	 * @return string HTML, safe to insert.
	 */
	public static function to_html( $text ) {
		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return '';
		}

		// Normalize line endings so the block parser only sees "\n".
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		/*
		 * Escape first. From here on the only "<" in the buffer are ones this
		 * class writes, which is what makes the rest of the parsing safe.
		 */
		$text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		// Fenced code blocks are pulled out before anything else so their
		// contents are never treated as Markdown.
		$fences = array();
		$text   = preg_replace_callback(
			'/```[a-z0-9_+-]*\n(.*?)```/is',
			static function ( $m ) use ( &$fences ) {
				$token            = "\0FENCE" . count( $fences ) . "\0";
				$fences[ $token ] = '<pre><code>' . rtrim( $m[1] ) . '</code></pre>';
				return "\n" . $token . "\n";
			},
			$text
		);

		$lines  = explode( "\n", $text );
		$out    = array();
		$buffer = array();
		$state  = '';

		$flush = static function () use ( &$out, &$buffer, &$state ) {
			if ( empty( $buffer ) ) {
				$state = '';
				return;
			}

			if ( 'ul' === $state || 'ol' === $state ) {
				$out[] = '<' . $state . '>' . implode( '', $buffer ) . '</' . $state . '>';
			} elseif ( 'quote' === $state ) {
				$out[] = '<blockquote><p>' . implode( '<br />', $buffer ) . '</p></blockquote>';
			} elseif ( 'table' === $state ) {
				$out[] = self::table( $buffer );
			} else {
				$out[] = '<p>' . implode( '<br />', $buffer ) . '</p>';
			}

			$buffer = array();
			$state  = '';
		};

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// A pulled-out code fence stands alone.
			if ( isset( $fences[ $trimmed ] ) ) {
				$flush();
				$out[] = $fences[ $trimmed ];
				continue;
			}

			if ( '' === $trimmed ) {
				$flush();
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^(\*\s*){3,}$|^(-\s*){3,}$|^(_\s*){3,}$/', $trimmed ) ) {
				$flush();
				$out[] = '<hr />';
				continue;
			}

			// Headings. Only h3/h4 — a chat bubble has no business emitting an
			// h1, and the page around it already owns the outline.
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $m ) ) {
				$flush();
				$tag   = ( strlen( $m[1] ) <= 3 ) ? 'h3' : 'h4';
				$out[] = '<' . $tag . '>' . self::inline( $m[2] ) . '</' . $tag . '>';
				continue;
			}

			// Table rows: a line that starts and ends with a pipe.
			if ( preg_match( '/^\|.*\|$/', $trimmed ) ) {
				if ( 'table' !== $state ) {
					$flush();
					$state = 'table';
				}
				$buffer[] = $trimmed;
				continue;
			}

			// Blockquote.
			if ( preg_match( '/^&gt;\s?(.*)$/', $trimmed, $m ) ) {
				if ( 'quote' !== $state ) {
					$flush();
					$state = 'quote';
				}
				$buffer[] = self::inline( $m[1] );
				continue;
			}

			// Unordered list.
			if ( preg_match( '/^[-*+]\s+(.*)$/', $trimmed, $m ) ) {
				if ( 'ul' !== $state ) {
					$flush();
					$state = 'ul';
				}
				$buffer[] = '<li>' . self::inline( $m[1] ) . '</li>';
				continue;
			}

			// Ordered list.
			if ( preg_match( '/^\d+[.)]\s+(.*)$/', $trimmed, $m ) ) {
				if ( 'ol' !== $state ) {
					$flush();
					$state = 'ol';
				}
				$buffer[] = '<li>' . self::inline( $m[1] ) . '</li>';
				continue;
			}

			// Anything else is paragraph text. A state change ends the block.
			if ( '' !== $state && 'p' !== $state ) {
				$flush();
			}

			$state    = 'p';
			$buffer[] = self::inline( $trimmed );
		}

		$flush();

		$html = implode( "\n", $out );

		return self::sanitize( $html );
	}

	/**
	 * The second fence: wp_kses against the allow-list, with `pre_kses`
	 * suspended.
	 *
	 * Nothing should ever be removed here — the parser only emits tags from the
	 * list — so this is a belt, not the trousers.
	 *
	 * `pre_kses` is suspended because it is a public hook that rewrites content
	 * on its way into wp_kses, and other plugins use it. Jetpack's
	 * Filter_Embedded_HTML_Objects::maybe_create_links was measured turning an
	 * escaped `<iframe src="...">` that the agent had written as literal text
	 * into a live link, replacing the words the agent actually said. That is
	 * benign in itself, but a chat reply is not post content: it should render
	 * as written, and what it renders as should be decided here rather than by
	 * whatever else is installed. Suspending the hook makes the output a
	 * function of this file alone, which is also what makes the injection tests
	 * mean anything.
	 *
	 * @param string $html Markup built by this class.
	 * @return string
	 */
	private static function sanitize( $html ) {
		global $wp_filter;

		$saved = isset( $wp_filter['pre_kses'] ) ? $wp_filter['pre_kses'] : null;

		if ( null !== $saved ) {
			unset( $wp_filter['pre_kses'] );
		}

		try {
			return wp_kses( $html, self::allowed_html() );
		} finally {
			if ( null !== $saved ) {
				$wp_filter['pre_kses'] = $saved;
			}
		}
	}

	/**
	 * Build a table from collected pipe rows.
	 *
	 * The alignment row (|---|---|) is used only to mark the header; alignment
	 * itself is ignored, since it would mean inline styles.
	 *
	 * @param array $rows Raw pipe-delimited lines.
	 * @return string
	 */
	private static function table( array $rows ) {
		$parsed = array();

		foreach ( $rows as $row ) {
			$row    = trim( $row, '|' );
			$cells  = array_map( 'trim', explode( '|', $row ) );
			$parsed[] = $cells;
		}

		$has_header = false;
		if ( isset( $parsed[1] ) ) {
			$divider = true;
			foreach ( $parsed[1] as $cell ) {
				if ( ! preg_match( '/^:?-{1,}:?$/', $cell ) ) {
					$divider = false;
					break;
				}
			}
			$has_header = $divider;
		}

		$html = '<table>';

		if ( $has_header ) {
			$html .= '<thead><tr>';
			foreach ( $parsed[0] as $cell ) {
				$html .= '<th>' . self::inline( $cell ) . '</th>';
			}
			$html .= '</tr></thead>';
			$body  = array_slice( $parsed, 2 );
		} else {
			$body = $parsed;
		}

		$html .= '<tbody>';
		foreach ( $body as $cells ) {
			$html .= '<tr>';
			foreach ( $cells as $cell ) {
				$html .= '<td>' . self::inline( $cell ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Inline spans: code, bold, italic, links.
	 *
	 * Inline code is extracted first so its contents cannot be re-parsed —
	 * `**not bold**` inside backticks must stay literal.
	 *
	 * @param string $text Already HTML-escaped text.
	 * @return string
	 */
	private static function inline( $text ) {
		$codes = array();
		$text  = preg_replace_callback(
			'/`([^`]+)`/',
			static function ( $m ) use ( &$codes ) {
				$token           = "\0CODE" . count( $codes ) . "\0";
				$codes[ $token ] = '<code>' . $m[1] . '</code>';
				return $token;
			},
			$text
		);

		// Links. The scheme check happens here; wp_kses checks it again.
		$text = preg_replace_callback(
			// The URL may contain one level of balanced parentheses, which real
			// reference URLs do. A leading "!" marks an image: images are not
			// rendered, but the caption and address are kept as an ordinary
			// link rather than left as a stray bang.
			'/!?\[([^\]]+)\]\(([^()\s]*(?:\([^()]*\)[^()\s]*)*)\)/',
			static function ( $m ) {
				$url = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );

				if ( ! preg_match( '#^https?://#i', $url ) ) {
					// Not a scheme worth following: keep the words, drop the link.
					return $m[1];
				}

				$safe = esc_url( $url, array( 'http', 'https' ) );

				if ( '' === $safe ) {
					return $m[1];
				}

				return '<a href="' . $safe . '" rel="nofollow noopener ugc" target="_blank">' . $m[1] . '</a>';
			},
			$text
		);

		// Bold before italic, so ** is not eaten by the single-asterisk rule.
		$text = preg_replace( '/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__(?=\S)(.+?)(?<=\S)__/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<![\*\w])\*(?=\S)([^*]+?)(?<=\S)\*(?![\*\w])/s', '<em>$1</em>', $text );
		$text = preg_replace( '/(?<![_\w])_(?=\S)([^_]+?)(?<=\S)_(?![_\w])/s', '<em>$1</em>', $text );

		return strtr( $text, $codes );
	}
}
