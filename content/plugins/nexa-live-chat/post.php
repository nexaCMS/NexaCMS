<?php
// Nexa Live Chat — post stage: render the same bubble on every front-end page.
// Its state (chat id/token/open) lives in localStorage, so the conversation
// and open panel survive navigation across the site.
add_action('nexa_post', 'nexa_livechat_widget');
