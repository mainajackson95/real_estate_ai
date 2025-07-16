<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "kaisec@2025";
$dbname = "real_estate_ai_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to get properties with primary image
function getProperties($conn, $query)
{
    $properties = [];
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Get primary image
            $imgQuery = "SELECT image_path FROM property_images 
                         WHERE property_id = {$row['id']} AND is_primary = 1 
                         LIMIT 1";
            $imgResult = $conn->query($imgQuery);
            $row['image_url'] = $imgResult && $imgResult->num_rows > 0
                ? $imgResult->fetch_assoc()['image_path']
                : 'https://via.placeholder.com/800x600?text=No+Image';
            $properties[] = $row;
        }
    }
    return $properties;
}

// Fetch different property sections
$topProperties = getProperties(
    $conn,
    "SELECT * FROM properties WHERE status = 'available' ORDER BY RAND() LIMIT 3"
);

$agentsChoice = getProperties(
    $conn,
    "SELECT p.*, AVG(ar.rating) AS avg_rating 
     FROM properties p
     JOIN agent_ratings ar ON p.agent_id = ar.agent_id
     WHERE p.status = 'available'
     GROUP BY p.id
     ORDER BY avg_rating DESC, p.price DESC
     LIMIT 3"
);

$sellersChoice = getProperties(
    $conn,
    "SELECT * FROM properties 
     WHERE status = 'available' AND is_featured = 1
     ORDER BY created_at DESC 
     LIMIT 3"
);

$buyersChoice = getProperties(
    $conn,
    "SELECT p.*, COUNT(sp.id) AS save_count 
     FROM properties p
     LEFT JOIN saved_properties sp ON p.id = sp.property_id
     WHERE p.status = 'available'
     GROUP BY p.id
     ORDER BY save_count DESC
     LIMIT 3"
);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Real Estate AI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        :root {
            --primary-teal: #0d9488;
            --secondary-coral: #f87171;
            --accent-gold: #fef08a;
            --neutral-bg: #fafafa;
            --card-bg: #ffffff;
            --text-dark: #1f2937;
            --text-light: #6b7280;
            --border-gray: #e5e7eb;
            --shadow-light: rgba(0, 0, 0, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Roboto", sans-serif;
            background-color: var(--neutral-bg);
            color: var(--text-dark);
            line-height: 1.6;
            text-align: center;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: linear-gradient(135deg, var(--primary-teal), #0891b2);
            color: #ffffff;
            padding: 60px 20px;
            border-radius: 8px;
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
        }

        header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
            opacity: 0.2;
        }

        header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 15px;
            position: relative;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        header p {
            font-size: 1.2rem;
            max-width: 700px;
            margin: 0 auto 30px;
            position: relative;
        }

        /* SEARCH BAR STYLES */
        .search-container {
            max-width: 700px;
            margin: 0 auto 30px;
            position: relative;
        }

        .search-box {
            display: flex;
            background: white;
            border-radius: 60px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .search-box input {
            flex: 1;
            border: none;
            padding: 18px 25px;
            font-size: 1.1rem;
            outline: none;
            background: rgba(255, 255, 255, 0.9);
        }

        .search-btn {
            width: 70px;
            background: var(--primary-teal);
            color: white;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .search-btn:hover {
            background: #0f766e;
        }

        /* END SEARCH BAR STYLES */

        .nav {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            position: relative;
        }

        .nav a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            padding: 12px 24px;
            border-radius: 30px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .nav a:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .auth {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 20px;
            position: relative;
        }

        .auth a {
            padding: 12px 30px;
            background-color: var(--accent-gold);
            color: var(--text-dark);
            text-decoration: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .auth a:hover {
            background-color: #facc15;
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        .features {
            margin: 60px 0;
        }

        .section-title {
            font-size: 2.5rem;
            color: var(--primary-teal);
            margin-bottom: 40px;
            position: relative;
            display: inline-block;
        }

        .section-title::after {
            content: "";
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: var(--accent-gold);
            border-radius: 2px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }

        .feature-card {
            background: var(--card-bg);
            padding: 30px 20px;
            border-radius: 15px;
            box-shadow: 0 5px 20px var(--shadow-light);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.15);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: rgba(13, 148, 136, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            color: var(--primary-teal);
        }

        .feature-card h3 {
            font-size: 1.7rem;
            font-weight: 600;
            color: var(--primary-teal);
            margin-bottom: 15px;
        }

        .feature-card p {
            font-size: 1.1rem;
            color: var(--text-light);
            margin-bottom: 20px;
            min-height: 80px;
        }

        .feature-card button {
            padding: 12px 25px;
            background-color: var(--primary-teal);
            color: white;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .feature-card button:hover {
            background-color: #0f766e;
            transform: scale(1.05);
        }

        /* Property Sections */
        .property-section {
            margin: 70px 0;
            padding: 30px 0;
        }

        .section-subtitle {
            font-size: 1.5rem;
            color: var(--text-light);
            margin-top: 10px;
            font-weight: 400;
        }

        .property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }

        .property-card {
            background: var(--card-bg);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
        }

        .property-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }

        .property-image {
            height: 220px;
            position: relative;
            overflow: hidden;
        }

        .property-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .favorite-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            color: var(--secondary-coral);
            font-size: 1.2rem;
            transition: all 0.3s ease;
            z-index: 10;
        }

        .favorite-btn:hover {
            background: white;
            transform: scale(1.1);
        }

        .favorite-btn.active {
            color: var(--secondary-coral);
        }

        .property-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background: var(--accent-gold);
            color: #854d0e;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            z-index: 10;
        }

        .property-details {
            padding: 20px;
        }

        .property-details h3 {
            font-size: 1.4rem;
            margin-bottom: 10px;
            color: var(--text-dark);
        }

        .property-price {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary-teal);
            margin-bottom: 15px;
        }

        .property-meta {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 15px;
            color: var(--text-light);
            font-size: 0.95rem;
            flex-wrap: wrap;
        }

        .property-meta span {
            display: flex;
            align-items: center;
        }

        .property-meta i {
            margin-right: 5px;
            color: var(--primary-teal);
        }

        .property-location {
            color: var(--text-light);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .view-btn {
            width: 100%;
            padding: 12px;
            background: var(--primary-teal);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-btn:hover {
            background: #0f766e;
            transform: translateY(-2px);
        }

        /* Footer */
        footer {
            background: var(--primary-teal);
            color: white;
            padding: 40px 0;
            margin-top: 80px;
            border-radius: 8px;
        }

        .footer-content {
            max-width: 800px;
            margin: 0 auto;
        }

        .footer-logo {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: inline-block;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin: 30px 0;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            transition: opacity 0.3s ease;
        }

        .footer-links a:hover {
            opacity: 0.8;
        }

        .copyright {
            margin-top: 20px;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        @media (max-width: 768px) {
            header h1 {
                font-size: 2.2rem;
            }

            .section-title {
                font-size: 2rem;
            }

            .property-grid,
            .features-grid {
                grid-template-columns: 1fr;
            }

            .nav {
                flex-direction: column;
                align-items: center;
            }

            .nav a {
                width: 80%;
                text-align: center;
            }

            /* Mobile search bar */
            .search-container {
                max-width: 90%;
            }

            .search-box input {
                padding: 15px 20px;
                font-size: 1rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <header>
            <h1>Welcome to Real Estate AI</h1>
            <p>Your trusted partner for intelligent property solutions</p>

            <!-- Search Bar -->
            <form action="properties.php" method="GET" class="search-container">
                <div class="search-box">
                    <input type="text" name="q" placeholder="Search properties, locations, or keywords...">
                    <button type="submit" class="search-btn">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>

            <!-- Navbar Links -->
            <div class="nav">
                <a href="index.php">Home</a>
                <a href="properties.php">Properties</a>
                <a href="about.html">About</a>
                <a href="contact.html">Contact</a>
            </div>

            <div class="auth">
                <a href="signin.html"><i class="fas fa-sign-in-alt"></i> Sign In</a>
                <a href="register.html"><i class="fas fa-user-plus"></i> Register</a>
            </div>
        </header>

        <!-- Features Section -->
        <section class="features">
            <h2 class="section-title">Our Features</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <h3>Find Your Dream Home</h3>
                    <p>
                        Browse a wide selection of properties that suit your needs and
                        preferences.
                    </p>
                    <button>Explore Now</button>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-tag"></i>
                    </div>
                    <h3>Sell Your Property</h3>
                    <p>
                        Let us help you connect with potential buyers through our
                        platform.
                    </p>
                    <button>List Your Property</button>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-robot"></i>
                    </div>
                    <h3>AI-Powered Tools</h3>
                    <p>
                        Utilize cutting-edge AI tools for property valuation, analysis,
                        and predictions.
                    </p>
                    <button>Learn More</button>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3>Track Transactions</h3>
                    <p>
                        Keep track of your property transactions and leads effortlessly.
                    </p>
                    <button>Get Started</button>
                </div>
            </div>
        </section>

        <!-- Top Properties Section -->
        <section class="property-section">
            <h2 class="section-title">Top Properties</h2>
            <p class="section-subtitle">Most popular listings this month</p>

            <div class="property-grid">
                <?php foreach ($topProperties as $index => $property): ?>
                    <div class="property-card">
                        <div class="property-image">
                            <img src="<?= $property['image_url'] ?>" alt="<?= $property['title'] ?>">
                            <button class="favorite-btn"><i class="far fa-heart"></i></button>
                            <?php if ($index == 0): ?>
                                <div class="property-badge">Featured</div>
                            <?php endif; ?>
                        </div>
                        <div class="property-details">
                            <h3><?= $property['title'] ?></h3>
                            <div class="property-price">$<?= number_format($property['price']) ?></div>
                            <div class="property-meta">
                                <span><i class="fas fa-bed"></i> <?= $property['bedrooms'] ?> Beds</span>
                                <span><i class="fas fa-bath"></i> <?= $property['bathrooms'] ?> Baths</span>
                                <span><i class="fas fa-ruler-combined"></i> <?= number_format($property['square_feet']) ?>
                                    sqft</span>
                            </div>
                            <div class="property-location">
                                <i class="fas fa-map-marker-alt"></i> <?= $property['city'] ?>, <?= $property['state'] ?>
                            </div>
                            <button class="view-btn">View Details</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Agent's Top Choice Section -->
        <section class="property-section">
            <h2 class="section-title">Agent's Top Choice</h2>
            <p class="section-subtitle">Recommended by our professional agents</p>

            <div class="property-grid">
                <?php foreach ($agentsChoice as $index => $property): ?>
                    <div class="property-card">
                        <div class="property-image">
                            <img src="<?= $property['image_url'] ?>" alt="<?= $property['title'] ?>">
                            <button class="favorite-btn"><i class="far fa-heart"></i></button>
                            <?php if ($index == 0): ?>
                                <div class="property-badge">Hot Deal</div>
                            <?php endif; ?>
                        </div>
                        <div class="property-details">
                            <h3><?= $property['title'] ?></h3>
                            <div class="property-price">$<?= number_format($property['price']) ?></div>
                            <div class="property-meta">
                                <span><i class="fas fa-bed"></i> <?= $property['bedrooms'] ?> Beds</span>
                                <span><i class="fas fa-bath"></i> <?= $property['bathrooms'] ?> Baths</span>
                                <span><i class="fas fa-ruler-combined"></i> <?= number_format($property['square_feet']) ?>
                                    sqft</span>
                            </div>
                            <div class="property-location">
                                <i class="fas fa-map-marker-alt"></i> <?= $property['city'] ?>, <?= $property['state'] ?>
                            </div>
                            <button class="view-btn">View Details</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Seller's Top Choice Section -->
        <section class="property-section">
            <h2 class="section-title">Seller's Top Choice</h2>
            <p class="section-subtitle">Properties with the best selling potential</p>

            <div class="property-grid">
                <?php foreach ($sellersChoice as $index => $property): ?>
                    <div class="property-card">
                        <div class="property-image">
                            <img src="<?= $property['image_url'] ?>" alt="<?= $property['title'] ?>">
                            <button class="favorite-btn"><i class="far fa-heart"></i></button>
                            <?php if ($index == 0): ?>
                                <div class="property-badge">Fast Selling</div>
                            <?php endif; ?>
                        </div>
                        <div class="property-details">
                            <h3><?= $property['title'] ?></h3>
                            <div class="property-price">$<?= number_format($property['price']) ?></div>
                            <div class="property-meta">
                                <span><i class="fas fa-bed"></i> <?= $property['bedrooms'] ?> Beds</span>
                                <span><i class="fas fa-bath"></i> <?= $property['bathrooms'] ?> Baths</span>
                                <span><i class="fas fa-ruler-combined"></i> <?= number_format($property['square_feet']) ?>
                                    sqft</span>
                            </div>
                            <div class="property-location">
                                <i class="fas fa-map-marker-alt"></i> <?= $property['city'] ?>, <?= $property['state'] ?>
                            </div>
                            <button class="view-btn">View Details</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Buyer's Top Choice Section -->
        <section class="property-section">
            <h2 class="section-title">Buyer's Top Choice</h2>
            <p class="section-subtitle">Most viewed properties by buyers</p>

            <div class="property-grid">
                <?php foreach ($buyersChoice as $index => $property): ?>
                    <div class="property-card">
                        <div class="property-image">
                            <img src="<?= $property['image_url'] ?>" alt="<?= $property['title'] ?>">
                            <button class="favorite-btn"><i class="far fa-heart"></i></button>
                            <?php if ($index == 0): ?>
                                <div class="property-badge">Popular</div>
                            <?php endif; ?>
                        </div>
                        <div class="property-details">
                            <h3><?= $property['title'] ?></h3>
                            <div class="property-price">$<?= number_format($property['price']) ?></div>
                            <div class="property-meta">
                                <span><i class="fas fa-bed"></i> <?= $property['bedrooms'] ?> Beds</span>
                                <span><i class="fas fa-bath"></i> <?= $property['bathrooms'] ?> Baths</span>
                                <span><i class="fas fa-ruler-combined"></i> <?= number_format($property['square_feet']) ?>
                                    sqft</span>
                            </div>
                            <div class="property-location">
                                <i class="fas fa-map-marker-alt"></i> <?= $property['city'] ?>, <?= $property['state'] ?>
                            </div>
                            <button class="view-btn">View Details</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="footer-logo">RealEstate AI</div>
                <p>Intelligent solutions for modern real estate needs</p>

                <div class="footer-links">
                    <a href="index.html">Home</a>
                    <a href="properties.html">Properties</a>
                    <a href="about.html">About Us</a>
                    <a href="contact.html">Contact</a>
                    <a href="terms.html">Terms</a>
                    <a href="privacy.html">Privacy</a>
                </div>

                <div class="copyright">
                    &copy; 2025 Real Estate AI. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

    <script>
        // Favorite button functionality
        document.querySelectorAll(".favorite-btn").forEach((button) => {
            button.addEventListener("click", function (e) {
                e.stopPropagation();
                const icon = this.querySelector("i");

                if (icon.classList.contains("far")) {
                    icon.classList.remove("far");
                    icon.classList.add("fas");
                    this.classList.add("active");

                    // In a real app, this would save to the database
                    const propertyTitle =
                        this.closest(".property-card").querySelector("h3").textContent;
                    console.log(`Added to favorites: ${propertyTitle}`);
                } else {
                    icon.classList.remove("fas");
                    icon.classList.add("far");
                    this.classList.remove("active");

                    // In a real app, this would remove from the database
                    const propertyTitle =
                        this.closest(".property-card").querySelector("h3").textContent;
                    console.log(`Removed from favorites: ${propertyTitle}`);
                }
            });
        });

        // View button functionality
        document.querySelectorAll(".view-btn").forEach((button) => {
            button.addEventListener("click", function () {
                const propertyTitle =
                    this.closest(".property-card").querySelector("h3").textContent;
                alert(`Viewing details for: ${propertyTitle}`);
            });
        });

        // Property card click functionality
        document.querySelectorAll(".property-card").forEach((card) => {
            card.addEventListener("click", function (e) {
                if (!e.target.closest("button")) {
                    const propertyTitle = this.querySelector("h3").textContent;
                    alert(`Viewing details for: ${propertyTitle}`);
                }
            });
        });

        // Search functionality - REMOVED ALERT
        document
            .querySelector(".search-btn")
            .addEventListener("click", function () {
                const searchTerm = document.querySelector(".search-box input").value;
                // Form will submit naturally - no alert needed
            });

        // Allow pressing Enter to search - REMOVED ALERT
        document
            .querySelector(".search-box input")
            .addEventListener("keyup", function (e) {
                if (e.key === "Enter") {
                    document.querySelector(".search-btn").click();
                }
            });

        // AJAX Search Functionality
        const searchForm = document.getElementById('search-form');
        const searchInput = document.getElementById('search-input');
        const searchResultsContainer = document.createElement('div');
        searchResultsContainer.id = 'search-results';
        document.querySelector('.container').appendChild(searchResultsContainer);

        searchForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const query = searchInput.value.trim();

            if (!query) return;

            try {
                const response = await fetch(`index_search.php?q=${encodeURIComponent(query)}`);
                const { success, data, error } = await response.json();

                if (!success) throw new Error(error || 'Search failed');

                displaySearchResults(data);
            } catch (err) {
                console.error('Search error:', err);
                alert('Search failed: ' + err.message);
            }
        });

        function displaySearchResults(properties) {
            if (properties.length === 0) {
                searchResultsContainer.innerHTML = `
      <div class="no-results" style="text-align: center; padding: 40px;">
        <h3>No properties found</h3>
        <p>Try different search terms</p>
      </div>
    `;
                return;
            }

            searchResultsContainer.innerHTML = `
    <section class="property-section">
      <h2 class="section-title">Search Results</h2>
      <div class="property-grid">
        ${properties.map(prop => `
          <div class="property-card">
            <div class="property-image">
              <img src="${prop.image_url}" alt="${prop.title}">
              <button class="favorite-btn"><i class="far fa-heart"></i></button>
            </div>
            <div class="property-details">
              <h3>${prop.title}</h3>
              <div class="property-price">$${prop.price.toLocaleString()}</div>
              <div class="property-meta">
                <span><i class="fas fa-bed"></i> ${prop.bedrooms} Beds</span>
                <span><i class="fas fa-bath"></i> ${prop.bathrooms} Baths</span>
              </div>
              <button class="view-btn">View Details</button>
            </div>
          </div>
        `).join('')}
      </div>
    </section>
  `;

            // Reattach event listeners to new elements
            attachPropertyEventListeners();
        }

        function attachPropertyEventListeners() {
            // Reattach favorite button handlers
            document.querySelectorAll('.favorite-btn').forEach(button => {
                button.addEventListener('click', handleFavoriteClick);
            });

            // Reattach view button handlers
            document.querySelectorAll('.view-btn').forEach(button => {
                button.addEventListener('click', handleViewClick);
            });
        }

        // Initialize event handlers
        function handleFavoriteClick(e) {
            // ... existing favorite logic ...
        }

        function handleViewClick(e) {
            // ... existing view logic ...
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            attachPropertyEventListeners();
        });
    </script>
</body>

</html>