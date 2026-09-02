<?php
/**
 * Shared paging arguments and arithmetic for the list endpoints.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use WP_REST_Request;

/**
 * The history and the schedules list page the same way, and an offset that is
 * subtly different between them is the kind of bug that only shows itself on page
 * three. Both the argument schema and the sum live here once.
 */
final class Paging {

	/**
	 * The largest page a caller may ask for, so no request turns a list endpoint
	 * into a full table dump.
	 */
	private const MAX_PER_PAGE = 200;

	/**
	 * The `page` and `per_page` arguments for a list route.
	 *
	 * @param int $per_page The page size when the caller does not name one.
	 * @return array<string, array<string, mixed>>
	 */
	public static function args( int $per_page ): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => $per_page,
				'minimum' => 1,
				'maximum' => self::MAX_PER_PAGE,
			),
		);
	}

	/**
	 * The page, page size, and row offset a request is asking for.
	 *
	 * @param WP_REST_Request $request  The request.
	 * @param int             $per_page Fallback page size, for a caller that named none.
	 * @return array{page: int, per_page: int, offset: int}
	 */
	public static function slice( WP_REST_Request $request, int $per_page ): array {
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$size = (int) $request->get_param( 'per_page' );
		$size = $size > 0 ? min( self::MAX_PER_PAGE, $size ) : $per_page;

		return array(
			'page'     => $page,
			'per_page' => $size,
			'offset'   => ( $page - 1 ) * $size,
		);
	}
}
