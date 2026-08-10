<?php
/**
 * A single lexical token of a formula.
 *
 * @package CatalogOps\Operations\Formula
 */

namespace CatalogOps\Operations\Formula;

/**
 * The flat output of the {@see Lexer}: a number, a name (variable or function —
 * the parser decides by whether a `(` follows), one of the arithmetic operators,
 * a parenthesis, or an argument comma. Whitespace never survives lexing, so the
 * parser can look at the next token without skipping over gaps.
 */
final class Token {

	public const NUMBER = 'number';
	public const NAME   = 'name';
	public const OP     = 'op';
	public const LPAREN = 'lparen';
	public const RPAREN = 'rparen';
	public const COMMA  = 'comma';

	/**
	 * Build a token.
	 *
	 * @param string $type  One of the type constants.
	 * @param string $value The literal text (the number, name, or operator symbol).
	 * @param int    $pos   Zero-based offset in the source, for error messages.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $value,
		public readonly int $pos,
	) {}
}
