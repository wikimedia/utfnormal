<?php
/**
 * Approximate benchmark for some basic operations.
 * Runs large chunks of text through cleanup with a lowish memory limit,
 * to test regression on mem usage (bug 28146)
 *
 * Copyright © 2004-2011 Brion Vibber <brion@wikimedia.org>
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
 * @ingroup UtfNormal
 */

use UtfNormal\Validator;

if ( PHP_SAPI != 'cli' ) {
	die( "Run me from the command line please.\n" );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'BENCH_CYCLES', 1 );
// 10 M
define( 'BIGSIZE', 1024 * 1024 * 10 );
ini_set( 'memory_limit', BIGSIZE + 120 * 1024 * 1024 );

$testfiles = [
	__DIR__ . '/testdata/washington.txt' => 'English text',
	__DIR__ . '/testdata/berlin.txt' => 'German text',
	__DIR__ . '/testdata/bulgakov.txt' => 'Russian text',
	__DIR__ . '/testdata/tokyo.txt' => 'Japanese text',
	__DIR__ . '/testdata/young.txt' => 'Korean text'
];
$normalizer = new Validator;
Validator::loadData();
foreach ( $testfiles as $file => $desc ) {
	benchmarkTest( $normalizer, $file, $desc );
}

/**
 * @param Validator &$u
 * @param string $filename
 * @param string $desc
 */
function benchmarkTest( &$u, $filename, $desc ) {
	print "Testing $filename ($desc)...\n";
	$data = file_get_contents( $filename );
	$all = $data;
	while ( strlen( $all ) < BIGSIZE ) {
		$all .= $all;
	}
	$data = $all;
	echo "Data is " . strlen( $data ) . " bytes.\n";
	$forms = [
		'quickIsNFCVerify',
		'cleanUp',
	];

	foreach ( $forms as $form ) {
		if ( is_array( $form ) ) {
			$str = $data;
			foreach ( $form as $step ) {
				$str = benchmarkForm( $u, $str, $step );
			}
		} else {
			benchmarkForm( $u, $data, $form );
		}
	}
}

/**
 * @param Validator &$u
 * @param string &$data
 * @param string $form
 * @return string
 */
function benchmarkForm( &$u, &$data, $form ) {
	# $start = microtime( true );
	for ( $i = 0; $i < BENCH_CYCLES; $i++ ) {
		$start = microtime( true );
		$out = $u->$form( $data, Validator::$utfCanonicalDecomp );
		$deltas[] = ( microtime( true ) - $start );
	}
	# $delta = (microtime( true ) - $start) / BENCH_CYCLES;
	sort( $deltas );
	# Take shortest time
	$delta = $deltas[0];

	$rate = intval( strlen( $data ) / $delta );
	$same = ( strcmp( $data, $out ) == 0 );

	printf( " %20s %6.1fms %12s bytes/s (%s)\n",
		$form,
		$delta * 1000.0,
		number_format( $rate ),
		( $same ? 'no change' : 'changed' ) );

	return $out;
}
