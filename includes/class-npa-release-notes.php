<?php
/**
 * Release notes, read from readme.txt (0.16.0).
 *
 * The Changelog section of readme.txt is the one place release notes are
 * written. WordPress requires the file, Plugin Update Checker reads it to
 * describe an update, and it ships in every release archive — so reading the
 * notes from it means they travel with the code they describe and cannot
 * drift from what the update screen says. Nothing here keeps a second copy.
 *
 * The What's new tab shows them, and an admin notice points to it once per
 * person after an update.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Release_Notes
 */
class NPA_Release_Notes {

	/**
	 * User meta: the last version whose notes this person has seen.
	 *
	 * @var string
	 */
	const SEEN_META = 'npa_seen_release_notes';

	/**
	 * Parsed entries, newest first; null until first read.
	 *
	 * @var array<int,array{version:string,body:string}>|null
	 */
	private static $entries = null;

	/**
	 * Every changelog entry, newest first, as Markdown.
	 *
	 * @param string|null $readme Readme text; null reads the bundled file.
	 * @return array<int,array{version:string,body:string}>
	 */
	public static function entries( $readme = null ) {
		if ( null === $readme && null !== self::$entries ) {
			return self::$entries;
		}

		if ( null === $readme ) {
			$path   = NPA_PLUGIN_DIR . 'readme.txt';
			$readme = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled file, not a remote resource.
			$cache  = true;
		}

		$readme = str_replace( array( "\r\n", "\r" ), "\n", (string) $readme );
		$out    = array();

		// The Changelog section runs to the next "== Section ==" or the end.
		if ( preg_match( '/^==\s*Changelog\s*==\s*$(.*?)(?=^==[^=]|\z)/ims', $readme, $section ) ) {
			$parts = preg_split( '/^=\s*([0-9][0-9A-Za-z.\-]*)\s*=\s*$/m', $section[1], -1, PREG_SPLIT_DELIM_CAPTURE );

			for ( $i = 1; $i + 1 < count( $parts ); $i += 2 ) {
				$out[] = array(
					'version' => trim( $parts[ $i ] ),
					'body'    => self::unwrap( trim( $parts[ $i + 1 ] ) ),
				);
			}
		}

		if ( ! empty( $cache ) ) {
			self::$entries = $out;
		}

		return $out;
	}

	/**
	 * The entry for one version, or null.
	 *
	 * @param string $version Version, e.g. "0.16.0".
	 * @return array{version:string,body:string}|null
	 */
	public static function for_version( $version ) {
		foreach ( self::entries() as $entry ) {
			if ( $entry['version'] === $version ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Rejoin readme.txt's hard-wrapped lines.
	 *
	 * The readme is wrapped at 80 columns, so a sentence or a bullet runs over
	 * several lines. The Markdown renderer reads each line as its own block,
	 * which would split a bullet at every wrap. A line continues the one above
	 * unless it is blank or starts a new bullet, numbered item or heading.
	 *
	 * @param string $body Entry text.
	 * @return string
	 */
	public static function unwrap( $body ) {
		$lines = explode( "\n", $body );
		$out   = array();

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			$starts  = '' === $trimmed || preg_match( '/^([-*+]\s|\d+\.\s|#)/', $trimmed );
			$last    = count( $out ) - 1;

			if ( ! $starts && $last >= 0 && '' !== $out[ $last ] ) {
				$out[ $last ] .= ' ' . $trimmed;
			} else {
				$out[] = $trimmed;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Whether this person should see the "updated" notice.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_unseen( $user_id ) {
		return null !== self::for_version( NPA_VERSION )
			&& NPA_VERSION !== (string) get_user_meta( $user_id, self::SEEN_META, true );
	}

	/**
	 * Record that this person has seen the current version's notes.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function mark_seen( $user_id ) {
		if ( $user_id ) {
			update_user_meta( $user_id, self::SEEN_META, NPA_VERSION );
		}
	}
}
