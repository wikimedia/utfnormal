<?php
/**
 * Unicode normalization routines
 *
 * Copyright © 2004 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */
namespace UtfNormal;

use Normalizer;

/**
 * @defgroup UtfNormal UtfNormal
 */

define( 'NORMALIZE_INTL', function_exists( 'normalizer_normalize' ) );

/**
 * Unicode normalization routines for working with UTF-8 strings.
 * Currently, it assumes that input strings are valid UTF-8!
 *
 * Not as fast as I'd like, but should be usable for most purposes.
 * UtfNormal\Validator::toNFC() will bail early if given ASCII text or text
 * it can quickly determine is already normalized.
 *
 * All functions can be called static.
 *
 * See description of forms at http://www.unicode.org/reports/tr15/
 *
 * @ingroup UtfNormal
 */
class Validator {

	/**
	 * @var array
	 */
	public static $utfCombiningClass;

	/**
	 * @var array
	 */
	public static $utfCanonicalComp;

	/**
	 * @var array
	 */
	public static $utfCanonicalDecomp;

	/**
	 * Load compatibility decompositions on demand if they are needed.
	 *
	 * @var array
	 */
	public static $utfCompatibilityDecomp;

	/**
	 * @var array|null
	 */
	public static $utfCheckNFC;

	/**
	 * @var string|null
	 */
	public static $utfIsolatedCombiningRegex;

	/**
	 * The ultimate convenience function! Clean up invalid UTF-8 sequences,
	 * and convert to normal form C, canonical composition, then clean up
	 * isolated combining characters.
	 *
	 * Fast return for pure ASCII strings; some lesser optimizations for
	 * strings containing only known-good characters. Not as fast as toNFC().
	 *
	 * @param string $string a UTF-8 string
	 * @return string a clean, shiny, normalized UTF-8 string
	 */
	public static function cleanUp( $string ) {
		if ( NORMALIZE_INTL ) {
			if ( !preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x80-\xff]/', $string ) ) {
				return $string;
			}
			$string = self::replaceForNativeNormalize( $string );
			$norm = normalizer_normalize( $string, Normalizer::FORM_C );
			if ( $norm === false ) {
				# normalizer_normalize will return false if invalid utf8 string.
				# quickIsNFCVerify cleans up invalid sequences.
				if ( self::quickIsNFCVerify( $string ) ) {
					# if that's true, the string is actually already normal.
					# (and doesn't have any combining characters, so we can
					# skip looking for isolated combining characters)
					return $string;
				} else {
					# Now we are valid but non-normal
					$norm = normalizer_normalize( $string, Normalizer::FORM_C );
				}
			}
			$norm = self::prependIsolatedCombining( $norm );
			return $norm;
		} elseif ( self::quickIsNFCVerify( $string ) ) {
			# Side effect -- $string has had UTF-8 errors cleaned up.
			return $string;
		} else {
			return self::prependIsolatedCombining( self::NFC( $string ) );
		}
	}

	public static function prependIsolatedCombining( string $string ): string {
		self::loadData();
		return preg_replace( self::$utfIsolatedCombiningRegex, "\u{25CC}", $string );
	}

	/**
	 * Convert a UTF-8 string to normal form C, canonical composition.
	 * Fast return for pure ASCII strings; some lesser optimizations for
	 * strings containing only known-good characters.
	 *
	 * @param string $string a valid UTF-8 string. Input is not validated.
	 * @return string a UTF-8 string in normal form C
	 */
	public static function toNFC( $string ) {
		if ( NORMALIZE_INTL ) {
			return normalizer_normalize( $string, Normalizer::FORM_C );
		} elseif ( self::quickIsNFC( $string ) ) {
			return $string;
		} else {
			return self::NFC( $string );
		}
	}

	/**
	 * Convert a UTF-8 string to normal form D, canonical decomposition.
	 * Fast return for pure ASCII strings.
	 *
	 * @param string $string A valid UTF-8 string. Input is not validated.
	 * @return string A UTF-8 string in normal form D
	 */
	public static function toNFD( $string ) {
		if ( NORMALIZE_INTL ) {
			return normalizer_normalize( $string, Normalizer::FORM_D );
		} elseif ( preg_match( '/[\x80-\xff]/', $string ) ) {
			return self::NFD( $string );
		} else {
			return $string;
		}
	}

	/**
	 * Convert a UTF-8 string to normal form KC, compatibility composition.
	 * This may cause irreversible information loss, use judiciously.
	 * Fast return for pure ASCII strings.
	 *
	 * @param string $string A valid UTF-8 string. Input is not validated.
	 * @return string A UTF-8 string in normal form KC
	 */
	public static function toNFKC( $string ) {
		if ( NORMALIZE_INTL ) {
			return normalizer_normalize( $string, Normalizer::FORM_KC );
		} elseif ( preg_match( '/[\x80-\xff]/', $string ) ) {
			return self::NFKC( $string );
		} else {
			return $string;
		}
	}

	/**
	 * Convert a UTF-8 string to normal form KD, compatibility decomposition.
	 * This may cause irreversible information loss, use judiciously.
	 * Fast return for pure ASCII strings.
	 *
	 * @param string $string a valid UTF-8 string. Input is not validated.
	 * @return string a UTF-8 string in normal form KD
	 */
	public static function toNFKD( $string ) {
		if ( NORMALIZE_INTL ) {
			return normalizer_normalize( $string, Normalizer::FORM_KD );
		} elseif ( preg_match( '/[\x80-\xff]/', $string ) ) {
			return self::NFKD( $string );
		} else {
			return $string;
		}
	}

	/**
	 * Load the basic composition data if necessary
	 */
	public static function loadData() {
		// @phan-suppress-next-line MediaWikiNoIssetIfDefined
		if ( !isset( self::$utfCombiningClass ) ) {
			require_once __DIR__ . '/UtfNormalData.inc';
		}
	}

	/**
	 * Returns true if the string is _definitely_ in NFC.
	 * Returns false if not or uncertain.
	 * @param string $string a valid UTF-8 string. Input is not validated.
	 * @return bool
	 */
	public static function quickIsNFC( $string ) {
		# ASCII is always valid NFC!
		# If it's pure ASCII, let it through.
		if ( !preg_match( '/[\x80-\xff]/', $string ) ) {
			return true;
		}

		self::loadData();

		$len = strlen( $string );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $string[$i];
			$n = ord( $c );
			if ( $n < 0x80 ) {
				continue;
			} elseif ( $n >= 0xf0 ) {
				$c = substr( $string, $i, 4 );
				$i += 3;
			} elseif ( $n >= 0xe0 ) {
				$c = substr( $string, $i, 3 );
				$i += 2;
			} elseif ( $n >= 0xc0 ) {
				$c = substr( $string, $i, 2 );
				$i++;
			}
			if ( isset( self::$utfCheckNFC[$c] ) ) {
				# If it's NO or MAYBE, bail and do the slow check.
				return false;
			}
			if ( isset( self::$utfCombiningClass[$c] ) ) {
				# Combining character? We might have to do sorting, at least.
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns true if the string is _definitely_ in NFC.
	 * Returns false if not or uncertain.
	 * @param string &$string A UTF-8 string, altered on output to be valid UTF-8 safe for XML.
	 * @return bool
	 */
	public static function quickIsNFCVerify( &$string ) {
		# Screen out some characters that eg won't be allowed in XML
		$string = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', Constants::UTF8_REPLACEMENT, $string );

		# ASCII is always valid NFC!
		# If we're only ever given plain ASCII, we can avoid the overhead
		# of initializing the decomposition tables by skipping out early.
		if ( !preg_match( '/[\x80-\xff]/', $string ) ) {
			return true;
		}

		static $checkit = null, $tailBytes = null, $utfCheckOrCombining = null;
		if ( $checkit === null ) {
			# Load/build some scary lookup tables...
			self::loadData();

			$utfCheckOrCombining = array_merge( self::$utfCheckNFC, self::$utfCombiningClass );

			# Head bytes for sequences which we should do further validity checks
			$checkit = array_flip( array_map( 'chr',
				[ 0xc0, 0xc1, 0xe0, 0xed, 0xef,
					0xf0, 0xf1, 0xf2, 0xf3, 0xf4, 0xf5, 0xf6, 0xf7,
					0xf8, 0xf9, 0xfa, 0xfb, 0xfc, 0xfd, 0xfe, 0xff ] ) );

			# Each UTF-8 head byte is followed by a certain
			# number of tail bytes.
			$tailBytes = [];
			for ( $n = 0; $n < 256; $n++ ) {
				if ( $n < 0xc0 ) {
					$remaining = 0;
				} elseif ( $n < 0xe0 ) {
					$remaining = 1;
				} elseif ( $n < 0xf0 ) {
					$remaining = 2;
				} elseif ( $n < 0xf8 ) {
					$remaining = 3;
				} elseif ( $n < 0xfc ) {
					$remaining = 4;
				} elseif ( $n < 0xfe ) {
					$remaining = 5;
				} else {
					$remaining = 0;
				}
				$tailBytes[chr( $n )] = $remaining;
			}
		}

		# Chop the text into pure-ASCII and non-ASCII areas;
		# large ASCII parts can be handled much more quickly.
		# Don't chop up Unicode areas for punctuation, though,
		# that wastes energy.
		$matches = [];
		preg_match_all(
			'/([\x00-\x7f]+|[\x80-\xff][\x00-\x40\x5b-\x5f\x7b-\xff]*)/',
			$string, $matches );

		$looksNormal = true;
		$base = 0;
		$replace = [];
		foreach ( $matches[1] as $str ) {
			$chunk = strlen( $str );

			if ( $str[0] < "\x80" ) {
				# ASCII chunk: guaranteed to be valid UTF-8
				# and in normal form C, so skip over it.
				$base += $chunk;
				continue;
			}

			# We'll have to examine the chunk byte by byte to ensure
			# that it consists of valid UTF-8 sequences, and to see
			# if any of them might not be normalized.

			# Since PHP is not the fastest language on earth, some of
			# this code is a little ugly with inner loop optimizations.

			$head = '';
			# Counting down is faster. I'm *so* sorry.
			$len = $chunk + 1;

			for ( $i = -1; --$len; ) {
				$remaining = $tailBytes[$c = $str[++$i]];
				if ( $remaining ) {
					# UTF-8 head byte!
					$sequence = $head = $c;
					do {
						# Look for the defined number of tail bytes...
						if ( --$len && ( $c = $str[++$i] ) >= "\x80" && $c < "\xc0" ) {
							# Legal tail bytes are nice.
							$sequence .= $c;
						} elseif ( $len === 0 ) {
							# Premature end of string!
							# Drop a replacement character into output to
							# represent the invalid UTF-8 sequence.
							$replace[] = [ Constants::UTF8_REPLACEMENT,
								$base + $i + 1 - strlen( $sequence ),
								strlen( $sequence ) ];
							break 2;
						} else {
							# Illegal tail byte; abandon the sequence.
							$replace[] = [ Constants::UTF8_REPLACEMENT,
								$base + $i - strlen( $sequence ),
								strlen( $sequence ) ];
							# Back up and reprocess this byte; it may itself
							# be a legal ASCII or UTF-8 sequence head.
							--$i;
							++$len;
							continue 2;
						}
					} while ( --$remaining );

					if ( isset( $checkit[$head] ) ) {
						# Do some more detailed validity checks, for
						# invalid characters and illegal sequences.
						if ( $head == "\xed" ) {
							# 0xed is relatively frequent in Korean, which
							# abuts the surrogate area, so we're doing
							# this check separately to speed things up.

							if ( $sequence >= Constants::UTF8_SURROGATE_FIRST ) {
								# Surrogates are legal only in UTF-16 code.
								# They are totally forbidden here in UTF-8
								# utopia.
								$replace[] = [ Constants::UTF8_REPLACEMENT,
									$base + $i + 1 - strlen( $sequence ),
									strlen( $sequence ) ];
								$head = '';
								continue;
							}
						} else {
							# Slower, but rarer checks...
							$n = ord( $head );
							if (
								# "Overlong sequences" are those that are syntactically
								# correct but use more UTF-8 bytes than are necessary to
								# encode a character. Naïve string comparisons can be
								# tricked into failing to see a match for an ASCII
								# character, for instance, which can be a security hole
								# if lists of excluded characters are being used.
								( $n < 0xc2 && $sequence <= Constants::UTF8_OVERLONG_A )
								|| ( $n == 0xe0 && $sequence <= Constants::UTF8_OVERLONG_B )
								|| ( $n == 0xf0 && $sequence <= Constants::UTF8_OVERLONG_C )

								# U+FFFE and U+FFFF are explicitly forbidden in Unicode.
								|| ( $n == 0xef &&
									( $sequence == Constants::UTF8_FFFE
									|| $sequence == Constants::UTF8_FFFF ) )

								# Unicode has been limited to 21 bits; longer
								# sequences are not allowed.
								|| ( $n >= 0xf0 && $sequence > Constants::UTF8_MAX )
							) {
								$replace[] = [ Constants::UTF8_REPLACEMENT,
									$base + $i + 1 - strlen( $sequence ),
									strlen( $sequence ) ];
								$head = '';
								continue;
							}
						}
					}

					if ( isset( $utfCheckOrCombining[$sequence] ) ) {
						# If it's NO or MAYBE, we'll have to rip
						# the string apart and put it back together.
						# That's going to be mighty slow.
						$looksNormal = false;
					}

					# The sequence is legal!
					$head = '';
				} elseif ( $c < "\x80" ) {
					# ASCII byte.
					$head = '';
				} elseif ( $c < "\xc0" ) {
					# Illegal tail bytes
					if ( $head == '' ) {
						# Out of the blue!
						$replace[] = [ Constants::UTF8_REPLACEMENT, $base + $i, 1 ];
					} else {
						# Don't add if we're continuing a broken sequence;
						# we already put a replacement character when we looked
						# at the broken sequence.
						$replace[] = [ '', $base + $i, 1 ];
					}
				} else {
					# Miscellaneous freaks.
					$replace[] = [ Constants::UTF8_REPLACEMENT, $base + $i, 1 ];
					$head = '';
				}
			}
			$base += $chunk;
		}
		if ( count( $replace ) ) {
			# There were illegal UTF-8 sequences we need to fix up.
			$out = '';
			$last = 0;
			foreach ( $replace as $rep ) {
				[ $replacement, $start, $length ] = $rep;
				if ( $last < $start ) {
					$out .= substr( $string, $last, $start - $last );
				}
				$out .= $replacement;
				$last = $start + $length;
			}
			if ( $last < strlen( $string ) ) {
				$out .= substr( $string, $last );
			}
			$string = $out;
		}

		return $looksNormal;
	}

	# These take a string and run the normalization on them, without
	# checking for validity or any optimization etc. Input must be
	# VALID UTF-8!

	/**
	 * @param string $string
	 * @return string
	 */
	public static function NFC( $string ) {
		return self::fastCompose( self::NFD( $string ) );
	}

	/**
	 * @param string $string
	 * @return string
	 */
	public static function NFD( $string ) {
		self::loadData();

		return self::fastCombiningSort(
			self::fastDecompose( $string, self::$utfCanonicalDecomp )
		);
	}

	/**
	 * @param string $string
	 * @return string
	 */
	public static function NFKC( $string ) {
		return self::fastCompose( self::NFKD( $string ) );
	}

	/**
	 * @param string $string
	 * @return string
	 */
	public static function NFKD( $string ) {
		// @phan-suppress-next-line MediaWikiNoIssetIfDefined
		if ( !isset( self::$utfCompatibilityDecomp ) ) {
			require_once __DIR__ . '/UtfNormalDataK.inc';
		}

		return self::fastCombiningSort(
			self::fastDecompose( $string, self::$utfCompatibilityDecomp ) );
	}

	/**
	 * Perform decomposition of a UTF-8 string into either D or KD form
	 * (depending on which decomposition map is passed to us).
	 * Input is assumed to be *valid* UTF-8. Invalid code will break.
	 * @param string $string valid UTF-8 string
	 * @param array $map hash of expanded decomposition map
	 * @return string a UTF-8 string decomposed, not yet normalized (needs sorting)
	 */
	public static function fastDecompose( $string, $map ) {
		self::loadData();

		$len = strlen( $string );
		$out = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $string[$i];
			$n = ord( $c );
			if ( $n < 0x80 ) {
				# ASCII chars never decompose
				# THEY ARE IMMORTAL
				$out .= $c;
				continue;
			} elseif ( $n >= 0xf0 ) {
				$c = substr( $string, $i, 4 );
				$i += 3;
			} elseif ( $n >= 0xe0 ) {
				$c = substr( $string, $i, 3 );
				$i += 2;
			} elseif ( $n >= 0xc0 ) {
				$c = substr( $string, $i, 2 );
				$i++;
			}
			if ( isset( $map[$c] ) ) {
				$out .= $map[$c];
				continue;
			} else {
				if ( $c >= Constants::UTF8_HANGUL_FIRST && $c <= Constants::UTF8_HANGUL_LAST ) {
					# Decompose a hangul syllable into jamo;
					# hardcoded for three-byte UTF-8 sequence.
					# A lookup table would be slightly faster,
					# but adds a lot of memory & disk needs.
					$index = ( ( ord( $c[0] ) & 0x0f ) << 12
							| ( ord( $c[1] ) & 0x3f ) << 6
							| ( ord( $c[2] ) & 0x3f ) )
						- Constants::UNICODE_HANGUL_FIRST;
					$l = intval( $index / Constants::UNICODE_HANGUL_NCOUNT );
					$v = intval(
						( $index % Constants::UNICODE_HANGUL_NCOUNT )
						/ Constants::UNICODE_HANGUL_TCOUNT
					);
					$t = $index % Constants::UNICODE_HANGUL_TCOUNT;
					$out .= "\xe1\x84" . chr( 0x80 + $l ) . "\xe1\x85" . chr( 0xa1 + $v );
					if ( $t >= 25 ) {
						$out .= "\xe1\x87" . chr( 0x80 + $t - 25 );
					} elseif ( $t ) {
						$out .= "\xe1\x86" . chr( 0xa7 + $t );
					}
					continue;
				}
			}
			$out .= $c;
		}

		return $out;
	}

	/**
	 * Sorts combining characters into canonical order. This is the
	 * final step in creating decomposed normal forms D and KD.
	 * @param string $string a valid, decomposed UTF-8 string. Input is not validated.
	 * @return string a UTF-8 string with combining characters sorted in canonical order
	 */
	public static function fastCombiningSort( $string ) {
		self::loadData();

		$len = strlen( $string );
		$out = '';
		$combiners = [];
		$lastClass = -1;
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $string[$i];
			$n = ord( $c );
			if ( $n >= 0x80 ) {
				if ( $n >= 0xf0 ) {
					$c = substr( $string, $i, 4 );
					$i += 3;
				} elseif ( $n >= 0xe0 ) {
					$c = substr( $string, $i, 3 );
					$i += 2;
				} elseif ( $n >= 0xc0 ) {
					$c = substr( $string, $i, 2 );
					$i++;
				}
				if ( isset( self::$utfCombiningClass[$c] ) ) {
					$lastClass = self::$utfCombiningClass[$c];
					if ( isset( $combiners[$lastClass] ) ) {
						$combiners[$lastClass] .= $c;
					} else {
						$combiners[$lastClass] = $c;
					}
					continue;
				}
			}
			if ( $lastClass ) {
				ksort( $combiners );
				$out .= implode( '', $combiners );
				$combiners = [];
			}
			$out .= $c;
			$lastClass = 0;
		}
		if ( $lastClass ) {
			ksort( $combiners );
			$out .= implode( '', $combiners );
		}

		return $out;
	}

	/**
	 * Produces canonically composed sequences, i.e. normal form C or KC.
	 *
	 * @param string $string a valid UTF-8 string in sorted normal form D or KD.
	 *   Input is not validated.
	 * @return string a UTF-8 string with canonical precomposed characters used
	 *   where possible.
	 */
	public static function fastCompose( $string ) {
		self::loadData();

		$len = strlen( $string );
		$out = '';
		$lastClass = -1;
		$lastHangul = 0;
		$startChar = '';
		$combining = '';

		// Optim: ord() ignores everything after the first byte
		$x1 = ord( Constants::UTF8_HANGUL_VBASE );
		$x2 = ord( Constants::UTF8_HANGUL_TEND );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $string[$i];
			$n = ord( $c );
			if ( $n < 0x80 ) {
				# No combining characters here...
				$out .= $startChar;
				$out .= $combining;
				$startChar = $c;
				$combining = '';
				$lastClass = 0;
				continue;
			} elseif ( $n >= 0xf0 ) {
				$c = substr( $string, $i, 4 );
				$i += 3;
			} elseif ( $n >= 0xe0 ) {
				$c = substr( $string, $i, 3 );
				$i += 2;
			} elseif ( $n >= 0xc0 ) {
				$c = substr( $string, $i, 2 );
				$i++;
			}
			$pair = $startChar . $c;
			if ( $n > 0x80 && isset( self::$utfCombiningClass[$c] ) ) {
				# A combining char; see what we can do with it
				$class = self::$utfCombiningClass[$c];
				if ( $startChar !== '' &&
					$lastClass < $class &&
					$class > 0 &&
					isset( self::$utfCanonicalComp[$pair] )
				) {
					$startChar = self::$utfCanonicalComp[$pair];
					$class = 0;
				} else {
					$combining .= $c;
				}
				$lastClass = $class;
				$lastHangul = 0;
				continue;
			}
			# New start char
			if ( $lastClass === 0 ) {
				if ( isset( self::$utfCanonicalComp[$pair] ) ) {
					$startChar = self::$utfCanonicalComp[$pair];
					$lastHangul = 0;
					continue;
				}
				if ( $n >= $x1 && $n <= $x2 ) {
					# WARNING: Hangul code is painfully slow.
					# I apologize for this ugly, ugly code; however
					# performance is even more teh suck if we call
					# out to nice clean functions. Lookup tables are
					# marginally faster, but require a lot of space.
					if ( $c >= Constants::UTF8_HANGUL_VBASE &&
						$c <= Constants::UTF8_HANGUL_VEND &&
						$startChar >= Constants::UTF8_HANGUL_LBASE &&
						$startChar <= Constants::UTF8_HANGUL_LEND
					) {
						# $lIndex = utf8ToCodepoint( $startChar ) - UNICODE_HANGUL_LBASE;
						# $vIndex = utf8ToCodepoint( $c ) - UNICODE_HANGUL_VBASE;
						$lIndex = ord( $startChar[2] ) - 0x80;
						$vIndex = ord( $c[2] ) - 0xa1;

						$hangulPoint = Constants::UNICODE_HANGUL_FIRST +
							Constants::UNICODE_HANGUL_TCOUNT *
							( Constants::UNICODE_HANGUL_VCOUNT * $lIndex + $vIndex );

						# Hardcode the limited-range UTF-8 conversion:
						$startChar = chr( $hangulPoint >> 12 & 0x0f | 0xe0 ) .
							chr( $hangulPoint >> 6 & 0x3f | 0x80 ) .
							chr( $hangulPoint & 0x3f | 0x80 );
						$lastHangul = 0;
						continue;
					} elseif ( $c >= Constants::UTF8_HANGUL_TBASE &&
						$c <= Constants::UTF8_HANGUL_TEND &&
						$startChar >= Constants::UTF8_HANGUL_FIRST &&
						$startChar <= Constants::UTF8_HANGUL_LAST &&
						!$lastHangul
					) {
						# $tIndex = utf8ToCodepoint( $c ) - UNICODE_HANGUL_TBASE;
						$tIndex = ord( $c[2] ) - 0xa7;
						if ( $tIndex < 0 ) {
							$tIndex = ord( $c[2] ) - 0x80 + ( 0x11c0 - 0x11a7 );
						}

						# Increment the code point by $tIndex, without
						# the function overhead of decoding and recoding UTF-8
						$tail = ord( $startChar[2] ) + $tIndex;
						if ( $tail > 0xbf ) {
							$tail -= 0x40;
							$mid = ord( $startChar[1] ) + 1;
							if ( $mid > 0xbf ) {
								$startChar[0] = chr( ord( $startChar[0] ) + 1 );
								$mid -= 0x40;
							}
							$startChar[1] = chr( $mid );
						}
						$startChar[2] = chr( $tail );

						# If there's another jamo char after this, *don't* try to merge it.
						$lastHangul = 1;
						continue;
					}
				}
			}
			$out .= $startChar;
			$out .= $combining;
			$startChar = $c;
			$combining = '';
			$lastClass = 0;
			$lastHangul = 0;
		}
		$out .= $startChar . $combining;

		return $out;
	}

	/**
	 * This is just used for the benchmark, comparing how long it takes to
	 * interate through a string without really doing anything of substance.
	 * @param string $string
	 * @return string
	 */
	public static function placebo( $string ) {
		$len = strlen( $string );
		$out = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $string[$i];
		}

		return $out;
	}

	/**
	 * Function to replace some characters that we don't want
	 * but most of the native normalize functions keep.
	 *
	 * @param string $string The string
	 * @return string String with the character codes replaced.
	 */
	private static function replaceForNativeNormalize( $string ) {
		$string = preg_replace(
			'/[\x00-\x08\x0b\x0c\x0e-\x1f]/',
			Constants::UTF8_REPLACEMENT,
			$string
		);
		return str_replace( [ Constants::UTF8_FFFE, Constants::UTF8_FFFF ], Constants::UTF8_REPLACEMENT, $string );
	}
}
