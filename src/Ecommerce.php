<?php

/**
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

/** Commerce events, with the reserved names filled in. */
final class Ecommerce
{
    public function __construct(private readonly Intempt $client)
    {
    }

    /** @param array<string, mixed> $options */
    public function productViewed(array $options): void
    {
        $productId = Validate::nonBlank($options['productId'] ?? null, 'productViewed', 'productId');
        Validate::identifier($options, 'productViewed');
        $this->client->trackLines(
            Intempt::COMMERCE_EVENTS['productViewed'],
            $options,
            [['productId' => $productId]]
        );
    }

    /** @param array<string, mixed> $options */
    public function addedToCart(array $options): void
    {
        $productId = Validate::nonBlank($options['productId'] ?? null, 'addedToCart', 'productId');
        $quantity = $options['quantity'] ?? null;
        if (!is_int($quantity) || $quantity <= 0) {
            throw new IntemptConfigException('addedToCart: quantity must be a positive integer');
        }
        Validate::identifier($options, 'addedToCart');
        $this->client->trackLines(
            Intempt::COMMERCE_EVENTS['addedToCart'],
            $options,
            [['productId' => $productId, 'quantity' => $quantity]]
        );
    }

    /** @param array<string, mixed> $options */
    public function ordered(array $options): void
    {
        $products = $options['products'] ?? null;
        if (!is_array($products) || $products === []) {
            throw new IntemptConfigException('ordered: products must be a non-empty array');
        }

        $lines = [];
        foreach (array_values($products) as $index => $product) {
            $productId = is_array($product) ? ($product['productId'] ?? null) : null;
            Validate::nonBlank($productId, sprintf('ordered: products[%d]', $index), 'productId');
            $quantity = is_array($product) ? ($product['quantity'] ?? null) : null;
            if ($quantity !== null && (!is_int($quantity) || $quantity <= 0)) {
                throw new IntemptConfigException(sprintf(
                    'ordered: products[%d].quantity must be a positive integer',
                    $index
                ));
            }
            $lines[] = Validate::compact(['productId' => $productId, 'quantity' => $quantity]);
        }

        Validate::identifier($options, 'ordered');
        $this->client->trackLines(Intempt::COMMERCE_EVENTS['ordered'], $options, $lines);
    }
}
