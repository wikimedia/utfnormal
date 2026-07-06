<?php
declare( strict_types = 1 );

/**
 * Approximate benchmark for some basic operations.
 *
 * Copyright © 2004 Brooke Vibber <bvibber@pobox.com>
 * https://www.mediawiki.org/
 *
 * @license GPL-2.0-or-later
 * @file
 * @ingroup UtfNormal
 */

use UtfNormal\Validator;

if ( PHP_SAPI != 'cli' ) {
	die( "Run me from the command line please.\n" );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'BENCH_CYCLES', 5 );

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
	$forms = [
# 		'placebo',
		'cleanUp',
		'toNFC',
# 		'toNFKC',
# 		'toNFD', 'toNFKD',
		'NFC',
# 		'NFKC',
# 		'NFD', 'NFKD',
		[ 'fastDecompose', 'fastCombiningSort', 'fastCompose' ],
# 		'quickIsNFC', 'quickIsNFCVerify',
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
