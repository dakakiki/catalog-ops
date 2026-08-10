<?php
/**
 * Raised when a formula cannot be parsed or is otherwise malformed.
 *
 * @package CatalogOps\Operations\Formula
 */

namespace CatalogOps\Operations\Formula;

use InvalidArgumentException;

/**
 * A formula that does not tokenize, balance, or type-check is rejected at build
 * time — never at execution time, and never with {@see eval()} (CONTEXT §2, rule
 * 6). Extending {@see InvalidArgumentException} lets it travel the same path as
 * every other bad-input error: {@see \CatalogOps\Operations\Actions\Action_Factory}
 * lets it bubble, and the REST boundary turns it into a 400 with the message.
 *
 * The message names what is wrong ("unknown function", "unbalanced parentheses")
 * so the person writing the formula can fix it, since the formula is authored in
 * the UI, not the code.
 */
final class Formula_Error extends InvalidArgumentException {}
