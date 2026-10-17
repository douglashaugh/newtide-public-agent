<?php
/**
 * What the visitor is looking at, handed to the agent with their question.
 *
 * The widget has always sent the page's address and title with each message,
 * and until 0.18.0 the plugin stored them for diagnostics and sent the agent
 * nothing. So a visitor on the Midland rack-market page asking "what supplies
 * this market?" got a question back, or an answer about somewhere else.
 *
 * The page is folded into the message itself rather than sent as a system
 * message, because the Agent API only accepts caller instructions when the
 * agent opts in, and the message always arrives. A page can add its own data
 * too (`details`): an "Ask" button beside a chart can hand the agent the
 * figures the visitor is looking at, which they would never type out.
 *
 * @package NewTide_Public_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the page-context preface for an outbound message.
 */
final class NPA_Context {

	/** Longest page-supplied detail block, characters. */
	const MAX_DETAILS = 2000;

	/** Longest page title kept, characters. */
	const MAX_TITLE = 190;

	/**
	 * Sanitize the context the widget sent.
	 *
	 * @param array $ctx Raw context from the request.
	 * @return array{page_url:string,page_title:string,locale:string,details:string}
	 */
	public static function sanitize( array $ctx ) {
		$details = isset( $ctx['details'] ) ? (string) $ctx['details'] : '';
		// Keep line breaks: a page may hand over a small table. Strip tags
		// and control characters, then cap the length.
		$details = wp_strip_all_tags( $details );
		$details = preg_replace( '/[^\P{C}\n\t]/u', '', $details );
		$details = trim( (string) $details );
		if ( strlen( $details ) > self::MAX_DETAILS ) {
			$details = rtrim( mb_substr( $details, 0, self::MAX_DETAILS ) ) . '…';
		}

		return array(
			'page_url'   => isset( $ctx['page_url'] ) ? esc_url_raw( (string) $ctx['page_url'] ) : '',
			'page_title' => isset( $ctx['page_title'] ) ? mb_substr( sanitize_text_field( (string) $ctx['page_title'] ), 0, self::MAX_TITLE ) : '',
			'locale'     => isset( $ctx['locale'] ) ? substr( sanitize_text_field( (string) $ctx['locale'] ), 0, 20 ) : '',
			'details'    => $details,
		);
	}

	/**
	 * The message as the agent should read it: context first, question last.
	 *
	 * The page line is only added when the site allows it; page-supplied
	 * details are always passed, since the page chose to send them.
	 *
	 * @param array  $context   Sanitized context (see sanitize()).
	 * @param string $message   The visitor's own words.
	 * @param bool   $with_page Whether to name the page.
	 * @return string
	 */
	public static function with( array $context, $message, $with_page = true ) {
		$lines = array();

		if ( $with_page ) {
			$title = isset( $context['page_title'] ) ? trim( (string) $context['page_title'] ) : '';
			$url   = isset( $context['page_url'] ) ? trim( (string) $context['page_url'] ) : '';
			if ( '' !== $title || '' !== $url ) {
				$where   = '' !== $title ? $title : $url;
				$where  .= ( '' !== $title && '' !== $url ) ? ' (' . $url . ')' : '';
				$lines[] = '[Visitor is viewing: ' . $where . ']';
			}
		}

		$details = isset( $context['details'] ) ? trim( (string) $context['details'] ) : '';
		if ( '' !== $details ) {
			$lines[] = '[Page data: ' . $details . ']';
		}

		if ( empty( $lines ) ) {
			return (string) $message;
		}

		return implode( "\n", $lines ) . "\n\n" . (string) $message;
	}
}
