<?php
/**
 * WordPress Beta Tester
 *
 * @package WordPress_Beta_Tester
 * @author Andy Fragen, original author Peter Westwood.
 * @license GPLv2+
 * @copyright 2009-2016 Peter Westwood (email : peter.westwood@ftwr.co.uk)
 */

/**
 * WPBT_Version_Compare
 *
 * Pre-release-aware version parsing and comparison.
 *
 * Supports the de-facto dot-org pre-release tagging conventions observed in the
 * wild, e.g. `9.9.0-beta.1`, `9.9.0-beta2`, `3.0.0-RC1`, `10.0-rc.2`, `1.2-alpha`,
 * `1.0-dev`. PHP's native `version_compare()` cannot reliably order mixed
 * pre-release suffix styles (`-RC1` vs `-beta.1`), so this class implements a
 * deterministic ordering instead.
 *
 * Ordering rules, for identical version bases:
 *  - stable (no suffix) sorts highest;
 *  - `dev < alpha < beta < pre/preview < rc`;
 *  - higher build number sorts higher within the same stage.
 */
class WPBT_Version_Compare {
	/**
	 * Stage ranking. Higher number = closer to stable.
	 *
	 * @var array
	 */
	const STAGE_ORDER = array(
		'dev'     => 1,
		'alpha'   => 2,
		'a'       => 2,
		'beta'    => 3,
		'b'       => 3,
		'pre'     => 4,
		'preview' => 4,
		'rc'      => 5,
	);

	/**
	 * Parse a version string into base, stage and build.
	 *
	 * Returns null when the string is not a recognisable dotted version,
	 * e.g. `trunk`, `v1.2`, `1.2.3.4.5`, `1.2-free`. Returns a null stage
	 * (and zero build) for stable versions.
	 *
	 * @param string $version Version string, e.g. `9.9.0-beta.1` or `11.1.2`.
	 * @return array|null {
	 *     @type string     $base  Base version, e.g. `9.9.0`.
	 *     @type string|null $stage Lowercase pre-release stage or null for stable.
	 *     @type int        $build Pre-release build number, 0 when absent.
	 * }
	 */
	public static function parse( $version ) {
		$version = trim( (string) $version );

		// Anything other than a dotted numeric base is not supported.
		if ( ! preg_match(
			'/^(\d+(?:\.\d+){0,3})(?:[.\-_]?(dev|alpha|a|beta|b|pre|preview|rc)[.\-_]?(\d*))?$/i',
			$version,
			$m
		) ) {
			return null;
		}

		$stage = isset( $m[2] ) ? strtolower( $m[2] ) : null;
		$build = ( ! empty( $m[3] ) && is_numeric( $m[3] ) ) ? (int) $m[3] : 0;

		return array(
			'base'  => $m[1],
			'stage' => $stage,
			'build' => $build,
		);
	}

	/**
	 * Is the version a pre-release (has a stage suffix)?
	 *
	 * @param string $version Version string.
	 * @return bool
	 */
	public static function is_prerelease( $version ) {
		$parsed = self::parse( $version );

		return null !== $parsed && null !== $parsed['stage'];
	}

	/**
	 * Compare two version strings, pre-release aware.
	 *
	 * Falls back to `version_compare()` when either input cannot be parsed.
	 *
	 * @param string $a First version.
	 * @param string $b Second version.
	 * @return int -1, 0 or 1 as $a is less than, equal to, or greater than $b.
	 */
	public static function compare( $a, $b ) {
		$pa = self::parse( $a );
		$pb = self::parse( $b );

		// Fall back to native comparison for unparseable input.
		if ( null === $pa || null === $pb ) {
			return version_compare( (string) $a, (string) $b );
		}

		$base = self::compare_bases( $pa['base'], $pb['base'] );
		if ( 0 !== $base ) {
			return $base;
		}

		// Same base: stable beats any pre-release.
		if ( null === $pa['stage'] && null === $pb['stage'] ) {
			return 0;
		}
		if ( null === $pa['stage'] ) {
			return 1;
		}
		if ( null === $pb['stage'] ) {
			return -1;
		}

		// Different stages.
		$order = self::STAGE_ORDER;
		if ( $order[ $pa['stage'] ] !== $order[ $pb['stage'] ] ) {
			return $order[ $pa['stage'] ] < $order[ $pb['stage'] ] ? -1 : 1;
		}

		// Same stage: compare build numbers.
		if ( $pa['build'] !== $pb['build'] ) {
			return $pa['build'] < $pb['build'] ? -1 : 1;
		}

		return 0;
	}

	/**
	 * Compare two dotted numeric bases, ignoring trailing `.0` segments.
	 *
	 * `9.9` and `9.9.0` are treated as equal; otherwise numeric segment
	 * comparison applies (`9.9 < 9.10 < 10.0`).
	 *
	 * @param string $base_a First base.
	 * @param string $base_b Second base.
	 * @return int -1, 0 or 1.
	 */
	private static function compare_bases( $base_a, $base_b ) {
		$segs_a = self::canonical_base( $base_a );
		$segs_b = self::canonical_base( $base_b );
		$count  = max( count( $segs_a ), count( $segs_b ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$seg_a = isset( $segs_a[ $i ] ) ? (int) $segs_a[ $i ] : 0;
			$seg_b = isset( $segs_b[ $i ] ) ? (int) $segs_b[ $i ] : 0;
			if ( $seg_a !== $seg_b ) {
				return $seg_a < $seg_b ? -1 : 1;
			}
		}

		return 0;
	}

	/**
	 * Split a dotted base into segments, dropping trailing zeros.
	 *
	 * @param string $base Base version, e.g. `9.9.0`.
	 * @return array Array of integer segments, e.g. `[9, 9]`.
	 */
	private static function canonical_base( $base ) {
		$segs = array_map( 'intval', explode( '.', (string) $base ) );

		// Drop trailing zeros as long as more than one segment remains.
		while ( true ) {
			$last = end( $segs );
			if ( 1 === count( $segs ) || 0 !== $last ) {
				break;
			}
			array_pop( $segs );
		}

		return $segs;
	}
}
