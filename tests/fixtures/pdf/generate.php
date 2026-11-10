<?php

declare(strict_types=1);

/**
 * Writes the synthetic PDFs PrinsPdfReaderTest reads. Run it from the repo root
 * and commit its output alongside it:
 *
 *     php tests/fixtures/pdf/generate.php
 *
 * The real tickets the reader was tuned on carry a name and live booking
 * references, so they never enter the repository. These files rebuild what
 * mattered about them instead: text placed at exact positions, with the gaps
 * measured on the real thing.
 *
 * Every piece of text is drawn at an absolute position (`Tm`) in Helvetica, one
 * of the 14 standard fonts, so nothing is embedded and the widths are the
 * published ones below. The encrypted variants need Ghostscript (`gs`).
 */

const SIZE = 12.0;
const DIR = __DIR__;

/** Helvetica advance widths (AFM), per 1000 em, for the characters used here. */
const WIDTHS = [
	' ' => 278, ':' => 278, '.' => 278, '-' => 333, '/' => 278, '(' => 333, ')' => 333,
	'0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
	'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278,
	'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722,
	'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
	'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222,
	'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333,
	's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
];

function width(string $text): float {
	$units = 0;
	foreach (str_split($text) as $char) {
		$units += WIDTHS[$char] ?? throw new RuntimeException('No width for "' . $char . '"');
	}
	return $units * SIZE / 1000;
}

/** One piece of text at an absolute position. */
function piece(float $x, float $y, string $text): string {
	$escaped = strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
	return sprintf("BT /F1 %.1F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", SIZE, $x, $y, $escaped);
}

/**
 * A line drawn as separate pieces, each gap given in ems after the previous
 * piece's end — the construction of the Amadeus e-ticket, which draws every
 * word on its own and never draws a space.
 *
 * @param list<string|float> $parts text, then gap, then text, ...
 */
function line(float $x, float $y, array $parts): string {
	$content = '';
	foreach ($parts as $part) {
		if (is_float($part)) {
			$x += $part * SIZE;
			continue;
		}
		$content .= piece($x, $y, $part);
		$x += width($part);
	}
	return $content;
}

/** @param list<string> $pageContents one content stream per page */
function pdf(array $pageContents): string {
	$objects = [];
	$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
	$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
	$kids = [];
	$next = 4;
	foreach ($pageContents as $content) {
		$page = $next++;
		$stream = $next++;
		$kids[] = $page . ' 0 R';
		$objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
			. '/Resources << /Font << /F1 3 0 R >> >> /Contents ' . $stream . ' 0 R >>';
		$objects[$stream] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
	}
	$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
	ksort($objects);

	$out = "%PDF-1.4\n";
	$offsets = [];
	foreach ($objects as $number => $body) {
		$offsets[$number] = strlen($out);
		$out .= $number . " 0 obj\n" . $body . "\nendobj\n";
	}
	$xref = strlen($out);
	$out .= 'xref' . "\n" . '0 ' . (count($objects) + 1) . "\n" . "0000000000 65535 f \n";
	foreach ($offsets as $offset) {
		$out .= sprintf("%010d 00000 n \n", $offset);
	}
	$out .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	return $out;
}

function write(string $name, string $pdf): void {
	file_put_contents(DIR . '/' . $name, $pdf);
	echo 'wrote ', $name, "\n";
}

// Words drawn separately with no space glyphs, at the gaps measured on the
// Amadeus e-ticket. The flight row's last gap is a column break.
$words = line(50, 760, ['Booking', 0.32, 'ref', 0.02, ':', 0.35, 'DM4R6O'])
	. line(50, 740, ['MH1204', 0.32, '06', 0.05, ':', 0.06, '40', 4.69, '07', 0.05, ':', 0.06, '45']);
write('words.pdf', pdf([$words]));

// The narrowest column gap measured (1.14 em, a boarding pass's luggage line)
// against the widest word gap (0.43 em), and a table row whose two columns are
// each one piece containing its own spaces.
$columns = line(50, 760, ['Small soft bag', 0.43, 'or', 1.14, 'Hold Luggage'])
	. piece(50, 740, 'Charleroi Airport (CRL)') . piece(330, 740, 'Ghent');
write('columns.pdf', pdf([$columns]));

// No text at all: a filled rectangle, as a scanned page is an image.
write('no-text.pdf', pdf(["0 0 0 rg 100 100 200 200 re f\n"]));

write('three-pages.pdf', pdf([
	piece(50, 760, 'Page one'),
	piece(50, 760, 'Page two'),
	piece(50, 760, 'Page three'),
]));

// Encrypted twice over: once needing a password to open, once only restricting
// what a reader may do, which every viewer opens without asking.
$source = DIR . '/columns.pdf';
$gs = 'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dEncryptionR=3 -dKeyLength=128';
foreach ([
	'encrypted-password.pdf' => '-sOwnerPassword=owner -sUserPassword=secret',
	'encrypted-owner-only.pdf' => '-sOwnerPassword=owner -dPermissions=-3904',
] as $name => $options) {
	$command = $gs . ' ' . $options . ' -sOutputFile=' . escapeshellarg(DIR . '/' . $name) . ' ' . escapeshellarg($source);
	exec($command, $output, $status);
	if ($status !== 0) {
		throw new RuntimeException('Ghostscript failed for ' . $name);
	}
	echo 'wrote ', $name, "\n";
}
