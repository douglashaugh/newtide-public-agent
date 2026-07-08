<?php
/**
 * Dependency + version manifest for the block editor script.
 *
 * Hand-authored (no build step): declares the WordPress editor packages the
 * buildless index.js relies on, so WordPress enqueues them as dependencies.
 *
 * @package NewTide\PublicAgent
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-i18n',
	),
	'version'      => '0.1.0',
);
