<?php

declare(strict_types=1);

trait CatalogOperations
{
    public function createCategory(int $admin, string $name): int
    {
        $this->actor($admin, 'admin');
        $name = trim($name);
        if ($name === '' || strlen($name) > 80) {
            throw new RuleViolation('请填写有效的分类名称。');
        }
        try {
            return $this->transaction(function () use ($admin, $name): int {
                $id = $this->insert('INSERT INTO product_categories (name) VALUES (?)', [$name]);
                $this->audit($admin, 'catalog.category_created', $id, ['name' => $name]);
                return $id;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('分类名称已存在。');
            }
            throw $error;
        }
    }

    public function categories(): array
    {
        return $this->rows('SELECT id,name FROM product_categories ORDER BY name,id');
    }

    public function submitSku(
        int $merchant, int $product, string $sku, string $name, int $price, int $stock,
        int $category, string $family, string $color, string $size, bool $delivery, bool $pickup, int $baseRevision = 0,
        ?int $memberPrice = null,
    ): int {
        $sku = trim($sku);
        $name = trim($name);
        $family = trim($family);
        $color = trim($color);
        $size = trim($size);
        if (!preg_match('/^[a-z0-9_-]{1,64}$/iD', $sku) || $name === '' || strlen($name) > 160
            || $family === '' || strlen($family) > 160 || strlen($color) > 40 || strlen($size) > 40
            || $price < 1 || $price > 100_000_000 || $stock < 0 || $stock > 1_000_000_000 || (!$delivery && !$pickup)
            || ($memberPrice !== null && ($memberPrice < 1 || $memberPrice > $price))) {
            throw new RuleViolation('请检查 SKU、名称、规格、售价、库存和销售渠道。');
        }
        try {
            return $this->transaction(function () use ($merchant, $product, $sku, $name, $price, $stock, $category,
                $family, $color, $size, $delivery, $pickup, $baseRevision, $memberPrice): int {
                $shop = $this->lockMerchantCatalog($merchant);
                if (!$this->one('SELECT id FROM product_categories WHERE id=?', [$category])) {
                    throw new RuleViolation('请选择有效的商品分类。');
                }
                if ($product) {
                    $current = $this->one('SELECT sku FROM products WHERE id=? AND shop_id=? FOR UPDATE', [$product, $shop]);
                    if (!$current || $current['sku'] !== $sku) {
                        throw new RuleViolation('商品不存在或 SKU 已锁定。');
                    }
                    $latest = $this->one('SELECT id FROM product_releases WHERE product_id=? ORDER BY id DESC LIMIT 1', [$product]);
                    if ($baseRevision !== (int) ($latest['id'] ?? 0)) {
                        throw new RuleViolation('商品已有更新版本，请刷新后再提交。');
                    }
                    $withdrawn = $this->query("UPDATE product_releases SET state='withdrawn' WHERE pending_product_id=?", [$product]);
                    if ($withdrawn->rowCount()) {
                        $this->audit($merchant, 'catalog.release_withdrawn', $baseRevision, ['product_id' => $product]);
                    }
                } else {
                    $product = $this->insert('INSERT INTO products (shop_id,sku,name,price,stock) VALUES (?,?,?,?,?)',
                        [$shop, $sku, $name, $price, $stock]);
                    $this->query('INSERT INTO warehouse_stock (product_id,warehouse_id,stock) VALUES (?,?,?)',
                        [$product, $this->mainWarehouse($shop), $stock]);
                }
                $familyRow = $this->one('SELECT id FROM product_families WHERE shop_id=? AND name=?', [$shop, $family]);
                $familyId = $familyRow['id'] ?? $this->insert('INSERT INTO product_families (shop_id,name) VALUES (?,?)', [$shop, $family]);
                $id = $this->insert("INSERT INTO product_releases
                    (product_id,family_id,category_id,name,price,color,size,delivery,pickup,state,submitted_by,member_price)
                    VALUES (?,?,?,?,?,?,?,?,?,'pending',?,?)",
                    [$product, $familyId, $category, $name, $price, $color, $size, (int) $delivery, (int) $pickup, $merchant, $memberPrice]);
                $this->audit($merchant, 'catalog.release_submitted', $id, ['product_id' => $product]);
                return $id;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('店铺内已存在该 SKU，或有其他版本正在处理。');
            }
            throw $error;
        }
    }

    public function reviewSku(int $admin, int $release, bool $approve, string $reason = ''): int
    {
        $this->actor($admin, 'admin');
        $reason = trim($reason);
        if (strlen($reason) > 250 || (!$approve && $reason === '')) {
            throw new RuleViolation('请填写审核处理依据。');
        }
        return $this->transaction(function () use ($admin, $release, $approve, $reason): int {
            $identity = $this->one('SELECT p.shop_id,r.product_id FROM product_releases r
                JOIN products p ON p.id=r.product_id WHERE r.id=?', [$release]);
            if (!$identity) {
                throw new RuleViolation('发布版本不存在。');
            }
            $product = (int) $identity['product_id'];
            $this->one('SELECT id FROM shops WHERE id=? FOR UPDATE', [$identity['shop_id']]);
            $proposal = $this->one('SELECT * FROM product_releases WHERE id=? FOR UPDATE', [$release]);
            $decision = $approve ? 'approved' : 'rejected';
            if ($proposal['state'] === $decision && $proposal['review_reason'] === $reason) {
                return $product;
            }
            if ($proposal['state'] !== 'pending') {
                throw new RuleViolation('此版本已处理，请刷新审核列表。');
            }
            if ($approve) {
                $duplicate = $this->one("SELECT p.id FROM product_releases r JOIN products p ON p.release_id=r.id
                    WHERE r.family_id=? AND r.color=? AND r.size=? AND p.status='active' AND p.id<>? FOR SHARE",
                    [$proposal['family_id'], $proposal['color'], $proposal['size'], $product]);
                if ($duplicate) {
                    throw new RuleViolation('同系列已有相同规格的在售 SKU。');
                }
                $this->query("UPDATE products SET release_id=?,name=?,price=?,status='active' WHERE id=?",
                    [$release, $proposal['name'], $proposal['price'], $product]);
            }
            $this->query('UPDATE product_releases SET state=?,review_reason=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?',
                [$decision, $reason, $admin, $release]);
            $this->audit($admin, 'catalog.release_reviewed', $release, ['product_id' => $product, 'decision' => $decision]);
            return $product;
        });
    }

    public function unlistSku(int $merchant, int $product): void
    {
        $this->transaction(function () use ($merchant, $product): void {
            $shop = $this->lockMerchantCatalog($merchant);
            $current = $this->one('SELECT status,release_id FROM products WHERE id=? AND shop_id=? FOR UPDATE', [$product, $shop]);
            if (!$current) {
                throw new RuleViolation('商品不存在。');
            }
            $pending = $this->one('SELECT id FROM product_releases WHERE pending_product_id=? FOR UPDATE', [$product]);
            if ($pending) {
                $this->query("UPDATE product_releases SET state='withdrawn' WHERE id=?", [$pending['id']]);
                $this->audit($merchant, 'catalog.release_withdrawn', (int) $pending['id'], ['product_id' => $product]);
            }
            if ($current['status'] !== 'archived') {
                $this->query("UPDATE products SET status='archived' WHERE id=?", [$product]);
                $this->audit($merchant, 'catalog.sku_unlisted', $product, ['release_id' => $current['release_id']]);
            }
        });
    }

    private function lockMerchantCatalog(int $merchant): int
    {
        $actor = $this->shopActor($merchant, 'catalog');
        $shop = $this->one('SELECT status FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
        if (!in_array($shop['status'] ?? null, ['active', 'suspended'], true)) {
            throw new RuleViolation('店铺通过审核后可维护商品。');
        }
        return (int) $actor['shop_id'];
    }

    public function merchantCatalog(int $merchant): array
    {
        $actor = $this->shopActor($merchant, 'catalog');
        return $this->rows("SELECT p.*, r.id AS latest_id,r.name AS proposed_name,r.price AS proposed_price,
            r.category_id,r.color,r.size,r.delivery,r.pickup,r.member_price,r.state AS review_state,r.review_reason,f.name AS family_name
            FROM products p LEFT JOIN product_releases r ON r.id=(
                SELECT id FROM product_releases WHERE product_id=p.id ORDER BY id DESC LIMIT 1
            )
            LEFT JOIN product_families f ON f.id=r.family_id WHERE p.shop_id=? ORDER BY p.id DESC LIMIT 100", [$actor['shop_id']]);
    }

    public function catalog(string $search = '', ?int $category = null, string $channel = ''): array
    {
        $pattern = '%' . strtr(trim($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where = ["p.status='active'", "s.status='active'"];
        $args = [];
        if ($search !== '') {
            $where[] = "(p.name LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!' OR f.name LIKE ? ESCAPE '!'
                OR r.color LIKE ? ESCAPE '!' OR r.size LIKE ? ESCAPE '!')";
            $args = array_fill(0, 5, $pattern);
        }
        if ($category !== null) {
            $where[] = 'r.category_id=?';
            $args[] = $category;
        }
        if ($channel === 'web' || $channel === 'store') {
            $where[] = $channel === 'web' ? 'r.delivery=1' : 'r.pickup=1';
        }
        return $this->rows("SELECT p.*,s.name AS shop_name,r.color,r.size,r.delivery,r.pickup,r.member_price,c.name AS category_name,
            f.name AS family_name FROM products p JOIN shops s ON s.id=p.shop_id
            JOIN product_releases r ON r.id=p.release_id LEFT JOIN product_categories c ON c.id=r.category_id
            LEFT JOIN product_families f ON f.id=r.family_id
            WHERE " . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 100', $args);
    }
}
