<?php
/**
 * Charts in agent replies (0.16.0).
 *
 * The agent draws nothing itself. It writes a fenced code block whose language
 * is `chart`, holding a small JSON description, and the widget turns that into
 * an SVG chart (assets/js/newtide-public-agent-charts.js). The reply stays
 * plain Markdown the whole way: the server renderer passes the block through
 * as escaped text in `<code class="language-chart">`, so no new tag or
 * attribute reaches the page beyond that one class, and a widget that cannot
 * draw it still shows readable data.
 *
 * What this class owns is teaching the agent the format. There are two routes:
 * the site owner pastes the instructions below into the agent on My Agents, or
 * switches on "Send chart instructions", which adds them to every Agent API
 * request as a system message. The second needs "Accept instructions" enabled
 * on the agent, and only the Agent API transport carries it, which is why it
 * is off by default.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Charts
 */
class NPA_Charts {

	/**
	 * Most series a chart draws. Past four, the colour palette stops being
	 * reliably distinguishable for colour-blind readers, so the widget shows
	 * the data as a table instead.
	 *
	 * @var int
	 */
	const MAX_SERIES = 4;

	/**
	 * Settings.
	 *
	 * @var NPA_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param NPA_Settings $settings Settings.
	 */
	public function __construct( NPA_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'npa_agent_api_body', array( $this, 'add_instructions' ), 5 );
	}

	/**
	 * The instructions an agent needs to draw a chart in the widget.
	 *
	 * English on purpose and not translated: it is read by the agent, not by
	 * a person, and its JSON example has to stay exact.
	 *
	 * @return string
	 */
	public static function instructions() {
		return <<<'TXT'
You can show a chart in your reply when it makes the answer clearer: a trend over time, or a comparison between a few items. Write it as a fenced code block whose language is "chart", containing JSON only, like this:

```chart
{"type": "line", "title": "WTI spot price", "subtitle": "Monthly average, 2026", "prefix": "$", "labels": ["Jan", "Feb", "Mar", "Apr"], "series": [{"name": "WTI", "values": [72.1, 74.3, 71.8, 69.5]}]}
```

Rules:
- "type": "line" for change over time, "bar" to compare categories.
- "labels": the points along the x-axis (line) or the categories (bar). At most 60 for a line chart, 15 for a bar chart.
- "series": 1 to 4 series. Each has a "name" and "values": numbers only, one per label, in the same order. Use null for a missing point.
- Optional: "title", "subtitle", "unit" (for example "thousand barrels"), "prefix" (for example "$"), "suffix" (for example "%").
- One measure per chart. Never put two different units on one chart; use two charts instead.
- Only chart numbers you actually have. Never estimate or invent values to fill a chart.
- Also state the main takeaway in a sentence. The chart supports the answer; it does not replace it.
- Valid JSON: double quotes, no comments, no trailing commas.
TXT;
	}

	/**
	 * Add the instructions to an Agent API request, when switched on.
	 *
	 * Filter callback for `npa_agent_api_body`. Placed first in `messages`, the
	 * position the chat-completions format gives a system message.
	 *
	 * @param array $body Request body.
	 * @return array
	 */
	public function add_instructions( $body ) {
		if ( ! is_array( $body ) || ! $this->settings->get( 'charts' ) || ! $this->settings->get( 'chart_instructions' ) ) {
			return $body;
		}

		$messages = isset( $body['messages'] ) && is_array( $body['messages'] ) ? $body['messages'] : array();

		array_unshift(
			$messages,
			array(
				'role'    => 'system',
				'content' => self::instructions(),
			)
		);

		$body['messages'] = $messages;

		return $body;
	}
}
