<?php
/**
 * GA4 Dynamic Tracker - Main Tracker Class (Secure Version)
 *
 * @package GA4_Dynamic_Tracker
 */

if (!defined("ABSPATH")) {
	exit();
}

/**
 * GA4DT Tracker Class
 */
class GA4DT_Tracker
{
	/**
	 * Single instance
	 */
	private static $instance = null;

	/**
	 * Get instance
	 */
	public static function instance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Prevent cloning
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization
	 */
	public function __wakeup()
	{
		throw new \Exception("Cannot unserialize singleton");
	}

	/**
	 * Constructor
	 */
	private function __construct()
	{
		// Only run on frontend
		if (!GA4DT_Security::is_valid_frontend_request()) {
			return;
		}

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks()
	{
		// Data layer initialization (early in head)
		add_action("wp_head", [$this, "init_data_layer"], 1);

		// Product data attributes
		add_filter(
			"woocommerce_loop_add_to_cart_link",
			[$this, "add_product_data_attributes"],
			10,
			2,
		);
		add_filter("post_class", [$this, "add_product_classes"], 10, 3);

		// Footer scripts
		add_action("wp_footer", [$this, "ultra_dynamic_tracking_script"], 5);
		add_action("wp_footer", [$this, "single_product_view"], 10);
		add_action("wp_footer", [$this, "single_product_data_script"], 10);
		// Note: view_item_list is handled by JavaScript GA4UltraTracker to avoid duplicates
		add_action("wp_footer", [$this, "track_payment_method"], 10);
		add_action("wp_footer", [$this, "track_shipping_method"], 10);
		add_action("wp_footer", [$this, "remove_from_cart_script"], 10);
		add_action("wp_footer", [$this, "debug_helper"], 999);

		// WooCommerce hooks
		add_action("woocommerce_before_cart", [$this, "view_cart"]);
		add_action("woocommerce_before_checkout_form", [
			$this,
			"begin_checkout",
		]);
		add_action("woocommerce_thankyou", [$this, "purchase_event"]);
	}

	/**
	 * Get product data with sale price support (secured)
	 */
	public function get_product_data($product, $quantity = 1, $index = 0)
	{
		if (!$product || !is_a($product, "WC_Product")) {
			return null;
		}

		$categories = [];
		$terms = get_the_terms($product->get_id(), "product_cat");
		if ($terms && !is_wp_error($terms)) {
			foreach ($terms as $term) {
				$categories[] = GA4DT_Security::sanitize_category($term->name);
			}
		}

		// Get prices - handle sale price (always 2 decimal places)
		$regular_price = floatval($product->get_regular_price());
		$sale_price = floatval($product->get_sale_price());
		$current_price = floatval($product->get_price());

		// Determine if product is on sale
		$is_on_sale = $product->is_on_sale();

		// Calculate discount amount and percentage
		$discount_amount = 0;
		$discount_percentage = 0;

		if ($is_on_sale && $regular_price > 0 && $sale_price > 0) {
			$discount_amount = $regular_price - $sale_price;
			$discount_percentage = round(
				($discount_amount / $regular_price) * 100,
				2,
			);
		}

		$sku = $product->get_sku();
		$data = [
			"item_id" => $sku
				? GA4DT_Security::sanitize_sku($sku)
				: (string) absint($product->get_id()),
			"item_name" => GA4DT_Security::sanitize_product_name(
				$product->get_name(),
			),
			"item_brand" => GA4DT_Security::sanitize_product_name(
				get_bloginfo("name"),
			),
			"price" => number_format($current_price, 2, ".", ""),
			"quantity" => GA4DT_Security::sanitize_quantity($quantity),
		];

		// Add sale price information if product is on sale
		if ($is_on_sale && $regular_price > 0) {
			$data["item_original_price"] = number_format(
				$regular_price,
				2,
				".",
				"",
			);
			$data["discount"] = number_format($discount_amount, 2, ".", "");
			$data["discount_percentage"] = number_format(
				$discount_percentage,
				2,
				".",
				"",
			);
			$data["item_on_sale"] = true;
		} else {
			$data["item_original_price"] = number_format(
				$current_price,
				2,
				".",
				"",
			);
			$data["item_on_sale"] = false;
		}

		if (!empty($categories)) {
			$data["item_category"] = $categories[0];
			if (isset($categories[1])) {
				$data["item_category2"] = $categories[1];
			}
			if (isset($categories[2])) {
				$data["item_category3"] = $categories[2];
			}
		}

		if ($index > 0) {
			$data["index"] = absint($index);
		}

		return $data;
	}

	/**
	 * Initialize data layer with user data (secured)
	 */
	public function init_data_layer()
	{
		$user_id = get_current_user_id();

		// Determine user status
		$user_status = "Guest";
		if ($user_id > 0) {
			$customer_orders = wc_get_orders([
				"customer_id" => $user_id,
				"status" => ["wc-completed", "wc-processing"],
				"limit" => 1,
			]);
			$user_status = !empty($customer_orders) ? "Customer" : "Registered";
		}
		$user_status = GA4DT_Security::sanitize_user_status($user_status);

		// Get first and last purchase dates
		$first_purchase_date = "";
		$last_purchase_date = "";

		if ($user_id > 0) {
			$all_orders = wc_get_orders([
				"customer_id" => $user_id,
				"status" => ["wc-completed", "wc-processing"],
				"limit" => -1,
				"orderby" => "date",
				"order" => "ASC",
			]);

			if (!empty($all_orders)) {
				$first_order = reset($all_orders);
				$last_order = end($all_orders);
				$first_purchase_date = GA4DT_Security::sanitize_date(
					$first_order->get_date_created()->format("Y-m-d"),
				);
				$last_purchase_date = GA4DT_Security::sanitize_date(
					$last_order->get_date_created()->format("Y-m-d"),
				);
			}
		}

		// Get first and last visit dates
		$first_visit_date = "";
		$last_visit_date = GA4DT_Security::sanitize_date(gmdate("Y-m-d"));

		if ($user_id > 0) {
			$first_visit_date = get_user_meta(
				$user_id,
				"_ga4_first_visit",
				true,
			);
			if (empty($first_visit_date)) {
				$first_visit_date = GA4DT_Security::sanitize_date(
					gmdate("Y-m-d"),
				);
				update_user_meta(
					$user_id,
					"_ga4_first_visit",
					$first_visit_date,
				);
			} else {
				$first_visit_date = GA4DT_Security::sanitize_date(
					$first_visit_date,
				);
			}
			update_user_meta($user_id, "_ga4_last_visit", $last_visit_date);
		} else {
			$cookie_first_visit = GA4DT_Security::get_secure_cookie(
				"ga4_first_visit",
			);
			if (empty($cookie_first_visit)) {
				$first_visit_date = GA4DT_Security::sanitize_date(
					gmdate("Y-m-d"),
				);
				GA4DT_Security::set_secure_cookie(
					"ga4_first_visit",
					$first_visit_date,
				);
			} else {
				$first_visit_date = GA4DT_Security::sanitize_date(
					$cookie_first_visit,
				);
			}
			GA4DT_Security::set_secure_cookie(
				"ga4_last_visit",
				$last_visit_date,
			);
		}

		// Create hashed user ID for privacy compliance
		$hashed_user_id =
			$user_id > 0
				? GA4DT_Security::hash_user_id($user_id)
				: GA4DT_Security::hash_guest_id();

		// Determine page type
		$page_type = "other";
		if (function_exists("is_product") && is_product()) {
			$page_type = "product";
		} elseif (
			function_exists("is_product_category") &&
			is_product_category()
		) {
			$page_type = "category";
		} elseif (function_exists("is_shop") && is_shop()) {
			$page_type = "shop";
		} elseif (function_exists("is_cart") && is_cart()) {
			$page_type = "cart";
		} elseif (function_exists("is_checkout") && is_checkout()) {
			$page_type = "checkout";
		} elseif (
			function_exists("is_order_received_page") &&
			is_order_received_page()
		) {
			$page_type = "purchase";
		}

		$currency = GA4DT_Security::sanitize_currency(
			get_woocommerce_currency(),
		);
		$is_debug = defined("WP_DEBUG") && WP_DEBUG;

		// Build page data
		$page_data = [
			"pageType" => esc_js($page_type),
			"currency" => esc_js($currency),
			"userStatus" => esc_js($user_status),
			"hashedUserId" => esc_js($hashed_user_id),
			"siteName" => esc_js(get_bloginfo("name")),
		];

		if (is_product_category()) {
			$page_data["categoryName"] = esc_js(single_cat_title("", false));
			$page_data["categoryId"] = absint(get_queried_object_id());
		}

		if (is_product()) {
			$page_data["productId"] = absint(get_the_ID());
		}
		?>
        <script>
        window.dataLayer = window.dataLayer || [];

        dataLayer.push({
            event: 'pageview',
            user_status: '<?php echo esc_js($user_status); ?>',
            user_type: '<?php echo $user_id > 0 ? "Logged In" : "Guest"; ?>',
            <?php if (!empty($first_purchase_date)): ?>
            first_purchase_date: '<?php echo esc_js($first_purchase_date); ?>',
            <?php endif; ?>
            <?php if (!empty($last_purchase_date)): ?>
            last_purchase_date: '<?php echo esc_js($last_purchase_date); ?>',
            <?php endif; ?>
            user_id: '<?php echo esc_js($hashed_user_id); ?>',
            first_visit_date: '<?php echo esc_js($first_visit_date); ?>',
            last_visit_date: '<?php echo esc_js($last_visit_date); ?>'
        });

        window.ga4Config = {
            currency: '<?php echo esc_js($currency); ?>',
            debug: <?php echo $is_debug ? "true" : "false"; ?>,
            trackingEnabled: true,
            autoTrackImpressions: true,
            impressionThreshold: 0.5,
            debounceDelay: 300,
            nonce: '<?php echo esc_js(GA4DT_Security::create_nonce()); ?>'
        };

        window.ga4PageData = <?php echo wp_json_encode($page_data); ?>;
        </script>
        <?php
	}

	/**
	 * Output single product data for JavaScript (secured)
	 */
	public function single_product_data_script()
	{
		if (!is_product()) {
			return;
		}

		global $product;
		if (!$product || !is_a($product, "WC_Product")) {
			return;
		}

		$product_data = $this->get_product_data($product);
		if (!$product_data) {
			return;
		}
		?>
        <script>
        window.ga4SingleProduct = <?php echo GA4DT_Security::json_encode_safe(
        	$product_data,
        ); ?>;
        </script>
        <?php
	}

	/**
	 * Add product data attributes to add to cart links (secured)
	 */
	public function add_product_data_attributes($html, $product)
	{
		if (!$product || !is_a($product, "WC_Product")) {
			return $html;
		}

		$product_data = $this->get_product_data($product);

		if (!$product_data) {
			return $html;
		}

		$data_attrs = sprintf(
			'data-ga4-id="%s" data-ga4-name="%s" data-ga4-price="%s" data-ga4-original-price="%s" data-ga4-on-sale="%s" data-ga4-category="%s"',
			esc_attr($product_data["item_id"]),
			esc_attr($product_data["item_name"]),
			esc_attr($product_data["price"]),
			esc_attr($product_data["item_original_price"]),
			esc_attr($product_data["item_on_sale"] ? "true" : "false"),
			esc_attr(
				isset($product_data["item_category"])
					? $product_data["item_category"]
					: "",
			),
		);

		$html = str_replace('class="', $data_attrs . ' class="', $html);

		return $html;
	}

	/**
	 * Add tracking classes to products
	 */
	public function add_product_classes($classes, $class, $post_id)
	{
		$post_id = absint($post_id);

		if (get_post_type($post_id) !== "product") {
			return $classes;
		}

		$classes[] = "ga4-trackable-product";

		return $classes;
	}

	/**
	 * Ultra dynamic tracking script (secured)
	 */
	public function ultra_dynamic_tracking_script()
	{
		?>
        <script>
        (function() {
            'use strict';

            const GA4UltraTracker = {
                config: window.ga4Config || {},
                pageData: window.ga4PageData || {},
                trackedContainers: new WeakSet(),
                trackedProducts: new WeakMap(),
                impressionObserver: null,
                clickDebounce: {},
                rescanTimeout: null,

                // Sanitize string for safety
                sanitizeString: function(str) {
                    if (typeof str !== 'string') return '';
                    const div = document.createElement('div');
                    div.textContent = str;
                    return div.innerHTML.substring(0, 500); // Limit length
                },

                // Sanitize number (always 2 decimal places)
                sanitizeNumber: function(num) {
                    const parsed = parseFloat(num);
                    return isNaN(parsed) ? '0.00' : parsed.toFixed(2);
                },

                pushEvent: function(eventName, data, dedupe) {
                    if (dedupe === undefined) dedupe = true;
                    if (!this.config.trackingEnabled) return;

                    if (dedupe) {
                        const eventKey = eventName + JSON.stringify(data.ecommerce?.items || []);
                        const now = Date.now();
                        if (this.clickDebounce[eventKey] && (now - this.clickDebounce[eventKey]) < 1000) {
                            if (this.config.debug) console.log('⚠️ Deduplicated:', eventName);
                            return;
                        }
                        this.clickDebounce[eventKey] = now;
                    }

                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push({ ecommerce: null });
                    window.dataLayer.push(data);

                    if (this.config.debug) {
                        console.log('📊 GA4 Event:', eventName, data);
                    }
                },

                getProductData: function(element, skipCache) {
                    if (skipCache === undefined) skipCache = false;
                    if (!skipCache && this.trackedProducts.has(element)) {
                        return this.trackedProducts.get(element);
                    }

                    let productData = null;

                    if (element.hasAttribute('data-ga4-id')) {
                        productData = {
                            item_id: this.sanitizeString(element.getAttribute('data-ga4-id')),
                            item_name: this.sanitizeString(element.getAttribute('data-ga4-name')),
                            price: this.sanitizeNumber(element.getAttribute('data-ga4-price')),
                            item_original_price: this.sanitizeNumber(element.getAttribute('data-ga4-original-price')),
                            item_on_sale: element.getAttribute('data-ga4-on-sale') === 'true',
                            item_category: this.sanitizeString(element.getAttribute('data-ga4-category'))
                        };

                        if (productData.item_on_sale && parseFloat(productData.item_original_price) > parseFloat(productData.price)) {
                            productData.discount = (parseFloat(productData.item_original_price) - parseFloat(productData.price)).toFixed(2);
                            productData.discount_percentage = ((parseFloat(productData.discount) / parseFloat(productData.item_original_price)) * 100).toFixed(2);
                        }
                    }

                    if (!productData) {
                        productData = this.extractProductFromDOM(element);
                    }

                    if (productData) {
                        this.trackedProducts.set(element, productData);
                    }

                    return productData;
                },

                extractProductFromDOM: function(element) {
                    const productItem = element.closest(
                        'li.product, ' +
                        '.product-item, ' +
                        '.kitify-product, ' +
                        '.elementor-post, ' +
                        '.e-loop-item, ' +
                        '[class*="product"]'
                    );

                    if (!productItem) return null;

                    const classList = productItem.className;
                    const idMatch = classList.match(/post-(\d+)/);
                    let productId = idMatch ? idMatch[1] : '';

                    if (!productId) {
                        productId = productItem.getAttribute('data-id') || '';
                    }

                    const nameSelectors = [
                        '.woocommerce-loop-product__title',
                        '.product-title',
                        '.elementor-post__title',
                        '.elementor-heading-title',
                        'h2', 'h3', 'h4',
                        '.product-name'
                    ];
                    let productName = '';
                    for (let i = 0; i < nameSelectors.length; i++) {
                        const nameEl = productItem.querySelector(nameSelectors[i]);
                        if (nameEl && nameEl.textContent.trim()) {
                            productName = this.sanitizeString(nameEl.textContent.trim());
                            break;
                        }
                    }

                    let currentPrice = 0;
                    let originalPrice = 0;
                    let isOnSale = false;

                    const priceContainer = productItem.querySelector('.price');
                    if (priceContainer) {
                        const salePriceEl = priceContainer.querySelector('ins .woocommerce-Price-amount bdi, ins .amount bdi');
                        const regularPriceEl = priceContainer.querySelector('del .woocommerce-Price-amount bdi, del .amount bdi');

                        if (salePriceEl && regularPriceEl) {
                            isOnSale = true;
                            currentPrice = this.sanitizeNumber(salePriceEl.textContent.replace(/[^0-9.]/g, ''));
                            originalPrice = this.sanitizeNumber(regularPriceEl.textContent.replace(/[^0-9.]/g, ''));
                        } else {
                            const normalPriceEl = priceContainer.querySelector('.woocommerce-Price-amount bdi:not(del .woocommerce-Price-amount bdi)');
                            if (normalPriceEl) {
                                currentPrice = this.sanitizeNumber(normalPriceEl.textContent.replace(/[^0-9.]/g, ''));
                                originalPrice = currentPrice;
                            }
                        }
                    }

                    if (currentPrice === 0) {
                        const fallbackSelectors = [
                            '.product-price .amount',
                            '.elementor-price',
                            '[class*="price"] .amount'
                        ];

                        for (let i = 0; i < fallbackSelectors.length; i++) {
                            const priceEl = productItem.querySelector(fallbackSelectors[i]);
                            if (priceEl) {
                                currentPrice = this.sanitizeNumber(priceEl.textContent.replace(/[^0-9.]/g, ''));
                                originalPrice = currentPrice;
                                if (currentPrice > 0) break;
                            }
                        }
                    }

                    const categoryEl = productItem.querySelector(
                        '.product-item__category a, ' +
                        '.content-product-cat, ' +
                        '.elementor-post__terms, ' +
                        '.product-category'
                    );
                    const category = categoryEl ? this.sanitizeString(categoryEl.textContent.trim()) : '';

                    const addToCartBtn = productItem.querySelector('.add_to_cart_button');
                    let sku = productId;
                    if (addToCartBtn) {
                        sku = addToCartBtn.getAttribute('data-product_sku') ||
                              addToCartBtn.getAttribute('data-ga4-id') ||
                              productId;
                    }

                    if (!productId && !productName) return null;

                    const productData = {
                        item_id: this.sanitizeString(String(sku || productId)),
                        item_name: productName || 'Unknown Product',
                        price: currentPrice,
                        item_original_price: originalPrice,
                        item_on_sale: isOnSale,
                        item_category: category
                    };

                    if (isOnSale && parseFloat(originalPrice) > parseFloat(currentPrice)) {
                        productData.discount = (parseFloat(originalPrice) - parseFloat(currentPrice)).toFixed(2);
                        productData.discount_percentage = (((parseFloat(originalPrice) - parseFloat(currentPrice)) / parseFloat(originalPrice)) * 100).toFixed(2);
                    }

                    return productData;
                },

                detectContainers: function() {
                    const containerSelectors = [
                        '.kitify-products__list',
                        'ul.products.kitify-products__list',
                        '.elementor-loop-container',
                        '.e-loop-container',
                        '.elementor-posts-container',
                        '.elementor-posts',
                        'ul.products.columns-1',
                        'ul.products.columns-2',
                        'ul.products.columns-3',
                        'ul.products.columns-4',
                        'ul.products.columns-5',
                        'ul.products.columns-6'
                    ];

                    const containers = document.querySelectorAll(containerSelectors.join(', '));
                    const self = this;

                    containers.forEach(function(container) {
                        if (!self.trackedContainers.has(container)) {
                            self.trackContainer(container);
                        }
                    });
                },

                trackContainer: function(container) {
                    const products = this.getContainerProducts(container);

                    if (products.length === 0) return;

                    const widget = container.closest('.elementor-widget, .widget, [class*="widget"]');
                    const widgetId = widget ?
                        (widget.getAttribute('data-id') ||
                         widget.getAttribute('id') ||
                         'widget_' + Math.random().toString(36).substr(2, 9)) :
                        'container_' + Math.random().toString(36).substr(2, 9);

                    const listName = this.getListName(container);
                    const listId = this.getListId(container, widgetId);

                    this.trackedContainers.add(container);

                    // Determine event name based on page type
                    const eventName = this.getItemListEventName();

                    this.pushEvent(eventName, {
                        event: eventName,
                        ecommerce: {
                            item_list_id: listId,
                            item_list_name: listName,
                            items: products
                        },
                        container_type: this.getContainerType(container),
                        widget_id: widgetId,
                        page_type: this.pageData.pageType
                    }, false);

                    if (this.config.autoTrackImpressions) {
                        this.trackImpressions(container, listName, listId);
                    }

                    this.trackContainerClicks(container, listName, listId);
                },

                getContainerProducts: function(container) {
                    const uniqueProducts = new Map();
                    const self = this;

                    let selector = ':scope > li.product';

                    if (container.classList.contains('kitify-products__list')) {
                        selector = ':scope > li.product, :scope > li.kitify-product';
                    } else if (container.classList.contains('elementor-posts') ||
                               container.classList.contains('elementor-loop-container') ||
                               container.classList.contains('elementor-posts-container')) {
                        selector = ':scope > .elementor-post, :scope > .e-loop-item';
                    } else if (container.classList.contains('e-loop-container')) {
                        selector = ':scope > .e-loop-item';
                    }

                    const productElements = container.querySelectorAll(selector);

                    productElements.forEach(function(element) {
                        const productData = self.getProductData(element);

                        if (productData && productData.item_id) {
                            if (!uniqueProducts.has(productData.item_id)) {
                                uniqueProducts.set(productData.item_id, productData);
                            }
                        }
                    });

                    const items = [];
                    let position = 1;
                    uniqueProducts.forEach(function(productData) {
                        productData.index = position;
                        items.push(productData);
                        position++;
                    });

                    return items;
                },

                getListName: function(container) {
                    // First, check page type - prioritize shop/category names
                    if (this.pageData.pageType === 'shop') {
                        return 'Shop';
                    }

                    if (this.pageData.pageType === 'category' && this.pageData.categoryName) {
                        return this.sanitizeString(this.pageData.categoryName);
                    }

                    // For other pages (product, cart, etc.), try to find a section heading
                    // but exclude product titles inside the container
                    const widget = container.closest('.elementor-widget, .elementor-section, .elementor-column, .widget');
                    if (widget) {
                        // Look for headings that are NOT inside product items
                        const headingEl = widget.querySelector(
                            '.elementor-heading-title:not(li.product .elementor-heading-title), ' +
                            '.widget-title, ' +
                            '.elementor-widget-heading .elementor-heading-title, ' +
                            'h2.section-title, h3.section-title'
                        );

                        if (headingEl && headingEl.textContent.trim()) {
                            // Make sure it's not a product title
                            const isProductTitle = headingEl.closest('li.product, .product-item, .kitify-product, .elementor-post, .e-loop-item');
                            if (!isProductTitle) {
                                return this.sanitizeString(headingEl.textContent.trim());
                            }
                        }
                    }

                    // Fallback names based on page type
                    if (this.pageData.pageType === 'product') {
                        return 'Related Products';
                    } else if (this.pageData.pageType === 'cart') {
                        return 'Cart Recommendations';
                    } else if (this.pageData.pageType === 'checkout') {
                        return 'Checkout Recommendations';
                    }

                    // Container type fallbacks
                    if (container.classList.contains('kitify-products__list')) {
                        return 'Product Grid';
                    } else if (container.classList.contains('elementor-loop-container')) {
                        return 'Product Loop';
                    }

                    return 'Product List';
                },

                getListId: function(container, widgetId) {
                    if (this.pageData.pageType === 'category' && this.pageData.categoryId) {
                        return 'category_' + this.pageData.categoryId;
                    } else if (this.pageData.pageType === 'shop') {
                        return 'shop_page';
                    }

                    if (container.classList.contains('kitify-products__list')) {
                        return 'kitify_' + widgetId;
                    } else if (container.classList.contains('elementor-loop-container')) {
                        return 'elementor_loop_' + widgetId;
                    }

                    return 'list_' + widgetId;
                },

                getContainerType: function(container) {
                    if (container.classList.contains('kitify-products__list')) return 'kitify';
                    if (container.classList.contains('elementor-loop-container')) return 'elementor_loop';
                    if (container.classList.contains('elementor-posts')) return 'elementor_posts';
                    if (container.tagName === 'UL' && container.classList.contains('products')) return 'woocommerce';
                    return 'generic';
                },

                // Get event name based on page type
                getItemListEventName: function() {
                    const pageType = this.pageData.pageType || 'other';

                    switch (pageType) {
                        case 'shop':
                            return 'view_item_list';
                        case 'category':
                            return 'view_category_item_list';
                        case 'product':
                            return 'view_related_item_list';
                        case 'cart':
                            return 'view_cart_item_list';
                        case 'checkout':
                            return 'view_checkout_item_list';
                        case 'purchase':
                            return 'view_purchase_item_list';
                        default:
                            return 'view_' + pageType + '_item_list';
                    }
                },

                trackImpressions: function(container, listName, listId) {
                    if (!window.IntersectionObserver) return;

                    const self = this;
                    const productElements = container.querySelectorAll(
                        ':scope > li.product, ' +
                        ':scope > li.kitify-product, ' +
                        ':scope > .elementor-post, ' +
                        ':scope > .e-loop-item'
                    );

                    const observer = new IntersectionObserver(function(entries) {
                        entries.forEach(function(entry) {
                            if (entry.isIntersecting && entry.intersectionRatio >= self.config.impressionThreshold) {
                                const productData = self.getProductData(entry.target);
                                if (productData && self.config.debug) {
                                    console.log('👁️ Product Impression:', productData.item_name, productData.item_on_sale ? '(ON SALE)' : '');
                                }
                                observer.unobserve(entry.target);
                            }
                        });
                    }, { threshold: this.config.impressionThreshold });

                    productElements.forEach(function(el) {
                        observer.observe(el);
                    });
                },

                trackContainerClicks: function(container, listName, listId) {
                    const self = this;

                    container.addEventListener('click', function(e) {
                        const productElement = e.target.closest(
                            'li.product, ' +
                            'li.kitify-product, ' +
                            '.elementor-post, ' +
                            '.e-loop-item'
                        );

                        if (!productElement) return;

                        if (e.target.closest('.add_to_cart_button, button, .button')) return;

                        const productData = self.getProductData(productElement);
                        if (productData && productData.item_id) {
                            self.pushEvent('select_item', {
                                event: 'select_item',
                                ecommerce: {
                                    item_list_id: listId,
                                    item_list_name: listName,
                                    items: [productData]
                                }
                            });
                        }
                    }, true);
                },

                trackAddToCart: function() {
                    var self = this;

                    // Handle AJAX add to cart (product listing pages)
                    if (typeof jQuery !== 'undefined') {
                        jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, button) {
                            var productData = self.getProductData(button[0]);
                            var quantity = parseInt(button.data('quantity')) || 1;

                            if (productData) {
                                productData.quantity = quantity;
                                self.pushEvent('add_to_cart', {
                                    event: 'add_to_cart',
                                    ecommerce: {
                                        currency: self.config.currency,
                                        value: (parseFloat(productData.price) * quantity).toFixed(2),
                                        items: [productData]
                                    }
                                });
                            }
                        });
                    }

                    // Handle single product page add to cart
                    var singleAddToCart = document.querySelector('form.cart button[type="submit"]');
                    if (singleAddToCart) {
                        singleAddToCart.addEventListener('click', function() {
                            var form = this.closest('form.cart');
                            var quantityInput = form ? form.querySelector('input.qty, input[name="quantity"]') : null;
                            var quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;

                            // Get product data from server-rendered single product data
                            var productData = self.getSingleProductData();

                            if (productData) {
                                setTimeout(function() {
                                    productData.quantity = quantity;
                                    self.pushEvent('add_to_cart', {
                                        event: 'add_to_cart',
                                        ecommerce: {
                                            currency: self.config.currency,
                                            value: (parseFloat(productData.price) * quantity).toFixed(2),
                                            items: [productData]
                                        }
                                    });
                                }, 100);
                            }
                        });
                    }
                },

                // Get product data specifically for single product page
                getSingleProductData: function() {
                    // Use server-rendered product data first (most reliable)
                    if (window.ga4SingleProduct) {
                        // Return a copy to avoid mutation
                        return Object.assign({}, window.ga4SingleProduct);
                    }

                    // Fallback: Extract from DOM on single product page
                    var productName = '';
                    var productId = '';
                    var sku = '';
                    var currentPrice = 0;
                    var originalPrice = 0;
                    var isOnSale = false;
                    var category = '';

                    // Get product name from title
                    var titleEl = document.querySelector('.product_title, h1.entry-title');
                    if (titleEl) {
                        productName = this.sanitizeString(titleEl.textContent.trim());
                    }

                    // Get product ID from body class or form
                    var bodyClass = document.body.className;
                    var idMatch = bodyClass.match(/postid-(\d+)/);
                    if (idMatch) {
                        productId = idMatch[1];
                    }

                    // Get SKU from product meta
                    var skuEl = document.querySelector('.sku, .product_meta .sku');
                    if (skuEl) {
                        sku = this.sanitizeString(skuEl.textContent.trim());
                    }

                    // Get price from single product price display
                    var priceContainer = document.querySelector('.summary .price, .product .price, .elementor-widget-woocommerce-product-price .price');
                    if (priceContainer) {
                        var salePriceEl = priceContainer.querySelector('ins .woocommerce-Price-amount bdi');
                        var regularPriceEl = priceContainer.querySelector('del .woocommerce-Price-amount bdi');

                        if (salePriceEl && regularPriceEl) {
                            isOnSale = true;
                            currentPrice = this.sanitizeNumber(salePriceEl.textContent.replace(/[^0-9.]/g, ''));
                            originalPrice = this.sanitizeNumber(regularPriceEl.textContent.replace(/[^0-9.]/g, ''));
                        } else {
                            var normalPriceEl = priceContainer.querySelector('.woocommerce-Price-amount bdi');
                            if (normalPriceEl) {
                                currentPrice = this.sanitizeNumber(normalPriceEl.textContent.replace(/[^0-9.]/g, ''));
                                originalPrice = currentPrice;
                            }
                        }
                    }

                    // Get category
                    var categoryEl = document.querySelector('.posted_in a, .product_meta .posted_in a');
                    if (categoryEl) {
                        category = this.sanitizeString(categoryEl.textContent.trim());
                    }

                    if (!productId && !productName) {
                        return null;
                    }

                    var productData = {
                        item_id: this.sanitizeString(String(sku || productId)),
                        item_name: productName || 'Unknown Product',
                        item_brand: this.sanitizeString(this.pageData.siteName || ''),
                        price: currentPrice,
                        item_original_price: originalPrice,
                        item_on_sale: isOnSale,
                        item_category: category
                    };

                    if (isOnSale && parseFloat(originalPrice) > parseFloat(currentPrice)) {
                        productData.discount = (parseFloat(originalPrice) - parseFloat(currentPrice)).toFixed(2);
                        productData.discount_percentage = (((parseFloat(originalPrice) - parseFloat(currentPrice)) / parseFloat(originalPrice)) * 100).toFixed(2);
                    }

                    return productData;
                },

                observeDynamicContent: function() {
                    const self = this;

                    const observer = new MutationObserver(function(mutations) {
                        let shouldRescan = false;

                        mutations.forEach(function(mutation) {
                            mutation.addedNodes.forEach(function(node) {
                                if (node.nodeType === 1) {
                                    if (node.matches && (
                                        node.matches('li.product, .elementor-post, .e-loop-item, .kitify-product') ||
                                        node.querySelector('li.product, .elementor-post, .e-loop-item, .kitify-product')
                                    )) {
                                        shouldRescan = true;
                                    }
                                }
                            });
                        });

                        if (shouldRescan) {
                            clearTimeout(self.rescanTimeout);
                            self.rescanTimeout = setTimeout(function() {
                                if (self.config.debug) console.log('🔄 Rescanning for new products...');
                                self.detectContainers();
                            }, self.config.debounceDelay);
                        }
                    });

                    observer.observe(document.body, {
                        childList: true,
                        subtree: true
                    });
                },

                init: function() {
                    if (this.config.debug) {
                        console.log('🚀 GA4 Ultra Tracker Initialized', {
                            config: this.config,
                            pageData: this.pageData
                        });
                    }

                    this.detectContainers();
                    this.trackAddToCart();
                    this.observeDynamicContent();

                    if (typeof jQuery !== 'undefined') {
                        const self = this;
                        jQuery(document).on('yith-wcan-ajax-filtered', function() { self.detectContainers(); });
                        jQuery(document.body).on('updated_wc_div', function() { self.detectContainers(); });
                    }

                    if (typeof elementorFrontend !== 'undefined' && elementorFrontend.hooks) {
                        const self = this;
                        elementorFrontend.hooks.addAction('frontend/element_ready/widget', function() {
                            setTimeout(function() { self.detectContainers(); }, 200);
                        });
                    }
                }
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() { GA4UltraTracker.init(); });
            } else {
                GA4UltraTracker.init();
            }

            window.GA4UltraTracker = GA4UltraTracker;

        })();
        </script>
        <?php
	}

	/**
	 * Single product view event (secured)
	 */
	public function single_product_view()
	{
		if (!is_product()) {
			return;
		}

		global $product;
		if (!$product || !is_a($product, "WC_Product")) {
			return;
		}

		$product_data = $this->get_product_data($product);
		if (!$product_data) {
			return;
		}

		$currency = GA4DT_Security::sanitize_currency(
			get_woocommerce_currency(),
		);
		?>
        <script>
        dataLayer.push({ ecommerce: null });
        dataLayer.push({
            event: 'view_item',
            ecommerce: {
                currency: '<?php echo esc_js($currency); ?>',
                value: <?php echo number_format(
                	floatval($product_data["price"]),
                	2,
                	".",
                	"",
                ); ?>,
                items: [<?php echo GA4DT_Security::json_encode_safe(
                	$product_data,
                ); ?>]
            }
        });
        </script>
        <?php
	}

	/**
	 * View item list event (secured)
	 * Note: This function is kept as a fallback but NOT hooked by default.
	 * The JavaScript GA4UltraTracker handles view_item_list dynamically to prevent duplicates.
	 * Enable this only if you need server-side tracking without JavaScript.
	 */
	public function view_item_list()
	{
		if (!is_shop() && !is_product_category() && !is_product_tag()) {
			return;
		}

		global $wp_query;

		$items = [];
		$position = 1;
		$original_query = $wp_query;

		if ($wp_query->have_posts()) {
			while ($wp_query->have_posts()) {
				$wp_query->the_post();
				global $product;

				if (!$product || !is_a($product, "WC_Product")) {
					continue;
				}

				$product_data = $this->get_product_data($product, 1, $position);
				if ($product_data) {
					$items[] = $product_data;
					$position++;
				}

				// Limit to prevent excessive data
				if ($position > 100) {
					break;
				}
			}
			wp_reset_postdata();
		}

		$wp_query = $original_query;

		if (empty($items)) {
			return;
		}

		$list_id = "shop_page";
		$list_name = "Shop";

		if (is_product_category()) {
			$category = get_queried_object();
			if ($category && isset($category->term_id)) {
				$list_id = "category_" . absint($category->term_id);
				$list_name = GA4DT_Security::sanitize_category($category->name);
			}
		} elseif (is_product_tag()) {
			$tag = get_queried_object();
			if ($tag && isset($tag->term_id)) {
				$list_id = "tag_" . absint($tag->term_id);
				$list_name = GA4DT_Security::sanitize_category($tag->name);
			}
		}

		// Determine event name based on page type
		$event_name = "view_item_list"; // default for shop
		if (is_product_category()) {
			$event_name = "view_category_item_list";
		} elseif (is_product_tag()) {
			$event_name = "view_tag_item_list";
		}
		?>
        <script>
        dataLayer.push({ ecommerce: null });
        dataLayer.push({
            event: '<?php echo esc_js($event_name); ?>',
            ecommerce: {
                item_list_id: '<?php echo esc_js($list_id); ?>',
                item_list_name: '<?php echo esc_js($list_name); ?>',
                items: <?php echo GA4DT_Security::json_encode_safe($items); ?>
            }
        });
        </script>
        <?php
	}

	/**
	 * View cart event (secured)
	 */
	public function view_cart()
	{
		$cart = WC()->cart;
		if (!$cart || $cart->is_empty()) {
			return;
		}

		$items = [];
		foreach ($cart->get_cart() as $cart_item) {
			$product = $cart_item["data"];
			if (!$product || !is_a($product, "WC_Product")) {
				continue;
			}
			$product_data = $this->get_product_data(
				$product,
				$cart_item["quantity"],
			);
			if ($product_data) {
				$items[] = $product_data;
			}
		}

		if (empty($items)) {
			return;
		}

		$currency = GA4DT_Security::sanitize_currency(
			get_woocommerce_currency(),
		);
		$cart_total = GA4DT_Security::sanitize_price(
			$cart->get_cart_contents_total(),
		);
		?>
        <script>
        dataLayer.push({ ecommerce: null });
        dataLayer.push({
            event: 'view_cart',
            ecommerce: {
                currency: '<?php echo esc_js($currency); ?>',
                value: <?php echo number_format(
                	floatval($cart_total),
                	2,
                	".",
                	"",
                ); ?>,
                items: <?php echo GA4DT_Security::json_encode_safe($items); ?>
            }
        });
        </script>
        <?php
	}

	/**
	 * Begin checkout event (secured)
	 */
	public function begin_checkout()
	{
		$cart = WC()->cart;
		if (!$cart || $cart->is_empty()) {
			return;
		}

		$items = [];
		foreach ($cart->get_cart() as $cart_item) {
			$product = $cart_item["data"];
			if (!$product || !is_a($product, "WC_Product")) {
				continue;
			}
			$product_data = $this->get_product_data(
				$product,
				$cart_item["quantity"],
			);
			if ($product_data) {
				$items[] = $product_data;
			}
		}

		if (empty($items)) {
			return;
		}

		$currency = GA4DT_Security::sanitize_currency(
			get_woocommerce_currency(),
		);
		$cart_total = GA4DT_Security::sanitize_price(
			$cart->get_cart_contents_total(),
		);
		?>
        <script>
        dataLayer.push({ ecommerce: null });
        dataLayer.push({
            event: 'begin_checkout',
            ecommerce: {
                currency: '<?php echo esc_js($currency); ?>',
                value: <?php echo number_format(
                	floatval($cart_total),
                	2,
                	".",
                	"",
                ); ?>,
                items: <?php echo GA4DT_Security::json_encode_safe($items); ?>
            }
        });
        </script>
        <?php
	}

	/**
	 * Track payment method selection (secured)
	 */
	public function track_payment_method()
	{
		if (!is_checkout()) {
			return;
		}

		$cart = WC()->cart;
		if (!$cart || $cart->is_empty()) {
			return;
		}

		$items = [];
		foreach ($cart->get_cart() as $cart_item) {
			$product = $cart_item["data"];
			if (!$product || !is_a($product, "WC_Product")) {
				continue;
			}
			$product_data = $this->get_product_data(
				$product,
				$cart_item["quantity"],
			);
			if ($product_data) {
				$items[] = $product_data;
			}
		}

		$currency = GA4DT_Security::sanitize_currency(
			get_woocommerce_currency(),
		);
		$cart_total = GA4DT_Security::sanitize_price(
			$cart->get_cart_contents_total(),
		);
		$items_json = GA4DT_Security::json_encode_safe($items);
		?>
        <script>
        jQuery(document).ready(function($) {
            var ga4PaymentItems = <?php echo $items_json; ?>;
            var ga4CartValue = '<?php echo number_format(
            	floatval($cart_total),
            	2,
            	".",
            	"",
            ); ?>';
            var ga4Currency = '<?php echo esc_js($currency); ?>';
            var paymentTracked = {};

            function sanitizeText(text) {
                if (typeof text !== 'string') return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML.substring(0, 200);
            }

            function trackPaymentMethod(paymentMethod, paymentTitle) {
                if (paymentTracked[paymentMethod]) return;
                paymentTracked[paymentMethod] = true;

                dataLayer.push({ ecommerce: null });
                dataLayer.push({
                    event: 'add_payment_info',
                    ecommerce: {
                        currency: ga4Currency,
                        value: ga4CartValue,
                        payment_type: sanitizeText(paymentTitle || paymentMethod),
                        items: ga4PaymentItems
                    }
                });

                if (window.ga4Config && window.ga4Config.debug) {
                    console.log('💳 Payment Method Selected:', paymentTitle || paymentMethod);
                }
            }

            $(document.body).on('change', 'input[name="payment_method"]', function() {
                var paymentMethod = $(this).val();
                var paymentTitle = $('label[for="payment_method_' + paymentMethod + '"]').text().trim();
                paymentTitle = paymentTitle.replace(/\s+/g, ' ').trim();

                paymentTracked = {};
                trackPaymentMethod(paymentMethod, paymentTitle);
            });

            setTimeout(function() {
                var initialPayment = $('input[name="payment_method"]:checked');
                if (initialPayment.length) {
                    var paymentMethod = initialPayment.val();
                    var paymentTitle = $('label[for="payment_method_' + paymentMethod + '"]').text().trim();
                    paymentTitle = paymentTitle.replace(/\s+/g, ' ').trim();
                    trackPaymentMethod(paymentMethod, paymentTitle);
                }
            }, 1000);

            $(document.body).on('updated_checkout', function() {
                setTimeout(function() {
                    var currentPayment = $('input[name="payment_method"]:checked');
                    if (currentPayment.length) {
                        var paymentMethod = currentPayment.val();
                        var paymentTitle = $('label[for="payment_method_' + paymentMethod + '"]').text().trim();
                        paymentTitle = paymentTitle.replace(/\s+/g, ' ').trim();

                        if (!paymentTracked[paymentMethod]) {
                            trackPaymentMethod(paymentMethod, paymentTitle);
                        }
                    }
                }, 500);
            });
        });
        </script>
        <?php
	}

	/**
	 * Track shipping method selection (secured)
	 */
	public function track_shipping_method()
	{
		if (!is_checkout()) {
			return;
		}

		$cart = WC()->cart;
		if (!$cart || $cart->is_empty()) {
			return;
		}

		$items = [];
		foreach ($cart->get_cart() as $cart_item) {
			$product = $cart_item["data"];
			if (!$product || !is_a($product, "WC_Product")) {
				continue;
			}
			$product_data = $this->get_product_data(
				$product,
				$cart_item["quantity"],
			);
			if ($product_data) {
				$items[] = $product_data;
			}
		}

		$currency = GA4DT_Security::sanitize_currency(
			get_woocommerce_currency(),
		);
		$cart_total = GA4DT_Security::sanitize_price(
			$cart->get_cart_contents_total(),
		);
		$items_json = GA4DT_Security::json_encode_safe($items);
		?>
        <script>
        jQuery(document).ready(function($) {
            var ga4ShippingItems = <?php echo $items_json; ?>;
            var ga4CartValue = '<?php echo number_format(
            	floatval($cart_total),
            	2,
            	".",
            	"",
            ); ?>';
            var ga4Currency = '<?php echo esc_js($currency); ?>';
            var shippingTracked = {};

            function sanitizeText(text) {
                if (typeof text !== 'string') return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML.substring(0, 200);
            }

            function trackShippingMethod(shippingMethod, shippingTitle) {
                if (shippingTracked[shippingMethod]) return;
                shippingTracked[shippingMethod] = true;

                dataLayer.push({ ecommerce: null });
                dataLayer.push({
                    event: 'add_shipping_info',
                    ecommerce: {
                        currency: ga4Currency,
                        value: ga4CartValue,
                        shipping_tier: sanitizeText(shippingTitle || shippingMethod),
                        items: ga4ShippingItems
                    }
                });

                if (window.ga4Config && window.ga4Config.debug) {
                    console.log('🚚 Shipping Method Selected:', shippingTitle || shippingMethod);
                }
            }

            $(document.body).on('change', 'input[name^="shipping_method"]', function() {
                var shippingMethod = $(this).val();
                var shippingLabel = $(this).closest('li').find('label').text().trim();
                shippingLabel = shippingLabel.replace(/\s+/g, ' ').trim();

                shippingTracked = {};
                trackShippingMethod(shippingMethod, shippingLabel);
            });

            setTimeout(function() {
                var initialShipping = $('input[name^="shipping_method"]:checked');
                if (initialShipping.length) {
                    var shippingMethod = initialShipping.val();
                    var shippingLabel = initialShipping.closest('li').find('label').text().trim();
                    shippingLabel = shippingLabel.replace(/\s+/g, ' ').trim();
                    trackShippingMethod(shippingMethod, shippingLabel);
                }
            }, 1000);
        });
        </script>
        <?php
	}

	/**
	 * Purchase event (secured)
	 */
	public function purchase_event($order_id)
	{
		$order_id = GA4DT_Security::sanitize_order_id($order_id);

		if (!$order_id) {
			return;
		}

		// Prevent duplicate tracking
		if (get_post_meta($order_id, "_ga4_tracked", true)) {
			return;
		}
		update_post_meta($order_id, "_ga4_tracked", "1");

		$order = GA4DT_Security::validate_order($order_id);
		if (!$order) {
			return;
		}

		$items = [];
		$total_discount = 0;

		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			if (!$product || !is_a($product, "WC_Product")) {
				continue;
			}

			$product_data = $this->get_product_data(
				$product,
				$item->get_quantity(),
			);
			$product_data["price"] = GA4DT_Security::sanitize_price(
				$order->get_item_total($item, false),
			);

			if (isset($product_data["discount"])) {
				$total_discount +=
					$product_data["discount"] * $item->get_quantity();
			}

			if ($product_data) {
				$items[] = $product_data;
			}
		}

		if (empty($items)) {
			return;
		}

		$payment_method = GA4DT_Security::sanitize_payment_method(
			$order->get_payment_method(),
		);
		$payment_method_title = GA4DT_Security::sanitize_payment_title(
			$order->get_payment_method_title(),
		);

		$shipping_methods = $order->get_shipping_methods();
		$shipping_method_title = "";
		if (!empty($shipping_methods)) {
			$first_shipping = reset($shipping_methods);
			$shipping_method_title = GA4DT_Security::sanitize_payment_title(
				$first_shipping->get_method_title(),
			);
		}

		$coupons = array_map("sanitize_text_field", $order->get_coupon_codes());
		$coupon_discount = GA4DT_Security::sanitize_price(
			$order->get_discount_total(),
		);
		$currency = GA4DT_Security::sanitize_currency($order->get_currency());

		$order_data = [
			"transaction_id" => sanitize_text_field($order->get_order_number()),
			"value" => GA4DT_Security::sanitize_price($order->get_total()),
			"tax" => GA4DT_Security::sanitize_price($order->get_total_tax()),
			"shipping" => GA4DT_Security::sanitize_price(
				$order->get_shipping_total(),
			),
			"currency" => $currency,
			"payment_type" => $payment_method_title,
			"payment_method" => $payment_method,
		];

		if ($shipping_method_title) {
			$order_data["shipping_tier"] = $shipping_method_title;
		}

		if (!empty($coupons)) {
			$order_data["coupon"] = implode(", ", $coupons);
			$order_data["coupon_discount"] = $coupon_discount;
		}

		if ($total_discount > 0) {
			$order_data["sale_discount"] = number_format(
				$total_discount,
				2,
				".",
				"",
			);
		}

		$order_data["items"] = $items;
		?>
        <script>
        dataLayer.push({ ecommerce: null });
        dataLayer.push({
            event: 'purchase',
            ecommerce: <?php echo GA4DT_Security::json_encode_safe(
            	$order_data,
            ); ?>
        });
        </script>
        <?php
	}

	/**
	 * Remove from cart script (secured)
	 */
	public function remove_from_cart_script()
	{
		if (!is_cart()) {
			return;
		}

		$currency = GA4DT_Security::sanitize_currency(
			get_woocommerce_currency(),
		);
		?>
        <script>
        jQuery(document).ready(function($) {
            function sanitizeNumber(num) {
                var parsed = parseFloat(num);
                return isNaN(parsed) ? '0.00' : parsed.toFixed(2);
            }

            function sanitizeText(text) {
                if (typeof text !== 'string') return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML.substring(0, 500);
            }

            $(document.body).on('click', '.product-remove a.remove', function() {
                var cartItem = $(this).closest('tr, .cart_item');
                var productName = sanitizeText(cartItem.find('.product-name, td.product-name').text().trim());

                var priceContainer = cartItem.find('.product-price');
                var currentPrice = 0;
                var originalPrice = 0;
                var isOnSale = false;

                var salePriceEl = priceContainer.find('ins .woocommerce-Price-amount bdi').first();
                var regularPriceEl = priceContainer.find('del .woocommerce-Price-amount bdi').first();

                if (salePriceEl.length && regularPriceEl.length) {
                    isOnSale = true;
                    currentPrice = sanitizeNumber(salePriceEl.text().replace(/[^0-9.]/g, ''));
                    originalPrice = sanitizeNumber(regularPriceEl.text().replace(/[^0-9.]/g, ''));
                } else {
                    var normalPriceEl = priceContainer.find('.woocommerce-Price-amount bdi').first();
                    currentPrice = sanitizeNumber(normalPriceEl.text().replace(/[^0-9.]/g, ''));
                    originalPrice = currentPrice;
                }

                var quantity = parseInt(cartItem.find('.qty').val()) || 1;

                var itemData = {
                    item_name: productName,
                    price: currentPrice,
                    item_original_price: originalPrice,
                    item_on_sale: isOnSale,
                    quantity: quantity
                };

                if (isOnSale && parseFloat(originalPrice) > parseFloat(currentPrice)) {
                    itemData.discount = (parseFloat(originalPrice) - parseFloat(currentPrice)).toFixed(2);
                    itemData.discount_percentage = (((parseFloat(originalPrice) - parseFloat(currentPrice)) / parseFloat(originalPrice)) * 100).toFixed(2);
                }

                dataLayer.push({ ecommerce: null });
                dataLayer.push({
                    event: 'remove_from_cart',
                    ecommerce: {
                        currency: '<?php echo esc_js($currency); ?>',
                        value: (parseFloat(currentPrice) * quantity).toFixed(2),
                        items: [itemData]
                    }
                });
            });
        });
        </script>
        <?php
	}

	/**
	 * Debug helper (secured)
	 */
	public function debug_helper()
	{
		if (!defined("WP_DEBUG") || !WP_DEBUG) {
			return;
		} ?>
        <script>
        if (window.ga4Config && window.ga4Config.debug) {
            setTimeout(function() {
                console.log('=== GA4 DataLayer Debug ===');
                console.log('Total Events:', dataLayer.length);
                console.log('All Events:', dataLayer);
                console.log('Page Data:', window.ga4PageData);
                console.log('Config:', window.ga4Config);
                console.log('Single Product Data:', window.ga4SingleProduct || 'N/A');

                var saleItems = [];
                dataLayer.forEach(function(event) {
                    if (event.ecommerce && event.ecommerce.items) {
                        event.ecommerce.items.forEach(function(item) {
                            if (item.item_on_sale) {
                                saleItems.push({
                                    name: item.item_name,
                                    sale_price: item.price,
                                    original_price: item.item_original_price,
                                    discount: item.discount,
                                    discount_percentage: item.discount_percentage + '%'
                                });
                            }
                        });
                    }
                });

                if (saleItems.length > 0) {
                    console.log('🏷️ Sale Items Detected:', saleItems);
                }
            }, 2000);
        }
        </script>
        <?php
	}
}
