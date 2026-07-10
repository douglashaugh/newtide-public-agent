<?php
/**
 * Built-in launcher icon library.
 *
 * A small set of inline SVGs shared by the front-end widget renderer, the admin
 * icon picker, and the live preview — so a chosen icon looks pixel-identical in
 * every surface. Each entry is 24×24 and fills with `currentColor`, letting CSS
 * drive the colour from the launcher's text colour.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Icons
 */
class NPA_Icons {

	/**
	 * Allowed built-in icon slugs (the sanitize whitelist).
	 *
	 * @var string[]
	 */
	const BUILTIN = array( 'chat', 'star', 'bolt', 'heart', 'help', 'smile' );

	/**
	 * Slug => inner SVG path markup (24×24 viewBox).
	 *
	 * @return array<string,string>
	 */
	private static function paths() {
		return array(
			'chat'  => '<path fill="currentColor" d="M12 3C6.5 3 2 6.7 2 11.2c0 2.3 1.2 4.4 3.1 5.8-.1 1-.6 2.4-1.6 3.5 1.6-.2 3.3-.8 4.6-1.8 1.2.4 2.5.6 3.9.6 5.5 0 10-3.7 10-8.1S17.5 3 12 3Z"/>',
			'star'  => '<path fill="currentColor" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>',
			'bolt'  => '<path fill="currentColor" d="M7 2v11h3v9l7-12h-4l4-8z"/>',
			'heart' => '<path fill="currentColor" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>',
			'help'  => '<path fill="currentColor" d="M11 18h2v-2h-2v2zm1-16C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm0-14c-2.21 0-4 1.79-4 4h2c0-1.1.9-2 2-2s2 .9 2 2c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5 0-2.21-1.79-4-4-4z"/>',
			'smile' => '<path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"/>',
		);
	}

	/**
	 * Human-readable labels for the picker.
	 *
	 * @return array<string,string>
	 */
	public static function choices() {
		return array(
			'chat'  => __( 'Chat bubble', 'newtide-public-agent' ),
			'star'  => __( 'Star', 'newtide-public-agent' ),
			'bolt'  => __( 'Lightning', 'newtide-public-agent' ),
			'heart' => __( 'Heart', 'newtide-public-agent' ),
			'help'  => __( 'Question', 'newtide-public-agent' ),
			'smile' => __( 'Smiley', 'newtide-public-agent' ),
		);
	}

	/**
	 * Whether a slug is a known built-in icon.
	 *
	 * @param string $slug Candidate slug.
	 * @return bool
	 */
	public static function is_valid( $slug ) {
		return in_array( $slug, self::BUILTIN, true );
	}

	/**
	 * Inline SVG markup for a built-in icon. Unknown slugs fall back to 'chat'.
	 *
	 * @param string $slug      Icon slug.
	 * @param string $css_class SVG class (defaults to the launcher-icon class).
	 * @return string
	 */
	public static function svg( $slug, $css_class = 'newtide-public-agent__launcher-icon' ) {
		$paths = self::paths();
		$slug  = isset( $paths[ $slug ] ) ? $slug : 'chat';
		return '<svg class="' . esc_attr( $css_class ) . '" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">' . $paths[ $slug ] . '</svg>';
	}

	/**
	 * Slug => SVG-markup map, for handing the icons to JS (admin preview/picker).
	 *
	 * @param string $css_class SVG class applied to each icon.
	 * @return array<string,string>
	 */
	public static function localized( $css_class = 'newtide-public-agent__launcher-icon' ) {
		$out = array();
		foreach ( array_keys( self::paths() ) as $slug ) {
			$out[ $slug ] = self::svg( $slug, $css_class );
		}
		return $out;
	}
}
