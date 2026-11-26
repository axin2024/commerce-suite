<?php

declare(strict_types=1);

trait ProcurementOperations
{
    private function procurementCode(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,47}$/D', $value)) {
            throw new RuleViolation('编码须为 1 至 48 位字母、数字、短横线或下划线。');
        }
        return $value;
    }

    private function procurementText(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^.{1,' . $limit . '}$/uD', $value)) {
            throw new RuleViolation("请填写 1 至 {$limit} 字的内容。");
        }
        return $value;
    }

    private function procurementBuyer(string $email): int
    {
        $buyer = $this->one("SELECT id FROM users WHERE email=? AND role='buyer'", [trim($email)]);
        if (!$buyer) {
            throw new RuleViolation('请指定已注册的买家账号。');
        }
        return (int) $buyer['id'];
    }

    public function enrollProcurementCompany(int $actor, string $code, string $name, string $managerEmail, string $zone='UTC'): int
    {
        $this->actor($actor, 'admin','finance');
        $code = $this->procurementCode($code);
        $name = $this->procurementText($name, 120);
        $manager = $this->procurementBuyer($managerEmail);
        if (!in_array($zone,DateTimeZone::listIdentifiers(),true)) {
            throw new RuleViolation('请指定有效的法人账务时区。');
        }
        return $this->transaction(function () use ($actor, $code, $name, $manager,$zone) {
            $write = $this->query('INSERT INTO procurement_companies (company_code,billing_name,manager_id) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE id=id', [$code, $name, $manager]);
            $row = $this->one('SELECT * FROM procurement_companies WHERE company_code=? FOR UPDATE', [$code]);
            if ($row['billing_name'] !== $name || (int) $row['manager_id'] !== $manager) {
                throw new RuleViolation('法人编码已登记，名称或负责人有差异。');
            }
            $id = (int) $row['id'];
            if ($write->rowCount() === 1) {
                $this->query('INSERT INTO procurement_billing_settings (company_id,time_zone) VALUES (?,?)',[$id,$zone]);
                $this->audit($actor, 'procurement.company.created', $id, ['manager' => $manager]);
            } elseif (($this->one('SELECT time_zone FROM procurement_billing_settings WHERE company_id=?',[$id])['time_zone'] ?? 'UTC')!==$zone) {
                throw new RuleViolation('已登记法人的账务时区不一致。');
            }
            return $id;
        });
    }

    public function addProcurementStore(int $actor, int $company, string $code, string $name,
        string $center, string $address): int
    {
        $code = $this->procurementCode($code);
        $name = $this->procurementText($name, 100);
        $center = $this->procurementText($center, 80);
        $address = $this->procurementText($address, 255);
        return $this->transaction(function () use ($actor, $company, $code, $name, $center, $address) {
            if (!$this->one('SELECT id FROM procurement_companies WHERE id=? AND manager_id=? FOR UPDATE', [$company, $actor])) {
                throw new RuleViolation('无权管理此采购组织。');
            }
            $existing = $this->one('SELECT * FROM procurement_stores WHERE company_id=? AND store_code=?', [$company, $code]);
            if ($existing) {
                if ([$existing['name'], $existing['cost_center'], $existing['delivery_address']] !== [$name, $center, $address]) {
                    throw new RuleViolation('门店编码已存在，登记资料有差异。');
                }
                return (int) $existing['id'];
            }
            $id = $this->insert('INSERT INTO procurement_stores (company_id,store_code,name,cost_center,delivery_address)
                VALUES (?,?,?,?,?)', [$company, $code, $name, $center, $address]);
            $this->audit($actor, 'procurement.store.created', $id, ['company' => $company]);
            return $id;
        });
    }

    public function changeProcurementMember(int $actor, int $store, string $email, bool $grant,string $duty='purchaser'): void
    {
        if (!in_array($duty,['purchaser','receiver'],true)) {
            throw new RuleViolation('请选择采购或收货岗位。');
        }
        $this->transaction(function () use ($actor, $store, $email, $grant,$duty) {
            if (!$this->one('SELECT s.id FROM procurement_stores s JOIN procurement_companies c ON c.id=s.company_id
                WHERE s.id=? AND c.manager_id=? FOR UPDATE', [$store, $actor])) {
                throw new RuleViolation('无权管理此门店。');
            }
            $member = $this->procurementBuyer($email);
            $exists = $this->one('SELECT duty FROM procurement_members WHERE store_id=? AND user_id=?', [$store, $member]);
            if (($grant && ($exists['duty'] ?? '')===$duty) || (!$grant && !$exists)) {
                return;
            }
            if ($grant) {
                $this->query('INSERT INTO procurement_members (store_id,user_id,duty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE duty=?', [$store,$member,$duty,$duty]);
            } else {
                $this->query('DELETE FROM procurement_members WHERE store_id=? AND user_id=?', [$store, $member]);
            }
            $this->audit($actor, $grant ? 'procurement.member.granted' : 'procurement.member.revoked', $store, ['member'=>$member,'duty'=>$duty]);
        });
    }

    public function managedProcurementCompanies(int $actor): array
    {
        return $this->rows('SELECT id,company_code,billing_name FROM procurement_companies WHERE manager_id=? ORDER BY id', [$actor]);
    }

    public function procurementStores(int $actor, ?int $store = null): array
    {
        $sql = 'SELECT s.*,c.billing_name,c.manager_id,c.accepting_requests,m.duty FROM procurement_stores s
            JOIN procurement_companies c ON c.id=s.company_id
            LEFT JOIN procurement_members m ON m.store_id=s.id AND m.user_id=?
            WHERE (c.manager_id=? OR m.user_id IS NOT NULL)';
        $params = [$actor, $actor];
        if ($store !== null) {
            $sql .= ' AND s.id=?';
            $params[] = $store;
        }
        return $this->rows($sql . ' ORDER BY s.company_id,s.id', $params);
    }

    private function procurementStoreForDuty(int $actor,int $store,string $duty): ?array
    {
        $row = $this->procurementStores($actor,$store)[0] ?? null;
        return $row && ((int)$row['manager_id']===$actor || $row['duty']===$duty) ? $row : null;
    }
}
