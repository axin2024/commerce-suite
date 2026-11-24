<div class="heading"><h1>账号</h1></div>
<?php if ($user): ?>
    <?php $roles = ['buyer' => '买家', 'merchant' => '商家', 'admin' => '平台运营', 'finance' => '财务']; ?>
    <section class="panel"><h2><?= e($user['email']) ?></h2><p><?= e($roles[$role]) ?></p>
        <?php form('logout'); ?><button class="secondary">退出登录</button></form>
    </section>
    <?php if ($role === 'buyer'): ?>
        <section class="panel" id="coupons">
            <h2>会员与优惠券</h2>
            <p>会员等级 · <?= $user['member_level'] === 'plus' ? '优享会员' : '普通会员' ?></p>
            <?php
            $coupons = $app->buyerCoupons((int) $user['id']);
            $states = ['available' => '可用', 'held' => '订单待付款', 'used' => '已使用', 'unavailable' => '活动不可用'];
            ?>
            <?php if (!$coupons): ?><p class="muted">暂无优惠券。</p><?php endif; ?>
            <?php foreach ($coupons as $coupon): ?>
                <div class="record">
                    <div><strong><?= e($coupon['name']) ?></strong>
                        <p class="muted">满 <?= cash($coupon['threshold']) ?> 减 <?= cash($coupon['discount']) ?>
                            <?= $coupon['ends_at'] ? ' · 截至 ' . e($coupon['ends_at']) . ' UTC' : '' ?></p></div>
                    <span><?= e($states[$coupon['status']]) ?></span>
                </div>
            <?php endforeach; ?>
            <a href="/">查看商城活动</a>
        </section>
    <?php endif; ?>
<?php else: ?>
    <div class="two">
        <section class="panel">
            <h2>登录</h2>
            <?php form('login'); ?>
            <label>邮箱<input type="email" name="email" autocomplete="username" maxlength="190" required></label>
            <label>密码<input type="password" name="password" autocomplete="current-password" maxlength="72" required></label>
            <button>登录</button></form>
        </section>
        <section class="panel">
            <h2>注册</h2>
            <?php form('register'); ?>
            <label>邮箱<input type="email" name="email" autocomplete="email" maxlength="190" required></label>
            <label>密码<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
            <label>店铺名称 <span class="muted">填写即申请商家入驻</span><input name="shop" maxlength="33" autocomplete="organization"></label>
            <button>创建账号</button></form>
        </section>
    </div>
<?php endif; ?>
