<div class="heading"><h1>账号</h1></div>
<?php if ($user): ?>
    <?php $roles = ['buyer' => '买家', 'merchant' => '商家', 'admin' => '平台运营', 'finance' => '财务']; ?>
    <section class="panel"><h2><?= e($user['email']) ?></h2><p><?= e($roles[$role]) ?></p>
        <?php form('logout'); ?><button class="secondary">退出登录</button></form>
    </section>
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
