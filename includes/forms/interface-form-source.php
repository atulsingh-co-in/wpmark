<?php
/**
 * Form source interface.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * One form plugin, seen through a single shape WPMark's tools understand.
 *
 * Each form plugin (Elementor Forms, WPForms, Contact Form 7, Gravity Forms…)
 * gets one class implementing this interface, and nothing else in WPMark has
 * to change. The tools only ever talk to this interface, never to a form
 * plugin directly.
 *
 * Rules for every implementation:
 *
 * 1. Read only. Never change, delete or mark entries as read.
 * 2. Return values exactly as the visitor typed them. Entry text is
 *    untrusted: a visitor can write anything, including text aimed at the AI
 *    reading it. Do not try to clean it up here. The tool layer frames
 *    every value as data before it reaches the AI, in one place, so it
 *    cannot be forgotten in a single adapter.
 * 3. Give every field a Field_Role, so tools can leave out names, emails
 *    and phone numbers when they do not need them.
 * 4. Respect the query's limit. Never load every entry into memory.
 */
interface Form_Source {

	/**
	 * Short, stable ID for this source, used in tool input and output.
	 *
	 * @return string Lowercase letters, numbers and hyphens, e.g. "wpforms" or "elementor".
	 */
	public function get_id(): string;

	/**
	 * Name shown to people, e.g. "WPForms".
	 *
	 * @return string Translated label.
	 */
	public function get_label(): string;

	/**
	 * Whether the form plugin is installed and active on this site.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Whether the plugin saves submissions that WPMark can read.
	 *
	 * Some plugins only email submissions and keep no copy: Contact Form 7
	 * without Flamingo, or WPForms Lite. Their forms can still be listed,
	 * but there are no entries to read, and tools should say so plainly
	 * rather than report "no leads".
	 *
	 * @return bool
	 */
	public function supports_entries(): bool;

	/**
	 * Every form this plugin has on the site.
	 *
	 * @return Form[]
	 */
	public function list_forms(): array;

	/**
	 * Submissions matching the query, newest first.
	 *
	 * Only called when supports_entries() is true.
	 *
	 * @param Entry_Query $query Which entries to return.
	 * @return Entry[] At most $query->limit entries.
	 */
	public function get_entries( Entry_Query $query ): array;

	/**
	 * How many submissions match the query, ignoring limit and offset.
	 *
	 * Only called when supports_entries() is true.
	 *
	 * @param Entry_Query $query Which entries to count.
	 * @return int
	 */
	public function count_entries( Entry_Query $query ): int;
}
