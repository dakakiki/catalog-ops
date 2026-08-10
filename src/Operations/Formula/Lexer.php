<?php
/**
 * Turns a formula string into a flat list of tokens.
 *
 * @package CatalogOps\Operations\Formula
 */

namespace CatalogOps\Operations\Formula;

/**
 * The first stage of the shunting-yard pipeline (CONTEXT §2, rule 6): scan the
 * source once, left to right, and emit {@see Token}s. It recognizes decimal
 * numbers, names (letters, digits, and underscores, not starting with a digit),
 * the four arithmetic operators, parentheses, and commas; it discards whitespace
 * and rejects any other character.
 *
 * The lexer is deliberately dumb: it does not know which names are variables and
 * which are functions, nor whether a `-` is unary — those are the parser's job.
 */
final class Lexer {

	/**
	 * Scan the source into tokens.
	 *
	 * @param string $source The formula text.
	 * @return list<Token>
	 *
	 * @throws Formula_Error When an unexpected character is found.
	 */
	public function tokenize( string $source ): array {
		$tokens = array();
		$length = strlen( $source );
		$i      = 0;

		while ( $i < $length ) {
			$char = $source[ $i ];

			if ( ctype_space( $char ) ) {
				++$i;
				continue;
			}

			if ( ctype_digit( $char ) || ( '.' === $char && $i + 1 < $length && ctype_digit( $source[ $i + 1 ] ) ) ) {
				$number   = $this->read_number( $source, $length, $i );
				$tokens[] = new Token( Token::NUMBER, $number, $i );
				$i       += strlen( $number );
				continue;
			}

			if ( ctype_alpha( $char ) || '_' === $char ) {
				$name     = $this->read_name( $source, $length, $i );
				$tokens[] = new Token( Token::NAME, $name, $i );
				$i       += strlen( $name );
				continue;
			}

			$single = $this->single_char_token( $char, $i );

			if ( null === $single ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
				throw new Formula_Error( sprintf( 'Unexpected character "%s" in formula.', $char ) );
			}

			$tokens[] = $single;
			++$i;
		}

		return $tokens;
	}

	/**
	 * Read a decimal number literal starting at $i (digits with at most one dot).
	 *
	 * @param string $source Source text.
	 * @param int    $length Source length.
	 * @param int    $i      Start offset.
	 *
	 * @throws Formula_Error When the number has more than one decimal point.
	 */
	private function read_number( string $source, int $length, int $i ): string {
		$start   = $i;
		$has_dot = false;

		while ( $i < $length ) {
			$char = $source[ $i ];

			if ( ctype_digit( $char ) ) {
				++$i;
				continue;
			}

			if ( '.' === $char ) {
				if ( $has_dot ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
					throw new Formula_Error( 'A number cannot contain two decimal points.' );
				}
				$has_dot = true;
				++$i;
				continue;
			}

			break;
		}

		return substr( $source, $start, $i - $start );
	}

	/**
	 * Read a name (variable or function) starting at $i.
	 *
	 * @param string $source Source text.
	 * @param int    $length Source length.
	 * @param int    $i      Start offset.
	 */
	private function read_name( string $source, int $length, int $i ): string {
		$start = $i;

		while ( $i < $length && ( ctype_alnum( $source[ $i ] ) || '_' === $source[ $i ] ) ) {
			++$i;
		}

		return substr( $source, $start, $i - $start );
	}

	/**
	 * Map a single character to its token, or null when it is not a valid one.
	 *
	 * @param string $char The character.
	 * @param int    $pos  Its offset, for the token.
	 */
	private function single_char_token( string $char, int $pos ): ?Token {
		return match ( $char ) {
			'+', '-', '*', '/' => new Token( Token::OP, $char, $pos ),
			'('                => new Token( Token::LPAREN, $char, $pos ),
			')'                => new Token( Token::RPAREN, $char, $pos ),
			','                => new Token( Token::COMMA, $char, $pos ),
			default            => null,
		};
	}
}
