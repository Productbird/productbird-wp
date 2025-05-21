=== Productbird ===
Contributors: productbird
Tags: woocommerce, ai, artificial intelligence, product description, ecommerce
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 0.1.0
Requires PHP: 7.4
Requires Plugins: woocommerce
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://productbird.ai

Productbird helps ecommerce owners get more done by providing various AI tools for WooCommerce.

== Description ==

Productbird uses AI to streamline your WooCommerce store management. Currently, its main feature is to help you generate compelling product descriptions quickly and easily.

Connect your store to the Productbird.ai service using an API key, select your products, and let the AI assist you in crafting descriptions that can save you time and improve your product listings.

**Key Features:**

*   Generate product descriptions using AI.
*   Bulk action to generate descriptions for multiple products at once.
*   "AI Desc." column in the WooCommerce product list to track generation status.
*   Filter products by "No description" or "AI generated".
*   Settings page to configure your Productbird.ai API key and generation preferences (tone, formality).

**Important Note on Data Usage:**

To provide AI-powered features, this plugin sends product data (such as product name, categories, SKU, attributes, image URLs, store name, and store URL) to the external Productbird.ai service. Your Productbird API key is required to use these features. Only this specific product and store information is sent; no other user or site data is transmitted for this purpose. Please ensure you are comfortable with this data usage before using the AI generation tools. You can find more information about Productbird.ai and its policies on [the Productbird website](https://productbird.ai), including our [Privacy Policy](https://productbird.ai/privacy-policy/) and [Terms and Conditions](https://productbird.ai/terms-conditions/).

== Installation ==

1.  Upload the `productbird` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Go to the "Productbird" settings page in your WordPress admin menu.
4.  Enter your Productbird.ai API key. You can obtain this from your Productbird.ai account.
5.  Configure any other settings, such as default tone and formality for generated descriptions.
6.  You can now use the "Generate description with AI" bulk action on the WooCommerce products page.

== Frequently Asked Questions ==

= Do I need a Productbird.ai account and API key? =

Yes, the AI generation features require a connection to the Productbird.ai service, which necessitates an API key from your account.

= What data is sent to Productbird.ai? =

When generating descriptions, product details like name, categories, SKU, attributes, image URLs, and store name are sent to the Productbird.ai service. The plugin does not send other user or site data for this purpose without your explicit action. For authentication features (if OIDC is enabled), your site name and URL may be shared during the client registration process.

= Is there a cost associated with using the AI features? =

The plugin itself is free and licensed under GPL. However, the Productbird.ai service has its own pricing plans and usage costs associated with API key access and AI generation, depending on your Productbird.ai account. Please refer to Productbird.ai for their pricing details.

== Changelog ==

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.0 =
Initial release.