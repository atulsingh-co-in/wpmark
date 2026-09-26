<?php
/**
 * Where WPMark's help pages live.
 *
 * @package WPMark
 */

namespace WPMark;

defined( 'ABSPATH' ) || exit;

/**
 * Web addresses shown in the WPMark screens, kept in one place.
 */
final class Links {

	/**
	 * WPMark's home page: download, beta sign-up and news.
	 */
	public const HOME = 'https://atulsingh.co.in/wpmark/';

	/**
	 * The developer's site.
	 */
	public const AUTHOR = 'https://atulsingh.co.in';

	/**
	 * The public source code and documentation.
	 */
	public const REPOSITORY = 'https://github.com/atulsingh-co-in/wpmark';

	/**
	 * Where to report a problem or suggest an idea.
	 */
	public const ISSUES = self::REPOSITORY . '/issues';

	/**
	 * A page in the documentation.
	 *
	 * @param string $page File name in the docs folder, such as "getting-started.md".
	 * @return string
	 */
	public static function doc( string $page ): string {
		return self::REPOSITORY . '/blob/main/docs/' . $page;
	}
}
