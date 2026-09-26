<?php
/**
 * Invalid input exception.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a tool is asked for something it cannot do.
 *
 * The message is shown to the AI (and often read out to the user), so it
 * must be plain language and say how to fix the request.
 */
final class Invalid_Input extends InvalidArgumentException {
}
