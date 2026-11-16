<?php
namespace BMTG\PrivateJourney\Catalogue;

use BMTG\PrivateJourney\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalogue data access + business rules.
 */
class Catalogue {
	private static ?Catalogue $instance = null;

	public static function instance(): Catalogue {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::catalogue_items();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function get_by_slug( string $slug ): ?array {
		global $wpdb;
		$table = Schema::catalogue_items();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE slug = %s", $slug ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	private function hydrate( array $row ): array {
		$row['gallery']    = Schema::decode( $row['gallery'] ?? '', array() );
		$row['meta']       = Schema::decode( $row['meta'] ?? '', array() );
		foreach ( array( 'facilities', 'highlights', 'inclusions', 'exclusions' ) as $key ) {
			$row[ $key ] = Schema::decode( $row[ $key ] ?? '', array() );
		}
		$row['tags']       = array_filter( array_map( 'trim', explode( ',', (string) ( $row['tags'] ?? '' ) ) ) );
		$row['price']      = null === $row['price'] ? null : (float) $row['price'];
		$row['price_type'] = $row['price_type'] ?: 'poa';
		$row['cover_url']  = $row['cover_image_id'] ? (string) wp_get_attachment_image_url( (int) $row['cover_image_id'], 'large' ) : '';
		return $row;
	}

	/**
	 * Public catalogue query.
	 *
	 * @param array $args type, category, search, page, per_page, featured, ids
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$table = Schema::catalogue_items();

		$args = wp_parse_args(
			$args,
			array(
				'type'      => '',
				'category'  => '',
				'search'    => '',
				'page'      => 1,
				'per_page'  => 9,
				'featured'  => false,
				'ids'       => array(),
				'public'    => true,
				'orderby'   => 'sort_order',
				'order'     => 'ASC',
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $args['public'] ) {
			$where[]  = "status = 'published'";
			$where[]  = 'public_visibility = 1';
		}
		if ( ! empty( $args['type'] ) ) {
			$where[] = 'item_type = %s';
			$params[] = (string) $args['type'];
		}
		if ( ! empty( $args['category'] ) ) {
			$where[] = 'category_slug = %s';
			$params[] = (string) $args['category'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]   = '(title LIKE %s OR summary LIKE %s OR destination LIKE %s OR tags LIKE %s OR search_meta LIKE %s)';
			array_push( $params, $like, $like, $like, $like, $like );
		}
		if ( $args['featured'] ) {
			$where[] = 'featured = 1';
		}
		if ( ! empty( $args['ids'] ) ) {
			$ids       = array_map( 'absint', (array) $args['ids'] );
			$in        = implode( ',', $ids );
			$where[]   = "id IN ($in)";
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 48, (int) $args['per_page'] ) );
		$page      = max( 1, (int) $args['page'] );
		$offset    = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_sql" );

		$orderby_map = array(
			'sort_order' => 'sort_order',
			'title'      => 'title',
			'price'      => 'price',
			'created_at' => 'created_at',
			'updated_at' => 'updated_at',
		);
		$orderby = $orderby_map[ $args['orderby'] ] ?? 'sort_order';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$order   = 'sort_order' === $orderby ? 'ASC' : $order;

		if ( ! empty( $params ) ) {
			$params[] = $per_page;
			$params[] = $offset;
			$sql      = "SELECT * FROM $table WHERE $where_sql ORDER BY $orderby $order, id ASC LIMIT %d OFFSET %d";
			$prepared = $wpdb->prepare( $sql, ...$params );
			$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM $table WHERE $where_sql ORDER BY $orderby $order, id ASC LIMIT $per_page OFFSET $offset", ARRAY_A );
		}

		return array(
			'items'    => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	public function categories( bool $public_only = true ): array {
		global $wpdb;
		$table = Schema::catalogue_categories();
		$where = $public_only ? "WHERE status = 'published'" : '';
		return (array) $wpdb->get_results( "SELECT * FROM $table $where ORDER BY sort_order ASC, name ASC", ARRAY_A );
	}

	public function options_for( int $item_id ): array {
		global $wpdb;
		$table = Schema::service_options();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE item_id = %d ORDER BY sort_order ASC, id ASC", $item_id ), ARRAY_A );
	}

	public function options_for_many( array $ids ): array {
		global $wpdb;
		$ids   = array_map( 'absint', $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		$table = Schema::service_options();
		$in    = implode( ',', $ids );
		return (array) $wpdb->get_results( "SELECT * FROM $table WHERE item_id IN ($in) ORDER BY item_id, sort_order", ARRAY_A );
	}

	public function create( array $data ): int {
		global $wpdb;
		$table = Schema::catalogue_items();
		$now   = Schema::now();

		$row = $this->normalize( $data );
		$row['slug']       = $this->unique_slug( $row['slug'] );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$wpdb->insert( $table, $row );
		$id = (int) $wpdb->insert_id;

		$this->sync_options( $id, $data['options'] ?? array() );
		return $id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;
		$table = Schema::catalogue_items();
		if ( ! $this->get( $id ) ) {
			return false;
		}

		$row = $this->normalize( $data );
		$row['slug'] = $this->unique_slug( $row['slug'], $id );
		unset( $row['created_at'] );
		$row['updated_at'] = Schema::now();

		$ok = false !== $wpdb->update( $table, $row, array( 'id' => $id ) );

		if ( array_key_exists( 'options', $data ) ) {
			$this->sync_options( $id, (array) $data['options'] );
		}

		return $ok;
	}

	public function delete( int $id ): bool {
		global $wpdb;
		$table   = Schema::catalogue_items();
		$options = Schema::service_options();
		$wpdb->delete( $options, array( 'item_id' => $id ) );
		return false !== $wpdb->delete( $table, array( 'id' => $id ) );
	}

	private function sync_options( int $item_id, array $options ): void {
		global $wpdb;
		$table = Schema::service_options();
		$wpdb->delete( $table, array( 'item_id' => $item_id ) );

		$order = 0;
		foreach ( $options as $opt ) {
			if ( ! is_array( $opt ) || ( empty( $opt['label'] ) && empty( $opt['price'] ) ) ) {
				continue;
			}
			$order++;
			$wpdb->insert(
				$table,
				array(
					'item_id'     => $item_id,
					'label'       => Schema::sanitize_text( $opt['label'] ?? '', 191 ),
					'description' => Schema::sanitize_textarea( $opt['description'] ?? '', 1000 ),
					'price_type'  => in_array( ( $opt['price_type'] ?? 'poa' ), array( 'fixed', 'poa' ), true ) ? $opt['price_type'] : 'poa',
					'price'       => ( 'fixed' === ( $opt['price_type'] ?? 'poa' ) && is_numeric( $opt['price'] ?? null ) ) ? (float) $opt['price'] : null,
					'meta'        => Schema::json( isset( $opt['meta'] ) && is_array( $opt['meta'] ) ? $opt['meta'] : array() ),
					'sort_order'  => $order,
					'status'      => 'published',
					'created_at'  => Schema::now(),
					'updated_at'  => Schema::now(),
				)
			);
		}
	}

	private function unique_slug( string $slug, int $exclude_id = 0 ): string {
		global $wpdb;
		$table = Schema::catalogue_items();
		$base  = sanitize_title( $slug );
		if ( '' === $base ) {
			$base = 'service';
		}
		$candidate = $base;
		$i         = 2;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE slug = %s AND id != %d", $candidate, $exclude_id ) ) ) {
			$candidate = $base . '-' . $i;
			$i++;
		}
		return substr( $candidate, 0, 180 );
	}

	private function normalize( array $data ): array {
		$type = isset( $data['item_type'] ) ? (string) $data['item_type'] : 'experience';
		if ( ! in_array( $type, Schema::item_types(), true ) ) {
			$type = 'experience';
		}

		$slug = isset( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : '';
		if ( '' === $slug ) {
			$slug = sanitize_title( (string) ( $data['title'] ?? '' ) );
		}
		$slug = substr( $slug, 0, 180 );

		$price_type = ( isset( $data['price_type'] ) && 'fixed' === $data['price_type'] ) ? 'fixed' : 'poa';
		$price      = ( 'fixed' === $price_type && isset( $data['price'] ) && is_numeric( $data['price'] ) ) ? (float) $data['price'] : null;

		$gallery = array();
		if ( isset( $data['gallery'] ) ) {
			foreach ( (array) $data['gallery'] as $img ) {
				$img = (int) $img;
				if ( $img ) {
					$gallery[] = $img;
				}
			}
		}

		$status = isset( $data['status'] ) && in_array( $data['status'], Schema::item_statuses(), true ) ? $data['status'] : 'draft';

		return array(
			'slug'             => $slug,
			'item_type'        => $type,
			'title'            => Schema::sanitize_text( $data['title'] ?? '', 255 ),
			'summary'          => Schema::sanitize_textarea( $data['summary'] ?? '', 2000 ),
			'description'      => wp_kses_post( (string) ( $data['description'] ?? '' ) ),
			'country'          => Schema::sanitize_text( $data['country'] ?? '', 120 ),
			'destination'      => Schema::sanitize_text( $data['destination'] ?? '', 191 ),
			'location'         => Schema::sanitize_text( $data['location'] ?? '', 191 ),
			'category_slug'    => sanitize_title( (string) ( $data['category_slug'] ?? '' ) ),
			'tags'             => Schema::sanitize_text( is_array( $data['tags'] ?? null ) ? implode( ',', $data['tags'] ) : ( $data['tags'] ?? '' ), 2000 ),
			'facilities'       => Schema::json( $this->list_or_text( $data['facilities'] ?? '' ) ),
			'highlights'       => Schema::json( $this->list_or_text( $data['highlights'] ?? '' ) ),
			'inclusions'       => Schema::json( $this->list_or_text( $data['inclusions'] ?? '' ) ),
			'exclusions'       => Schema::json( $this->list_or_text( $data['exclusions'] ?? '' ) ),
			'conditions'       => Schema::sanitize_textarea( $data['conditions'] ?? '', 5000 ),
			'duration'         => Schema::sanitize_text( $data['duration'] ?? '', 60 ),
			'price_type'       => $price_type,
			'price'            => $price,
			'currency'         => strtoupper( substr( Schema::sanitize_text( $data['currency'] ?? 'EUR', 3 ), 0, 3 ) ),
			'cover_image_id'   => (int) ( $data['cover_image_id'] ?? 0 ) ?: null,
			'gallery'          => Schema::json( $gallery ),
			'status'           => $status,
			'featured'         => empty( $data['featured'] ) ? 0 : 1,
			'public_visibility' => empty( $data['public_visibility'] ) ? 0 : 1,
			'search_meta'      => Schema::sanitize_textarea( $data['search_meta'] ?? '', 2000 ),
			'sort_order'       => (int) ( $data['sort_order'] ?? 0 ),
			'meta'             => Schema::json( isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array() ),
		);
	}

	private function list_or_text( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'trim', $value ) ) );
		}
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}

	public function price_html( ?float $price, string $currency = 'EUR', string $type = 'fixed' ): string {
		if ( 'poa' === $type || null === $price ) {
			return '<span class="pj-price pj-price--poa">' . esc_html__( 'Price on Request', 'bmtg-private-journey' ) . '</span>';
		}
		$formatted = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol( $currency )
			: $currency . ' ';
		return '<span class="pj-price">' . esc_html( $formatted . number_format_i18n( (float) $price, 2 ) ) . '</span>';
	}
}