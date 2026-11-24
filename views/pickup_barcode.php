<?php
// ISO/IEC 16388 Code 39 symbols for hexadecimal digits and the start/stop character.
$hexPatterns = [
    0x034, 0x121, 0x061, 0x160, 0x031, 0x130, 0x070, 0x025,
    0x124, 0x064, 0x109, 0x049, 0x148, 0x019, 0x118, 0x058,
];
$symbols = str_split('*' . $pickupCode . '*');
?>
<div class="pickup-pass">
    <p>提货码 <code><?= e($pickupCode) ?></code></p>
    <div class="pickup-barcode" aria-hidden="true">
        <?php foreach ($symbols as $index => $symbol):
            if ($index > 0): ?><span></span><?php endif;
            $pattern = $symbol === '*' ? 0x094 : $hexPatterns[hexdec($symbol)];
            for ($element = 0; $element < 9; $element++):
                $classes = [];
                if ($element % 2 === 0) {
                    $classes[] = 'bar';
                }
                if ($pattern & (1 << (8 - $element))) {
                    $classes[] = 'wide';
                }
                ?><span class="<?= e(implode(' ', $classes)) ?>"></span><?php
            endfor;
        endforeach; ?>
    </div>
    <p class="muted">请在门店出示。有效期至 <?= e($merchantOrder['code_expires_at']) ?> UTC，本页刷新后需重新生成。</p>
</div>
