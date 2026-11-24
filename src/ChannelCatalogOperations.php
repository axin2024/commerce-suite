<?php

declare(strict_types=1);

final class InvalidCatalogCredential extends RuntimeException {}

trait ChannelCatalogOperations
{
    public function catalogChannels(int $merchant): array
    {
        $actor = $this->shopActor($merchant, 'owner');
        return $this->rows('SELECT id,code,label,generation,revoked_at FROM catalog_channels
            WHERE shop_id=? ORDER BY (revoked_at IS NULL) DESC,id DESC', [$actor['shop_id']]);
    }

    public function createCatalogChannel(int $merchant, string $code, string $label): array
    {
        $label = trim($label);
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $code) || $label === '' || strlen($label) > 80) {
            throw new RuleViolation('请填写有效的渠道代码和名称。');
        }
        $token = self::newCatalogToken();
        try {
            $id = $this->transaction(function () use ($merchant, $code, $label, $token): int {
                $actor = $this->shopActor($merchant, 'owner');
                $shop = $this->one('SELECT status FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
                if (!in_array($shop['status'] ?? null, ['active', 'suspended'], true)) {
                    throw new RuleViolation('店铺通过审核后可创建渠道。');
                }
                $id = $this->insert('INSERT INTO catalog_channels (shop_id,code,label,token_sha256) VALUES (?,?,?,?)',
                    [$actor['shop_id'], $code, $label, hash('sha256', $token)]);
                $this->audit($merchant, 'catalog_channel.created', $id, ['shop_id' => (int) $actor['shop_id'], 'code' => $code]);
                return $id;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('本店已存在这个渠道代码。');
            }
            throw $error;
        }
        return ['id' => $id, 'token' => $token];
    }

    public function changeCatalogCredential(int $merchant, int $id, int $generation, bool $revoke): ?string
    {
        return $this->transaction(function () use ($merchant, $id, $generation, $revoke): ?string {
            $channel = $this->catalogChannelForOwner($merchant, $id, lock: true);
            if ($channel['revoked_at'] !== null || (int) $channel['generation'] !== $generation) {
                throw new RuleViolation('渠道凭据已变更，请刷新后再操作。');
            }
            if ($revoke) {
                $this->query('UPDATE catalog_channels SET revoked_at=UTC_TIMESTAMP(),generation=generation+1 WHERE id=?', [$id]);
                $this->audit($merchant, 'catalog_channel.revoked', $id);
                return null;
            }
            $token = self::newCatalogToken();
            $this->query('UPDATE catalog_channels SET token_sha256=?,generation=generation+1 WHERE id=?', [hash('sha256', $token), $id]);
            $this->audit($merchant, 'catalog_channel.rotated', $id);
            return $token;
        });
    }

    public function catalogChannelListings(int $merchant, int $id, int $after): array
    {
        $channel = $this->catalogChannelForOwner($merchant, $id);
        $rows = $this->rows('SELECT e.id,e.enabled,e.revision,p.sku,p.name FROM catalog_channel_entries e
            JOIN products p ON p.id=e.product_id AND p.shop_id=? WHERE e.channel_id=? AND e.id>? ORDER BY e.id LIMIT 51',
            [$channel['shop_id'], $id, $after]);
        return [$channel, $rows];
    }

    public function setCatalogChannelListing(int $merchant, int $id, string $sku, bool $enabled, int $revision): void
    {
        if (!preg_match('/^[a-z0-9_-]{1,64}$/iD', $sku)) {
            throw new RuleViolation('请填写有效的 SKU。');
        }
        $this->transaction(function () use ($merchant, $id, $sku, $enabled, $revision): void {
            $channel = $this->catalogChannelForOwner($merchant, $id, lock: true);
            if ($channel['revoked_at'] !== null) {
                throw new RuleViolation('渠道已撤销。');
            }
            $product = $this->one("SELECT p.id,p.status,r.delivery FROM products p JOIN product_releases r ON r.id=p.release_id
                WHERE p.shop_id=? AND p.sku=? AND r.state IN ('approved','legacy') FOR SHARE", [$channel['shop_id'], $sku]);
            if (!$product || ($enabled && ($product['status'] !== 'active' || !(bool) $product['delivery']))) {
                throw new RuleViolation('请选择本店已审核且开放配送的在售 SKU。');
            }
            $entry = $this->one('SELECT id,enabled,revision FROM catalog_channel_entries WHERE channel_id=? AND product_id=? FOR UPDATE',
                [$id, $product['id']]);
            if ($entry && (bool) $entry['enabled'] === $enabled) {
                return;
            }
            if ((int) ($entry['revision'] ?? 0) !== $revision || (!$entry && !$enabled)) {
                throw new RuleViolation('渠道商品已变更，请刷新后再操作。');
            }
            if ($entry) {
                $entryId = (int) $entry['id'];
                $this->query('UPDATE catalog_channel_entries SET enabled=?,revision=revision+1 WHERE id=?', [(int) $enabled, $entryId]);
            } else {
                $entryId = $this->insert('INSERT INTO catalog_channel_entries (channel_id,product_id) VALUES (?,?)', [$id, $product['id']]);
            }
            $this->audit($merchant, 'catalog_channel.listing_changed', $entryId, ['channel_id' => $id, 'enabled' => $enabled]);
        });
    }

    public function catalogChannelFeed(string $token, ?string $cursor, int $limit): array
    {
        if (!preg_match('/^cf1_[a-f0-9]{64}$/D', $token)) {
            throw new InvalidCatalogCredential();
        }
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid page size.');
        }
        $digest = hash('sha256', $token);
        $after = 0;
        $through = null;
        if ($cursor !== null) {
            [$after, $through] = self::decodeChannelCursor($cursor, $digest);
        }
        // Authentication, the scan boundary and this page share one statement snapshot.
        $rows = $this->rows("SELECT c.code,s.status AS shop_status,cut.through_id,feed.*
            FROM catalog_channels c JOIN shops s ON s.id=c.shop_id
            JOIN LATERAL (SELECT COALESCE(MAX(id),0) AS through_id FROM catalog_channel_entries WHERE channel_id=c.id) cut ON TRUE
            LEFT JOIN LATERAL (SELECT e.id,e.enabled,e.revision,p.sku,p.status,p.version,r.id AS release_id,
                r.name,r.price,r.color,r.size,r.delivery,f.name AS family,cat.name AS category,(p.stock-p.reserved) AS available
                FROM catalog_channel_entries e JOIN products p ON p.id=e.product_id AND p.shop_id=c.shop_id
                LEFT JOIN product_releases r ON r.id=p.release_id AND r.product_id=p.id AND r.state IN ('approved','legacy')
                LEFT JOIN product_families f ON f.id=r.family_id LEFT JOIN product_categories cat ON cat.id=r.category_id
                WHERE e.channel_id=c.id AND e.id>? AND e.id<=COALESCE(?,cut.through_id)
                ORDER BY e.id LIMIT " . ($limit + 1) . ") feed ON TRUE
            WHERE c.token_sha256=? AND c.revoked_at IS NULL ORDER BY feed.id", [$after, $through, $digest]);
        if (!$rows) {
            throw new InvalidCatalogCredential();
        }
        $channel = $rows[0];
        $shopActive = $channel['shop_status'] === 'active';
        $through ??= (int) $channel['through_id'];
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        $items = [];
        foreach ($rows as $row) {
            if ($row['id'] === null) {
                break;
            }
            $listed = $shopActive && (bool) $row['enabled'] && $row['status'] === 'active' && (bool) $row['delivery'];
            $items[] = [
                'listing_id' => (int) $row['id'], 'sku' => $row['sku'], 'listing_revision' => (int) $row['revision'],
                'catalog_revision' => (int) $row['release_id'], 'inventory_revision' => (int) $row['version'],
                'status' => $listed ? 'listed' : 'withdrawn', 'available_units' => $listed ? (int) $row['available'] : 0,
                'product' => $listed ? [
                    'name' => $row['name'], 'price_minor' => (int) $row['price'], 'color' => $row['color'],
                    'size' => $row['size'], 'family' => $row['family'], 'category' => $row['category'],
                ] : null,
            ];
        }
        $next = $more ? end($items)['listing_id'] . '.' . $through : null;
        return ['version' => 1, 'channel' => $channel['code'], 'shop_status' => $channel['shop_status'], 'currency' => 'CNY',
            'items' => $items, 'next_cursor' => $next === null ? null : $next . '.' . hash_hmac('sha256', $next, $digest)];
    }

    private function catalogChannelForOwner(int $merchant, int $id, bool $lock = false): array
    {
        $actor = $this->shopActor($merchant, 'owner');
        $channel = $this->one('SELECT id,shop_id,code,label,generation,revoked_at FROM catalog_channels WHERE id=? AND shop_id=?'
            . ($lock ? ' FOR UPDATE' : ''), [$id, $actor['shop_id']]);
        if (!$channel) {
            throw new RuleViolation('渠道不存在或无权访问。');
        }
        return $channel;
    }

    private static function newCatalogToken(): string
    {
        return 'cf1_' . bin2hex(random_bytes(32));
    }

    private static function decodeChannelCursor(string $cursor, string $digest): array
    {
        if (!preg_match('/^([1-9][0-9]{0,18})\.([1-9][0-9]{0,18})\.([a-f0-9]{64})$/D', $cursor, $parts)) {
            throw new InvalidArgumentException('Invalid catalog cursor.');
        }
        $after = filter_var($parts[1], FILTER_VALIDATE_INT);
        $through = filter_var($parts[2], FILTER_VALIDATE_INT);
        if ($after === false || $through === false || $after >= $through
            || !hash_equals(hash_hmac('sha256', $parts[1] . '.' . $parts[2], $digest), $parts[3])) {
            throw new InvalidArgumentException('Invalid catalog cursor.');
        }
        return [$after, $through];
    }
}
