<?php
/**
 * MCP service key.
 *
 * One key per site, not one per person. The agent is the only consumer of this
 * server, so per-user credentials would be machinery serving nobody: there is no
 * human to attribute a call to, and no second consumer to revoke independently.
 *
 * Provisioned automatically rather than by an admin action. The MCP server is
 * what lets the agent read the site it is deployed on, so a site that activates
 * this plugin and finds no key has an agent that cannot see anything — a setup
 * step nobody would know they had missed.
 *
 * Follows the gateway_key precedent: an NPA_MCP_KEY constant overrides the
 * stored value for sites that keep credentials out of the database. The
 * difference is that this key must stay readable in admin, because it is pasted
 * into the agent platform's connector configuration by hand.
 *
 * @package NewTide_Public_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates, stores and verifies the single MCP service key.
 */
class NPA_MCP_Key {

	/**
	 * Option holding the generated key.
	 *
	 * Deliberately its own row rather than a field inside npa_options: the
	 * settings row is rewritten wholesale on every save, and a credential that
	 * can be lost to an unrelated form submission is a credential that will be.
	 *
	 * @var string
	 */
	const OPTION = 'npa_mcp_key';

	/**
	 * Prefix, so a leaked key is identifiable on sight.
	 *
	 * @var string
	 */
	const PREFIX = 'npa_mcp_';

	/**
	 * Return the active key, generating one if the site has none.
	 *
	 * @return string
	 */
	public function get() {
		if ( defined( 'NPA_MCP_KEY' ) && '' !== (string) NPA_MCP_KEY ) {
			return (string) NPA_MCP_KEY;
		}

		$stored = (string) get_option( self::OPTION, '' );

		if ( '' === $stored ) {
			$stored = $this->rotate();
		}

		return $stored;
	}

	/**
	 * Whether the key comes from a constant rather than the database.
	 *
	 * The admin screen uses this to explain why rotation is unavailable.
	 *
	 * @return bool
	 */
	public function is_constant() {
		return defined( 'NPA_MCP_KEY' ) && '' !== (string) NPA_MCP_KEY;
	}

	/**
	 * Generate and store a new key, invalidating the previous one immediately.
	 *
	 * @return string The new key.
	 */
	public function rotate() {
		$key = self::PREFIX . bin2hex( random_bytes( 20 ) );

		update_option( self::OPTION, $key, false );

		return $key;
	}

	/**
	 * Verify a presented credential.
	 *
	 * Constant-time comparison: a plain === leaks the position of the first
	 * differing byte through timing, which is enough to recover a key given
	 * patience and an endpoint that answers quickly.
	 *
	 * @param string $presented Credential supplied by the caller.
	 * @return bool
	 */
	public function verify( $presented ) {
		$presented = is_string( $presented ) ? trim( $presented ) : '';

		if ( '' === $presented ) {
			return false;
		}

		return hash_equals( $this->get(), $presented );
	}

	/**
	 * Read the credential out of a request.
	 *
	 * Three accepted locations, in descending order of safety. The query
	 * parameter exists because hosted agent platforms commonly configure an MCP
	 * connector with a URL and nothing else — no field for a header — and a
	 * server that only accepts headers simply cannot be used from them.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string Empty string when absent.
	 */
	public function from_request( WP_REST_Request $request ) {
		$header = $request->get_header( 'X-NPA-MCP-Key' );

		if ( is_string( $header ) && '' !== $header ) {
			return trim( $header );
		}

		$auth = $request->get_header( 'Authorization' );

		if ( is_string( $auth ) && 0 === stripos( $auth, 'Bearer ' ) ) {
			return trim( substr( $auth, 7 ) );
		}

		$param = $request->get_param( 'api_key' );

		return is_string( $param ) ? trim( $param ) : '';
	}
}
