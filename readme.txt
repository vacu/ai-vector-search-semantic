=== AI Vector Search (Semantic) ===
Contributors: calingrim
Tags: woocommerce, search, ai, semantic, recommendations
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable Tag: 0.16.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

🚀 Transform your WooCommerce search with AI-powered semantic search. Get smarter product recommendations and blazing-fast search results.

== Description ==

**AI Vector Search** revolutionizes your WooCommerce store's search experience by combining traditional keyword search with cutting-edge semantic AI technology. Built on Supabase's powerful PostgreSQL backend with optional OpenAI embeddings, it delivers lightning-fast, highly relevant search results that understand customer intent, not just keywords.

### 🎯 Key Features

**Smart Search Technology:**
* **Full-Text Search (FTS)** - Lightning-fast PostgreSQL-powered keyword matching
* **Semantic Vector Search** - AI understanding of product meaning and context using OpenAI embeddings
* **SKU & GTIN Search** - Find products by partial SKU, EAN, UPC, or ISBN codes
* **Hybrid Search** - Combines multiple search methods for best results

**Intelligent Recommendations:**
* **Similar Products** - AI-powered product recommendations on product detail pages
* **Cart Recommendations** - Smart upsell suggestions based on cart contents
* **Category-Aware Suggestions** - Recommendations that understand product relationships

**Advanced Integrations:**
* **Woodmart Theme Support** - Seamless integration with Woodmart's live search
* **Auto-Sync** - Automatically sync products when saved/updated
* **Batch Processing** - Handle large catalogs with intelligent batching

**Developer-Friendly:**
* **Secure Key Management** - Encrypted storage of API keys with master key support
* **Row-Level Security** - Built-in Supabase RLS policies
* **Comprehensive Admin Interface** - Status monitoring, health checks, and sync tools

### 🔒 Security & Privacy First

* **Your Keys, Your Control** - All API keys stored securely in your WordPress database
* **Encrypted Storage** - Sensitive data encrypted with configurable master keys
* **No Data Lock-in** - Full control over your Supabase project and data
* **HTTPS Only** - All API communications secured with SSL/TLS

### 💰 Transparent Pricing

**OpenAI Embedding Costs (One-time per product):**
* 1,000 products: ~$0.05-$0.10
* 5,000 products: ~$0.25-$0.50
* 10,000 products: ~$0.50-$1.00

**Supabase Free Tier Includes:**
* 500MB database storage
* 50,000 monthly queries
* 500,000 edge function calls
* Perfect for small to medium stores

### 🎯 Perfect For

* **E-commerce stores** wanting better search relevance
* **Large catalogs** needing semantic understanding
* **International stores** with multi-language products
* **Stores with complex product attributes** and variations
* **Developers** seeking modern, scalable search infrastructure

== Installation ==

### Quick Start (5 Minutes)

1. **Install & Activate** the plugin from WordPress admin or upload manually
2. **Set up Supabase:**
   - Create a free account at [supabase.com](https://supabase.com)
   - Create a new project
   - Copy your project URL and service key
3. **Configure Plugin:**
   - Go to **Settings → AI Supabase**
   - Enter your Supabase URL and key
   - (Optional) Add OpenAI API key for semantic search
4. **Install Database Schema:**
   - Copy the provided SQL from the setup guide
   - Paste into **Supabase → SQL Editor** and run
5. **Sync Products:**
   - Visit **Settings → Sync Products**
   - Click "Sync All Products" or use batch sync for large catalogs

### Getting Your API Keys

**Supabase:**
1. Visit [app.supabase.com](https://app.supabase.com)
2. Go to Project Settings → API
3. Copy your project URL and service role key

**OpenAI (Optional for Semantic Search):**
1. Visit [platform.openai.com/api-keys](https://platform.openai.com/api-keys)
2. Create a new API key
3. Ensure billing is set up for embedding API usage

== Frequently Asked Questions ==

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

== Technical Requirements ==

* **WordPress:** 6.0 or higher
* **PHP:** 7.4 or higher (8.1+ recommended)
* **WooCommerce:** 5.0 or higher
* **Supabase Account:** Free tier sufficient for most stores
* **OpenAI API Key:** Optional, only for semantic search

== Privacy & Data Usage ==

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

== Support & Professional Services ==

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

== Changelog ==

= 0.16.5 (Latest) =
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

== Upgrade Notice ==

= 0.16.5 (Latest) =
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

== Technical Documentation ==

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
