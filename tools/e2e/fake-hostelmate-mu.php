<?php
/**
 * LOCAL COPY ONLY. A fake HostelMate for end-to-end tests, active only while the option
 * local_fake_hm is set: {"availability": "free"|"full"|"error", "calls": n}.
 */
defined( 'ABSPATH' ) || exit;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$fake = get_option( 'local_fake_hm' );
		if ( ! is_array( $fake ) || ! str_starts_with( $url, 'https://api.hostelmate.co/' ) ) {
			return $pre;
		}
		$fake['calls'] = (int) ( $fake['calls'] ?? 0 ) + 1;
		update_option( 'local_fake_hm', $fake, false );
		if ( 'error' === ( $fake['availability'] ?? '' ) ) {
			return new WP_Error( 'http_request_failed', 'timeout' );
		}
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		if ( str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/rates' ) && ! empty( $fake['rates'] ) ) {
			return local_fake_hm_rates( (string) $q['start_date'], (string) $q['end_date'], (int) $fake['rates'] );
		}
		$rooms = array();
		foreach ( (array) get_option( 'hostelmate_hbook_mapping' )['rooms'] as $room => $units ) {
			$full    = 'full' === $fake['availability'];
			$rooms[] = array( 'room_id' => $room, 'name' => 'x', 'bookable' => ! $full, 'total' => $full ? null : 100, 'nights' => array( array( 'date' => $q['check_in'], 'price' => $full ? null : 100, 'available_beds' => $full ? 0 : 5 ) ), 'restriction' => null );
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'rooms' => $rooms ) ), 'headers' => array(), 'cookies' => array() );
	},
	5,
	3
);

/**
 * GET /rates for the saved test property, shaped like HostelMate's answer (API/services/rates.py):
 * every mapped room, every night. $seed changes every price, so a run with a new seed rewrites them all.
 */
function local_fake_hm_rates( string $start, string $end, int $seed ): array {
	$property = \HostelMate\HBook\Core\State::get( 'property' );
	$mapping  = (array) ( get_option( 'hostelmate_hbook_mapping' )['rooms'] ?? array() );
	$rooms    = array();
	foreach ( (array) $property['rooms'] as $room ) {
		$units  = count( (array) ( $mapping[ $room['id'] ] ?? array() ) );
		$nights = array();
		for ( $d = $start, $i = 0; $d <= $end; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ), $i++ ) {
			$nights[] = array( 'date' => $d, 'price' => 30000 + ( ( $i + $seed ) % 9 ) * 1500, 'closed' => false, 'available' => max( 0, $units - ( 0 === $i % 3 ? 1 : 0 ) ), 'min_stay' => null, 'max_stay' => null, 'closed_to_arrival' => false, 'closed_to_departure' => false );
		}
		$rooms[] = array( 'room_id' => $room['id'], 'name' => $room['name'], 'sell_mode' => $room['sell_mode'], 'nights' => $nights );
	}
	$body = array( 'property_id' => $property['id'], 'currency' => $property['currency'], 'start_date' => $start, 'end_date' => $end, 'rooms' => $rooms );
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ), 'headers' => array(), 'cookies' => array() );
}
