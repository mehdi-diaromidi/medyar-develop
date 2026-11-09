<?php
/**
 * Register plugin page templates in the Page attributes dropdown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Page_Templates {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		add_filter( 'theme_page_templates', array( $this, 'register_templates' ) );
		add_filter( 'template_include', array( $this, 'load_template' ) );
	}

	/**
	 * @param array<string,string> $templates Theme templates.
	 * @return array<string,string>
	 */
	public function register_templates( array $templates ): array {
		return array_merge( $templates, Arp_Pages::templates() );
	}

	/**
	 * @param string $template Current template path.
	 */
	public function load_template( string $template ): string {
		if ( ! is_singular( 'page' ) ) {
			return $template;
		}

		$slug = (string) get_page_template_slug();
		if ( ! Arp_Pages::is_arp_template( $slug ) ) {
			return $template;
		}

		$file = ARP_PLUGIN_DIR . 'public/templates/' . $slug;
		if ( is_readable( $file ) ) {
			return $file;
		}

		return $template;
	}
}
