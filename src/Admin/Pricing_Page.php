<?php
/**
 * The plans, drawn here rather than fetched from Freemius.
 *
 * @package CatalogOps\Admin
 */

namespace CatalogOps\Admin;

use CatalogOps\Licensing\License;

/**
 * Two menu items that the Freemius SDK would otherwise provide: **Pricing** and
 * **Contact**.
 *
 * The SDK's own Pricing screen is a JavaScript application it loads from its
 * servers and renders inside wp-admin. It works, and the owner did not want it:
 * it looks like somebody else's product inside this one. So the SDK's two items
 * are switched off in the init config (`menu.pricing`, `menu.contact` in
 * catalogops.php) and these replace them. **Account is deliberately left to the
 * SDK** — it is where a licence is actually activated, deactivated and synced,
 * and none of that is presentation.
 *
 * **What that costs, stated plainly, because it is a real trade-off rather than
 * a free win.** The SDK's Pricing screen is not only a picture of the plans: it
 * carries this installation's identity to the checkout, so a purchase made from
 * it comes back and activates itself on this site. These buttons go to the same
 * public checkout the website's own pricing table links to, which has no idea
 * which site the buyer came from — so the licence arrives as a key, and the
 * buyer activates it on the Account screen the way somebody who bought from the
 * website does. That is one extra step for a paying customer, and it is the
 * price of the screen looking like this plugin. It is worth naming here so that
 * whoever reads this later is deciding rather than discovering.
 *
 * **The plan copy mirrors the website and is not fetched from it.** A screen in
 * wp-admin that calls out to a marketing site is a screen that is blank when the
 * site is down, slow when it is slow, and a privacy question on a plugin whose
 * whole site was built to set no cookies. So the words live here, and the
 * `catalogops_plans` filter is how a build changes them without editing this.
 */
final class Pricing_Page {

	/**
	 * Deliberately NOT `catalogops-pricing`.
	 *
	 * `{slug}-pricing` belongs to the Freemius SDK, and switching its menu item
	 * off in the init config removes the item without unhooking the renderer: the
	 * SDK still attaches its pricing application to that page. Measured — with
	 * `menu.pricing => false` and this screen registered on the SDK's slug, the
	 * rendered page carried our three plan cards AND `fs_pricing_wrapper` with
	 * the SDK's script enqueued behind them. Taking our own slug sidesteps the
	 * whole argument rather than fighting it.
	 */
	public const MENU_SLUG = 'catalogops-plans';

	/**
	 * Who may see these screens. The same capability the main screen uses: a
	 * person who cannot run a bulk edit has no reason to be shown what a licence
	 * for one costs.
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * The marketing site, without a trailing slash.
	 *
	 * A constant rather than an option because it is not the shop's business, and
	 * filterable because a development copy of this plugin has a local site to
	 * point at and no way to say so otherwise.
	 */
	private const SITE = 'https://catalog-ops.app';

	/**
	 * Absolute path to the main plugin file, for asset URLs.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * What this site's licence permits, so the page can say which plan is already
	 * in force instead of inviting somebody to buy what they have.
	 *
	 * @var License
	 */
	private License $license;

	/**
	 * Build the page.
	 *
	 * @param string       $plugin_file Absolute path to the main plugin file.
	 * @param License|null $license     Plan gating; defaults to unlimited, which
	 *                                  is what a source checkout without the
	 *                                  Freemius SDK resolves to.
	 */
	public function __construct( string $plugin_file, ?License $license = null ) {
		$this->plugin_file = $plugin_file;
		$this->license     = $license ?? License::unlimited();
	}

	/**
	 * The marketing site's base URL.
	 */
	private function site(): string {
		/**
		 * Filters the marketing site CatalogOps links out to.
		 *
		 * @param string $url Absolute URL, no trailing slash.
		 */
		return untrailingslashit( (string) apply_filters( 'catalogops_site_url', self::SITE ) );
	}

	/**
	 * Where "Contact" goes: the site's own contact form.
	 *
	 * The SDK's contact screen posts into Freemius' support inbox. The owner
	 * answers mail through the form on the site, so pointing at the SDK's would
	 * have been pointing at an inbox nobody reads.
	 */
	public function contact_url(): string {
		return $this->site() . '/contact/';
	}

	/**
	 * Register both submenu items. Hook to admin_menu, late.
	 *
	 * Late, because the parent menu has to exist first and because the SDK adds
	 * Account on the same hook: registering after it keeps Account where a
	 * Freemius user expects to find it, with these two after.
	 */
	public function register_menu(): void {
		add_submenu_page(
			Admin_Page::MENU_SLUG,
			__( 'CatalogOps Pricing', 'catalogops' ),
			__( 'Pricing', 'catalogops' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		// WordPress treats a menu slug that is a URL as a plain link rather than a
		// screen, which is the whole mechanism here — there is no page to render,
		// the item simply leaves wp-admin.
		add_submenu_page(
			Admin_Page::MENU_SLUG,
			__( 'Contact', 'catalogops' ),
			__( 'Contact', 'catalogops' ),
			self::CAPABILITY,
			$this->contact_url()
		);

		add_action( 'admin_head', array( $this, 'print_external_link_script' ) );
	}

	/**
	 * Open the Contact item in a new tab.
	 *
	 * There is no filter on a submenu item's anchor attributes, and the item is a
	 * bare link by construction — so the one thing that can reach it is the
	 * rendered menu. Navigating away from wp-admin mid-task to ask a question, and
	 * losing an unsaved filter doing it, is the outcome worth spending four lines
	 * to avoid.
	 */
	public function print_external_link_script(): void {
		// Matched on a PREFIX, and that is not laziness. `add_submenu_page()` runs
		// the slug through `plugin_basename()`, which trims trailing slashes — so
		// the item registered as `…/contact/` renders as `…/contact`, and an exact
		// href match finds nothing at all. Measured, after writing it the exact
		// way first.
		$url = untrailingslashit( $this->contact_url() );

		printf(
			'<script id="catalogops-external-menu">document.addEventListener("DOMContentLoaded",function(){var a=document.querySelector(\'#adminmenu a[href^="%s"]\');if(a){a.target="_blank";a.rel="noopener";}});</script>',
			esc_js( $url )
		);
	}

	/**
	 * Load the page's stylesheet on the page itself and nowhere else.
	 *
	 * A plain file rather than a build entry: it imports nothing, no other file
	 * imports it, and adding a second webpack entry to carry 200 lines of CSS
	 * would make every future build slower in exchange for nothing.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'catalogops_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$file = plugin_dir_path( $this->plugin_file ) . 'assets/pricing.css';

		if ( ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_style(
			'catalogops-pricing',
			plugins_url( 'assets/pricing.css', $this->plugin_file ),
			array(),
			// The file's own timestamp, so an edit reaches a browser that has
			// already loaded it once. A hardcoded version here is the defect that
			// hides every other fix.
			(string) filemtime( $file )
		);
	}

	/**
	 * The three plans, in the order they are drawn.
	 *
	 * Three columns is the design and not the data — a fourth would need a fourth
	 * column drawn — so the differences between them are decided here by name
	 * rather than being three more values for somebody to fill in wrongly. A
	 * feature line beginning with `-` is one the plan does NOT have and renders
	 * greyed; a seats line is `label | price`.
	 *
	 * @return array<int, array<string, string>> The plans.
	 */
	private function plans(): array {
		$plans = array(
			array(
				'key'      => 'free',
				'variant'  => '',
				'name'     => __( 'Free', 'catalogops' ),
				'tag'      => '',
				'price'    => '$0',
				'period'   => __( 'one site', 'catalogops' ),
				'features' => __( "Filter, preview and apply\nSet a value, or adjust by an amount\nUp to 200 objects per operation\n-No undo\n-No percentages or formulas\n-No scheduling", 'catalogops' ),
				'cta'      => '',
				'url'      => '',
				'seats'    => '',
				'note'     => '',
			),
			array(
				'key'      => 'solo',
				'variant'  => 'is-featured',
				'name'     => __( 'Solo', 'catalogops' ),
				'tag'      => __( 'Most shops', 'catalogops' ),
				'price'    => '$99',
				'period'   => __( '/ year · one site', 'catalogops' ),
				'features' => __( "Everything, with no object limit\nPercentages and formulas\nUndo any run from the history\nRecurring schedules\nEmail report after every run", 'catalogops' ),
				'cta'      => __( 'Buy Solo', 'catalogops' ),
				'url'      => 'https://checkout.freemius.com/plugin/36843/plan/61201/',
				'seats'    => '',
				'note'     => '',
			),
			array(
				'key'      => 'studio',
				'variant'  => 'is-studio',
				'name'     => __( 'Studio', 'catalogops' ),
				'tag'      => __( 'Agencies', 'catalogops' ),
				'price'    => '$199',
				'period'   => __( '/ year · from', 'catalogops' ),
				'features' => __( "Everything in Solo\nFilter by your ACF fields\nMultisite", 'catalogops' ),
				'cta'      => __( 'Buy Studio', 'catalogops' ),
				'url'      => 'https://checkout.freemius.com/plugin/36843/plan/61377/',
				'seats'    => "5 sites | $199\n25 sites | $399\nUnlimited | $699",
				'note'     => __( 'Choose the number of sites at checkout.', 'catalogops' ),
			),
		);

		/**
		 * Filters the plans shown on the Pricing screen.
		 *
		 * @param array<int, array<string, string>> $plans The plans.
		 */
		return (array) apply_filters( 'catalogops_plans', $plans );
	}

	/**
	 * Split a textarea-style value into its non-empty lines.
	 *
	 * @param string $value The raw value.
	 * @return array<int, string> The lines.
	 */
	private function lines( string $value ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $value );

		if ( ! is_array( $lines ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', $lines ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		$premium = $this->license->is_premium();

		echo '<div class="wrap catalogops-pricing">';

		printf(
			'<div class="catalogops-pricing__head"><span class="catalogops-pricing__eyebrow">%s</span><h1>%s</h1><p class="catalogops-pricing__lede">%s</p></div>',
			esc_html__( 'Pricing', 'catalogops' ),
			esc_html__( 'Annual, per site, no surprises', 'catalogops' ),
			esc_html__( 'If a licence lapses, the plugin keeps working exactly as it did. You stop receiving updates and support — you never lose access to your own catalogue tools.', 'catalogops' )
		);

		// A site that already pays is not a buyer, and three Buy buttons with
		// nothing said about the licence it already holds reads as though the
		// purchase never registered.
		if ( $premium ) {
			printf(
				'<div class="notice notice-success inline catalogops-pricing__licensed"><p>%s</p></div>',
				esc_html__( 'This site has an active licence. The plans below are here for reference — manage the licence itself on the Account screen.', 'catalogops' )
			);
		}

		echo '<div class="catalogops-plans">';

		foreach ( $this->plans() as $plan ) {
			$this->render_plan( $plan, $premium );
		}

		echo '</div>';

		printf(
			'<div class="catalogops-guarantee"><p class="catalogops-guarantee__main"><span class="catalogops-guarantee__mark" aria-hidden="true">&#8617;</span><span><b>%s</b> %s</span></p><p class="catalogops-guarantee__note"><a href="%s" target="_blank" rel="noopener">%s</a></p></div>',
			esc_html__( '14 days, money back.', 'catalogops' ),
			esc_html__( 'Any reason, no questions asked.', 'catalogops' ),
			esc_url( $this->site() . '/legal/#refunds' ),
			esc_html__( 'How refunds work', 'catalogops' )
		);

		echo '</div>';
	}

	/**
	 * Render one plan column.
	 *
	 * @param array<string, string> $plan    The plan.
	 * @param bool                  $premium Whether this site already holds a licence.
	 */
	private function render_plan( array $plan, bool $premium ): void {
		printf( '<div class="catalogops-plan %s">', esc_attr( $plan['variant'] ?? '' ) );

		printf( '<div class="catalogops-plan__name">%s', esc_html( $plan['name'] ?? '' ) );

		if ( '' !== ( $plan['tag'] ?? '' ) ) {
			printf( '<span class="catalogops-plan__tag">%s</span>', esc_html( $plan['tag'] ) );
		}

		echo '</div>';

		printf(
			'<p class="catalogops-plan__price"><b>%s</b><span>%s</span></p>',
			esc_html( $plan['price'] ?? '' ),
			esc_html( $plan['period'] ?? '' )
		);

		echo '<ul class="catalogops-plan__features">';

		foreach ( $this->lines( $plan['features'] ?? '' ) as $line ) {
			$off = str_starts_with( $line, '-' );

			printf(
				'<li class="%s">%s</li>',
				$off ? 'is-off' : '',
				esc_html( ltrim( $line, '- ' ) )
			);
		}

		echo '</ul>';

		$seats = $this->lines( $plan['seats'] ?? '' );

		if ( array() !== $seats ) {
			echo '<div class="catalogops-plan__seats">';

			foreach ( $seats as $seat ) {
				$parts = array_map( 'trim', explode( '|', $seat, 2 ) );

				printf( '<div><span>%s</span><span>%s</span></div>', esc_html( $parts[0] ), esc_html( $parts[1] ?? '' ) );
			}

			echo '</div>';
		}

		// The free tier has no button, and that is the one deliberate difference
		// from the website's table. There it says "Download", which is the only
		// sensible thing to offer a reader who does not have the plugin; here the
		// reader is inside the plugin, so a Download button would offer them what
		// they are already running. What is useful instead is which tier this site
		// is actually on, which the website cannot know and this screen can.
		if ( '' !== ( $plan['url'] ?? '' ) && '' !== ( $plan['cta'] ?? '' ) ) {
			printf(
				'<a class="catalogops-plan__cta %s" href="%s" target="_blank" rel="noopener">%s</a>',
				'is-featured' === ( $plan['variant'] ?? '' ) ? 'is-primary' : 'is-quiet',
				esc_url( $plan['url'] ),
				esc_html( $plan['cta'] )
			);
		} elseif ( ! $premium ) {
			printf(
				'<p class="catalogops-plan__current">%s</p>',
				esc_html__( 'This site is on the free tier.', 'catalogops' )
			);
		}

		if ( '' !== ( $plan['note'] ?? '' ) ) {
			printf( '<p class="catalogops-plan__note">%s</p>', esc_html( $plan['note'] ) );
		}

		echo '</div>';
	}
}
