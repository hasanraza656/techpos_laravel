<?php

namespace App\Domain\Operations;

final class SaleCalculator
{
    public function calculate(array $lines, string $cartDiscount): array
    {
        $cart = Decimal::input($cartDiscount);
        $bases = [];
        foreach ($lines as $i => $line) {
            $qty = Decimal::input($line['quantity'], 3);
            if (bccomp($qty, '0', 3) <= 0) {
                Decimal::fail('quantity', 'Sale quantities must be positive.');
            }
            $price = Decimal::input($line['unit_price']);
            $discount = Decimal::input($line['discount'] ?? '0');
            $sub = Decimal::multiply($qty, $price);
            if (bccomp($sub, '999999999999.99', 2) > 0) {
                Decimal::fail('total', 'Line subtotal exceeds the supported document limit.');
            }
            if (bccomp($discount, $sub, 2) > 0) {
                Decimal::fail('discount', 'Item discount cannot exceed its subtotal.');
            }
            $lines[$i]['subtotal'] = $sub;
            $lines[$i]['line_discount'] = $discount;
            $bases[$i] = bcsub($sub, $discount, 2);
        }
        $base = Decimal::sum($bases);
        if (bccomp(Decimal::sum(array_column($lines, 'subtotal')), '999999999999.99', 2) > 0) {
            Decimal::fail('total', 'Subtotal exceeds the supported document limit.');
        }
        if (bccomp($cart, $base, 2) > 0) {
            Decimal::fail('cart_discount', 'Cart discount cannot exceed the sale amount.');
        }
        $remaining = $cart;
        $last = array_key_last($lines);
        foreach ($lines as $i => &$line) {
            $allocation = $i === $last ? $remaining : (bccomp($base, '0', 2) > 0 ? bcdiv(bcmul($cart, $bases[$i], 12), $base, 2) : '0.00');
            $remaining = bcsub($remaining, $allocation, 2);
            $amount = bcsub($bases[$i], $allocation, 2);
            $rate = Decimal::input($line['tax_rate'], 4);
            $line['cart_discount'] = $allocation;
            if ($line['tax_inclusive']) {
                $net = Decimal::round(bcdiv($amount, bcadd('1', bcdiv($rate, '100', 8), 8), 12));
                $tax = bcsub($amount, $net, 2);
                $total = $amount;
            } else {
                $tax = Decimal::round(bcdiv(bcmul($amount, $rate, 12), '100', 12));
                $total = bcadd($amount, $tax, 2);
            }
            $line['tax'] = $tax;
            $line['total'] = $total;
        }unset($line);
        $total = Decimal::sum(array_column($lines, 'total'));
        if (bccomp($total, '999999999999.99', 2) > 0) {
            Decimal::fail('total', 'Sale total exceeds the supported document limit.');
        }

        return ['lines' => $lines, 'subtotal' => Decimal::sum(array_column($lines, 'subtotal')), 'line_discount' => Decimal::sum(array_column($lines, 'line_discount')), 'cart_discount' => $cart, 'tax_total' => Decimal::sum(array_column($lines, 'tax')), 'total' => $total];
    }
}
