<?php
/**
 * A published agent as returned by list_agents().
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Gateway_Agent
 */
class NPA_Gateway_Agent {

	/**
	 * Construct an agent descriptor.
	 *
	 * @param string $id          Published-agent identifier.
	 * @param string $name        Human-readable agent name.
	 * @param string $description Short description for the admin picker.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name = '',
		public readonly string $description = ''
	) {}
}
