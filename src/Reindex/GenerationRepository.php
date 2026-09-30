<?php
/**
 * Compact lineage ledger for repeatable Reindex generations.
 *
 * The ledger deliberately lives in one bounded option instead of another
 * row-heavy table. Only one compact record is stored per real Reindex run.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Reindex;

final class GenerationRepository {
	private const OPTION = 'shcd_tornado_dbm_reindex_generations';
	private const MAX_HISTORY = 10;
	private const PROTECTED_MAPPINGS = 3;

	/** @return list<array<string,mixed>> */
	public function history(): array {
		$value = get_option( self::OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array_values(
			array_filter(
				$value,
				static fn( mixed $row ): bool => is_array( $row ) && isset( $row['job_uuid'], $row['generation_no'] )
			)
		);
		usort(
			$rows,
			static fn( array $a, array $b ): int => (int) ( $a['generation_no'] ?? 0 ) <=> (int) ( $b['generation_no'] ?? 0 )
		);
		return array_slice( $rows, -self::MAX_HISTORY );
	}

	/** @return array<string,mixed>|null */
	public function latest(): ?array {
		$rows = $this->history();
		return empty( $rows ) ? null : $rows[ count( $rows ) - 1 ];
	}

	public function recovery_required(): bool {
		$latest = $this->latest();
		return is_array( $latest ) && 'post_commit_failed' === (string) ( $latest['status'] ?? '' );
	}

	/** @return array<string,mixed>|null */
	public function latest_completed(): ?array {
		$rows = array_reverse( $this->history() );
		foreach ( $rows as $row ) {
			if ( 'completed' === (string) ( $row['status'] ?? '' ) && ! empty( $row['verified'] ) ) {
				return $row;
			}
		}
		return null;
	}

	/** @return array<string,mixed> */
	public function preview( string $source_fingerprint, int $row_count, int $gap_count ): array {
		$latest = $this->latest_completed();
		return array(
			'generation_no'         => null === $latest ? 1 : (int) $latest['generation_no'] + 1,
			'parent_generation_uuid'=> null === $latest ? '' : (string) ( $latest['job_uuid'] ?? '' ),
			'parent_mapping_uuid'   => null === $latest ? '' : (string) ( $latest['mapping_uuid'] ?? '' ),
			'repeated_reindex'      => null !== $latest,
			'source_fingerprint'    => $source_fingerprint,
			'source_row_count'      => max( 0, $row_count ),
			'source_gap_count'      => max( 0, $gap_count ),
			'previous_verified'     => null === $latest || ! empty( $latest['verified'] ),
		);
	}

	/** @param array<string,mixed> $source_state @return array<string,mixed> */
	public function begin( string $job_uuid, string $mapping_uuid, string $backup_uuid, array $source_state ): array {
		$preview = $this->preview(
			(string) ( $source_state['post_id_fingerprint'] ?? '' ),
			(int) ( $source_state['row_count'] ?? 0 ),
			(int) ( $source_state['gap_count'] ?? 0 )
		);
		$row = array_merge(
			$preview,
			array(
				'job_uuid'                  => $job_uuid,
				'mapping_uuid'              => $mapping_uuid,
				'backup_uuid'               => $backup_uuid,
				'status'                    => 'running',
				'verified'                  => false,
				'source_schema_fingerprint' => (string) ( $source_state['schema_fingerprint'] ?? '' ),
				'started_at'                => current_time( 'mysql', true ),
				'completed_at'              => '',
			)
		);
		$this->upsert( $row );
		return $row;
	}

	/** @param array<string,mixed> $target_state */
	public function complete( string $job_uuid, array $target_state ): void {
		$rows = $this->history();
		foreach ( $rows as &$row ) {
			if ( hash_equals( (string) ( $row['job_uuid'] ?? '' ), $job_uuid ) ) {
				$row['status']                    = 'completed';
				$row['verified']                  = true;
				$row['target_post_id_fingerprint']= (string) ( $target_state['post_id_fingerprint'] ?? '' );
				$row['target_schema_fingerprint'] = (string) ( $target_state['schema_fingerprint'] ?? '' );
				$row['target_row_count']          = (int) ( $target_state['row_count'] ?? 0 );
				$row['target_gap_count']          = (int) ( $target_state['gap_count'] ?? 0 );
				$row['target_min_id']             = (int) ( $target_state['min_id'] ?? 0 );
				$row['target_max_id']             = (int) ( $target_state['max_id'] ?? 0 );
				$row['target_auto_increment']     = (int) ( $target_state['auto_increment'] ?? 0 );
				$row['completed_at']              = current_time( 'mysql', true );
				break;
			}
		}
		unset( $row );
		$this->save( $rows );
		update_option( 'shcd_tornado_dbm_last_generation_uuid', $job_uuid, false );
	}

	public function fail( string $job_uuid, string $message, bool $committed ): void {
		$rows = $this->history();
		foreach ( $rows as &$row ) {
			if ( hash_equals( (string) ( $row['job_uuid'] ?? '' ), $job_uuid ) ) {
				$row['status']       = $committed ? 'post_commit_failed' : 'rolled_back';
				$row['verified']     = false;
				$row['error_message']= sanitize_text_field( $message );
				$row['completed_at'] = current_time( 'mysql', true );
				break;
			}
		}
		unset( $row );
		$this->save( $rows );
	}

	/** @return list<string> */
	public function protected_job_uuids(): array {
		$uuids = array();
		foreach ( array_reverse( $this->history() ) as $row ) {
			$uuid = (string) ( $row['job_uuid'] ?? '' );
			if ( wp_is_uuid( $uuid ) ) {
				$uuids[] = $uuid;
			}
		}
		return array_values( array_unique( $uuids ) );
	}

	/** @return list<string> */
	public function protected_backup_uuids(): array {
		$uuids = array();
		foreach ( array_reverse( $this->history() ) as $row ) {
			$uuid = (string) ( $row['backup_uuid'] ?? '' );
			if ( wp_is_uuid( $uuid ) ) {
				$uuids[] = $uuid;
			}
			if ( count( $uuids ) >= self::PROTECTED_MAPPINGS ) {
				break;
			}
		}
		return array_values( array_unique( $uuids ) );
	}

	/** @return list<string> */
	public function protected_mapping_uuids(): array {
		$rows = array_reverse( $this->history() );
		$uuids = array();
		foreach ( $rows as $row ) {
			if ( 'completed' !== (string) ( $row['status'] ?? '' ) || empty( $row['verified'] ) ) {
				continue;
			}
			$uuid = (string) ( $row['mapping_uuid'] ?? '' );
			if ( wp_is_uuid( $uuid ) ) {
				$uuids[] = $uuid;
			}
			if ( count( $uuids ) >= self::PROTECTED_MAPPINGS ) {
				break;
			}
		}
		return array_values( array_unique( $uuids ) );
	}

	/** @param array<string,mixed> $row */
	private function upsert( array $row ): void {
		$rows = $this->history();
		$replaced = false;
		foreach ( $rows as $index => $existing ) {
			if ( hash_equals( (string) ( $existing['job_uuid'] ?? '' ), (string) $row['job_uuid'] ) ) {
				$rows[ $index ] = $row;
				$replaced = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$rows[] = $row;
		}
		$this->save( $rows );
	}

	/** @param list<array<string,mixed>> $rows */
	private function save( array $rows ): void {
		usort(
			$rows,
			static fn( array $a, array $b ): int => (int) ( $a['generation_no'] ?? 0 ) <=> (int) ( $b['generation_no'] ?? 0 )
		);
		update_option( self::OPTION, array_slice( $rows, -self::MAX_HISTORY ), false );
	}
}
