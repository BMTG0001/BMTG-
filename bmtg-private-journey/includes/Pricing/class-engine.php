<?php
namespace BMTG\PrivateJourney\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side pricing and margin engine.
 * Never present an indicative or POA price as confirmed.
 */
class Engine {
	public static function round( float $amount, int $decimals = 2 ): float {
		return round( $amount, $decimals );
	}

	public static function markup_price( ?float $cost, float $markup ): ?float {
		if ( null === $cost ) {
			return null;
		}
		return self::round( $cost * ( 1 + $markup ) );
	}

	/**
	 * @param array<int,array{unit_cost?:float|null,unit_price?:float|null,quantity?:int}> $lines
	 * @param array{discount_type?:string,discount?:float,tax_rate?:float,currency?:string}        $opts
	 */
	public static function totals( array $lines, array $opts = array() ): array {
		$opts = array_merge(
			array(
				'discount_type' => 'none',
				'discount'      => 0,
				'tax_rate'      => 0,
				'currency'      => 'EUR',
			),
			$opts
		);

		$cost    = 0.0;
		$subtotal = 0.0;
		$has_poa = false;
		$has_cost = false;

		foreach ( $lines as $line ) {
			$qty  = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			$cost_line = isset( $line['unit_cost'] ) && null !== $line['unit_cost'] ? (float) $line['unit_cost'] : null;
			$price_line = isset( $line['unit_price'] ) && null !== $line['unit_price'] ? (float) $line['unit_price'] : null;

			if ( null !== $cost_line ) {
				$cost += $cost_line * $qty;
				$has_cost = true;
			}
			if ( null === $price_line ) {
				$has_poa = true;
				continue;
			}
			$subtotal += $price_line * $qty;
		}

		$discount = 0.0;
		if ( 'fixed' === $opts['discount_type'] ) {
			$discount = max( 0, (float) $opts['discount'] );
		} elseif ( 'percent' === $opts['discount_type'] ) {
			$discount = $subtotal * ( min( 100, max( 0, (float) $opts['discount'] ) ) / 100 );
		}
		$discount = min( $discount, $subtotal );

		$taxable = max( 0, $subtotal - $discount );
		$tax     = $taxable * max( 0, (float) $opts['tax_rate'] );
		$total   = $taxable + $tax;

		return array(
			'currency'       => strtoupper( substr( (string) $opts['currency'], 0, 3 ) ),
			'cost'           => self::round( $cost ),
			'has_cost'       => $has_cost,
			'subtotal'       => self::round( $subtotal ),
			'discount'       => self::round( $discount ),
			'discount_type'  => (string) $opts['discount_type'],
			'tax_rate'       => (float) $opts['tax_rate'],
			'tax'            => self::round( $tax ),
			'total'          => self::round( $total ),
			'margin'         => self::round( $subtotal - $cost ),
			'margin_percent' => $cost > 0 ? self::round( ( ( $subtotal - $cost ) / $cost ) * 100, 1 ) : null,
			'markup_percent' => $cost > 0 ? self::round( ( ( $subtotal - $cost ) / $cost ) * 100, 1 ) : null,
			'has_poa'        => $has_poa,
			'confirmed'      => ! $has_poa,
		);
	}

	public static function format( ?float $amount, string $currency = 'EUR' ): string {
		if ( null === $amount ) {
			return __( 'Price on Request', 'bmtg-private-journey' );
		}
		return $currency . ' ' . number_format_i18n( (float) $amount, 2 );
	}

	/** Merge line totals into a saved quotation payload. */
	public static function line_total( ?float $unit_price, int $quantity ): ?float {
		if ( null === $unit_price ) {
			return null;
		}
		return self::round( $unit_price * max( 1, $quantity ) );
	}
}