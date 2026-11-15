<?php
// Layout CSS: only emitted when at least one active layout uses columns
// or a card style, and scoped to .nexa-post-list containers printed by
// themes that support Nexa Post Layouts.
$nxHasLayout = false;
try {
    foreach (get_table('post_layouts')['data'] as $nxRow) {
        if ((string) ($nxRow['active'] ?? '0') === '1' && ((int) ($nxRow['columns'] ?? 0) > 1 || !in_array((string) ($nxRow['card_style'] ?? 'theme'), ['', 'theme'], true))) { $nxHasLayout = true; break; }
    }
} catch (Throwable $e) {}
if ($nxHasLayout): ?>
<style id="nexa-post-layouts">
  .nexa-post-list{display:flex;flex-wrap:wrap;gap:22px;}
  .nexa-post-list>*{flex:1 1 100%;min-width:0;}
  .nexa-post-list .nexa-layout-block{flex:1 1 100%;}
  .nexa-cols-2>.nexa-post-item{flex:1 1 calc(50% - 22px);}
  .nexa-cols-3>.nexa-post-item{flex:1 1 calc(33.333% - 22px);}
  .nexa-cols-4>.nexa-post-item{flex:1 1 calc(25% - 22px);}
  @media (max-width:800px){.nexa-cols-3>.nexa-post-item,.nexa-cols-4>.nexa-post-item{flex-basis:calc(50% - 22px);}}
  @media (max-width:520px){.nexa-post-list>.nexa-post-item{flex-basis:100%;}}
  .nexa-card-bordered>.nexa-post-item{border:1px solid #e3e6ea;border-radius:10px;padding:16px;background:#fff;}
  .nexa-card-shadow>.nexa-post-item{border-radius:10px;padding:16px;background:#fff;box-shadow:0 6px 24px rgba(15,23,42,.10);}
  .nexa-card-minimal>.nexa-post-item{border:none !important;box-shadow:none !important;background:transparent !important;padding:4px 0;}
  .nexa-layout-block{margin:18px 0;text-align:center;}
  .nexa-layout-block img{max-width:100%;height:auto;}
</style>
<?php endif; ?>
