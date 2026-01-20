=== AI Vector Search (Semantic) ===
Contributors: calingrim
Tags: woocommerce, search, ai, semantic, recommendations
Requires at least: 6.0
Tested up to: 6.9
Woocommerce tested up to: 10.4.2
Requires PHP: 8.0
Stable Tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

🚀 Transform your WooCommerce search with AI-powered semantic search. Get smarter product recommendations and blazing-fast search results.

== Description ==

Transform your WooCommerce search with AI-powered semantic technology. **AI Vector Search** delivers lightning-fast, highly relevant results that understand customer intent, not just keywords.

### 🎯 Key Features

**Three Flexible Modes:**
* **Lite Mode (Default)** - Zero-config local TF-IDF search with synonyms and stopwords. No external services required.
* **Self-Hosted Supabase** - Full-text + vector search on your own Supabase infrastructure. You control the data and configuration.
* **Managed API Service** - Let us host the entire stack (Supabase + OpenAI). Just activate with your license key and we handle the infrastructure, updates, and scaling.

**Smart Search:**
* Full-text PostgreSQL search with instant results
* AI semantic understanding via OpenAI embeddings (optional)
* SKU, EAN, UPC, ISBN partial matching
* Hybrid search combining multiple methods

**AI Recommendations:**
* Similar products on detail pages
* Smart cart-based upsells
* Shortcode, Gutenberg block, and Elementor widget support

**Analytics & Insights:**
* Search volume, success rate, and CTR tracking
* Zero-result alerts for merchandising opportunities
* Popular search terms and click tracking

**Professional Tools:**
* WP-CLI commands for schema management and sync
* Encrypted API key storage with master key support
* Auto-sync on product updates
* Woodmart theme integration

### 🔒 Security & Pricing

**Security:**
All API keys encrypted in your WordPress database. Self-hosted mode gives you complete data control. HTTPS-only communications.

**Costs:**
* **Lite Mode:** Free, runs locally with no external dependencies
* **Self-Hosted Supabase:** Free tier includes 50,000 queries/month. Optional OpenAI costs ~$0.05-$1.00 per 1,000 products (one-time)
* **Managed API Service:** Subscription-based pricing. We handle all infrastructure costs and maintenance.

== 📦 Installation ==

### Quick Start (5 Minutes)

1. **Install & Activate** the plugin from WordPress admin or upload manually.
2. **Use Lite Mode instantly** - search works locally out of the box. Visit **AI Vector Search -> Settings** to adjust Lite stopwords, synonyms, or index limits if you need to tune results.
3. **(Optional) Connect Supabase for self-hosted search:**
   - Create a free account at [supabase.com](https://supabase.com) and start a new project.
   - Copy your project URL and service key into the plugin settings.
   - Run the built-in schema installer from the admin UI or WP-CLI.
4. **(Optional) Enable Semantic Search with OpenAI:**
   - Add your OpenAI API key to generate embeddings (self-hosted or API modes).
   - Choose the search mode that fits your catalog and budget.
5. **(Optional) Run a product sync** from **AI Vector Search -> Sync Products** or with `wp aivs sync-products` when Supabase is connected.

### Command Line Tools (WP-CLI)

Speed up setup and maintenance with new WP-CLI commands (requires the PostgreSQL client and, for schema installs, the encrypted connection string saved in settings):
* `wp aivs install-schema` - install or update the Supabase schema from your WordPress server.
* `wp aivs check-schema` - verify tables, functions, and extensions are present.
* `wp aivs test-connection` - confirm credentials before running migrations.
* `wp aivs sync-products` - batch sync products after catalog changes.

You can also trigger schema installation from the admin UI; both paths use the encrypted PostgreSQL connection string you store under **Settings  AI Supabase**.

### Getting Your API Keys

**Supabase:**
1. Visit [app.supabase.com](https://app.supabase.com)
2. Go to Project Settings → API
3. Copy your project URL and service role key

**OpenAI (Optional for Semantic Search):**
1. Visit [platform.openai.com/api-keys](https://platform.openai.com/api-keys)
2. Create a new API key
3. Ensure billing is set up for embedding API usage

== 📸 Screenshots ==

1. Dashboard notice showing new WP-CLI support and quick setup actions.
2. Settings menu entries added by AI Vector Search (Search Analytics, Supabase Status, Sync Products).
3. Search Analytics dashboard with success rate, CTR, and popular search terms.
4. Main plugin settings page with Supabase and OpenAI configuration, plus WP-CLI schema installation.
5. Status page showing store health overview and configuration summary.
6. Sync Products page with full sync, batch sync, and embeddings generation options.
7. Setup guide for manual and WP-CLI installation, including PostgreSQL connection and OpenAI configuration.

== ❓ Frequently Asked Questions ==

= What are the connection modes? =

Lite mode runs locally and is enabled by default. Switch to self-hosted Supabase when you want scalable vector search on your own infrastructure, or activate the managed API service with your license key when you prefer a fully hosted stack. You can change modes in **Settings  AI Supabase** and the plugin will guide you through any extra steps (keys, schema install, or product sync).

= When should I use each connection mode? =

* **Lite Mode** - Best for: small stores (<1000 products), budget-conscious merchants, or testing the plugin
* **Self-Hosted Supabase** - Best for: full control, larger catalogs, semantic search, international stores

= Can I switch connection modes later? =

Yes! You can switch between Lite, Self-Hosted, and Managed API modes at any time from Settings. Your search analytics are preserved, but you'll need to re-sync products when switching to Supabase or API modes.

= Is OpenAI required? =

No! The plugin works great with just Supabase for fast keyword search. OpenAI is only needed for semantic (AI) search and enhanced recommendations. You can start with keyword search and add semantic features later.

= How much does it cost to run? =

**Supabase:** Free tier includes 50,000 monthly queries - perfect for most stores. Paid plans start at $25/month for high-traffic sites.

**OpenAI:** One-time embedding cost of ~$0.05-$1.00 per 1,000 products. After initial setup, ongoing costs are minimal (only for new products).

= Will this slow down my site? =

No! Search queries run on Supabase's fast PostgreSQL infrastructure, not your WordPress server. This often makes search faster than default WooCommerce.

= Does it work with my theme? =

Yes! The plugin uses standard WordPress and WooCommerce hooks. It includes special integration for Woodmart theme's live search feature.

= Can I customize the search behavior? =

Absolutely! The plugin is built with developer hooks and filters. Need custom field indexing or search logic? Check our Premium setup service for advanced customization.

= What happens to my data if I uninstall? =

Your product data remains in your Supabase project - you have full control. The WordPress plugin only removes its settings and stops syncing. You can delete data from Supabase manually if desired.

= Is it GDPR compliant? =

The plugin only syncs product data (names, descriptions, prices, etc.) - no personal customer information. When semantic search is enabled, product text is processed by OpenAI according to their privacy policy.

= Can I use this on multiple stores? =

Yes! Each store gets its own unique Store ID, allowing multiple WooCommerce sites to use the same Supabase project while keeping data separate.

= How do I display cart recommendations? =

You can show AI-powered recommendations based on cart contents using:
* **Shortcode:** `[aivs_cart_recommendations]` - Add anywhere in your content
* **Gutenberg Block:** Search for "Cart Recommendations" in the block editor
* **Elementor Widget:** Available in Elementor's widget panel
* **Template Function:** `<?php echo do_shortcode('[aivs_cart_recommendations]'); ?>` for theme files

== ⚡ Technical Requirements ==

* **WordPress:** 6.0 or higher
* **PHP:** 8.0 or higher (8.1+ recommended)
* **WooCommerce:** 5.0 or higher
* **Supabase Account:** Free tier sufficient for most stores
* **OpenAI API Key:** Optional, only for semantic search

== 🔐 Privacy & Data Usage ==

### What Data is Synced?
- Product names, descriptions, and short descriptions
- SKUs, GTINs (EAN/UPC/ISBN), and brand information
- Categories, tags, and custom attributes
- Prices (regular, sale, cost) and stock status
- Product images (URLs only) and ratings

### What Data is NOT Synced?
- Customer information
- Order details
- Personal data
- Payment information

### Third-Party Services
- **Supabase:** Product data stored in your own Supabase project
- **OpenAI:** Product text processed for embeddings when semantic search is enabled

All communication uses HTTPS. You maintain full control over your API keys and can revoke access at any time.

== 🛠️ Support & Professional Services ==

### Community Support
- Plugin documentation and FAQ
- WordPress.org support forums
- GitHub issues (for technical bugs)

### Premium Setup Service by ZZZ Solutions
- **Complete Setup:** We install and configure everything for you
- **Custom Field Mapping:** Index specific product attributes and meta fields
- **Advanced Search Tuning:** Optimize search relevance for your catalog
- **Multi-language Support:** Configure search for international stores
- **Custom Recommendations:** Tailored recommendation algorithms
- **Performance Optimization:** Fine-tune for large catalogs

[Contact ZZZ Solutions](https://zzzsolutions.ro) for professional setup and customization.

== 📝 Changelog ==

= 1.0.0 (Latest) =
* **Milestone:** Official stable release with production-ready feature set
* **New:** Three flexible connection modes - Lite (local), Self-Hosted (Supabase), and Managed API Service
* **New:** Complete WP-CLI command suite for professional database management and setup
* **New:** Cart recommendations with shortcode, Gutenberg block, and Elementor widget support
* **New:** Search Analytics dashboard with detailed insights, CTR tracking, and zero-result alerts
* **New:** Advanced encryption system with master key support for secure credential storage
* **New:** Lite Mode TF-IDF engine with synonym expansion and stopword filtering for zero-dependency search
* **Security:** Enhanced nonce verification and URL escaping throughout admin interface
* **Security:** Encrypted PostgreSQL connection string storage with enterprise-grade protection
* **Performance:** Optimized admin interface with modular asset loading and template system
* **Performance:** Improved Supabase schema with re-runnable migrations and simplified RLS policies
* **Compatibility:** PHP 8.0+ required, tested up to WordPress 6.9 and WooCommerce 10.4.2
* **Developer:** Better code organization following PSR-12 standards and WordPress best practices
* **Developer:** Comprehensive hooks and filters for customization and extensibility

= 0.18.3 =
* **New:** Cart recommendations shortcode, block, and Elementor widget for flexible placement
* **New:** Admin tool to update product sold_count in Supabase for any selected timeframe
* **New:** Explicit dependency-ordered class loading replaces autoloader for better reliability
* **Fix:** Woodmart live search nonce handling with backwards-compatible fallbacks
* **Fix:** Analytics AJAX endpoints now properly handle multiple nonce sources
* **Fix:** Supabase client improved error handling for request failures
* **Security:** Enhanced URL escaping throughout admin interface with esc_url()
* **Security:** Improved nonce verification across all AJAX handlers
* **Update:** Supabase SQL schema now fully re-runnable with DROP IF EXISTS statements
* **Update:** Simplified RLS policies - removed problematic anon policies, keeping public read-only access
* **Update:** Code formatting standardized to PSR-12 throughout the codebase
* **Update:** Requires PHP 8.0+ (previously 7.4+)
* **Dev:** Better separation of concerns in JavaScript with centralized nonce handling

= 0.18.2 =
* **New:** Top level menu for the plugin
* **New:** Configurable search limit for search results
* **Fix:** Analytics template

= 0.18.0 =
* **New:** Lite Mode local TF-IDF search with configurable stopwords, synonym expansion, scheduled re-indexing, and upgrade guidance for stores that want zero external services
* **New:** Search enable/disable toggle so merchants can fall back to default WooCommerce search without disabling the plugin, plus Lite Mode defaults on fresh installs
* **New:** Fuzzy matching fallback across Lite, API, and self-hosted modes to reduce zero-result searches and surface partial matches
* **Update:** Analytics engine now sanitises input, caches recent results, secures CSV export with nonces, and invalidates caches on data changes for faster, safer reporting
* **Fix:** Lite Mode AJAX endpoints, synonym/stopword sanitisation, and uninstall cleanup ensure consistent local search behaviour and remove scheduled jobs/transients on uninstall

= 0.17.0 =
**Major Architectural Improvements:**
* **New:** Modular asset structure with separate CSS, JS, and template files
* **New:** Template system for better customization
* **New:** Optimized admin interface loading with conditional asset enqueueing
* **Update:** Refactored admin interface for better performance and maintainability
* **Update:** Enhanced error handling and user feedback systems
* **Update:** Improved code organization following WordPress best practices
* **Fix:** Better asset loading prevents conflicts with other plugins
* **Dev:** Easier customization and theming capabilities for developers

= 0.16.5.1 =
* **Update:** Updated readme with screenshots

= 0.16.5 =
* **New:** WP-CLI schema installation commands for reliable database setup
* **New:** Direct PostgreSQL connection support with encrypted credential storage
* **New:** Three-tier installation system: Manual copy/paste, WP-CLI commands, and Admin interface buttons
* **New:** Migration Runner architecture for transactional database operations with rollback support
* **New:** Real-time installation progress feedback in admin interface
* **Update:** Enhanced admin interface with PostgreSQL connection configuration field
* **Update:** Improved security with encrypted PostgreSQL connection string storage
* **Update:** Better user experience with step-by-step setup guidance
* **Update:** Enhanced status checking with detailed environment diagnostics
* **Fix:** Improved error messages and troubleshooting guidance for failed installations

= 0.16.4 =
* **New:** Custom SQL upsert function for reliable product synchronization
* **Fix:** Product sync failures due to Row Level Security (RLS) policy conflicts
* **Fix:** Auto-sync triggering duplicate requests due to WooCommerce hook behavior
* **Update:** Enhanced GTIN field mapping for EAN/UPC/ISBN product codes
* **Update:** Improved Cost of Goods Sold (COGS) field detection and mapping

= 0.16.3 =
* **Fix:** Added track_click method

= 0.16.2 =
* **New:** Added analytics dashboard
* **New:** Fuzzy search
* **New:** Prepared for API managed API service alongside self-hosted
* **New:** Dual connection mode support (API Service vs Self-Hosted)
* **New:** License key activation system for API service
* **New:** Connection manager for seamless mode switching
* **New:** Enhanced admin interface with mode-specific guidance
* **New:** Welcome notices for new installations
* **New:** Plugin action links showing current mode
* **Update:** Refactored architecture to support both deployment options
* **Update:** Improved error handling and user feedback
* **Update:** Enhanced security for API communications
* **Update:** Increased match_threshold
* **Fix:** Woodmart AJAX integration

= 0.15.3 =
* **Update:** Improved readme file and documentation

= 0.15.2 =
* **New:** Enhanced SQL schema with partial SKU search
* **New:** Updated admin interface with better user experience
* **New:** Improved search result ranking and relevance
* **New:** Better admin notifications for SQL updates
* **Fix:** Resolved various edge cases in product sync

= 0.15.1 =
* **New:** Woodmart theme live search integration
* **New:** SKU/GTIN search fallback for better product discovery
* **New:** Complete plugin architecture refactor for better performance
* **Fix:** Improved search result accuracy and ranking

= 0.14.0 =
* **Fix:** Resolved embedding generation for missing products
* **Fix:** Status page statistics (division by zero errors)
* **New:** Admin notification system for database updates

= 0.13.5 =
* **Update:** Comprehensive readme improvements
* **Update:** Better documentation and setup instructions

= 0.13.4 =
* **Fix:** Product synchronization improvements
* **New:** Professional services banner and information

= 0.13.3 =
* **Update:** Enhanced plugin assets and branding
* **Update:** Improved readme file and documentation

= 0.13.2 =
* **Fix:** Removed debug logging that could impact performance

= 0.13.1 =
* **Update:** Documentation and user experience improvements

= 0.13.0 =
* **Fix:** Secure key storage for Supabase and OpenAI API keys
* **Fix:** Product recommendations and cart suggestions
* **New:** Enhanced encryption system with master key support
* **Update:** Improved security for sensitive data

= 0.12.3.1 =
* **Update:** Changelog formatting and documentation

= 0.12.3 =
* **New:** Automatic UUID generation for store identification
* **Update:** Renamed master key constant for better security

= 0.12.2 =
* **Launch:** Initial public release with full feature set

== ⬆️ Upgrade Notice ==

= 1.0.0 (Latest) =
🎉 **Major Milestone: Production-Ready v1.0.0!** This stable release brings together all features from the 0.x series into a production-ready package. Includes three connection modes (Lite/Self-Hosted/Managed API), WP-CLI commands, cart recommendations, search analytics, and enterprise-grade security. PHP 8.0+ now required. Recommended for all users - this is the foundation for future development. Safe upgrade with full backward compatibility.

= 0.18.3 =
* **New:** Cart recommendations shortcode, block, and Elementor widget for flexible placement
* **New:** Admin tool to update product sold_count in Supabase for any selected timeframe
* **New:** Explicit dependency-ordered class loading replaces autoloader for better reliability
* **Fix:** Woodmart live search nonce handling with backwards-compatible fallbacks
* **Fix:** Analytics AJAX endpoints now properly handle multiple nonce sources
* **Fix:** Supabase client improved error handling for request failures
* **Security:** Enhanced URL escaping throughout admin interface with esc_url()
* **Security:** Improved nonce verification across all AJAX handlers
* **Update:** Supabase SQL schema now fully re-runnable with DROP IF EXISTS statements
* **Update:** Simplified RLS policies - removed problematic anon policies, keeping public read-only access
* **Update:** Code formatting standardized to PSR-12 throughout the codebase
* **Update:** Requires PHP 8.0+ (previously 7.4+)
* **Dev:** Better separation of concerns in JavaScript with centralized nonce handling

= 0.18.2 =
* **New:** Top level menu for the plugin
* **New:** Configurable search limit for search results
* **Fix:** Analytics template

= 0.18.0 =
* **New:** Lite Mode local TF-IDF search with configurable stopwords, synonym expansion, scheduled re-indexing, and upgrade guidance for stores that want zero external services
* **New:** Search enable/disable toggle so merchants can fall back to default WooCommerce search without disabling the plugin, plus Lite Mode defaults on fresh installs
* **New:** Fuzzy matching fallback across Lite, API, and self-hosted modes to reduce zero-result searches and surface partial matches
* **Update:** Analytics engine now sanitises input, caches recent results, secures CSV export with nonces, and invalidates caches on data changes for faster, safer reporting
* **Fix:** Lite Mode AJAX endpoints, synonym/stopword sanitisation, and uninstall cleanup ensure consistent local search behaviour and remove scheduled jobs/transients on uninstall

= 0.17.0 =
**Major Architectural Improvements:**
* **New:** Modular asset structure with separate CSS, JS, and template files
* **New:** Template system for better customization
* **New:** Optimized admin interface loading with conditional asset enqueueing
* **Update:** Refactored admin interface for better performance and maintainability
* **Update:** Enhanced error handling and user feedback systems
* **Update:** Improved code organization following WordPress best practices
* **Fix:** Better asset loading prevents conflicts with other plugins
* **Dev:** Easier customization and theming capabilities for developers

= 0.16.5.1 =
* **Update:** Updated readme with screenshots

= 0.16.5 =
* **New:** WP-CLI schema installation commands for reliable database setup
* **New:** Direct PostgreSQL connection support with encrypted credential storage
* **New:** Three-tier installation system: Manual copy/paste, WP-CLI commands, and Admin interface buttons
* **New:** Migration Runner architecture for transactional database operations with rollback support
* **New:** Real-time installation progress feedback in admin interface
* **Update:** Enhanced admin interface with PostgreSQL connection configuration field
* **Update:** Improved security with encrypted PostgreSQL connection string storage
* **Update:** Better user experience with step-by-step setup guidance
* **Update:** Enhanced status checking with detailed environment diagnostics
* **Fix:** Improved error messages and troubleshooting guidance for failed installations

= 0.16.4 =
* **New:** Custom SQL upsert function for reliable product synchronization
* **Fix:** Product sync failures due to Row Level Security (RLS) policy conflicts
* **Fix:** Auto-sync triggering duplicate requests due to WooCommerce hook behavior
* **Update:** Enhanced GTIN field mapping for EAN/UPC/ISBN product codes
* **Update:** Improved Cost of Goods Sold (COGS) field detection and mapping

= 0.16.3 =
* **Fix:** Added track_click method

= 0.16.2 =
* **New:** Added analytics dashboard
* **New:** Fuzzy search
* **New:** Prepared for API managed API service alongside self-hosted
* **New:** Dual connection mode support (API Service vs Self-Hosted)
* **New:** License key activation system for API service
* **New:** Connection manager for seamless mode switching
* **New:** Enhanced admin interface with mode-specific guidance
* **New:** Welcome notices for new installations
* **New:** Plugin action links showing current mode
* **Update:** Refactored architecture to support both deployment options
* **Update:** Improved error handling and user feedback
* **Update:** Enhanced security for API communications
* **Update:** Increased match_threshold
* **Fix:** Woodmart AJAX integration

= 0.16.0 =
* API Support

= 0.15.3 =
* **Update:** Improved readme file and documentation

= 0.15.2 =
**Important:** New SQL schema available with enhanced search capabilities. Update your Supabase database using the SQL provided in Settings → AI Supabase for best performance.

= 0.15.1 =
Major architecture improvements with Woodmart integration and enhanced search. Recommended for all users.

= 0.14.0 =
Bug fixes for embedding generation and statistics. Recommended update for semantic search users.

== 🎨 Blocks & Shortcodes ==

**Cart Recommendations:**
* Shortcode: `[aivs_cart_recommendations]`
* Gutenberg Block: Available in block editor
* Elementor Widget: Drag and drop widget

**Similar Products:**
Automatically displayed on product pages when recommendations are enabled.

== 📚 Technical Documentation ==

### Hooks and Filters

**Actions:**
- `aivesese_product_synced` - Fired when a product is successfully synced
- `aivesese_batch_complete` - Fired when a batch sync is completed

**Filters:**
- `aivesese_product_data` - Modify product data before syncing
- `aivesese_search_results` - Filter search results before display
- `aivesese_embedding_text` - Customize text used for embeddings

### Database Schema

The plugin creates the following in your Supabase project:
- `products` table with full-text search indexes
- Vector similarity indexes for semantic search
- RPC functions for search and recommendations
- Row-level security policies for data protection

### Performance Tuning

For large catalogs (10,000+ products):
- Use batch sync instead of full sync
- Consider upgrading to Supabase Pro for better performance
- Monitor embedding generation costs during initial setup
- Implement custom caching strategies if needed
