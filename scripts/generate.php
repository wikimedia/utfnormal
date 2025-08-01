<?php
/**
 * This script generates UniNormalData.inc from the Unicode Character Database
 * and supplementary files.
 *
 * Copyright (C) 2004 Brion Vibber <brion@pobox.com>
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

use UtfNormal\Utils;

if ( PHP_SAPI != 'cli' ) {
	die( "Run me from the command line please.\n" );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * @param string $file
 * @param string $url
 */
function download( $file, $url ) {
	print "Downloading data from $url...\n";
	$fp = fopen( $file, 'w+' );
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_FILE, $fp );
	curl_exec( $ch );
	curl_close( $ch );
	fclose( $fp );
}

/**
 * @param string $file
 * @param string $url
 * @return resource
 */
function getFilePointer( $file, $url ) {
	if ( in_array( '--fetch', $_SERVER['argv'] ) ) {
		download( $file, $url );
	} elseif ( !file_exists( $file ) ) {
		print "Can't open $file for reading.\n";
		print "If necessary, fetch this file from the internet:\n";
		print "$url\n";
		print "Or re-run this script with --fetch\n";
		exit( -1 );
	}

	$fp = fopen( $file, "rt" );
	if ( !$fp ) {
		// Eh?
		print "Can't open $file for reading.\n";
		exit( -1 );
	}

	return $fp;
}

$in = getFilePointer(
	__DIR__ . "/data/DerivedNormalizationProps.txt",
	'http://www.unicode.org/Public/UNIDATA/DerivedNormalizationProps.txt'
);
print "Initializing normalization quick check tables...\n";
$checkNFC = [];
while ( ( $line = fgets( $in ) ) !== false ) {
	$matches = [];
	if ( preg_match(
		'/^([0-9A-F]+)(?:..([0-9A-F]+))?\s*;\s*(NFC_QC)\s*;\s*([MN])/',
		$line,
		$matches )
	) {
		[ $junk, $first, $last, $prop, $value ] = $matches;
		# print "$first $last $prop $value\n";
		if ( !$last ) {
			$last = $first;
		}

		$lastInDecimal = hexdec( $last );
		for ( $i = hexdec( $first ); $i <= $lastInDecimal; $i++ ) {
			$char = Utils::codepointToUtf8( $i );
			$checkNFC[$char] = $value;
		}
	}
}
fclose( $in );

$in = getFilePointer(
	__DIR__ . "/data/CompositionExclusions.txt",
	'http://www.unicode.org/Public/UNIDATA/CompositionExclusions.txt'
);
$exclude = [];
while ( ( $line = fgets( $in ) ) !== false ) {
	if ( preg_match( '/^([0-9A-F]+)/i', $line, $matches ) ) {
		$codepoint = $matches[1];
		$source = Utils::codepointToUtf8( hexdec( $codepoint ) );
		$exclude[$source] = true;
	}
}
fclose( $in );

$in = getFilePointer(
	__DIR__ . "/data/UnicodeData.txt",
	'http://www.unicode.org/Public/UNIDATA/UnicodeData.txt'
);
$compatibilityDecomp = [];
$canonicalDecomp = [];
$canonicalComp = [];
$combiningClass = [];
$total = 0;
$compat = 0;
$canon = 0;
$isCombining = [];
$canPrecedeCombining = [];
$lastCodepoint = 0;
$lastGeneralCategory = '';

print "Reading character definitions...\n";
while ( ( $line = fgets( $in ) ) !== false ) {
	$columns = explode( ';', $line );
	$codepoint = $columns[0];
	$name = $columns[1];
	$generalCategory = $columns[2];
	$canonicalCombiningClass = $columns[3];
	$decompositionMapping = $columns[5];

	$source = Utils::codepointToUtf8( hexdec( $codepoint ) );

	if ( $canonicalCombiningClass != 0 ) {
		$combiningClass[$source] = intval( $canonicalCombiningClass );
	}

	$isRange = str_ends_with( $name, ', Last>' );
	if ( preg_match( '/^(Mc|Mn|Me)$/', $generalCategory ) ) {
		$last = hexdec( $codepoint );
		$first = $isRange ? $lastCodepoint : $last;
		for ( $i = $first; $i <= $last; $i++ ) {
			$isCombining[$i] = true;
		}
	}
	if (
		// Base or Combining character
		preg_match( '/^(L|N|P|S|Zs|M)/', $generalCategory ) ||
		// Zero-Width Non-Joiner
		$source === "\u{200C}" ||
		// Zero-Width Joiner
		$source === "\u{200D}"
	) {
		// Unicode D52 definition of Combining character says this code point
		// is allowed to precede a combining character
		$last = hexdec( $codepoint );
		$first = $isRange ? $lastCodepoint : $last;
		for ( $i = $first; $i <= $last; $i++ ) {
			$canPrecedeCombining[$i] = true;
		}
	}
	$lastCodepoint = hexdec( $codepoint );
	$lastGeneralCategory = $generalCategory;

	if ( $decompositionMapping === '' ) {
		continue;
	}
	if ( preg_match( '/^<(.+)> (.*)$/', $decompositionMapping, $matches ) ) {
		# Compatibility decomposition
		$canonical = false;
		$decompositionMapping = $matches[2];
		$compat++;
	} else {
		$canonical = true;
		$canon++;
	}
	$total++;
	$dest = Utils::hexSequenceToUtf8( $decompositionMapping );

	$compatibilityDecomp[$source] = $dest;
	if ( $canonical ) {
		$canonicalDecomp[$source] = $dest;
		if ( empty( $exclude[$source] ) ) {
			$canonicalComp[$dest] = $source;
		}
	}
	# print "$codepoint | $canonicalCombiningClasses | $decompositionMapping\n";
}
fclose( $in );

print "Recursively expanding canonical mappings...\n";
$changed = 42;
$pass = 1;
while ( $changed > 0 ) {
	print "pass $pass\n";
	$changed = 0;
	foreach ( $canonicalDecomp as $source => $dest ) {
		$newDest = preg_replace_callback(
			'/([\xc0-\xff][\x80-\xbf]+)/',
			static function ( $matches ) use ( &$canonicalDecomp ) {
				if ( isset( $canonicalDecomp[$matches[1]] ) ) {
					return $canonicalDecomp[$matches[1]];
				}

				return $matches[1];
			},
			$dest
		);
		if ( $newDest === $dest ) {
			continue;
		}
		$changed++;
		$canonicalDecomp[$source] = $newDest;
	}
	$pass++;
}

print "Recursively expanding compatibility mappings...\n";
$changed = 42;
$pass = 1;
while ( $changed > 0 ) {
	print "pass $pass\n";
	$changed = 0;
	foreach ( $compatibilityDecomp as $source => $dest ) {
		$newDest = preg_replace_callback(
			'/([\xc0-\xff][\x80-\xbf]+)/',
			static function ( $matches ) use ( &$compatibilityDecomp ) {
				if ( isset( $compatibilityDecomp[$matches[1]] ) ) {
					return $compatibilityDecomp[$matches[1]];
				}

				return $matches[1];
			},
			$dest
		);
		if ( $newDest === $dest ) {
			continue;
		}
		$changed++;
		$compatibilityDecomp[$source] = $newDest;
	}
	$pass++;
}

print "Generating regular expression for isolated combining characters...\n";

function arrayToCharClass( array $a ): string {
	$limit = max( array_keys( $a ) );
	$r = '';
	# iterate through all codepoints
	for ( $i = 0; $i <= $limit; $i++ ) {
		# for each one included in the class...
		if ( $a[$i] ?? false ) {
			# see if we can make a range of other included codepoints.
			for ( $j = $i + 1; ; $j++ ) {
				if ( !( $a[$j] ?? false ) ) {
					break;
				}
			}
			# codepoints [$i, $j) are included
			# generate regex-format character (or range)
			$r .= '\x{' . dechex( $i ) . '}';
			if ( --$j > $i ) {
				$r .= '-';
				$r .= '\x{' . dechex( $j ) . '}';
			}
			$i = $j;
		}
	}
	return $r;
}

# Regexp is zero width so it matches the place where we need to insert a
# base character to un-isolate the combining character.
$isolatedCombiningRegex =
	# we're looking for characters which *can't* precede combining chars...
	'/(?<![' . arrayToCharClass( $canPrecedeCombining ) . '])' .
	# followed by characters which *are* combining chars.
	'(?=[' . arrayToCharClass( $isCombining ) . '])/Su';

print "$total decomposition mappings ($canon canonical, $compat compatibility)\n";
print count( $canPrecedeCombining ) . " codepoints are allowed to precede " .
	count( $isCombining ) . " combining characters\n";

$out = fopen( dirname( __DIR__ ) . "/src/UtfNormalData.inc", "wt" );
if ( $out ) {
	$serCombining = Utils::escapeSingleString( serialize( $combiningClass ) );
	$serComp = Utils::escapeSingleString( serialize( $canonicalComp ) );
	$serCanon = Utils::escapeSingleString( serialize( $canonicalDecomp ) );
	$serCheckNFC = Utils::escapeSingleString( serialize( $checkNFC ) );
	$serIsoRegex = Utils::escapeSingleString( $isolatedCombiningRegex );
	$outdata = "<" . "?php
/**
 * This file was automatically generated -- do not edit!
 * Run scripts/generate.php to create this file again (make clean && make)
 *
 * @file
 */

UtfNormal\Validator::\$utfCombiningClass = unserialize( '$serCombining' );
UtfNormal\Validator::\$utfCanonicalComp = unserialize( '$serComp' );
UtfNormal\Validator::\$utfCanonicalDecomp = unserialize( '$serCanon' );
UtfNormal\Validator::\$utfCheckNFC = unserialize( '$serCheckNFC' );
UtfNormal\Validator::\$utfIsolatedCombiningRegex = '$serIsoRegex';
";
	fputs( $out, $outdata );
	fclose( $out );
	print "Wrote out UtfNormalData.inc\n";
} else {
	print "Can't create file UtfNormalData.inc\n";
	exit( -1 );
}

$out = fopen( dirname( __DIR__ ) . "/src/UtfNormalDataK.inc", "wt" );
if ( $out ) {
	$serCompat = Utils::escapeSingleString( serialize( $compatibilityDecomp ) );
	$outdata = "<" . "?php
/**
 * This file was automatically generated -- do not edit!
 * Run scripts/generate.php to create this file again (make clean && make)
 *
 * @file
 */

UtfNormal\Validator::\$utfCompatibilityDecomp = unserialize( '$serCompat' );
";
	fputs( $out, $outdata );
	fclose( $out );
	print "Wrote out UtfNormalDataK.inc\n";
	exit( 0 );
} else {
	print "Can't create file UtfNormalDataK.inc\n";
	exit( -1 );
}
