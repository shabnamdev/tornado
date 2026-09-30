<?php
/**
 * JSON, CSV and minimal PDF report export.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Report;

use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use InvalidArgumentException;

final class ReportExporter {
	public function __construct( private readonly JobRepository $jobs ) {}

	/**
	 * @return array{content:string,mime:string,filename:string}
	 */
	public function export( string $uuid, string $format ): array {
		if ( ! wp_is_uuid( $uuid ) ) {
			throw new InvalidArgumentException( 'Invalid report UUID.' );
		}

		$job = $this->jobs->find( $uuid );
		if ( null === $job ) {
			throw new InvalidArgumentException( 'Report not found.' );
		}

		$format = strtolower( sanitize_key( $format ) );
		$data   = $this->normalize( $job );

		return match ( $format ) {
			'json' => array(
				'content'  => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'mime'     => 'application/json; charset=utf-8',
				'filename' => 'shcd-tornado-dbm-report-' . $uuid . '.json',
			),
			'csv' => array(
				'content'  => $this->csv( $data ),
				'mime'     => 'text/csv; charset=utf-8',
				'filename' => 'shcd-tornado-dbm-report-' . $uuid . '.csv',
			),
			'pdf' => array(
				'content'  => $this->pdf( $data ),
				'mime'     => 'application/pdf',
				'filename' => 'shcd-tornado-dbm-report-' . $uuid . '.pdf',
			),
			default => throw new InvalidArgumentException( 'Unsupported report format.' ),
		};
	}

	/**
	 * @param array<string, mixed> $job Job data.
	 * @return array<string, mixed>
	 */
	private function normalize( array $job ): array {
		unset( $job['error_message'] );
		return $job;
	}

	/**
	 * @param array<string, mixed> $data Report data.
	 */
	private function csv( array $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory temporary stream used to generate CSV output.
		$stream = fopen( 'php://temp', 'w+b' );
		if ( false === $stream ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes to php://temp, not the server filesystem.
		fwrite( $stream, "\xEF\xBB\xBF" );
		fputcsv( $stream, array( 'Path', 'Value' ) );
		foreach ( $this->flatten( $data ) as $path => $value ) {
			fputcsv( $stream, array( $path, $value ) );
		}
		rewind( $stream );
		$content = stream_get_contents( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the php://temp stream.
		fclose( $stream );
		return is_string( $content ) ? $content : '';
	}

	/**
	 * Minimal valid PDF with ASCII-safe report lines.
	 *
	 * @param array<string, mixed> $data Report data.
	 */
	private function pdf( array $data ): string {
		$lines = array( 'Tornado Report', str_repeat( '-', 44 ) );
		foreach ( $this->flatten( $data ) as $path => $value ) {
			$line    = $path . ': ' . $value;
			$line    = preg_replace( '/[^\x20-\x7E]/', '?', $line ) ?? '';
			$lines[] = substr( $line, 0, 105 );
		}
		$lines = array_slice( $lines, 0, 52 );

		$content = "BT\n/F1 9 Tf\n50 790 Td\n12 TL\n";
		foreach ( $lines as $index => $line ) {
			$escaped = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $line );
			$content .= ( 0 === $index ? '' : "T*\n" ) . '(' . $escaped . ") Tj\n";
		}
		$content .= "ET\n";

		$objects = array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			4 => '<< /Length ' . strlen( $content ) . ">>\nstream\n" . $content . 'endstream',
			5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		);

		$pdf     = "%PDF-1.4\n";
		$offsets = array( 0 );
		foreach ( $objects as $id => $object ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $object . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 6\n0000000000 65535 f \n";
		for ( $id = 1; $id <= 5; ++$id ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] );
		}
		$pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
		return $pdf;
	}

	/**
	 * @param array<string, mixed> $data Nested values.
	 * @return array<string, string>
	 */
	private function flatten( array $data, string $prefix = '' ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				$result += $this->flatten( $value, $path );
			} elseif ( is_bool( $value ) ) {
				$result[ $path ] = $value ? 'true' : 'false';
			} elseif ( null === $value ) {
				$result[ $path ] = 'null';
			} else {
				$result[ $path ] = (string) $value;
			}
		}
		return $result;
	}
}
