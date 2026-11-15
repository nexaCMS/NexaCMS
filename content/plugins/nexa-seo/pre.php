<?php
// Sitemap endpoint only; never intercept admin requests.
if (empty($GLOBALS['adminarea'])) { try { nexa_seo_sitemap(); } catch (Throwable $e) { error_log('Nexa SEO sitemap: ' . $e->getMessage()); } }
