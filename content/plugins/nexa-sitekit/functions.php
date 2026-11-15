<?php
function nexa_sitekit_head(): void { $ga=trim((string)get_setting('sitekit_ga4','')); $verify=trim((string)get_setting('sitekit_verify','')); if($verify!=='') echo '<meta name="google-site-verification" content="'.esc_attr($verify).'">'."\n"; if($ga!==''){ echo '<script async src="https://www.googletagmanager.com/gtag/js?id='.esc_attr($ga).'"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config","'.esc_attr($ga).'");</script>'."\n"; } }
add_action('nexa_head','nexa_sitekit_head');
