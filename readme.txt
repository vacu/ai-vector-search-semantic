=== AI Vector Search (Semantic) ===
Contributors: calingrim
Tags: woocommerce, search, recommendations, supabase, embeddings
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable Tag: 0.15.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Supabase‑powered WooCommerce search with optional semantic (vector) search via OpenAI, plus related/upsell recommendations.

== Description ==
AI Vector Search replaces default WooCommerce search with a fast, relevance‑driven Supabase backend, and (optionally) semantic vector search using OpenAI embeddings. It also adds:
* PDP “Similar products” (vectors)
* Cart‑below recommendations
* Batch/full sync UI for exporting products to Supabase
* One‑click SQL installer snippet you can paste in Supabase

**You control keys.** Use a Supabase anon or service key. Semantic search is optional and only used if you provide an OpenAI key.

= Features =
* Keyword search via Postgres full‑text search (FTS)
* Optional semantic search with OpenAI embeddings
* Related products on PDP via similarity RPC
* Cart recommendations
* Admin pages: Settings • Status • Sync
* Auto‑sync on product save (optional)

= Security & Privacy =
* Uses your Supabase project URL + key.
* Keys are stored as WordPress options.

== Installation ==
1. Install and activate the plugin.
2. Go to **Settings → AI Supabase** and enter:
   * Supabase URL
   * Supabase key (anon or service)
   * Store ID (UUID)
   * (Optional) OpenAI key, then enable “semantic”
3. In the same page, copy the provided SQL and paste it in **Supabase → SQL Editor**, then run it.
4. Optionally trigger **Sync Products** from **Settings → Supabase Status → Sync**.

== Privacy & Costs ==
This plugin syncs your WooCommerce product data (including names, descriptions, prices, SKUs, categories, tags, attributes, and image URLs) to your Supabase account for search and recommendation features. No personal customer data is synced.
* Embeddings are billed by your OpenAI account when enabled. Keys are stored securely; you can disable semantic mode at any time.
* Approximate embedding costs (OpenAI `text-embedding-3-small` as of 2025):
* ~1,000 products → **~$0.05–0.10 one‑time**
* ~5,000 products → **~$0.25–0.50 one‑time**
* ~10,000 products → **~$0.50–1.00 one‑time**
* Supabase Free Tier (as of 2025) includes:
* **500 MB database space**, 500 MB file storage
* **50,000 monthly active queries (searches/recommendations)**
* **500,000 edge function invocations**
* Sufficient for many small/medium WooCommerce shops.

If semantic search is enabled, product text is sent to OpenAI to generate embeddings. OpenAI's privacy policy applies: https://openai.com/policies/privacy-policy.

All data transmission uses HTTPS. You control your Supabase/OpenAI keys and can delete data from those services at any time.

== Frequently Asked Questions ==
= Do I need OpenAI? =
Yes, if you want semantic search and recommendations. An OpenAI key is required to generate embeddings during product sync. If you don’t provide it, only keyword search works.

= How much does it cost? =
Embedding generation is a one‑time cost per product. Typical pricing (OpenAI 2025):
- ~1,000 products → ~$0.05–0.10
- ~5,000 products → ~$0.25–0.50
- ~10,000 products → ~$0.50–1.00
After embeddings are generated, queries are cheap and handled by Supabase.

= What about Supabase limits? =
The free tier includes 50,000 queries per month, enough for many small shops. Larger stores may upgrade as needed.

= What about privacy? =
Product text used for embeddings is sent to OpenAI only when semantic mode is on. You can disable it anytime. Keys are stored using WordPress options with sanitization and can be removed.

= Does it change WooCommerce templates? =
It hooks into search and related‑products logic with standard filters/actions. Woodmart live‑search is supported without template overrides.

= Can I customize which fields are indexed? =
Yes. The Premium setup service can include custom field mapping and category normalization.

== Changelog ==
= 0.15.1 =
Added Woodmart search integration.
Fixed search.
Added SKU fallback.
Refactored the whole plugin code.
= 0.14.0 =
Fixed Supabase Generate missing embeddings
Fixed Status Page stats (division by 0)
Added banner to notify the user to update Supabase DB
= 0.13.5 =
Updated readme
= 0.13.4 =
Fixed product sync
Added banner
= 0.13.3 =
Updated readme file
Added banner and icon
= 0.13.2 =
Removed log block
= 0.13.1 =
Updated readme
= 0.13 =
Fixed saving of Supabase key to DB
Fixed saving of OpenAI key to DB
Fixed checks for recommendations & below cart
Updated encryption
= 0.12.3.1 =
Updated Changelog
= 0.12.3 =
Renamed key to AIVESESE_MASTER_KEY_B64
Added auto-generation of UUID (store id)
= 0.12.2 =
* Initial public release

== Upgrade Notice ==
= 0.15.1 =
Added Woodmart search integration.
Fixed search.
Added SKU fallback.
Refactored the whole plugin code.
= 0.14.0 =
Fixed Supabase Generate missing embeddings
Fixed Status Page stats (division by 0)
Added banner to notify the user to update Supabase DB
= 0.13.5 =
Updated readme
= 0.13.4 =
Fixed product sync
Added banner
= 0.13.3 =
Updated readme file
Added banner and icon
= 0.13.2 =
Removed log block
= 0.13.1 =
Updated readme
= 0.13 =
Fixed saving of Supabase key to DB
Fixed saving of OpenAI key to DB
Fixed checks for recommendations & below cart
Updated encryption
= 0.12.3.1 =
Updated Changelog
= 0.12.3 =
Renamed key to AIVESESE_MASTER_KEY_B64
Added auto-generation of UUID (store id)
= 0.12.2 =
* Initial public release
