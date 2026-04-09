<?php
/**
 * Theme functions and definitions
 *
 * @package HelloElementor
 */

use Elementor\WPNotificationsPackage\V110\Notifications as ThemeNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_VERSION', '3.3.0' );

if ( ! isset( $content_width ) ) {
	$content_width = 800; // Pixels.
}

if ( ! function_exists( 'hello_elementor_setup' ) ) {
	/**
	 * Set up theme support.
	 *
	 * @return void
	 */
	function hello_elementor_setup() {
		if ( is_admin() ) {
			hello_maybe_update_theme_version_in_db();
		}

		if ( apply_filters( 'hello_elementor_register_menus', true ) ) {
			register_nav_menus( [ 'menu-1' => esc_html__( 'Header', 'hello-elementor' ) ] );
			register_nav_menus( [ 'menu-2' => esc_html__( 'Footer', 'hello-elementor' ) ] );
		}

		if ( apply_filters( 'hello_elementor_post_type_support', true ) ) {
			add_post_type_support( 'page', 'excerpt' );
		}

		if ( apply_filters( 'hello_elementor_add_theme_support', true ) ) {
			add_theme_support( 'post-thumbnails' );
			add_theme_support( 'automatic-feed-links' );
			add_theme_support( 'title-tag' );
			add_theme_support(
				'html5',
				[
					'search-form',
					'comment-form',
					'comment-list',
					'gallery',
					'caption',
					'script',
					'style',
				]
			);
			add_theme_support(
				'custom-logo',
				[
					'height'      => 100,
					'width'       => 350,
					'flex-height' => true,
					'flex-width'  => true,
				]
			);
			add_theme_support( 'align-wide' );
			add_theme_support( 'responsive-embeds' );

			/*
			 * Editor Styles
			 */
			add_theme_support( 'editor-styles' );
			add_editor_style( 'editor-styles.css' );

			/*
			 * WooCommerce.
			 */
			if ( apply_filters( 'hello_elementor_add_woocommerce_support', true ) ) {
				// WooCommerce in general.
				add_theme_support( 'woocommerce' );
				// Enabling WooCommerce product gallery features (are off by default since WC 3.0.0).
				// zoom.
				add_theme_support( 'wc-product-gallery-zoom' );
				// lightbox.
				add_theme_support( 'wc-product-gallery-lightbox' );
				// swipe.
				add_theme_support( 'wc-product-gallery-slider' );
			}
		}
	}
}
add_action( 'after_setup_theme', 'hello_elementor_setup' );

function hello_maybe_update_theme_version_in_db() {
	$theme_version_option_name = 'hello_theme_version';
	// The theme version saved in the database.
	$hello_theme_db_version = get_option( $theme_version_option_name );

	// If the 'hello_theme_version' option does not exist in the DB, or the version needs to be updated, do the update.
	if ( ! $hello_theme_db_version || version_compare( $hello_theme_db_version, HELLO_ELEMENTOR_VERSION, '<' ) ) {
		update_option( $theme_version_option_name, HELLO_ELEMENTOR_VERSION );
	}
}

if ( ! function_exists( 'hello_elementor_display_header_footer' ) ) {
	/**
	 * Check whether to display header footer.
	 *
	 * @return bool
	 */
	function hello_elementor_display_header_footer() {
		$hello_elementor_header_footer = true;

		return apply_filters( 'hello_elementor_header_footer', $hello_elementor_header_footer );
	}
}

if ( ! function_exists( 'hello_elementor_scripts_styles' ) ) {
	/**
	 * Theme Scripts & Styles.
	 *
	 * @return void
	 */
	function hello_elementor_scripts_styles() {
		$min_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		if ( apply_filters( 'hello_elementor_enqueue_style', true ) ) {
			wp_enqueue_style(
				'hello-elementor',
				get_template_directory_uri() . '/style' . $min_suffix . '.css',
				[],
				HELLO_ELEMENTOR_VERSION
			);
		}

		if ( apply_filters( 'hello_elementor_enqueue_theme_style', true ) ) {
			wp_enqueue_style(
				'hello-elementor-theme-style',
				get_template_directory_uri() . '/theme' . $min_suffix . '.css',
				[],
				HELLO_ELEMENTOR_VERSION
			);
		}

		if ( hello_elementor_display_header_footer() ) {
			wp_enqueue_style(
				'hello-elementor-header-footer',
				get_template_directory_uri() . '/header-footer' . $min_suffix . '.css',
				[],
				HELLO_ELEMENTOR_VERSION
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_scripts_styles' );

if ( ! function_exists( 'hello_elementor_woocommerce_myaccount_styles' ) ) {
	/**
	 * Enqueue custom styles for WooCommerce MyAccount navigation and content.
	 *
	 * @return void
	 */
	function hello_elementor_woocommerce_myaccount_styles() {
		// Only load on WooCommerce MyAccount pages
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		$css_file = 'woocommerce-myaccount.css';
		$css_path = get_template_directory() . '/assets/css/' . $css_file;
		
		// Check if file exists
		if ( ! file_exists( $css_path ) ) {
			return;
		}

		wp_enqueue_style(
			'hello-elementor-woocommerce-myaccount',
			get_template_directory_uri() . '/assets/css/' . $css_file,
			array( 'hello-elementor-theme-style' ),
			filemtime( $css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_woocommerce_myaccount_styles', 20 );

if ( ! function_exists( 'hello_elementor_message_styles' ) ) {
	/**
	 * Inline styles for Elementor message elements (transparent background).
	 *
	 * @return void
	 */
	function hello_elementor_message_styles() {
		$css = '
		/* Elementor Message Elements - Custom Styling */
		.elementor-message,
		.elementor-message.elementor-message-success,
		.elementor-form .elementor-message,
		.elementor-form .elementor-message.elementor-message-success {
			background: #d4edda !important;
			background-color: #d4edda !important;
			margin-top: 24px !important;
			padding: 16px !important;
		}

		/* Keep SVG and children transparent */
		.elementor-message .elementor-message-svg,
		.elementor-message .elementor-message-svg *,
		.elementor-message-svg,
		.elementor-message-svg *,
		.elementor-form .elementor-message .elementor-message-svg,
		.elementor-form .elementor-message .elementor-message-svg *,
		.elementor-form .elementor-message-svg,
		.elementor-form .elementor-message-svg * {
			background: transparent !important;
			background-color: transparent !important;
			background-image: none !important;
		}
		';

		// Try to attach to Elementor styles first, then theme styles
		$handle = 'elementor-frontend';
		if ( ! wp_style_is( $handle, 'enqueued' ) && ! wp_style_is( $handle, 'registered' ) ) {
			$handle = 'hello-elementor-theme-style';
		}
		if ( ! wp_style_is( $handle, 'enqueued' ) && ! wp_style_is( $handle, 'registered' ) ) {
			$handle = 'hello-elementor-header-footer';
		}

		if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
			wp_add_inline_style( $handle, $css );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_message_styles', 999 );

if ( ! function_exists( 'hello_elementor_message_styles_fallback' ) ) {
	/**
	 * Fallback: Add Elementor message styles directly to wp_head with high priority.
	 *
	 * @return void
	 */
	function hello_elementor_message_styles_fallback() {
		$css = '
		/* Elementor Message Elements - Custom Styling */
		.elementor-message,
		.elementor-message.elementor-message-success,
		.elementor-form .elementor-message,
		.elementor-form .elementor-message.elementor-message-success {
			background: #d4edda !important;
			background-color: #d4edda !important;
			margin-top: 24px !important;
			padding: 16px !important;
		}

		/* Keep SVG and children transparent */
		.elementor-message .elementor-message-svg,
		.elementor-message .elementor-message-svg *,
		.elementor-message-svg,
		.elementor-message-svg *,
		.elementor-form .elementor-message .elementor-message-svg,
		.elementor-form .elementor-message .elementor-message-svg *,
		.elementor-form .elementor-message-svg,
		.elementor-form .elementor-message-svg * {
			background: transparent !important;
			background-color: transparent !important;
			background-image: none !important;
		}
		';
		echo '<style id="hello-elementor-message-styles" type="text/css">' . $css . '</style>';
	}
}
add_action( 'wp_head', 'hello_elementor_message_styles_fallback', 9999 );

if ( ! function_exists( 'hello_elementor_message_styles_force' ) ) {
	/**
	 * Force inject CSS in wp_footer to ensure it loads after all Elementor styles.
	 *
	 * @return void
	 */
	function hello_elementor_message_styles_force() {
		$css = '
		/* Elementor Message Elements - Custom Styling (Force) */
		.elementor-message,
		.elementor-message.elementor-message-success,
		.elementor-form .elementor-message,
		.elementor-form .elementor-message.elementor-message-success {
			background: #d4edda !important;
			background-color: #d4edda !important;
			margin-top: 24px !important;
			padding: 16px !important;
		}

		/* Keep SVG and children transparent */
		.elementor-message .elementor-message-svg,
		.elementor-message .elementor-message-svg *,
		.elementor-message-svg,
		.elementor-message-svg *,
		.elementor-form .elementor-message .elementor-message-svg,
		.elementor-form .elementor-message .elementor-message-svg *,
		.elementor-form .elementor-message-svg,
		.elementor-form .elementor-message-svg * {
			background: transparent !important;
			background-color: transparent !important;
			background-image: none !important;
		}
		';
		echo '<style id="hello-elementor-message-styles-force" type="text/css">' . $css . '</style>';
	}
}
add_action( 'wp_footer', 'hello_elementor_message_styles_force', 9999 );

if ( ! function_exists( 'hello_elementor_message_styles_js' ) ) {
	/**
	 * JavaScript solution to handle AJAX-rendered Elementor messages.
	 * This directly manipulates inline styles to override Elementor's inline styles.
	 *
	 * @return void
	 */
		function hello_elementor_message_styles_js() {
		?>
		<script type="text/javascript">
		(function() {
			'use strict';

			var processedElements = new WeakSet();
			var throttleDelay = 100;
			var lastProcessTime = 0;

			// Apply styles to single element
			function applyMessageStyles(element) {
				if (!element || !element.style || processedElements.has(element)) return false;

				try {
					element.style.setProperty('background', '#d4edda', 'important');
					element.style.setProperty('background-color', '#d4edda', 'important');
					element.style.setProperty('margin-top', '24px', 'important');
					element.style.setProperty('padding', '16px', 'important');
					
					// Keep SVG transparent
					var svgChildren = element.querySelectorAll('.elementor-message-svg, .elementor-message-svg *');
					for (var i = 0; i < svgChildren.length; i++) {
						if (svgChildren[i].style) {
							svgChildren[i].style.setProperty('background', 'transparent', 'important');
							svgChildren[i].style.setProperty('background-color', 'transparent', 'important');
						}
					}
					processedElements.add(element);
					return true;
				} catch(e) {
					element.style.cssText += ';background: #d4edda !important; background-color: #d4edda !important; margin-top: 24px !important; padding: 16px !important;';
					processedElements.add(element);
					return true;
				}
			}

			// Process all messages with throttling
			function processAllMessages() {
				var now = Date.now();
				if (now - lastProcessTime < throttleDelay) return 0;
				lastProcessTime = now;

				var elements = document.querySelectorAll('.elementor-message, .elementor-message.elementor-message-success, .elementor-form .elementor-message, .elementor-form .elementor-message.elementor-message-success');
				var count = 0;
				for (var i = 0; i < elements.length; i++) {
					if (applyMessageStyles(elements[i])) count++;
				}
				return count;
			}

			// Debounced processing helper
			function debounceProcess(delay) {
				var timeout;
				return function() {
					clearTimeout(timeout);
					timeout = setTimeout(processAllMessages, delay || 150);
				};
			}

			// Check if node contains message element
			function hasMessage(node) {
				return node && node.nodeType === 1 && node.classList && (
					node.classList.contains('elementor-message') ||
					node.classList.contains('elementor-message-success') ||
					(node.querySelector && node.querySelector('.elementor-message, .elementor-message-success'))
				);
			}

			// Expose for testing
			window.helloElementorMakeMessagesTransparent = processAllMessages;

			// MutationObserver for AJAX-rendered elements
			if (typeof MutationObserver !== 'undefined') {
				var observer = new MutationObserver(function(mutations) {
					var shouldProcess = false;
					for (var i = 0; i < mutations.length && !shouldProcess; i++) {
						var m = mutations[i];
						if (m.addedNodes) {
							for (var j = 0; j < m.addedNodes.length; j++) {
								if (hasMessage(m.addedNodes[j])) {
									shouldProcess = true;
									break;
								}
							}
						}
						if (!shouldProcess && m.type === 'attributes' && m.attributeName === 'class' && hasMessage(m.target)) {
							shouldProcess = true;
						}
					}
					if (shouldProcess) {
						debounceProcess(50)();
						setTimeout(processAllMessages, 200);
					}
				});

				function startObserver() {
					if (document.body) {
						observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
					} else {
						setTimeout(startObserver, 100);
					}
				}
				startObserver();
			}

			// AJAX and form submission handlers
			var debouncedProcess = debounceProcess(150);
			if (typeof jQuery !== 'undefined') {
				jQuery(document).ajaxComplete(debouncedProcess);
				jQuery(document).on('submit', 'form.elementor-form', function() {
					var count = 0;
					var interval = setInterval(function() {
						if (processAllMessages() > 0 || ++count > 30) clearInterval(interval);
					}, 100);
				});
			}

			if (typeof elementorFrontend !== 'undefined') {
				elementorFrontend.hooks.addAction('frontend/element_ready/form.default', function($scope) {
					if ($scope && $scope.length) {
						$scope.on('submit_success submit', debouncedProcess);
					}
				});
				elementorFrontend.hooks.addAction('frontend/element_ready/global', debouncedProcess);
			}

			// Initialize
			function init() {
				processAllMessages();
				setInterval(processAllMessages, 500); // Fallback interval
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		</script>
		<?php
	}
}
add_action( 'wp_footer', 'hello_elementor_message_styles_js', 99999 );

if ( ! function_exists( 'hello_elementor_add_myaccount_after_nav' ) ) {
	/**
	 * Add My Account button after navigation menu (separate from menu).
	 * This works for standard WordPress headers (not Elementor).
	 *
	 * @param string $nav_menu The HTML content for the navigation menu.
	 * @param object $args     An object containing wp_nav_menu() arguments.
	 * @return string Modified navigation menu output.
	 */
	function hello_elementor_add_myaccount_after_nav( $nav_menu, $args ) {
		static $button_added = false;
		
		// Skip if already added (prevent duplicates)
		if ( $button_added ) {
			return $nav_menu;
		}

		// Validate $args
		if ( ! is_object( $args ) ) {
			return $nav_menu;
		}

		// Check if WooCommerce is active
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return $nav_menu;
		}

		// Only add to header menu (menu-1)
		if ( ! isset( $args->theme_location ) || 'menu-1' !== $args->theme_location ) {
			return $nav_menu;
		}

		// Get My Account page URL (works for both login and account)
		$account_url = wc_get_page_permalink( 'myaccount' );
		
		if ( ! $account_url ) {
			return $nav_menu;
		}

		// Check if already added in HTML (prevent duplicates)
		if ( false !== strpos( $nav_menu, 'header-myaccount-wrapper' ) ) {
			return $nav_menu;
		}

		// Mark as added
		$button_added = true;

		// Determine button text and icon based on login status
		if ( is_user_logged_in() ) {
			$button_text = esc_html__( 'My Account', 'hello-elementor' );
			$button_icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.59 22C20.59 18.13 16.74 15 12 15C7.26 15 3.41 18.13 3.41 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		} else {
			$button_text = esc_html__( 'Login', 'hello-elementor' );
			$button_icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M15 3H19C19.5304 3 20.0391 3.21071 20.4142 3.58579C20.7893 3.96086 21 4.46957 21 5V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 17L15 12L10 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 12H3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		}
		
		$button_html = '<div class="header-myaccount-wrapper">';
		$button_html .= '<a href="' . esc_url( $account_url ) . '" class="header-myaccount-link" aria-label="' . esc_attr( $button_text ) . '">';
		$button_html .= '<div class="myaccount-inner">';
		$button_html .= '<span class="myaccount-icon" aria-hidden="true">' . $button_icon . '</span>';
		$button_html .= '<span class="myaccount-text">' . esc_html( $button_text ) . '</span>';
		$button_html .= '</div>';
		$button_html .= '</a>';
		$button_html .= '</div>';
		
		// Append after nav menu
		return $nav_menu . $button_html;
	}
}
add_filter( 'wp_nav_menu', 'hello_elementor_add_myaccount_after_nav', 20, 2 );

if ( ! function_exists( 'hello_elementor_add_myaccount_menu_js' ) ) {
	/**
	 * Add JavaScript fallback to inject My Account menu item for Elementor headers.
	 *
	 * @return void
	 */
	function hello_elementor_add_myaccount_menu_js() {
		// Check if WooCommerce is active
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return;
		}

		// Get My Account page URL (works for both login and account)
		$account_url = wc_get_page_permalink( 'myaccount' );
		
		if ( ! $account_url ) {
			return;
		}

		// Determine button text and icon based on login status
		if ( is_user_logged_in() ) {
			$button_text = __( 'My Account', 'hello-elementor' );
			$button_icon_svg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.59 22C20.59 18.13 16.74 15 12 15C7.26 15 3.41 18.13 3.41 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		} else {
			$button_text = __( 'Login', 'hello-elementor' );
			$button_icon_svg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M15 3H19C19.5304 3 20.0391 3.21071 20.4142 3.58579C20.7893 3.96086 21 4.46957 21 5V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 17L15 12L10 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 12H3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		}

		$button_text_js = esc_js( $button_text );
		$button_url_js = esc_js( $account_url );
		$button_icon_svg_js = wp_json_encode( $button_icon_svg );
		?>
		<script type="text/javascript">
		(function($) {
			$(document).ready(function() {
				var accountUrl = <?php echo wp_json_encode( $account_url ); ?>;
				var accountText = <?php echo wp_json_encode( $button_text ); ?>;
				var accountIconSvg = <?php echo $button_icon_svg_js; ?>;
				
				// Function to add Account/Login button outside menu
				function addAccountButton() {
					// Remove any duplicates first (keep only the first one)
					var $existing = $('.header-myaccount-wrapper');
					if ($existing.length > 1) {
						$existing.slice(1).remove();
						return; // Already exists, don't add more
					}
					
					// Skip if already exists
					if ($existing.length > 0) {
						return;
					}

					// Find navigation containers in header
					var $navContainers = $('header .site-navigation, header nav.elementor-nav-menu, header .elementor-widget-nav-menu, header nav');
					
					// If not found in header, try broader search
					if ($navContainers.length === 0) {
						$navContainers = $('.elementor-widget-nav-menu, .elementor-nav-menu, nav');
					}
					
					// Try to find the first nav container in header
					var $targetNav = null;
					$navContainers.each(function() {
						var $nav = $(this);
						
						// Prefer nav in header
						if ($nav.closest('header').length > 0) {
							// Skip if already has Account button after this nav
							if ($nav.next('.header-myaccount-wrapper').length === 0) {
								$targetNav = $nav;
								return false; // break
							}
						}
					});
					
					// If no target found, use first nav container
					if (!$targetNav && $navContainers.length > 0) {
						$targetNav = $navContainers.first();
					}
					
					if ($targetNav && $targetNav.length > 0) {
						// Create Account/Login button wrapper (separate from menu)
						var $accountWrapper = $('<div class="header-myaccount-wrapper"><a href="' + accountUrl + '" class="header-myaccount-link" aria-label="' + accountText + '"><div class="myaccount-inner"><span class="myaccount-icon" aria-hidden="true">' + accountIconSvg + '</span><span class="myaccount-text">' + accountText + '</span></div></a></div>');
						
						// Insert after navigation
						$targetNav.after($accountWrapper);
					} else {
						// Fallback: try to add after header-inner if nav containers not found
						var $headerInner = $('header .header-inner');
						if ($headerInner.length > 0 && $headerInner.find('.header-myaccount-wrapper').length === 0) {
							var $accountWrapper = $('<div class="header-myaccount-wrapper"><a href="' + accountUrl + '" class="header-myaccount-link" aria-label="' + accountText + '"><div class="myaccount-inner"><span class="myaccount-icon" aria-hidden="true">' + accountIconSvg + '</span><span class="myaccount-text">' + accountText + '</span></div></a></div>');
							$headerInner.append($accountWrapper);
						}
					}
				}

				// Run immediately
				addAccountButton();

				// Run after Elementor frontend is ready (for Elementor headers)
				if (typeof elementorFrontend !== 'undefined') {
					elementorFrontend.hooks.addAction('frontend/element_ready/global', function() {
						setTimeout(addAccountButton, 100);
					});
					
					// Also listen for navigation menu widget
					elementorFrontend.hooks.addAction('frontend/element_ready/nav-menu.default', function() {
						setTimeout(addAccountButton, 100);
					});
				}

				// Also run after delays to catch late-loading menus
				setTimeout(addAccountButton, 500);
				setTimeout(addAccountButton, 1000);
				setTimeout(addAccountButton, 2000);
			});
		})(jQuery);
		</script>
		<?php
	}
}
add_action( 'wp_footer', 'hello_elementor_add_myaccount_menu_js' );

if ( ! function_exists( 'hello_elementor_myaccount_menu_styles' ) ) {
	/**
	 * Inline styles for the My Account icon-only menu item with hover-expand overlay behavior.
	 *
	 * @return void
	 */
	function hello_elementor_myaccount_menu_styles() {
		// Styles apply to both logged-in and logged-out users

		$css = '
		/* My Account wrapper - separate from menu */
		.header-myaccount-wrapper {
			display: flex;
			align-items: center;
			margin-left: 15px;
			position: relative;
			z-index: 100;
		}

		/* My Account/Login link - always visible with text */
		.header-myaccount-link {
			display: flex;
			align-items: center;
			gap: 8px;
			height: 44px;
			padding: 0 14px;
			border: 1px solid #017FDD !important;
			border-radius: 6px;
			background: transparent;
			color: #017FDD !important;
			text-decoration: none;
			white-space: nowrap;
			transition: background-color 220ms ease, color 220ms ease, box-shadow 220ms ease;
			justify-content: center;
			position: relative;
		}

		.header-myaccount-link .myaccount-inner {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			white-space: nowrap;
		}

		.header-myaccount-link .myaccount-icon {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 20px;
			height: 20px;
			flex: 0 0 20px;
		}

		.header-myaccount-link .myaccount-text {
			font-weight: 500;
			font-size: 14px;
		}

		/* Hover effect */
		.header-myaccount-link:hover,
		.header-myaccount-link:focus,
		.header-myaccount-link:focus-visible {
			background-color: #017FDD;
			color: #ffffff !important;
			box-shadow: 0 6px 18px rgba(1, 127, 221, 0.25);
		}
		
		/* Ensure navigation menu allows overflow */
		header .site-navigation,
		header nav {
			position: relative;
		}

		/* Mobile menu text-only My Account item: hidden by default (desktop & non-mobile) */
		.menu-item-myaccount-mobile {
			display: none !important;
		}

		/* Ensure header layout accommodates My Account */
		header .header-inner {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
		}

		/* Mobile/Dropdown: hide My Account wrapper (will be handled separately if needed) */
		@media (max-width: 768px) {
			.header-myaccount-wrapper {
				display: none !important;
			}
			
			.header-myaccount-link {
				width: 40px;
				height: 40px;
			}

			/* Only on mobile: show text My Account inside Elementor dropdown menu */
			.elementor-nav-menu--dropdown .menu-item-myaccount-mobile {
				display: block !important;
			}
		}
		';

		// Prefer attaching to header-footer CSS handle if present.
		$handle = 'hello-elementor-header-footer';
		if ( ! wp_style_is( $handle, 'enqueued' ) && ! wp_style_is( $handle, 'registered' ) ) {
			$handle = 'hello-elementor-theme-style';
		}

		if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
			wp_add_inline_style( $handle, $css );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_myaccount_menu_styles', 25 );

if ( ! function_exists( 'hello_elementor_text_editor_styles' ) ) {
	/**
	 * Add styles for Elementor text editor widget.
	 *
	 * @return void
	 */
	function hello_elementor_text_editor_styles() {
		$css = '
		/* Elementor Text Editor Widget - Margin Bottom */
		.elementor-widget-text-editor {
			margin-bottom: 32px !important;
		}
		';

		// Try to attach to Elementor styles first, then theme styles
		$handle = 'elementor-frontend';
		if ( ! wp_style_is( $handle, 'enqueued' ) && ! wp_style_is( $handle, 'registered' ) ) {
			$handle = 'hello-elementor-theme-style';
		}
		if ( ! wp_style_is( $handle, 'enqueued' ) && ! wp_style_is( $handle, 'registered' ) ) {
			$handle = 'hello-elementor-header-footer';
		}

		if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
			wp_add_inline_style( $handle, $css );
		} else {
			// Fallback: add directly to wp_head
			echo '<style id="hello-elementor-text-editor-styles" type="text/css">' . $css . '</style>';
		}
	}
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_text_editor_styles', 30 );

if ( ! function_exists( 'hello_elementor_sitemap_styles' ) ) {
	/**
	 * Add styles for Elementor sitemap widget.
	 *
	 * @return void
	 */
	function hello_elementor_sitemap_styles() {
		$css = '
		/* Elementor Sitemap Widget - Overflow and Gap */
		.elementor-sitemap-list,
		.elementor-sitemap-category-list {
			overflow: hidden !important;
		}

		/* For flex or grid layouts */
		.elementor-sitemap-list,
		.elementor-sitemap-category-list {
			display: flex;
			flex-direction: column;
			gap: 16px !important;
		}

		/* Sitemap item width fit content */
		.elementor-sitemap-list .elementor-sitemap-item,
		.elementor-sitemap-category-list .elementor-sitemap-item {
			width: fit-content !important;
		}

		/* Disable horizontal scroll on sitemap section */
		.elementor-sitemap-section {
			overflow-x: hidden !important;
			overflow-y: auto !important;
		}

		/* Tablet: flex-basis 100% */
		@media screen and (min-width: 768px) and (max-width: 1024px) {
			.elementor-sitemap-section {
				flex-basis: 100% !important;
			}
		}

		/* Mobile: enable horizontal scroll */
		@media screen and (max-width: 767px) {
			.elementor-sitemap-list,
			.elementor-sitemap-category-list {
				overflow-x: auto !important;
				overflow-y: hidden !important;
				-webkit-overflow-scrolling: touch !important;
				flex-direction: row !important;
				flex-wrap: nowrap !important;
				gap: 0 !important;
				justify-content: flex-start !important;
			}

			.elementor-sitemap-list > *,
			.elementor-sitemap-category-list > * {
				flex: 0 0 auto !important;
				margin-bottom: 0 !important;
				margin-right: 16px !important;
			}

			.elementor-sitemap-list .elementor-sitemap-item,
			.elementor-sitemap-category-list .elementor-sitemap-item {
				width: fit-content !important;
				min-width: fit-content !important;
			}

			.elementor-sitemap-list > *:last-child,
			.elementor-sitemap-category-list > *:last-child {
				margin-right: 0 !important;
			}
		}
		';

		// Try to attach to Elementor styles first, then theme styles
		$handle = 'elementor-frontend';
		if ( ! wp_style_is( $handle, 'enqueued' ) && ! wp_style_is( $handle, 'registered' ) ) {
			$handle = 'hello-elementor-theme-style';
		}
		if ( ! wp_style_is( $handle, 'enqueued' ) && ! wp_style_is( $handle, 'registered' ) ) {
			$handle = 'hello-elementor-header-footer';
		}

		if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
			wp_add_inline_style( $handle, $css );
		} else {
			// Fallback: add directly to wp_head
			echo '<style id="hello-elementor-sitemap-styles" type="text/css">' . $css . '</style>';
		}
	}
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_sitemap_styles', 30 );

if ( ! function_exists( 'hello_elementor_add_mobile_myaccount_menu_item' ) ) {
	/**
	 * Append a text-only My Account item into main menu (for mobile view),
	 * while visibility is controlled via CSS.
	 *
	 * @param string   $items The HTML list content for the menu items.
	 * @param stdClass $args  An object containing wp_nav_menu() arguments.
	 *
	 * @return string
	 */
	function hello_elementor_add_mobile_myaccount_menu_item( $items, $args ) {
		// Only for frontend (both logged-in and logged-out users).
		if ( is_admin() ) {
			return $items;
		}

		// Detect header/Elementor nav menus.
		$in_header_menu = false;

		// 1) WordPress header menu location.
		if ( isset( $args->theme_location ) && 'menu-1' === $args->theme_location ) {
			$in_header_menu = true;
		}

		// 2) Elementor Nav Menu widget (uses specific menu classes, theme_location often empty).
		if ( isset( $args->menu_class ) && false !== strpos( $args->menu_class, 'elementor-nav-menu' ) ) {
			$in_header_menu = true;
		}

		// If not a recognized header/menu instance, do nothing.
		if ( ! $in_header_menu ) {
			return $items;
		}

		// Avoid duplicate injection.
		if ( false !== strpos( $items, 'menu-item-myaccount-mobile' ) ) {
			return $items;
		}

		// WooCommerce My Account URL.
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return $items;
		}

		$account_url = wc_get_page_permalink( 'myaccount' );
		if ( ! $account_url ) {
			return $items;
		}

		// Determine text based on login status
		if ( is_user_logged_in() ) {
			$account_text = esc_html__( 'My Account', 'hello-elementor' );
		} else {
			$account_text = esc_html__( 'Login', 'hello-elementor' );
		}

		$mobile_item  = '<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-myaccount-mobile">';
		$mobile_item .= '<a href="' . esc_url( $account_url ) . '">' . $account_text . '</a>';
		$mobile_item .= '</li>';

		return $items . $mobile_item;
	}
}
add_filter( 'wp_nav_menu_items', 'hello_elementor_add_mobile_myaccount_menu_item', 20, 2 );

if ( ! function_exists( 'hello_elementor_hide_faq_blog_when_logged_in' ) ) {
	/**
	 * Hide FAQ and Blog menu items from header menu when user is logged in.
	 *
	 * @param WP_Post[] $sorted_menu_items The menu items, sorted by each menu item's menu order.
	 * @param stdClass  $args              An object containing wp_nav_menu() arguments.
	 *
	 * @return WP_Post[]
	 */
	function hello_elementor_hide_faq_blog_when_logged_in( $sorted_menu_items, $args ) {
		// Only affect logged-in users on the frontend.
		if ( is_admin() || ! is_user_logged_in() ) {
			return $sorted_menu_items;
		}

		$filtered_items = [];

		foreach ( $sorted_menu_items as $item ) {
			// Normalize title.
			$title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );

			// Normalize URL path.
			$url_path = '';
			if ( ! empty( $item->url ) ) {
				$parsed = wp_parse_url( $item->url );
				if ( ! empty( $parsed['path'] ) ) {
					$url_path = strtolower( trim( $parsed['path'], '/' ) );
				}
			}

			// Keywords to hide.
			$hide_keywords = [ 'faq', 'faqs', 'blog' ];

			$should_hide = false;
			foreach ( $hide_keywords as $keyword ) {
				if (
					false !== strpos( $title, $keyword ) ||
					( $url_path && false !== strpos( $url_path, $keyword ) )
				) {
					$should_hide = true;
					break;
				}
			}

			// Skip (hide) matching items.
			if ( $should_hide ) {
				continue;
			}

			$filtered_items[] = $item;
		}

		return $filtered_items;
	}
}
add_filter( 'wp_nav_menu_objects', 'hello_elementor_hide_faq_blog_when_logged_in', 10, 2 );

if ( ! function_exists( 'hello_elementor_hide_affiliate_faq_when_not_logged_in' ) ) {
	/**
	 * Hide Affiliate and FAQ menu items from Elementor nav menu when user is NOT logged in.
	 *
	 * @param WP_Post[] $sorted_menu_items The menu items, sorted by each menu item's menu order.
	 * @param stdClass  $args              An object containing wp_nav_menu() arguments.
	 *
	 * @return WP_Post[]
	 */
	function hello_elementor_hide_affiliate_faq_when_not_logged_in( $sorted_menu_items, $args ) {
		// Only affect non-logged-in users on the frontend.
		if ( is_admin() || is_user_logged_in() ) {
			return $sorted_menu_items;
		}

		// Only apply to Elementor nav menu.
		$is_elementor_menu = false;
		if ( isset( $args->menu_class ) && false !== strpos( $args->menu_class, 'elementor-nav-menu' ) ) {
			$is_elementor_menu = true;
		}

		// If not an Elementor nav menu, do nothing.
		if ( ! $is_elementor_menu ) {
			return $sorted_menu_items;
		}

		$filtered_items = [];

		foreach ( $sorted_menu_items as $item ) {
			// Normalize title.
			$title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );

			// Normalize URL path.
			$url_path = '';
			if ( ! empty( $item->url ) ) {
				$parsed = wp_parse_url( $item->url );
				if ( ! empty( $parsed['path'] ) ) {
					$url_path = strtolower( trim( $parsed['path'], '/' ) );
				}
			}

			// Keywords to hide.
			$hide_keywords = [ 'affiliate', 'faq', 'faqs' ];

			$should_hide = false;
			foreach ( $hide_keywords as $keyword ) {
				if (
					false !== strpos( $title, $keyword ) ||
					( $url_path && false !== strpos( $url_path, $keyword ) )
				) {
					$should_hide = true;
					break;
				}
			}

			// Skip (hide) matching items.
			if ( $should_hide ) {
				continue;
			}

			$filtered_items[] = $item;
		}

		return $filtered_items;
	}
}
add_filter( 'wp_nav_menu_objects', 'hello_elementor_hide_affiliate_faq_when_not_logged_in', 10, 2 );

if ( ! function_exists( 'hello_elementor_register_elementor_locations' ) ) {
	/**
	 * Register Elementor Locations.
	 *
	 * @param ElementorPro\Modules\ThemeBuilder\Classes\Locations_Manager $elementor_theme_manager theme manager.
	 *
	 * @return void
	 */
	function hello_elementor_register_elementor_locations( $elementor_theme_manager ) {
		if ( apply_filters( 'hello_elementor_register_elementor_locations', true ) ) {
			$elementor_theme_manager->register_all_core_location();
		}
	}
}
add_action( 'elementor/theme/register_locations', 'hello_elementor_register_elementor_locations' );

if ( ! function_exists( 'hello_elementor_content_width' ) ) {
	/**
	 * Set default content width.
	 *
	 * @return void
	 */
	function hello_elementor_content_width() {
		$GLOBALS['content_width'] = apply_filters( 'hello_elementor_content_width', 800 );
	}
}
add_action( 'after_setup_theme', 'hello_elementor_content_width', 0 );

if ( ! function_exists( 'hello_elementor_add_description_meta_tag' ) ) {
	/**
	 * Add description meta tag with excerpt text.
	 *
	 * @return void
	 */
	function hello_elementor_add_description_meta_tag() {
		if ( ! apply_filters( 'hello_elementor_description_meta_tag', true ) ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( empty( $post->post_excerpt ) ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $post->post_excerpt ) ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'hello_elementor_add_description_meta_tag' );

// Admin notice
if ( is_admin() ) {
	require get_template_directory() . '/includes/admin-functions.php';
}

// Settings page
require get_template_directory() . '/includes/settings-functions.php';

// Header & footer styling option, inside Elementor
require get_template_directory() . '/includes/elementor-functions.php';

if ( ! function_exists( 'hello_elementor_customizer' ) ) {
	// Customizer controls
	function hello_elementor_customizer() {
		if ( ! is_customize_preview() ) {
			return;
		}

		if ( ! hello_elementor_display_header_footer() ) {
			return;
		}

		require get_template_directory() . '/includes/customizer-functions.php';
	}
}
add_action( 'init', 'hello_elementor_customizer' );

if ( ! function_exists( 'hello_elementor_check_hide_title' ) ) {
	/**
	 * Check whether to display the page title.
	 *
	 * @param bool $val default value.
	 *
	 * @return bool
	 */
	function hello_elementor_check_hide_title( $val ) {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$current_doc = Elementor\Plugin::instance()->documents->get( get_the_ID() );
			if ( $current_doc && 'yes' === $current_doc->get_settings( 'hide_title' ) ) {
				$val = false;
			}
		}
		return $val;
	}
}
add_filter( 'hello_elementor_page_title', 'hello_elementor_check_hide_title' );

/**
 * BC:
 * In v2.7.0 the theme removed the `hello_elementor_body_open()` from `header.php` replacing it with `wp_body_open()`.
 * The following code prevents fatal errors in child themes that still use this function.
 */
if ( ! function_exists( 'hello_elementor_body_open' ) ) {
	function hello_elementor_body_open() {
		wp_body_open();
	}
}

function hello_elementor_get_theme_notifications(): ThemeNotifications {
	static $notifications = null;

	if ( null === $notifications ) {
		require get_template_directory() . '/vendor/autoload.php';

		$notifications = new ThemeNotifications(
			'hello-elementor',
			HELLO_ELEMENTOR_VERSION,
			'theme'
		);
	}

	return $notifications;
}

hello_elementor_get_theme_notifications();
