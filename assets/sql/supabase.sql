-- ══════════════════════════════════════════════════════════════
-- AI Vector Search SQL v2.0 - Enhanced Search Schema
-- ══════════════════════════════════════════════════════════════
-- 🆕 NEW FEATURES:
-- ✨ Partial SKU search with sku_search() function
-- 🔍 Enhanced full-text search ranking
-- 🚀 Woodmart live search integration support
-- 📈 Improved search performance with better indexes
--
-- 🔄 SAFE TO RE-RUN: Uses CREATE OR REPLACE and IF NOT EXISTS
-- ══════════════════════════════════════════════════════════════

/* ──────────────────────────────────────────────────────────────
   0. EXTENSIONS
   ────────────────────────────────────────────────────────────── */
create extension if not exists pgcrypto;       -- gen_random_uuid()
create extension if not exists pg_trgm;        -- trigram (optional)
create extension if not exists vector;         -- pgvector 0.6+

/* ──────────────────────────────────────────────────────────────
   1. PRODUCTS TABLE
   ────────────────────────────────────────────────────────────── */
create table if not exists products (
  id              uuid primary key default gen_random_uuid(),
  store_id        uuid      not null,
  woocommerce_id  bigint    not null,
  sku             text,
  gtin            text,                        -- EAN/UPC/ISBN
  name            text    not null,
  description     text,
  image_url       text,

  /* taxonomy-like */
  brand           text,
  categories      text[],                       -- ["Vitamins","Kids"]
  tags            text[],

  /* pricing & stock */
  regular_price   numeric(10,2),
  sale_price      numeric(10,2),
  cost_price      numeric(10,2),
  margin          numeric generated always as
                   (regular_price - cost_price) stored,
  stock_quantity  int,
  stock_status    text default 'in',            -- in / out / backorder

  /* search vectors */
  ts_index        tsvector,
  embedding       vector(1536),

  /* metrics */
  average_rating  numeric(2,1),
  review_count    int,
  sold_count      int default 0,

  /* attributes - moved up here for clarity */
  attributes      jsonb,

  /* bookkeeping */
  status          text default 'publish',     -- published/draft/archived
  created_at      timestamptz default now(),
  updated_at      timestamptz default now()
);

create unique index if not exists products_store_wc_uidx
  on products(store_id, woocommerce_id);

/* ──────────────────────────────────────────────────────────────
   2. FULL-TEXT SEARCH TRIGGER + INDEX (UPDATED VERSION)
   ────────────────────────────────────────────────────────────── */
create or replace function trg_products_tsvector()
returns trigger language plpgsql as $$
begin
  new.ts_index :=
    to_tsvector('simple',
      coalesce(new.name,'') || ' ' ||
      coalesce(new.description,'') || ' ' ||
      array_to_string(new.categories,' ') || ' ' ||
      array_to_string(new.tags,' ') || ' ' ||
      coalesce(new.brand,'') || ' ' ||
      coalesce(new.sku,'') || ' ' ||
      coalesce(new.gtin,'') || ' ' ||
      coalesce(new.attributes::text,'')          -- includes attributes
    );
  new.updated_at := now();  -- auto-update timestamp
  return new;
end $$;

create trigger tsvector_update
  before insert or update
  on products
  for each row execute function trg_products_tsvector();

create index if not exists idx_products_fts
  on products using gin(ts_index);

/* ──────────────────────────────────────────────────────────────
   3. VECTOR INDEX (IVF-Flat)
   ────────────────────────────────────────────────────────────── */
create index if not exists idx_products_embedding
  on products
  using ivfflat (embedding vector_l2_ops)
  with (lists = 100);   -- tune if catalogue is huge

/* Additional helpful indexes */
create index if not exists idx_products_store_status
  on products(store_id, status);

create index if not exists idx_products_stock_status
  on products(stock_status) where status = 'publish';

create index if not exists idx_products_categories
  on products using gin(categories);

/* ──────────────────────────────────────────────────────────────
   5. RPCs
   ────────────────────────────────────────────────────────────── */

-- 5.1  Semantic search  (query vector → k-NN over embeddings)
create or replace function semantic_search(
  store_id          uuid,
  query_embedding   vector,
  match_threshold   float  default 0.5,
  p_k               int    default 20
)
returns table (woocommerce_id bigint, distance float)
language sql stable as $$
  select p.woocommerce_id,
         (p.embedding <=> query_embedding) as distance
  from products p
  where p.store_id = store_id
    and (p.embedding <=> query_embedding) < match_threshold
    and p.status = 'publish'
    and p.stock_status = 'in'
  order by distance
  limit p_k;
$$;

-- 5.2  "Similar products" for PDP widget
create or replace function similar_products(
  prod_wc_id bigint,
  k          int default 8
)
returns table (woocommerce_id bigint)
language sql stable as
$$
with ref as (
  select store_id, categories, embedding
  from products
  where woocommerce_id = prod_wc_id
    and status = 'publish'
  limit 1
),
candidates as (
  select p.*,
         (p.embedding <=> r.embedding) as dist
  from   products p, ref r
  where  p.store_id      = r.store_id
    and  p.woocommerce_id <> prod_wc_id
    and  p.status        = 'publish'
    and  p.stock_status  = 'in'
    and  p.regular_price is not null and p.regular_price > 0
    and  p.categories && r.categories               -- same cat only
    and  (p.embedding <=> r.embedding) < 0.35      -- distance gate
)
select woocommerce_id
from   candidates
order  by dist asc          -- smallest distance first
limit  k;
$$;

-- 5.3  Rule-based mini-cart recommendations
create or replace function get_recommendations(
  store_id uuid,
  cart     bigint[],   -- array of Woo IDs
  p_k      int default 4
)
returns table (woocommerce_id bigint)
language sql stable as $$
  with cart_cats as (
    select unnest(categories) as cat
    from products
    where store_id = get_recommendations.store_id
      and woocommerce_id = any(cart)
  )
  select p.woocommerce_id
  from products p
  join cart_cats cc on cc.cat = any(p.categories)
  where p.store_id = get_recommendations.store_id
    and p.woocommerce_id <> all(cart)
    and p.stock_status = 'in'
    and p.status = 'publish'
  group by p.woocommerce_id, p.margin, p.sold_count
  order by p.margin desc, p.sold_count desc, random()
  limit p_k;
$$;

-- 5.4  Health check function (useful for WordPress admin)
DROP FUNCTION IF EXISTS store_health_check(uuid);

CREATE FUNCTION store_health_check(check_store_id uuid)
RETURNS TABLE (
    total_products         integer,
    published_products     integer,
    in_stock_products      integer,
    with_embeddings        integer,
    avg_embedding_quality  integer          -- average vector dimension
) LANGUAGE sql STABLE AS
$$
SELECT
    COUNT(*)                                                     AS total_products,
    COUNT(*) FILTER (WHERE status = 'publish')                   AS published_products,
    COUNT(*) FILTER (WHERE stock_status = 'in')                  AS in_stock_products,
    COUNT(*) FILTER (WHERE embedding IS NOT NULL)                AS with_embeddings,
    COALESCE(AVG(vector_dims(embedding)), 0)::int                AS avg_embedding_quality
FROM   products
WHERE  store_id = check_store_id;
$$;

/* ──────────────────────────────────────────────────────────────
   6. ROW-LEVEL SECURITY (optional but recommended)
   ────────────────────────────────────────────────────────────── */
alter table products enable row level security;

create policy products_public_select
  on products
  for select
  using (status = 'publish');

/* Views inherit RLS from base table. */

/* ──────────────────────────────────────────────────────────────
   7. HELPFUL VIEWS FOR ANALYTICS
   ────────────────────────────────────────────────────────────── */

-- Product performance view
create or replace view product_analytics as
select
  store_id,
  woocommerce_id,
  name,
  brand,
  categories[1] as primary_category,
  regular_price,
  margin,
  sold_count,
  average_rating,
  review_count,
  stock_status,
  case
    when embedding is not null then 'yes'
    else 'no'
  end as has_embedding
from products
where status = 'publish';

CREATE OR REPLACE FUNCTION fts_search(
    search_store_id uuid,
    search_term text,
    search_limit integer DEFAULT 20
)
RETURNS TABLE (woocommerce_id integer, rank real)
LANGUAGE sql
STABLE
AS $$
SELECT
    p.woocommerce_id,
    ts_rank_cd(p.ts_index, websearch_to_tsquery('simple', search_term)) as rank
FROM products p
WHERE p.store_id = search_store_id
  AND p.ts_index @@ websearch_to_tsquery('simple', search_term)
ORDER BY rank DESC
LIMIT search_limit;
$$;

CREATE OR REPLACE FUNCTION sku_search(
    search_store_id uuid,
    search_term text,
    search_limit integer DEFAULT 20
)
RETURNS TABLE (woocommerce_id integer, rank real)
LANGUAGE sql
STABLE
AS $$
SELECT
    p.woocommerce_id,
    CASE
        WHEN p.sku ILIKE search_term || '%' THEN 100.0::real
        WHEN p.gtin ILIKE search_term || '%' THEN 95.0::real
        WHEN p.sku ILIKE '%' || search_term || '%' THEN 50.0::real
        WHEN p.gtin ILIKE '%' || search_term || '%' THEN 45.0::real
        ELSE 10.0::real
    END as rank
FROM products p
WHERE p.store_id = search_store_id
  AND p.status = 'publish'
  AND (
      p.sku ILIKE '%' || search_term || '%'
      OR p.gtin ILIKE '%' || search_term || '%'
  )
ORDER BY rank DESC
LIMIT search_limit;
$$;

/* ──────────────────────────────────────────────────────────────
   8. DONE
   ────────────────────────────────────────────────────────────── */
