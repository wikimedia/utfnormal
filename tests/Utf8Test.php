<?php
use UtfNormal\Validator;

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

/**
 * Runs the UTF-8 decoder test at:
 * http://www.cl.cam.ac.uk/~mgk25/ucs/examples/UTF-8-test.txt
 *
 * @ingroup UtfNormal
 * @group UtfNormal
 */
class Utf8Test extends PHPUnit\Framework\TestCase {
	public static function provideLines() {
		$in = fopen( __DIR__ . '/data/UTF-8-test.txt', "rt" );

		$columns = 0;
		while ( ( $line = fgets( $in ) ) !== false ) {
			$matches = [];
			if ( preg_match( '/^(Here come the tests:\s*)\|$/', $line, $matches ) ) {
				$columns = strpos( $line, '|' );
				break;
			}
		}

		if ( !$columns ) {
			print "Something seems to be wrong; couldn't extract line length.\n";
			print "Check that UTF-8-test.txt was downloaded correctly from\n";
			print "http://www.cl.cam.ac.uk/~mgk25/ucs/examples/UTF-8-test.txt\n";
			exit( -1 );
		}

		$ignore = [
			# These two lines actually seem to be corrupt
			'2.1.1', '2.2.1'
		];

		$exceptions = [
			# Tests that should mark invalid characters due to using long
			# sequences beyond what is now considered legal.
			'2.1.5', '2.1.6', '2.2.4', '2.2.5', '2.2.6', '2.3.5',

			# Literal 0xffff, which is illegal
			'2.2.3'
		];

		$longTests = [
			# These tests span multiple lines
			'3.1.9', '3.2.1', '3.2.2', '3.2.3', '3.2.4', '3.2.5',
			'3.4'
		];

		$testCases = [];

		while ( ( $line = fgets( $in ) ) !== false ) {
			$matches = [];
			if ( preg_match( '/^(\d+)\s+(.*?)\s*\|/', $line, $matches ) ) {
				continue;
			}
			if ( preg_match( '/^(\d+\.\d+\.\d+)\s*/', $line, $matches ) ) {
				$test = $matches[1];

				if ( in_array( $test, $ignore ) ) {
					continue;
				}
				if ( in_array( $test, $longTests ) ) {
					fgets( $in );

					for ( $line = fgets( $in ); !preg_match( '/^\s+\|/', $line ); $line = fgets( $in ) ) {
						$testCases[] = [ $test, $line, $columns, $exceptions ];
					}
				} else {
					$testCases[] = [ $test, $line, $columns, $exceptions ];
				}
			}
		}

		return $testCases;
	}

	/**
	 * @dataProvider provideLines
	 * @covers \UtfNormal\Validator::quickisNFCVerify
	 */
	public function testLine( $test, $line, $columns, $exceptions ) {
		$stripped = $line;
		Validator::quickisNFCVerify( $stripped );

		$same = ( $line == $stripped );
		$len = mb_strlen( substr( $stripped, 0, strpos( $stripped, '|' ) ), 'UTF-8' );
		if ( $len == 0 ) {
			$len = strlen( substr( $stripped, 0, strpos( $stripped, '|' ) ) );
		}

		$ok = $same ^ ( $test >= 3 );

		$ok ^= in_array( $test, $exceptions );

		$ok &= ( $columns == $len );

		$this->assertSame( 1, $ok );
	}

}
