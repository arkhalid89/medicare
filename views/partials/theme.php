<?php
/**
 * Theme variables from Settings → Theme. Values are validated as hex colours
 * and a numeric size before they are written into CSS.
 */
$hex = static fn (string $v, string $fallback): string => preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $fallback;
$primary = $hex((string) setting('theme_primary', '#0A2463'), '#0A2463');
$secondary = $hex((string) setting('theme_secondary', '#C9A227'), '#C9A227');
$size = max(12, min(16, (int) setting('theme_font_size', 13)));

// Lighter tint of the primary colour for hover/selected backgrounds.
[$r, $g, $b] = sscanf($primary, '#%02x%02x%02x');
$tint = sprintf('#%02x%02x%02x', (int) ($r + (255 - $r) * .92), (int) ($g + (255 - $g) * .92), (int) ($b + (255 - $b) * .92));
?>
<style>:root{--primary:<?= $primary ?>;--primary-600:<?= $primary ?>;--primary-50:<?= $tint ?>;--secondary:<?= $secondary ?>;--fs:<?= $size ?>px}</style>
