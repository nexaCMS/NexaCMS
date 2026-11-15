<?php
/** Flip Turn — main template (v2.0.0). */
$td = str_replace(baseurl(), "", $theme_dir_url);
get_header();
get_template_part("blog");
get_footer();
