<?php
declare( strict_types = 1 );

namespace UtfNormal;

use InvalidArgumentException;

/**
 * Copyright © 2004 Brooke Vibber <bvibber@pobox.com>
 * https://www.mediawiki.org/
 *
 * @license GPL-2.0-or-later
 * @file
 */

/**
 * Some of these functions are adapted from places in MediaWiki.
 * Should probably merge them for consistency.
 *
 * @ingroup UtfNormal
 */
class Utils {
	/**
	 * Return UTF-8 sequence for a given Unicode code point.
	 *
	 * @param int $codepoint
	 * @return string
	 * @throws InvalidArgumentException if fed out of range data.
	 */
	public static function codepointToUtf8( $codepoint ) {
		// In PHP 7.2, mb_chr is buggy when $codepoint is 0 (null byte)
		if ( $codepoint === 0 ) {
			return "\u{0000}";
		}
		// In PHP >=7.4, mb_chr fails when $codepoint is in surrogate range
		// U+D800 - U+DBFF / U+DC00 - U+DFFF
		if ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF ) {
			// UTF-8 encoding of the codepoint, the hard way.
			return (
				chr( 0xED ) .
				chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) ) .
				chr( 0x80 | ( $codepoint & 0x3F ) )
			);
		}
		$char = mb_chr( $codepoint );
		if ( $char === false ) {
			throw new InvalidArgumentException( "Asked for code outside of range ($codepoint)" );
		}

		return $char;
	}

	/**
	 * Take a series of space-separated hexadecimal numbers representing
	 * Unicode code points and return a UTF-8 string composed of those
	 * characters. Used by UTF-8 data generation and testing routines.
	 *
	 * @param string $sequence
	 * @return string
	 * @throws InvalidArgumentException if fed out of range data.
	 * @private Used in tests and data table generation
	 */
	public static function hexSequenceToUtf8( $sequence ) {
		$utf = '';
		foreach ( explode( ' ', $sequence ) as $hex ) {
			$n = hexdec( $hex );
			$utf .= self::codepointToUtf8( $n );
		}

		return $utf;
	}

	/**
	 * Take a UTF-8 string and return a space-separated series of hex
	 * numbers representing Unicode code points. For debugging.
	 *
	 * @param string $str UTF-8 string.
	 * @return string
	 * @private
	 */
	private static function utf8ToHexSequence( $str ) {
		$buf = '';
		foreach ( preg_split( '//u', $str, -1, PREG_SPLIT_NO_EMPTY ) as $cp ) {
			$buf .= sprintf( '%04x ', mb_ord( $cp ) );
		}

		return rtrim( $buf );
	}

	/**
	 * Determine the Unicode codepoint of a single-character UTF-8 sequence.
	 * Does not check for invalid input data.
	 *
	 * @deprecated since 2.1, use mb_ord()
	 *
	 * @param string $char
	 * @return int|false
	 */
	public static function utf8ToCodepoint( $char ) {
		return mb_strlen( $char ) > 1 ? false : mb_ord( $char );
	}

	/**
	 * Escape a string for inclusion in a PHP single-quoted string literal.
	 *
	 * @param string $string String to be escaped.
	 * @return string Escaped string.
	 */
	public static function escapeSingleString( $string ) {
		return strtr(
			$string,
			[
				'\\' => '\\\\',
				'\'' => '\\\''
			]
		);
	}
}
