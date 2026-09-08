<?php
/**
 * Draw the CatalogOps brand lockup as a raster logo for email.
 *
 * A build-time tool, not part of the shipped plugin. It exists because the brand
 * is only ever defined as SVG plus CSS — inline in the admin app's header
 * (`assets/src/admin/index.js`, the `catalogops-brand` block, styled in
 * `assets/src/admin/style.css:42-81`) — and Gmail, Outlook and Yahoo all strip
 * SVG, inline or linked. A mark that reaches an inbox has to be a PNG.
 *
 * The whole lockup is one image: tile, wordmark and tagline together, exactly as
 * the admin header composes them. Setting the wordmark as HTML text beside the
 * tile would be the usual advice, and it is wrong here — the header's two-tone
 * name and letterspaced uppercase tagline depend on a font stack no mail client
 * guarantees, so the text half would land in whatever Times New Roman the client
 * felt like while the tile stayed pixel-perfect beside it. One image cannot come
 * apart. The cost is that a reader with images off sees the `alt` text, which is
 * why the alt is the product's name and nothing else.
 *
 * Deliberately NOT drawn from `assets/menu-icon.svg`. That is the monochrome
 * variant made to read against the WordPress admin sidebar — a white tile with a
 * dark mark — and on an email's white card the tile disappears entirely.
 *
 * The admin header is the specification. Re-run this if it changes:
 *   php bin/make-email-logo.php
 *
 * Two techniques carry the quality:
 *
 *   - **Supersampling for the tile.** GD antialiases neither polygons nor
 *     ellipses, so the tile is drawn at {@see SS} times size and resampled down.
 *     Text is not supersampled — FreeType hints and antialiases it better at the
 *     final size than any downsample would.
 *   - **A coverage mask for the glyph.** Its three white shapes sit at 95%, 70%
 *     and 45% over a gradient, so there is no single backdrop colour to bake
 *     against. Each is drawn into a greyscale mask at the value its opacity
 *     implies, and one pass blends white in by that mask. This avoids GD's alpha
 *     blending, which darkens every place a stroke's segment overlaps its own
 *     round join and leaves visible knuckles at each corner of the chevrons.
 *
 * @package CatalogOps\Build
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "make-email-logo.php must be run from the command line.\n" );
	exit( 1 );
}

if ( ! function_exists( 'imagettftext' ) ) {
	fwrite( STDERR, "GD with FreeType is required.\n" );
	exit( 1 );
}

/** Everything is drawn at twice its CSS size, for retina. */
const DPR = 2;

/** Supersampling factor for the tile. */
const SS = 8;

/** The mark's coordinate space, from the SVG's viewBox. */
const VIEWBOX = 40;

/** CSS sizes, from style.css:42-81. */
const MARK_CSS  = 40;
const GAP_CSS   = 12;
const NAME_CSS  = 21;
const TAG_CSS   = 12;

/** Brand colours, from style.css:14-17. */
const INK    = array( 0x1d, 0x23, 0x27 );
const ACCENT = array( 0x43, 0x38, 0xca );
const MUTED  = array( 0x64, 0x69, 0x70 );

$font_bold    = 'C:/Windows/Fonts/segoeuib.ttf';
$font_regular = 'C:/Windows/Fonts/segoeui.ttf';

foreach ( array( $font_bold, $font_regular ) as $font ) {
	if ( ! is_readable( $font ) ) {
		fwrite( STDERR, "Font not readable: {$font}\n" );
		exit( 1 );
	}
}

/**
 * Fill a rounded rectangle covering a whole canvas — the tile's silhouette.
 *
 * @param \GdImage $image  Target.
 * @param int      $radius Corner radius in device pixels.
 * @param int      $color  Fill colour.
 */
function co_rounded( \GdImage $image, int $radius, int $color ): void {
	$last = imagesx( $image ) - 1;

	imagefilledrectangle( $image, $radius, 0, $last - $radius, $last, $color );
	imagefilledrectangle( $image, 0, $radius, $last, $last - $radius, $color );

	$d = $radius * 2;

	imagefilledellipse( $image, $radius, $radius, $d, $d, $color );
	imagefilledellipse( $image, $last - $radius, $radius, $d, $d, $color );
	imagefilledellipse( $image, $radius, $last - $radius, $d, $d, $color );
	imagefilledellipse( $image, $last - $radius, $last - $radius, $d, $d, $color );
}

/**
 * Stroke a polyline with round caps and round joins.
 *
 * Each segment is a quadrilateral offset perpendicular by half the stroke width,
 * and every vertex carries a disc. That is what a round cap and a round join are,
 * and drawing them explicitly is why GD's square ends and mitred corners never
 * appear.
 *
 * @param \GdImage                        $image  Target.
 * @param list<array{0: float, 1: float}> $points Vertices in the 40-unit space.
 * @param float                           $width  Stroke width in that space.
 * @param float                           $scale  Device pixels per unit.
 * @param int                             $color  Stroke colour.
 */
function co_polyline( \GdImage $image, array $points, float $width, float $scale, int $color ): void {
	$half = ( $width * $scale ) / 2;

	$device = array_map(
		static fn( array $p ): array => array( $p[0] * $scale, $p[1] * $scale ),
		$points
	);

	$count = count( $device );

	for ( $i = 0; $i < $count - 1; $i++ ) {
		[ $x1, $y1 ] = $device[ $i ];
		[ $x2, $y2 ] = $device[ $i + 1 ];

		$dx     = $x2 - $x1;
		$dy     = $y2 - $y1;
		$length = sqrt( ( $dx * $dx ) + ( $dy * $dy ) );

		if ( $length <= 0.0 ) {
			continue;
		}

		$nx = ( -$dy / $length ) * $half;
		$ny = ( $dx / $length ) * $half;

		imagefilledpolygon(
			$image,
			array(
				(int) round( $x1 + $nx ),
				(int) round( $y1 + $ny ),
				(int) round( $x2 + $nx ),
				(int) round( $y2 + $ny ),
				(int) round( $x2 - $nx ),
				(int) round( $y2 - $ny ),
				(int) round( $x1 - $nx ),
				(int) round( $y1 - $ny ),
			),
			$color
		);
	}

	$d = (int) round( $half * 2 );

	foreach ( $device as [ $x, $y ] ) {
		imagefilledellipse( $image, (int) round( $x ), (int) round( $y ), $d, $d, $color );
	}
}

/**
 * Render the tile — gradient, glyph, rounded silhouette — at the given edge.
 *
 * @param int $edge Output edge in pixels.
 */
function co_tile( int $edge ): \GdImage {
	$size  = $edge * SS;
	$scale = $size / VIEWBOX;
	$px    = static fn( float $v ): int => (int) round( $v * $scale );

	// The 45-degree gradient, matching the header's `catalogops-brand-g`
	// (userSpaceOnUse, 0,0 to 40,40).
	$tile = imagecreatetruecolor( $size, $size );
	$from = array( 0x4f, 0x46, 0xe5 );
	$to   = ACCENT;

	for ( $y = 0; $y < $size; $y++ ) {
		for ( $x = 0; $x < $size; $x++ ) {
			$t = ( $x + $y ) / ( 2 * ( $size - 1 ) );

			imagesetpixel(
				$tile,
				$x,
				$y,
				imagecolorallocate(
					$tile,
					(int) round( $from[0] + ( ( $to[0] - $from[0] ) * $t ) ),
					(int) round( $from[1] + ( ( $to[1] - $from[1] ) * $t ) ),
					(int) round( $from[2] + ( ( $to[2] - $from[2] ) * $t ) )
				)
			);
		}
	}

	// The glyph as a coverage mask: grey level is how much white to mix in.
	$mask = imagecreatetruecolor( $size, $size );
	imagefilledrectangle( $mask, 0, 0, $size - 1, $size - 1, imagecolorallocate( $mask, 0, 0, 0 ) );

	$level = static function ( float $opacity ) use ( $mask ): int {
		$v = (int) round( $opacity * 255 );

		return imagecolorallocate( $mask, $v, $v, $v );
	};

	// The top face: M20 9 L31 15 L20 21 L9 15 Z, at 0.95.
	imagefilledpolygon(
		$mask,
		array( $px( 20 ), $px( 9 ), $px( 31 ), $px( 15 ), $px( 20 ), $px( 21 ), $px( 9 ), $px( 15 ) ),
		$level( 0.95 )
	);

	// The two layers beneath it, stroke width 2.2, at 0.7 and 0.45.
	co_polyline( $mask, array( array( 9, 20 ), array( 20, 26 ), array( 31, 20 ) ), 2.2, $scale, $level( 0.7 ) );
	co_polyline( $mask, array( array( 9, 25 ), array( 20, 31 ), array( 31, 25 ) ), 2.2, $scale, $level( 0.45 ) );

	// Blend white in by the mask.
	for ( $y = 0; $y < $size; $y++ ) {
		for ( $x = 0; $x < $size; $x++ ) {
			$coverage = ( imagecolorat( $mask, $x, $y ) & 0xff ) / 255;

			if ( $coverage <= 0.0 ) {
				continue;
			}

			$rgb = imagecolorat( $tile, $x, $y );

			imagesetpixel(
				$tile,
				$x,
				$y,
				imagecolorallocate(
					$tile,
					(int) round( ( ( ( $rgb >> 16 ) & 0xff ) * ( 1 - $coverage ) ) + ( 255 * $coverage ) ),
					(int) round( ( ( ( $rgb >> 8 ) & 0xff ) * ( 1 - $coverage ) ) + ( 255 * $coverage ) ),
					(int) round( ( ( $rgb & 0xff ) * ( 1 - $coverage ) ) + ( 255 * $coverage ) )
				)
			);
		}
	}

	// Cut the rounded silhouette against white — the colour the email card
	// supplies. A PNG with real transparency would be tidier, but Outlook's
	// handling of one is not worth the corner it would win.
	$canvas = imagecreatetruecolor( $size, $size );
	imagefilledrectangle( $canvas, 0, 0, $size - 1, $size - 1, imagecolorallocate( $canvas, 255, 255, 255 ) );

	$silhouette = imagecreatetruecolor( $size, $size );
	imagefilledrectangle( $silhouette, 0, 0, $size - 1, $size - 1, imagecolorallocate( $silhouette, 0, 0, 0 ) );
	co_rounded( $silhouette, $px( 9 ), imagecolorallocate( $silhouette, 255, 255, 255 ) );

	for ( $y = 0; $y < $size; $y++ ) {
		for ( $x = 0; $x < $size; $x++ ) {
			if ( 0 !== ( imagecolorat( $silhouette, $x, $y ) & 0xff ) ) {
				imagesetpixel( $canvas, $x, $y, imagecolorat( $tile, $x, $y ) );
			}
		}
	}

	$out = imagecreatetruecolor( $edge, $edge );
	imagecopyresampled( $out, $canvas, 0, 0, 0, 0, $edge, $edge, $size, $size );

	foreach ( array( $tile, $mask, $canvas, $silhouette ) as $r ) {
		imagedestroy( $r );
	}

	return $out;
}

/**
 * Draw text one glyph at a time so a tracking value can be applied between them.
 *
 * GD has no letter-spacing, and the tagline's is 0.02em of uppercase — the part
 * that makes it read as a label rather than a sentence.
 *
 * @param \GdImage $image    Target.
 * @param float    $size     Point size.
 * @param int      $x        Left edge.
 * @param int      $y        Baseline.
 * @param int      $color    Colour.
 * @param string   $font     Font path.
 * @param string   $text     The text.
 * @param float    $tracking Extra pixels between glyphs.
 * @return int The x position after the last glyph.
 */
function co_text( \GdImage $image, float $size, int $x, int $y, int $color, string $font, string $text, float $tracking = 0.0 ): int {
	$cursor = (float) $x;

	foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $glyph ) {
		imagettftext( $image, $size, 0, (int) round( $cursor ), $y, $color, $font, $glyph );

		$box     = imagettfbbox( $size, 0, $font, $glyph );
		$cursor += ( $box[2] - $box[0] ) + $tracking;
	}

	return (int) round( $cursor );
}

/**
 * Width of a tracked string, without drawing it.
 *
 * @param float  $size     Point size.
 * @param string $font     Font path.
 * @param string $text     The text.
 * @param float  $tracking Extra pixels between glyphs.
 */
function co_width( float $size, string $font, string $text, float $tracking = 0.0 ): int {
	$width = 0.0;

	foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $glyph ) {
		$box    = imagettfbbox( $size, 0, $font, $glyph );
		$width += ( $box[2] - $box[0] ) + $tracking;
	}

	return (int) ceil( $width );
}

// ---------------------------------------------------------------------------
// Compose the lockup.
// ---------------------------------------------------------------------------

$mark = MARK_CSS * DPR;
$gap  = GAP_CSS * DPR;

// GD's TTF size is in points at 96 DPI in practice; 0.75 converts CSS pixels.
$name_pt = NAME_CSS * DPR * 0.75;
$tag_pt  = TAG_CSS * DPR * 0.75;

$tagline  = strtoupper( 'Bulk catalog operations' );
$tracking = TAG_CSS * DPR * 0.02;

$w_catalog = co_width( $name_pt, $font_bold, 'Catalog' );
$w_ops     = co_width( $name_pt, $font_bold, 'Ops' );
$w_tag     = co_width( $tag_pt, $font_regular, $tagline, $tracking );

$text_width = max( $w_catalog + $w_ops, $w_tag );

$width  = $mark + $gap + $text_width + DPR;
$height = $mark;

$canvas = imagecreatetruecolor( $width, $height );
imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, imagecolorallocate( $canvas, 255, 255, 255 ) );

$tile = co_tile( $mark );
imagecopy( $canvas, $tile, 0, 0, 0, 0, $mark, $mark );
imagedestroy( $tile );

$ink    = imagecolorallocate( $canvas, ...INK );
$accent = imagecolorallocate( $canvas, ...ACCENT );
$muted  = imagecolorallocate( $canvas, ...MUTED );

// Two lines at line-height 1.15, centred as a block against the tile.
$name_box   = imagettfbbox( $name_pt, 0, $font_bold, 'Catalog' );
$name_ascent = abs( $name_box[7] );
$line_gap    = (int) round( NAME_CSS * DPR * 1.15 );
$block       = $line_gap + (int) round( TAG_CSS * DPR * 1.15 );
$top         = (int) round( ( $height - $block ) / 2 );

$text_x   = $mark + $gap;
$baseline = $top + $name_ascent;

$after = co_text( $canvas, $name_pt, $text_x, $baseline, $ink, $font_bold, 'Catalog' );
co_text( $canvas, $name_pt, $after, $baseline, $accent, $font_bold, 'Ops' );

$tag_box    = imagettfbbox( $tag_pt, 0, $font_regular, $tagline );
$tag_ascent = abs( $tag_box[7] );

co_text( $canvas, $tag_pt, $text_x, $top + $line_gap + $tag_ascent, $muted, $font_regular, $tagline, $tracking );

$path = dirname( __DIR__ ) . '/assets/email-logo.png';

if ( ! imagepng( $canvas, $path, 9 ) ) {
	fwrite( STDERR, "Could not write {$path}\n" );
	exit( 1 );
}

imagedestroy( $canvas );

printf(
	"Wrote %s (%d bytes, %dx%d, displays at %dx%d)\n",
	$path,
	(int) filesize( $path ),
	$width,
	$height,
	(int) round( $width / DPR ),
	(int) round( $height / DPR )
);
