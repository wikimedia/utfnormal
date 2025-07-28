<?php
/**
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

use UtfNormal\Constants;
use UtfNormal\Utils;
use UtfNormal\Validator;

/**
 * Implements the conformance test at:
 * http://www.unicode.org/Public/UNIDATA/NormalizationTest.txt
 *
 * @ingroup UtfNormal
 * @group UtfNormal
 * @group large
 */
class UtfNormalTest extends PHPUnit\Framework\TestCase {

	/**
	 * @var array
	 */
	protected static $testedChars = [];

	public static function provideNormalizationTest() {
		$in = fopen( __DIR__ . '/data/NormalizationTest.txt', "rt" );

		$testCases = [];
		while ( ( $line = fgets( $in ) ) !== false ) {
			[ $data, $comment ] = explode( '#', $line );
			if ( $data === '' ) {
				continue;
			}
			$matches = [];
			if ( preg_match( '/@Part([\d])/', $data, $matches ) ) {
				continue;
			}

			$columns = array_map(
				[ Utils::class, 'hexSequenceToUtf8' ],
				explode( ";", $data )
			);
			array_unshift( $columns, '' );

			self::$testedChars[$columns[1]] = true;
			$testCases[] = [ $columns, $comment ];
		}
		fclose( $in );

		return [ [ $testCases ] ];
	}

	private function assertStringEquals( $a, $b, $desc ) {
		$this->assertSame( 0, strcmp( $a, $b ), $desc );
	}

	private function assertNFC( $c, $desc ) {
		$this->assertStringEquals( $c[2], Validator::toNFC( $c[1] ), $desc );
		$this->assertStringEquals( $c[2], Validator::toNFC( $c[2] ), $desc );
		$this->assertStringEquals( $c[2], Validator::toNFC( $c[3] ), $desc );
		$this->assertStringEquals( $c[4], Validator::toNFC( $c[4] ), $desc );
		$this->assertStringEquals( $c[4], Validator::toNFC( $c[5] ), $desc );
	}

	private function assertNFD( $c, $desc ) {
		$this->assertStringEquals( $c[3], Validator::toNFD( $c[1] ), $desc );
		$this->assertStringEquals( $c[3], Validator::toNFD( $c[2] ), $desc );
		$this->assertStringEquals( $c[3], Validator::toNFD( $c[3] ), $desc );
		$this->assertStringEquals( $c[5], Validator::toNFD( $c[4] ), $desc );
		$this->assertStringEquals( $c[5], Validator::toNFD( $c[5] ), $desc );
	}

	private function assertNFKC( $c, $desc ) {
		$this->assertStringEquals( $c[4], Validator::toNFKC( $c[1] ), $desc );
		$this->assertStringEquals( $c[4], Validator::toNFKC( $c[2] ), $desc );
		$this->assertStringEquals( $c[4], Validator::toNFKC( $c[3] ), $desc );
		$this->assertStringEquals( $c[4], Validator::toNFKC( $c[4] ), $desc );
		$this->assertStringEquals( $c[4], Validator::toNFKC( $c[5] ), $desc );
	}

	private function assertNFKD( $c, $desc ) {
		$this->assertStringEquals( $c[5], Validator::toNFKD( $c[1] ), $desc );
		$this->assertStringEquals( $c[5], Validator::toNFKD( $c[2] ), $desc );
		$this->assertStringEquals( $c[5], Validator::toNFKD( $c[3] ), $desc );
		$this->assertStringEquals( $c[5], Validator::toNFKD( $c[4] ), $desc );
		$this->assertStringEquals( $c[5], Validator::toNFKD( $c[5] ), $desc );
	}

	private function assertCleanUp( $c, $desc ) {
		$this->assertStringEquals( $c[2], Validator::cleanUp( $c[1] ), $desc );
		$this->assertStringEquals( $c[2], Validator::cleanUp( $c[2] ), $desc );
		$this->assertStringEquals( $c[2], Validator::cleanUp( $c[3] ), $desc );
		$this->assertStringEquals( $c[4], Validator::cleanUp( $c[4] ), $desc );
		$this->assertStringEquals( $c[4], Validator::cleanUp( $c[5] ), $desc );
	}

	/**
	 * The data provider for this intentionally returns all the
	 * test cases as one since PHPUnit is too slow otherwise
	 *
	 * @dataProvider provideNormalizationTest
	 * @coversNothing
	 */
	public function testNormals( $testCases ) {
		foreach ( $testCases as $case ) {
			$c = $case[0];
			$desc = $case[1];
			$this->assertNFC( $c, $desc );
			$this->assertNFD( $c, $desc );
			$this->assertNFKC( $c, $desc );
			$this->assertNFKD( $c, $desc );
			$this->assertCleanUp( $c, $desc );
		}
	}

	public static function provideUnicodeData() {
		$in = fopen( __DIR__ . '/data/UnicodeData.txt', "rt" );
		$testCases = [];
		while ( ( $line = fgets( $in ) ) !== false ) {
			$cols = explode( ';', $line );
			try {
				$char = Utils::codepointToUtf8( hexdec( $cols[0] ) );
			} catch ( InvalidArgumentException $ex ) {
				// Skip codes out of range
				continue;
			}
			$desc = $cols[0] . ": " . $cols[1];
			if ( $char < "\x20" ||
				( $char >= Constants::UTF8_SURROGATE_FIRST && $char <= Constants::UTF8_SURROGATE_LAST )
			) {
				# Can't check NULL with the ICU plugin, as null bytes fail in C land.
				# Skip other control characters, as we strip them for XML safety.
				# Surrogates are illegal on their own or in UTF-8, ignore.
				continue;
			}
			if ( empty( self::$testedChars[$char] ) ) {
				$testCases[] = [ $char, $desc ];
			}
		}
		fclose( $in );

		return [ [ $testCases ] ];
	}

	/**
	 * The data provider for this intentionally returns all the
	 * test cases as one since PHPUnit is too slow otherwise
	 *
	 * @depends testNormals
	 * @dataProvider provideUnicodeData
	 * @coversNothing
	 */
	public function testInvariant( $testCases ) {
		foreach ( $testCases as $case ) {
			$char = $case[0];
			$desc = $case[1];
			$this->assertStringEquals( $char, Validator::toNFC( $char ), $desc );
			$this->assertStringEquals( $char, Validator::toNFD( $char ), $desc );
			$this->assertStringEquals( $char, Validator::toNFKC( $char ), $desc );
			$this->assertStringEquals( $char, Validator::toNFKD( $char ), $desc );
			$this->assertStringEquals( $char, Validator::cleanUp( $char ), $desc );
		}
	}
}
