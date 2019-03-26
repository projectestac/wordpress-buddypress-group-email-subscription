<?php

/**
 * Async process for migrating digest queue data in 3.9.0 upgrade.
 */
class BPGES_Async_Request_Digest_Queue_Migrate extends BPGES_Async_Request {
	/**
	 * @var   string
	 * @since 3.9.0
	 */
	protected $query_url;

	/**
	 * @var   string
	 * @since 3.9.0
	 */
	protected $action = 'bpges_migrate_digest_queue';

	/**
	 * Constructor.
	 *
	 * @since 3.9.0
	 */
	public function __construct() {
		/**
		 * Filters the query URL for BPGES async requests.
		 *
		 * @since 3.9.0
		 *
		 * @param string $url
		 */
		$this->query_url = apply_filters( 'bpges_async_request_query_url', get_admin_url( bp_get_root_blog_id(), 'admin-ajax.php' ) );

		parent::__construct();
	}

	/**
	 * Handles a migration batch.
	 *
	 * @since 3.9.0
	 *
	 * @param int $group_id ID of the group whose subscriptions are being migrated.
	 */
	protected function handle() {
		global $wpdb;

		$batch_size = 50;

		$user_ids = $wpdb->get_col( $wpdb->prepare( "SELECT um.user_id FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->usermeta} um1 ON ( um.user_id = um1.user_id AND um1.meta_key = '_ges_digest_queue_migrated' ) WHERE um.meta_key = 'ass_digest_items' AND um.meta_value IS NOT NULL AND um1.meta_value IS NULL LIMIT %d", $batch_size ) );

		if ( ! $user_ids ) {
			bp_update_option( '_ges_39_digest_queue_migrated', 1 );
			bp_delete_option( '_ges_39_digest_queue_migration_in_progress' );

			delete_metadata( 'user', 1, '_ges_digest_queue_migrated', false, true );

			return;
		}

		bp_update_option( '_ges_39_digest_queue_migration_in_progress', time() );

		foreach ( $user_ids as $user_id ) {
			$user_queues = bp_get_user_meta( $user_id, 'ass_digest_items', true );
			foreach ( $user_queues as $digest_type => $user_groups ) {
				foreach ( $user_groups as $group_id => $activity_ids ) {
					$query = new BPGES_Subscription_Query( array(
						'user_id'  => $user_id,
						'group_id' => $group_id,
					) );

					$existing = $query->get_results();
					if ( ! $existing ) {
						// Nothing to migrate.
						bp_update_user_meta( $user_id, '_ges_digest_queue_migrated', 1 );
						continue;
					}

					$subscription = reset( $existing );

					$to_queue = array();
					foreach ( $activity_ids as $activity_id ) {
						// Don't migrate deleted, stale, or other invalid items.
						if ( ! bp_ges_activity_is_valid_for_digest( $activity_id, $digest_type, $user_id ) ) {
							continue;
						}

						$to_queue[] = array(
							'user_id'       => $user_id,
							'group_id'      => $group_id,
							'activity_id'   => $activity_id,
							'type'          => $digest_type,
							'date_recorded' => date( 'Y-m-d H:i:s' ),
						);
					}

					if ( $to_queue ) {
						BPGES_Queued_Item::bulk_insert( $to_queue );
					}
				}
			}

			// Delete the legacy queue, to avoid double-processing.
			bp_update_user_meta( $user_id, '_ges_digest_queue_migrated', 1 );
		}

		// Launch another item in the queue.
		$process = new self();
		$process->dispatch();
	}
}
