<?php
// Calculate human-readable price
$formatted_price = '$' . number_format($property['price']);
?>

<div class="property-card" data-id="<?= $property['id'] ?>"
    data-location="<?= $property['city'] . ', ' . $property['state'] ?>" data-type="<?= $property['property_type'] ?>"
    data-price="<?= $property['price'] ?>" data-beds="<?= $property['bedrooms'] ?>">

    <div class="property-image"
        style="background-image: url('<?= $property['image_path'] ?: 'default_property.jpg' ?>')">
        <?php if (time() - strtotime($property['created_at']) < 604800): // 7 days ?>
            <div class="property-badge badge-new">New</div>
        <?php endif; ?>

        <?php if ($property['is_featured']): ?>
            <div class="property-badge badge-featured">Featured</div>
        <?php endif; ?>

        <button class="favorite-btn <?= $property['is_favorite'] ? 'active' : '' ?>" data-id="<?= $property['id'] ?>">
            <i class="<?= $property['is_favorite'] ? 'fas' : 'far' ?> fa-heart"></i>
        </button>
    </div>

    <div class="property-details">
        <h3><?= htmlspecialchars($property['title']) ?></h3>
        <div class="property-price"><?= $formatted_price ?></div>
        <div class="property-meta">
            <span><i class="fas fa-bed"></i> <?= $property['bedrooms'] ?> Beds</span>
            <span><i class="fas fa-bath"></i> <?= $property['bathrooms'] ?> Baths</span>
            <span><i class="fas fa-ruler-combined"></i> <?= number_format($property['square_feet']) ?> sqft</span>
        </div>
        <p class="property-address">
            <i class="fas fa-map-marker-alt"></i>
            <?= htmlspecialchars($property['address']) ?>, <?= $property['city'] ?>, <?= $property['state'] ?>
        </p>
        <div class="property-actions">
            <button class="details-btn">
                <i class="fas fa-info-circle"></i> Details
            </button>
            <button class="contact-btn">
                <i class="fas fa-envelope"></i> Contact
            </button>
        </div>
    </div>
</div>
