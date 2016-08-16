<?php

$versions = json_decode(
	file_get_contents( 'https://api.wordpress.org/core/version-check/1.7/' )
);

$versions = $versions->offers;

// Sorting available WordPress offers by version number
uasort( $versions, function( $first, $second ) {
	return version_compare( $first->version, $second->version, '<' );
} );

$version_stack = array();

foreach( $versions as $offer ) {
	list( $major, $minor, $patch ) = explode( '.',  $offer->version );

	$base = $major . '.' . $minor;

	if (
		! isset( $version_stack[ $base ] )
		|| version_compare( $offer->version, $version_stack[ $base ] ) ) {

		// There is no version like this yet or there is a newer patch to this major version
		$version_stack[ $base ] = $offer->version;
	}

	if ( count( $version_stack ) === 2 ) {
		break;
	}
}

if ( empty( $argv[1] ) ) {
	print array_values( $version_stack )[0] . "\n";
} else if ( '--previous' === $argv[1] ) {
	print array_values(  $version_stack )[1] . "\n";
} else {
	die(
		"Unknown argument: " . $argv[1] . "\n"
		. "Use with no arguments to get the latest stable WordPress version, or use `--previous' to get the previous stable major release.\n"
	);
}