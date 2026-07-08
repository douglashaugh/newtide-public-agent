<?php
/**
 * Per-day message budget: a courtesy limiter and cost-visibility aid.
 *
 * NOT the abuse defense — the gateway owns that (plan Appendix A). This is a
 * cheap ceiling so a runaway page can't silently rack up calls, plus the "used
 * today" figure the Service Status tab surfaces. A cap of 0 means unlimited.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Budget
 */
class NPA_Budget {

	/**
	 * Settings provider (anything exposing get( $key, $default )).
	 *
	 * @var NPA_Settings
	 */
	private $settings;

	/**
	 * Usage store (anything exposing count_today()).
	 *
	 * @var NPA_Store
	 */
	private $store;

	/**
	 * @param NPA_Settings $settings Config provider.
	 * @param NPA_Store    $store    Usage store.
	 */
	public function __construct( $settings, $store ) {
		$this->settings = $settings;
		$this->store    = $store;
	}

	/**
	 * The configured daily cap (0 = unlimited).
	 *
	 * @return int
	 */
	public function cap() {
		return max( 0, (int) $this->settings->get( 'daily_message_cap', 0 ) );
	}

	/**
	 * Messages recorded so far today.
	 *
	 * @return int
	 */
	public function used_today() {
		return (int) $this->store->count_today();
	}

	/**
	 * Remaining messages before the cap (PHP_INT_MAX when unlimited).
	 *
	 * @return int
	 */
	public function remaining() {
		$cap = $this->cap();
		if ( 0 === $cap ) {
			return PHP_INT_MAX;
		}
		return max( 0, $cap - $this->used_today() );
	}

	/**
	 * Whether today's cap is reached.
	 *
	 * @return bool
	 */
	public function is_exhausted() {
		$cap = $this->cap();
		return $cap > 0 && $this->used_today() >= $cap;
	}

	/**
	 * Status row for the Service Status registry.
	 *
	 * @return array { ok, message }
	 */
	public function status() {
		$cap = $this->cap();
		if ( 0 === $cap ) {
			return array(
				'ok'      => true,
				'message' => __( 'No daily cap set (unlimited).', 'newtide-public-agent' ),
			);
		}

		$used = $this->used_today();
		return array(
			'ok'      => ! $this->is_exhausted(),
			/* translators: 1: messages used today, 2: daily cap. */
			'message' => sprintf( __( '%1$d of %2$d messages used today.', 'newtide-public-agent' ), $used, $cap ),
		);
	}
}
