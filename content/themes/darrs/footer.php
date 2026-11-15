<?php
$diFooterText = theme_setting('footer_text');
if ($diFooterText !== '') echo '<div style="text-align:center;padding:10px;color:#999;">' . esc_html($diFooterText) . '</div>';
up_footer();
up_post();
?>
  </body>
</html>
