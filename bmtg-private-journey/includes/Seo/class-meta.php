<?php
namespace BMTG\PrivateJourney\Seo;

use BMTG\PrivateJourney\Catalogue\Catalogue;
use BMTG\PrivateJourney\Core\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public catalogue SEO: canonical URLs, meta descriptions and JSON-LD.
 * Private portal pages stay noindex (enforced in Assets).
 */
class Meta {
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output' ), 2 );
		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
	}

	private function current_item(): ?array {
		if ( ! is_page() ) {
			return null;
		}
		$id = get_queried_object_id();
		if ( Pages::page_id( 'item_detail' ) !== (int) $id ) {
			return null;
		}
		$slug = get_query_var( 'pj_item' );
		if ( ! $slug && isset( $_GET['pj_item'] ) ) {
			$slug = sanitize_title( wp_unslash( $_GET['pj_item'] ) );
		}
		if ( ! $slug ) {
			return null;
		}
		$item = Catalogue::instance()->get_by_slug( $slug );
		if ( ! $item || 'published' !== $item['status'] || empty( $item['public_visibility'] ) ) {
			return null;
		}
		return $item;
	}

	public function title_parts( $parts ) {
		$item = $this->current_item();
		if ( $item ) {
			$parts['title'] = $item['title'];
		}
		return $parts;
	}

	public function output(): void {
		$item = $this->current_item();
		if ( ! $item ) {
			return;
		}

		$url = add_query_arg( array( 'pj_item' => $item['slug'] ), Pages::url( 'item_detail' ) );
		?>
		<link rel="canonical" href="<?php echo esc_url( $url ); ?>" />
		<meta name="description" content="<?php echo esc_attr( wp_trim_words( $item['summary'] ?: $item['description'], 28 ) ); ?>" />
		<meta property="og:title" content="<?php echo esc_attr( $item['title'] ); ?>" />
		<meta property="og:description" content="<?php echo esc_attr( wp_trim_words( $item['summary'] ?: $item['description'], 28 ) ); ?>" />
		<meta property="og:type" content="website" />
		<meta property="og:url" content="<?php echo esc_url( $url ); ?>" />
		<?php if ( ! empty( $item['cover_url'] ) ) : ?>
			<meta property="og:image" content="<?php echo esc_url( $item['cover_url'] ); ?>" />
			<meta name="twitter:card" content="summary_large_image" />
		<?php endif; ?>
		<?php
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => $this->schema_type( $item['item_type'] ),
			'name'        => $item['title'],
			'description' => wp_strip_all_tags( $item['summary'] ?: $item['description'] ),
			'url'         => $url,
		);
		if ( ! empty( $item['cover_url'] ) ) {
			$schema['image'] = $item['cover_url'];
		}
		if ( ! empty( $item['destination'] ) ) {
			$schema['touristType'] = array( $item['destination'] );
		}
		if ( 'fixed' === $item['price_type'] && null !== $item['price'] ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => (string) $item['price'],
				'priceCurrency' => $item['currency'],
				'availability'  => 'https://schema.org/InStock',
			);
		}
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = str_replace( '</', '<\/', (string) $json );
		?>
		<script type="application/ld+json"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<?php
	}

	private function schema_type( string $type ): string {
		$map = array(
			'hotel'    => 'Hotel',
			'restaurant' => 'Restaurant',
			'experience' => 'TouristAttraction',
			'journey'  => 'TouristTrip',
			'package'  => 'TouristTrip',
			'train'    => 'TrainTrip',
			'transport' => 'Trip',
			'airport'  => 'Service',
			'wellness' => 'HealthAndBeautyBusiness',
			'heritage' => 'LandmarksOrHistoricalBuildings',
			'exclusive' => 'TouristAttraction',
			'safari'   => 'TouristAttraction',
		);
		return $map[ $type ] ?? 'Product';
	}
}