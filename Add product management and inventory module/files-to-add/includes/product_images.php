<?php

// Positions in the locally generated five-column, four-row sample image sheet.
function productSampleImageIndex(string $name): ?int {
    $names = [
        'Business Cards', 'Flyers', 'Brochures', 'Posters', 'Stickers',
        'Labels', 'Invitations', 'Custom T-Shirts', 'Mugs', 'Birthday Package',
        'Anniversary Package', "Valentine's Package", 'Graduation Package',
        "Mother's Day Package", "Father's Day Package", 'Wedding Package',
        'Proposal Package', 'Baby Shower Package', 'Christmas Package',
    ];
    $index = array_search(strtolower(trim($name)), array_map('strtolower', $names), true);
    return $index === false ? null : $index;
}

/** Root-relative catalog preview asset path. Product previews are illustrative mockups. */
function productPreviewImage(string $name): ?string {
    $images = [
        'tarpaulin printing' => 'tarpaulin-printing.png',
        'sintra board' => 'sintra-board.png',
        'posters' => 'posters.png',
        'customized shirts' => 'customized-shirts.png',
        'customized mugs' => 'customized-mugs.png',
        'acrylic keychains' => 'acrylic-keychains.png',
        'button pins' => 'button-pins.png',
        'ref magnets' => 'ref-magnets.png',
        'bookmarks' => 'bookmarks.png',
        'business cards' => 'business-cards.png',
        'flyers' => 'flyers.png',
        'flyers & brochures' => 'flyers.png',
        'flyers/brochure' => 'flyers.png',
        'brochures' => 'brochures.png',
        'stickers' => 'stickers.png',
        'stickers & labels' => 'stickers.png',
        'stickers/labels' => 'stickers.png',
        'labels' => 'labels.png',
        'invitations' => 'invitations.png',
        'photo printing' => 'photo-printing.png',
        'id picture printing' => 'id-picture-printing.png',
        'document printing' => 'document-printing.png',
        'lamination' => 'lamination.png',
        'pvc id cards' => 'pvc-id-cards.png',
        'mugs' => 'customized-mugs.png', // historical orders
        'custom t-shirts' => 'customized-shirts.png', // historical orders
    ];
    $file = $images[strtolower(trim($name))] ?? null;
    return $file === null ? null : 'assets/img/products/' . $file;
}

/** Resolve an admin-uploaded image first, or preserve the existing shop mockup. */
function productDisplayImage(array $product): ?string {
    $name = basename(trim((string)($product['image'] ?? '')));
    if ($name !== '' && preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/i', $name)
        && is_file(__DIR__ . '/../uploads/product-images/' . $name)) {
        return 'uploads/product-images/' . $name;
    }
    return productPreviewImage((string)($product['name'] ?? ''));
}
