<?php
/**
 * Nexa Live Chat — persistent visitor chat, FAQ/AI first contact, human queue.
 *
 * Data is deliberately kept in normal NexaCMS tables through query()/update_table()
 * so the same plugin works on CSV, JSON, Array, SQLite, MySQL and PostgreSQL.
 */

function nexa_livechat_now(): string { return date('Y-m-d H:i:s'); }

function nexa_livechat_setting(string $key, string $default = ''): string {
    return (string) get_setting('livechat_' . $key, $default);
}

function nexa_livechat_enabled(): bool {
    return nexa_livechat_setting('enabled', '1') === '1';
}

function nexa_livechat_ensure(): void {
    if (!table_exists('livechat_agents')) {
        create_table('livechat_agents', ['user_id', 'username', 'display_name', 'status', 'capacity', 'enabled', 'last_seen', 'created_at']);
    }
    if (!table_exists('livechat_chats')) {
        create_table('livechat_chats', ['access_token', 'visitor_name', 'visitor_email', 'visitor_user_id', 'status', 'assigned_agent', 'subject', 'source_page', 'last_page', 'ip', 'user_agent', 'bot_attempts', 'visitor_unread', 'agent_unread', 'released_agent_id', 'escalated_at', 'suggestions', 'created_at', 'updated_at', 'last_message_at', 'closed_at']);
    }
    if (!table_exists('livechat_messages')) {
        create_table('livechat_messages', ['chat_id', 'sender_type', 'sender_id', 'sender_name', 'body', 'created_at']);
    }
    if (!table_exists('livechat_faqs')) {
        create_table('livechat_faqs', ['question', 'answer', 'keywords', 'followups', 'enabled', 'sort', 'created_at']);
    }
    if (function_exists('ensure_fields')) {
        try {
            ensure_fields('livechat_agents', ['user_id', 'username', 'display_name', 'status', 'capacity', 'enabled', 'last_seen', 'created_at']);
            ensure_fields('livechat_chats', ['access_token', 'visitor_name', 'visitor_email', 'visitor_user_id', 'status', 'assigned_agent', 'subject', 'source_page', 'last_page', 'ip', 'user_agent', 'bot_attempts', 'visitor_unread', 'agent_unread', 'released_agent_id', 'escalated_at', 'suggestions', 'created_at', 'updated_at', 'last_message_at', 'closed_at']);
            ensure_fields('livechat_messages', ['chat_id', 'sender_type', 'sender_id', 'sender_name', 'body', 'created_at']);
            ensure_fields('livechat_faqs', ['question', 'answer', 'keywords', 'followups', 'enabled', 'sort', 'created_at']);
        } catch (Throwable $e) { /* schema repair is best-effort */ }
    }

    // Seed editable FAQs once. Admins can replace these with real answers.
    if ((string) get_setting('livechat_seeded_v1', '') !== '1') {
        try {
            if (count_records('livechat_faqs') === 0) {
                $faqs = [
                    ['How do I speak to a real human?', 'Type “human” at any time, or tap “Talk to a human”. I will place you in the agent queue. If an agent has capacity you will be connected straight away; otherwise you will see your queue position.', 'human,agent,person,real person,representative,support person', '1'],
                    ['What are your opening hours?', 'Live agents aim to respond during our normal support hours. Outside those hours, leave your question and email address here and the team can follow up when the queue reopens. You can edit this answer in Admin → Live Chat → FAQs.', 'hours,open,opening,when open,available,support hours', '2'],
                    ['How can I contact you?', 'You can ask here in live chat, or use the contact page on this website. If your question is urgent, type “human” and I will route you to the agent queue.', 'contact,email,phone,call,reach you,address', '3'],
                    ['What can you help me with?', 'I can answer common questions from our FAQ knowledge base and find relevant pages on this site. If I cannot help, I will route you to a human agent rather than guessing.', 'services,what do you do,support,assist', '4'],
                    ['Where can I find your latest articles or pages?', 'Use the site menu or search to browse the latest pages. Ask me about a topic and I will try to find a matching page for you.', 'articles,blog,pages,latest,news,posts', '5'],
                ];
                foreach ($faqs as $f) {
                    update_table('livechat_faqs', ['question' => $f[0], 'answer' => $f[1], 'keywords' => $f[2], 'enabled' => '1', 'sort' => $f[3], 'created_at' => nexa_livechat_now()], 'insert');
                }
            }
            // Make the first site admin usable as an agent immediately.
            if (count_records('livechat_agents') === 0 && table_exists('users')) {
                foreach (get_table('users')['data'] as $u) {
                    if (in_array((string) ($u['role'] ?? ''), ['super_admin', 'admin'], true)) {
                        update_table('livechat_agents', [
                            'user_id' => (string) ($u['id'] ?? ''), 'username' => (string) ($u['user'] ?? ''),
                            'display_name' => function_exists('di_user_display_name') ? di_user_display_name($u) : (string) ($u['user'] ?? 'Support Agent'),
                            'status' => 'online', 'capacity' => (string) max(1, (int) nexa_livechat_setting('default_capacity', '3')),
                            'enabled' => '1', 'last_seen' => nexa_livechat_now(), 'created_at' => nexa_livechat_now(),
                        ], 'insert');
                        break;
                    }
                }
            }
        } catch (Throwable $e) { /* seeding must never break a page */ }
        set_setting('livechat_seeded_v1', '1', 'Live Chat Seeded');
    }

    // Smart-suggestion seed data: gives the opening-hours answer natural
    // follow-ups, and makes sure those follow-ups actually have answers.
    if ((string) get_setting('livechat_seeded_smart_v1', '') !== '1') {
        try {
            $smartFaqs = [
                ['Do you take walk-ins?', 'Walk-ins are welcome when an agent/arrivals desk is available, but booking or messaging ahead is the fastest way to avoid a wait. You can edit this answer in Admin → Live Chat → FAQs.', 'walk-in,walk-ins,walkins,without appointment,drop in,just come', "What are your opening hours?\nAre you open on holidays?", '90'],
                ['Are you open on holidays?', 'On public holidays we may run reduced hours or be closed, so it is best to message us here before travelling. You can edit this answer in Admin → Live Chat → FAQs.', 'holiday,holidays,public holiday,weekend,sunday,christmas,new year,open today', "What are your opening hours?\nDo you take walk-ins?", '91'],
            ];
            $byNorm = [];
            foreach (get_table('livechat_faqs')['data'] as $f) {
                $byNorm[mb_strtolower(trim((string) ($f['question'] ?? '')))] = $f;
                if (stripos((string) ($f['question'] ?? ''), 'opening hours') !== false && trim((string) ($f['followups'] ?? '')) === '') {
                    update_table('livechat_faqs', ['id' => (int) $f['id'], 'followups' => "Do you take walk-ins?\nAre you open on holidays?"], 'update');
                }
            }
            foreach ($smartFaqs as $f) {
                if (!isset($byNorm[mb_strtolower($f[0])])) {
                    update_table('livechat_faqs', ['question' => $f[0], 'answer' => $f[1], 'keywords' => $f[2], 'followups' => $f[3], 'enabled' => '1', 'sort' => $f[4], 'created_at' => nexa_livechat_now()], 'insert');
                }
            }
        } catch (Throwable $e) { /* suggestion seed is best-effort */ }
        set_setting('livechat_seeded_smart_v1', '1', 'Live Chat Smart Suggestions Seeded');
    }
}
nexa_livechat_ensure();

function nexa_livechat_clean_text(string $text, int $max = 2000): string {
    $text = trim(strip_tags($text));
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    return mb_substr($text, 0, $max);
}

function nexa_livechat_agent_load(int $agentId): int {
    $rows = query("SELECT COUNT(*) AS c FROM livechat_chats WHERE assigned_agent = ? AND status = ?", [(string) $agentId, 'active']);
    return (int) ($rows[0]['c'] ?? 0);
}

function nexa_livechat_agents(): array {
    nexa_livechat_ensure();
    $rows = query("SELECT * FROM livechat_agents WHERE enabled = ? ORDER BY display_name ASC", ['1']);
    foreach ($rows as &$r) {
        $r['load'] = nexa_livechat_agent_load((int) $r['id']);
        $r['capacity'] = max(1, (int) ($r['capacity'] ?? nexa_livechat_setting('default_capacity', '3')));
        $r['available'] = ($r['status'] ?? '') === 'online' && $r['load'] < $r['capacity'];
    }
    unset($r);
    return $rows;
}

function nexa_livechat_agent_by_id(int $id): ?array {
    if ($id <= 0) return null;
    $rows = query("SELECT * FROM livechat_agents WHERE id = ?", [$id]);
    if (empty($rows)) return null;
    $r = $rows[0];
    $r['load'] = nexa_livechat_agent_load($id);
    $r['capacity'] = max(1, (int) ($r['capacity'] ?? nexa_livechat_setting('default_capacity', '3')));
    $r['available'] = ($r['status'] ?? '') === 'online' && $r['load'] < $r['capacity'];
    return $r;
}

function nexa_livechat_is_manager(): bool {
    return is_user_logged_in() && (current_user_can('manage_options') || in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true));
}

function nexa_livechat_current_agent(bool $createForAdmin = false): ?array {
    if (!is_user_logged_in()) return null;
    nexa_livechat_ensure();
    $uid = get_current_user_id();
    $rows = $uid > 0 ? query("SELECT * FROM livechat_agents WHERE user_id = ? AND enabled = ?", [(string) $uid, '1']) : [];
    if (empty($rows) && !empty($_SESSION['name'])) {
        $rows = query("SELECT * FROM livechat_agents WHERE username = ? AND enabled = ?", [(string) $_SESSION['name'], '1']);
    }
    if (!empty($rows)) {
        $agent = nexa_livechat_agent_by_id((int) $rows[0]['id']);
        if ($agent !== null) {
            query("UPDATE livechat_agents SET last_seen = ? WHERE id = ?", [nexa_livechat_now(), (int) $agent['id']]);
            $agent['last_seen'] = nexa_livechat_now();
        }
        return $agent;
    }
    if ($createForAdmin && nexa_livechat_is_manager()) {
        $id = update_table('livechat_agents', [
            'user_id' => (string) $uid, 'username' => (string) ($_SESSION['name'] ?? ''), 'display_name' => (string) ($_SESSION['display-name'] ?? 'Support Agent'),
            'status' => 'online', 'capacity' => (string) max(1, (int) nexa_livechat_setting('default_capacity', '3')), 'enabled' => '1',
            'last_seen' => nexa_livechat_now(), 'created_at' => nexa_livechat_now(),
        ], 'insert');
        return nexa_livechat_agent_by_id((int) $id);
    }
    return null;
}

function nexa_livechat_get_chat(int $id): ?array {
    if ($id <= 0) return null;
    $rows = query("SELECT * FROM livechat_chats WHERE id = ?", [$id]);
    return $rows[0] ?? null;
}

function nexa_livechat_update_chat(int $id, array $fields): void {
    $fields['id'] = $id;
    $fields['updated_at'] = nexa_livechat_now();
    update_table('livechat_chats', $fields, 'update');
}

function nexa_livechat_add_message(int $chatId, string $type, string $senderId, string $senderName, string $body): int {
    $body = nexa_livechat_clean_text($body, 4000);
    if ($body === '') return 0;
    $id = update_table('livechat_messages', [
        'chat_id' => (string) $chatId, 'sender_type' => $type, 'sender_id' => $senderId,
        'sender_name' => nexa_livechat_clean_text($senderName, 100), 'body' => $body, 'created_at' => nexa_livechat_now(),
    ], 'insert');
    $fields = ['last_message_at' => nexa_livechat_now()];
    if ($type === 'visitor') $fields['agent_unread'] = '1';
    if ($type === 'agent') $fields['visitor_unread'] = '1';
    nexa_livechat_update_chat($chatId, $fields);
    return (int) $id;
}

function nexa_livechat_messages(int $chatId, int $limit = 100): array {
    $limit = max(1, min(200, $limit));
    $rows = query("SELECT * FROM livechat_messages WHERE chat_id = ? ORDER BY id ASC LIMIT " . $limit, [(string) $chatId]);
    return $rows ?: [];
}

function nexa_livechat_queue_position(int $chatId): int {
    $rows = query("SELECT id FROM livechat_chats WHERE status = ? ORDER BY COALESCE(NULLIF(escalated_at,''), created_at) ASC, id ASC", ['queued']);
    foreach ($rows as $i => $r) if ((int) $r['id'] === $chatId) return $i + 1;
    return 0;
}

function nexa_livechat_public_chat(array $chat): array {
    $agent = !empty($chat['assigned_agent']) ? nexa_livechat_agent_by_id((int) $chat['assigned_agent']) : null;
    return [
        'id' => (int) $chat['id'], 'status' => (string) ($chat['status'] ?? 'bot'),
        'visitor_name' => (string) ($chat['visitor_name'] ?? ''), 'visitor_email' => (string) ($chat['visitor_email'] ?? ''),
        'agent_name' => $agent ? (string) $agent['display_name'] : '', 'queue_position' => nexa_livechat_queue_position((int) $chat['id']),
        'created_at' => (string) ($chat['created_at'] ?? ''), 'updated_at' => (string) ($chat['updated_at'] ?? ''),
        'last_page' => (string) ($chat['last_page'] ?? ''),
    ];
}

function nexa_livechat_dispatch_queue(array $skipAgentForChat = []): void {
    $queued = query("SELECT * FROM livechat_chats WHERE status = ? ORDER BY COALESCE(NULLIF(escalated_at,''), created_at) ASC, id ASC", ['queued']);
    foreach ($queued as $chat) {
        $candidates = [];
        foreach (nexa_livechat_agents() as $agent) {
            if (($agent['status'] ?? '') !== 'online') continue;
            if ((int) ($skipAgentForChat[(int) $chat['id']] ?? 0) === (int) $agent['id']) continue;
            if ((int) ($chat['released_agent_id'] ?? 0) === (int) $agent['id'] && count(nexa_livechat_agents()) > 1) {
                // A released chat should not bounce straight back to the same agent if someone else exists.
                $others = array_filter(nexa_livechat_agents(), static fn($a) => (int) $a['id'] !== (int) $agent['id'] && ($a['status'] ?? '') === 'online' && (int) $a['load'] < (int) $a['capacity']);
                if (!empty($others)) continue;
            }
            if ((int) $agent['load'] < (int) $agent['capacity']) $candidates[] = $agent;
        }
        if (!$candidates) return;
        usort($candidates, static function ($a, $b) {
            $ratioA = $a['load'] / max(1, $a['capacity']); $ratioB = $b['load'] / max(1, $b['capacity']);
            return ($ratioA <=> $ratioB) ?: ((int) $a['load'] <=> (int) $b['load']) ?: strcasecmp((string) $a['display_name'], (string) $b['display_name']);
        });
        $agent = $candidates[0];
        nexa_livechat_update_chat((int) $chat['id'], ['status' => 'active', 'assigned_agent' => (string) $agent['id'], 'released_agent_id' => '']);
        nexa_livechat_add_message((int) $chat['id'], 'system', '', 'System', 'You are now connected with ' . $agent['display_name'] . '.');
    }
}

function nexa_livechat_escalate(array $chat, string $reason = ''): array {
    if (($chat['status'] ?? '') === 'active' || ($chat['status'] ?? '') === 'closed') return $chat;
    if (nexa_livechat_setting('queue_offline', '1') !== '1') {
        $anyAvailable = false;
        foreach (nexa_livechat_agents() as $agent) {
            if (($agent['status'] ?? '') === 'online' && (int) $agent['load'] < (int) $agent['capacity']) { $anyAvailable = true; break; }
        }
        if (!$anyAvailable) {
            nexa_livechat_add_message((int) $chat['id'], 'bot', '', nexa_livechat_setting('bot_name', 'Nexa Assistant'), nexa_livechat_setting('offline_message', 'No agent is free right now. Leave your question and email address and we will follow up.'));
            return nexa_livechat_get_chat((int) $chat['id']) ?? $chat;
        }
    }
    $was = (string) ($chat['status'] ?? 'bot');
    nexa_livechat_store_suggestions((int) $chat['id'], []);
    nexa_livechat_update_chat((int) $chat['id'], [
        'status' => 'queued', 'escalated_at' => ($chat['escalated_at'] ?? '') ?: nexa_livechat_now(), 'subject' => $reason !== '' ? mb_substr($reason, 0, 120) : (string) ($chat['subject'] ?? ''),
    ]);
    if ($was !== 'queued') {
        nexa_livechat_add_message((int) $chat['id'], 'system', '', 'System', nexa_livechat_setting('queue_message', 'I’m connecting you with a human agent. Please keep this chat open.'));
    }
    nexa_livechat_dispatch_queue();
    return nexa_livechat_get_chat((int) $chat['id']) ?? $chat;
}

/* ---------------- FAQ / systematic + AI first contact ---------------- */

function nexa_livechat_tokens(string $text): array {
    $text = strtolower(strip_tags($text));
    $text = preg_replace('/[^a-z0-9\s-]/', ' ', $text) ?? $text;
    $stop = array_flip(['the','a','an','and','or','to','of','for','in','on','is','are','do','does','can','i','you','me','my','your','it','this','that','with','from','how','what','when','where','why','please','need','want','know','tell','show','give','get','got','looking','look']);
    $out = [];
    foreach (preg_split('/\s+/', $text) ?: [] as $w) {
        $w = trim($w, '-');
        if (strlen($w) >= 3 && !isset($stop[$w])) $out[$w] = true;
    }
    // Small synonym expansion makes the no-AI mode feel much less brittle.
    $groups = [
        ['price','pricing','cost','fee','fees','charge','quote'], ['hour','hours','open','opening','available','availability'],
        ['contact','email','phone','call','reach','address'], ['human','agent','person','representative','someone'],
        ['service','services','offer','provide'], ['login','account','password','register','sign'],
        ['buy','order','shop','product','checkout','delivery'], ['book','booking','appointment','schedule'],
    ];
    foreach ($groups as $g) if (array_intersect_key($out, array_flip($g))) foreach ($g as $w) $out[$w] = true;
    return array_keys($out);
}

function nexa_livechat_faq_match(string $message): array {
    $norm = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($message)) ?? ''));
    $tokens = nexa_livechat_tokens($message);
    $best = null; $bestScore = 0.0;
    foreach (query("SELECT * FROM livechat_faqs WHERE enabled = ? ORDER BY sort ASC, id ASC", ['1']) as $faq) {
        $score = 0.0;
        $question = strtolower((string) ($faq['question'] ?? ''));
        $keywords = strtolower((string) ($faq['keywords'] ?? ''));
        if ($norm !== '' && (str_contains($question, $norm) || str_contains($norm, $question))) $score += 12;
        foreach (array_filter(array_map('trim', explode(',', $keywords))) as $phrase) {
            if ($phrase !== '' && str_contains($norm, $phrase)) $score += 8;
        }
        foreach ($tokens as $tok) {
            if (preg_match('/\b' . preg_quote($tok, '/') . '\b/', $question)) $score += 3;
            if (preg_match('/\b' . preg_quote($tok, '/') . '\b/', $keywords)) $score += 2;
            if (preg_match('/\b' . preg_quote($tok, '/') . '\b/', strtolower((string) ($faq['answer'] ?? '')))) $score += 0.4;
        }
        if ($score > $bestScore) { $bestScore = $score; $best = $faq; }
    }
    return ['faq' => $best, 'score' => $bestScore];
}

function nexa_livechat_site_search(string $message): array {
    if (!table_exists('posts')) return [];
    $tokens = nexa_livechat_tokens($message);
    if (!$tokens) return [];
    $found = [];
    foreach (get_table('posts')['data'] as $post) {
        if (($post['post_status'] ?? '') !== 'published') continue;
        $score = 0;
        foreach ($tokens as $tok) {
            if (stripos((string) ($post['post_title'] ?? ''), $tok) !== false) $score += 5;
            if (stripos((string) ($post['post_content'] ?? ''), $tok) !== false) $score += 1;
        }
        if ($score >= 6) $found[] = ['score' => $score, 'title' => (string) ($post['post_title'] ?? 'Untitled'), 'url' => post_url($post['id'] ?? 0)];
    }
    usort($found, static fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($found, 0, 2);
}


/* ---------------- Smart suggestions (both ends) ---------------- */

function nexa_livechat_split_followups(string $raw): array {
    $parts = preg_split('/\R|\||;/', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
        // Also allow a single line of comma-separated questions.
        $sub = (str_contains($part, '?') && substr_count($part, '?') > 1 && str_contains($part, ','))
            ? (preg_split('/(?<=\?)\s*,\s*|\s*,\s*(?=[^,]{3,}\?$)/', trim($part)) ?: [$part])
            : [$part];
        foreach ($sub as $q) {
            $q = nexa_livechat_clean_text(trim((string) $q, " \t,-"), 300);
            if ($q !== '') $out[] = $q;
        }
    }
    return array_values(array_unique($out));
}

function nexa_livechat_suggestion_key(string $text): string {
    return mb_strtolower(trim(preg_replace('/[^a-z0-9 ]+/i', '', $text) ?? $text));
}

function nexa_livechat_related_faqs(array $faq, int $limit = 3): array {
    $mine = array_flip(nexa_livechat_tokens((string) ($faq['question'] ?? '') . ' ' . (string) ($faq['keywords'] ?? '')));
    if (!$mine) return [];
    $scored = [];
    foreach (query("SELECT * FROM livechat_faqs WHERE enabled = ? ORDER BY sort ASC, id ASC", ['1']) as $row) {
        if ((int) ($row['id'] ?? 0) === (int) ($faq['id'] ?? 0)) continue;
        $theirs = nexa_livechat_tokens((string) ($row['question'] ?? '') . ' ' . (string) ($row['keywords'] ?? ''));
        $overlap = count(array_intersect_key($mine, array_flip($theirs)));
        if ($overlap >= 2) $scored[] = ['score' => $overlap, 'faq' => $row];
    }
    usort($scored, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: ((int) ($a['faq']['sort'] ?? 0) <=> (int) ($b['faq']['sort'] ?? 0)));
    return array_map(static fn($s) => $s['faq'], array_slice($scored, 0, max(0, $limit)));
}

/** Build visitor follow-ups: admin-picked first, then AI, then auto-related FAQs, then a human escape hatch. */
function nexa_livechat_build_followups(?array $faq, string $message = '', array $aiFollowups = [], int $limit = 5): array {
    $out = []; $seen = [];
    $push = static function (string $label, string $msg) use (&$out, &$seen): void {
        $k = nexa_livechat_suggestion_key($msg);
        if ($k === '' || isset($seen[$k])) return;
        $seen[$k] = true;
        $out[] = ['label' => nexa_livechat_clean_text($label, 90), 'message' => nexa_livechat_clean_text($msg, 300)];
    };
    if ($faq) {
        foreach (nexa_livechat_split_followups((string) ($faq['followups'] ?? '')) as $q) $push($q, $q);
        if ($faq['question'] ?? '') $seen[nexa_livechat_suggestion_key((string) $faq['question'])] = true;
    }
    foreach (array_slice($aiFollowups, 0, 3) as $q) { $q = nexa_livechat_clean_text((string) $q, 300); if ($q !== '') $push($q, $q); }
    if ($faq && count($out) < $limit - 1) {
        foreach (nexa_livechat_related_faqs($faq, $limit) as $rel) $push((string) $rel['question'], (string) $rel['question']);
    }
    if (count($out) < $limit - 1 && $message !== '') {
        $tokens = nexa_livechat_tokens($message);
        if ($tokens) {
            $scored = [];
            foreach (query("SELECT * FROM livechat_faqs WHERE enabled = ? ORDER BY sort ASC, id ASC", ['1']) as $row) {
                if ($faq && (int) ($row['id'] ?? 0) === (int) ($faq['id'] ?? 0)) continue;
                $hay = strtolower((string) ($row['question'] ?? '') . ' ' . (string) ($row['keywords'] ?? ''));
                $score = 0;
                foreach ($tokens as $tok) if (preg_match('/\b' . preg_quote($tok, '/') . '\b/', $hay)) $score++;
                if ($score >= 2) $scored[] = ['score' => $score, 'faq' => $row];
            }
            usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
            foreach (array_slice($scored, 0, $limit) as $s) $push((string) $s['faq']['question'], (string) $s['faq']['question']);
        }
    }
    if (count($out) < $limit - 1) {
        foreach (nexa_livechat_starter_suggestions($limit) as $s) {
            if (($s['label'] ?? '') === 'Talk to a human') continue;
            if ($faq && nexa_livechat_suggestion_key((string) $s['message']) === nexa_livechat_suggestion_key((string) ($faq['question'] ?? ''))) continue;
            $push((string) $s['label'], (string) $s['message']);
        }
    }
    if (count($out) < $limit) $push('Talk to a human', 'I need to speak to a human');
    return array_slice($out, 0, $limit);
}

function nexa_livechat_store_suggestions(int $chatId, array $suggestions): void {
    $clean = [];
    foreach (array_slice($suggestions, 0, 5) as $s) {
        $label = nexa_livechat_clean_text((string) ($s['label'] ?? ''), 90);
        $msg = nexa_livechat_clean_text((string) ($s['message'] ?? ''), 300);
        if ($label !== '' && $msg !== '') $clean[] = ['label' => $label, 'message' => $msg];
    }
    try { nexa_livechat_update_chat($chatId, ['suggestions' => $clean ? json_encode($clean) : '']); } catch (Throwable $e) { /* older schema */ }
}

function nexa_livechat_starter_suggestions(int $limit = 4): array {
    $out = [['label' => 'Talk to a human', 'message' => 'I need to speak to a human']];
    $seen = [nexa_livechat_suggestion_key('I need to speak to a human') => true, nexa_livechat_suggestion_key('How do I speak to a real human?') => true];
    foreach (query("SELECT * FROM livechat_faqs WHERE enabled = ? ORDER BY sort ASC, id ASC LIMIT 12", ['1']) as $f) {
        $q = trim((string) ($f['question'] ?? ''));
        $k = nexa_livechat_suggestion_key($q);
        if ($q === '' || isset($seen[$k])) continue;
        $seen[$k] = true; $out[] = ['label' => $q, 'message' => $q];
        if (count($out) >= $limit) break;
    }
    return array_slice($out, 0, $limit);
}

/** Suggestions the visitor bubble should show right now. */
function nexa_livechat_visitor_suggestions(array $chat): array {
    if (nexa_livechat_setting('suggestions_visitor', '1') !== '1') return [];
    $status = (string) ($chat['status'] ?? 'bot');
    if ($status === 'closed') return [];
    if ($status === 'queued') {
        return [
            ['label' => 'While I wait: opening hours', 'message' => 'What are your opening hours?'],
            ['label' => 'Add more detail', 'message' => 'I can add more detail about my issue while I wait.'],
        ];
    }
    if ($status === 'active') {
        $msgs = nexa_livechat_messages((int) $chat['id'], 120);
        $last = $msgs ? end($msgs) : null;
        if ($last && ($last['sender_type'] ?? '') === 'agent') {
            return [
                ['label' => 'Thank you, that helps', 'message' => 'Thank you, that helps.'],
                ['label' => 'I still need help', 'message' => 'I still need help with this.'],
                ['label' => 'Can you explain more?', 'message' => 'Can you explain that a bit more?'],
                ['label' => "That's solved it", 'message' => "That's solved it — thank you!"],
            ];
        }
        return [];
    }
    // Bot mode: last computed follow-ups win; otherwise smart starters.
    $stored = json_decode((string) ($chat['suggestions'] ?? ''), true);
    if (is_array($stored) && $stored) {
        $out = [];
        foreach ($stored as $s) if (!empty($s['label']) && !empty($s['message'])) $out[] = ['label' => (string) $s['label'], 'message' => (string) $s['message']];
        if ($out) return array_slice($out, 0, 5);
    }
    return nexa_livechat_starter_suggestions();
}

/** Draft replies for the agent console: click inserts into the reply box (never auto-sends). */
function nexa_livechat_agent_suggestions(array $chat): array {
    if (nexa_livechat_setting('suggestions_agent', '1') !== '1') return [];
    $msgs = nexa_livechat_messages((int) $chat['id'], 120);
    $lastVisitor = ''; $lastAny = null;
    foreach ($msgs as $m) { if (($m['sender_type'] ?? '') === 'visitor') $lastVisitor = (string) $m['body']; $lastAny = $m; }
    $name = trim((string) ($chat['visitor_name'] ?? ''));
    $first = ($name !== '' && strcasecmp($name, 'Guest') !== 0) ? strtok($name, ' ') : '';
    $hi = $first !== '' ? 'Hi ' . $first . ', ' : 'Hi there, ';
    $out = []; $seen = [];
    $push = static function (string $label, string $text, string $kind) use (&$out, &$seen): void {
        $k = nexa_livechat_suggestion_key($text);
        if ($text === '' || isset($seen[$k])) return;
        $seen[$k] = true; $out[] = ['label' => $label, 'text' => $text, 'kind' => $kind];
    };

    if ($lastVisitor !== '') {
        if (preg_match('/\b(thanks|thank you|great|perfect|awesome)\b/i', $lastVisitor)) {
            $push('Closing — glad it helped', $hi . "you're very welcome! Glad that helped. If anything else comes up, just send us a message here.", 'closing');
        } elseif (preg_match('/^(hi|hello|hey|good (morning|afternoon|evening))\b/i', trim($lastVisitor)) && str_word_count($lastVisitor) <= 5) {
            $push('Greeting', $hi . 'thanks for contacting us. How can I help you today?', 'greeting');
        }
        $match = nexa_livechat_faq_match($lastVisitor);
        $threshold = max(1, (int) nexa_livechat_setting('faq_threshold', '4'));
        if (($match['faq'] ?? null) && $match['score'] >= $threshold) {
            $answer = trim(preg_replace('/\s*Was that helpful\?.*$/s', '', (string) $match['faq']['answer']) ?? (string) $match['faq']['answer']);
            $push('Answer: ' . mb_substr((string) $match['faq']['question'], 0, 42), $hi . $answer . "\n\nDoes that answer your question, or is there anything else I can check for you?", 'answer');
            foreach (nexa_livechat_split_followups((string) ($match['faq']['followups'] ?? '')) as $i => $fq) {
                if ($i >= 2) break;
                $push('Follow-up: ' . mb_substr($fq, 0, 42), 'Just to check — ' . lcfirst(rtrim($fq, '?')) . '? I can confirm that for you right away.', 'followup');
            }
        }
    }
    if (!$lastVisitor || ($lastAny && ($lastAny['sender_type'] ?? '') === 'agent')) {
        $push('Greeting', $hi . 'thanks for contacting us. I have your message and I am looking into it now.', 'greeting');
    }
    $push('Ask for more detail', $hi . 'could you share a little more detail so I can get this exactly right? Any order/reference number or a screenshot description helps.', 'followup');
    $push('Please hold — checking', 'Thanks for your patience — I am checking that for you now and will be right back.', 'followup');
    if (trim((string) ($chat['visitor_email'] ?? '')) === '') {
        $push('Ask for email follow-up', 'In case we get disconnected, what is the best email address to follow up with you on?', 'followup');
    }
    $push('Follow-up — anything else?', 'Is there anything else I can help you with today?', 'closing');
    $push('Closing', 'Thanks for chatting with us today. If you need anything else, just reopen this chat or start a new one — we are happy to help.', 'closing');
    return array_slice($out, 0, 6);
}

function nexa_livechat_ai_answer(array $chat, string $message): array {
    $key = trim(nexa_livechat_setting('ai_key', ''));
    $endpoint = trim(nexa_livechat_setting('ai_endpoint', 'https://api.openai.com/v1/chat/completions'));
    $model = trim(nexa_livechat_setting('ai_model', 'gpt-4o-mini'));
    if ($endpoint === '' || $model === '') return ['answer' => '', 'escalate' => false, 'used' => false];
    if (str_ends_with($endpoint, '/v1')) $endpoint .= '/chat/completions';

    $faqContext = [];
    foreach (query("SELECT question, answer FROM livechat_faqs WHERE enabled = ? ORDER BY sort ASC, id ASC LIMIT 12", ['1']) as $f) {
        $faqContext[] = 'Q: ' . $f['question'] . "\nA: " . $f['answer'];
    }
    $history = [];
    foreach (array_slice(nexa_livechat_messages((int) $chat['id'], 30), -8) as $m) {
        if (($m['sender_type'] ?? '') === 'system') continue;
        $history[] = ['role' => ($m['sender_type'] === 'visitor' ? 'user' : 'assistant'), 'content' => (string) $m['body']];
    }
    $system = nexa_livechat_setting('ai_system_prompt', 'You are the friendly first-contact assistant for this website. Answer briefly and helpfully. Use the FAQ context when relevant. If you are unsure, if the visitor asks for a person, or if the issue needs account-specific/private help, escalate to a human.');
    $system .= "\n\nFAQ context:\n" . implode("\n\n", $faqContext) . "\n\nReturn ONLY valid JSON: {\"answer\":\"...\",\"escalate\":false,\"confidence\":0.0,\"followups\":[\"a short follow-up question the visitor might ask next\"]}. Give up to 3 natural followups. Set escalate=true when a human should take over.";
    $payload = ['model' => $model, 'temperature' => 0.3, 'messages' => array_merge([['role' => 'system', 'content' => $system]], $history, [['role' => 'user', 'content' => $message]])];
    $json = json_encode($payload);
    $raw = '';

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            $headers = ['Content-Type: application/json'];
            if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
            $raw = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code < 200 || $code >= 300) $raw = '';
        } else {
            $headers = "Content-Type: application/json\r\n" . ($key !== '' ? 'Authorization: Bearer ' . $key . "\r\n" : '');
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => $json, 'timeout' => 15, 'ignore_errors' => true]]);
            $raw = (string) file_get_contents($endpoint, false, $ctx);
        }
    } catch (Throwable $e) { $raw = ''; }
    if ($raw === '') return ['answer' => '', 'escalate' => false, 'used' => false];
    $data = json_decode($raw, true);
    $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    if ($content === '') return ['answer' => '', 'escalate' => false, 'used' => false];
    $content = preg_replace('/^```(?:json)?|```$/m', '', $content) ?? $content;
    $parsed = json_decode(trim($content), true);
    if (is_array($parsed)) {
        $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.8;
        $followups = [];
        foreach ((array) ($parsed['followups'] ?? []) as $fq) { $fq = nexa_livechat_clean_text((string) $fq, 300); if ($fq !== '') $followups[] = $fq; }
        return ['answer' => nexa_livechat_clean_text((string) ($parsed['answer'] ?? ''), 2000), 'escalate' => !empty($parsed['escalate']) || $confidence < 0.45, 'used' => true, 'followups' => array_slice($followups, 0, 3)];
    }
    return ['answer' => nexa_livechat_clean_text($content, 2000), 'escalate' => stripos($content, 'ESCALATE') !== false, 'used' => true, 'followups' => []];
}

function nexa_livechat_bot_reply(array $chat, string $message): void {
    $botName = nexa_livechat_setting('bot_name', 'Nexa Assistant');
    $clean = trim($message);
    $lower = strtolower($clean);

    if (preg_match('/\b(human|real person|agent|representative|someone real|talk to (a )?(person|human|agent))\b/i', $clean)) {
        nexa_livechat_escalate($chat, 'Visitor asked for a human');
        return;
    }
    if (preg_match('/^(hi|hello|hey|good (morning|afternoon|evening))\b/i', $clean) && str_word_count($clean) <= 5) {
        nexa_livechat_add_message((int) $chat['id'], 'bot', '', $botName, 'Hello! I can answer common questions, find a relevant page, or connect you with a human agent. What can I help you with?');
        nexa_livechat_store_suggestions((int) $chat['id'], nexa_livechat_starter_suggestions());
        return;
    }
    if (preg_match('/\b(thanks|thank you|bye|goodbye)\b/i', $clean) && str_word_count($clean) <= 6) {
        nexa_livechat_add_message((int) $chat['id'], 'bot', '', $botName, 'You’re welcome. If anything else comes up, I’m here — or type “human” for an agent.');
        nexa_livechat_store_suggestions((int) $chat['id'], nexa_livechat_build_followups(null, $clean));
        return;
    }

    $mode = strtolower(nexa_livechat_setting('initial_mode', 'faq'));
    if (!in_array($mode, ['faq', 'ai', 'hybrid'], true)) $mode = 'faq';
    $faq = nexa_livechat_faq_match($clean);
    $threshold = max(1, (int) nexa_livechat_setting('faq_threshold', '4'));

    if ($mode === 'ai') {
        $ai = nexa_livechat_ai_answer($chat, $clean);
        if (!empty($ai['used']) && !$ai['escalate'] && $ai['answer'] !== '') {
            nexa_livechat_add_message((int) $chat['id'], 'bot', '', $botName, $ai['answer']);
            if (nexa_livechat_setting('suggestions_ai', '1') === '1') {
                nexa_livechat_store_suggestions((int) $chat['id'], nexa_livechat_build_followups($faq['faq'] ?? null, $clean, (array) ($ai['followups'] ?? [])));
            }
            return;
        }
        if (!empty($ai['used']) && $ai['escalate']) { nexa_livechat_escalate($chat, 'AI requested a human'); return; }
    }
    if ($faq['faq'] !== null && $faq['score'] >= $threshold) {
        nexa_livechat_add_message((int) $chat['id'], 'bot', '', $botName, (string) $faq['faq']['answer'] . "\n\nWas that helpful? If not, type “human” and I’ll connect you with an agent.");
        nexa_livechat_store_suggestions((int) $chat['id'], nexa_livechat_build_followups($faq['faq'], $clean));
        return;
    }
    $pages = nexa_livechat_site_search($clean);
    if ($pages) {
        $lines = ['I found this on the site that may help:'];
        foreach ($pages as $p) $lines[] = '• ' . $p['title'] . ' — ' . $p['url'];
        $lines[] = 'If that does not answer it, type “human” and I’ll route you to an agent.';
        nexa_livechat_add_message((int) $chat['id'], 'bot', '', $botName, implode("\n", $lines));
        nexa_livechat_store_suggestions((int) $chat['id'], nexa_livechat_build_followups(null, $clean));
        return;
    }
    if ($mode === 'hybrid') {
        $ai = nexa_livechat_ai_answer($chat, $clean);
        if (!empty($ai['used']) && !$ai['escalate'] && $ai['answer'] !== '') {
            nexa_livechat_add_message((int) $chat['id'], 'bot', '', $botName, $ai['answer']);
            if (nexa_livechat_setting('suggestions_ai', '1') === '1') {
                nexa_livechat_store_suggestions((int) $chat['id'], nexa_livechat_build_followups($faq['faq'] ?? null, $clean, (array) ($ai['followups'] ?? [])));
            }
            return;
        }
    }

    // Unable to answer confidently: do not guess — hand over to the queue.
    nexa_livechat_update_chat((int) $chat['id'], ['bot_attempts' => (string) ((int) ($chat['bot_attempts'] ?? 0) + 1)]);
    nexa_livechat_add_message((int) $chat['id'], 'bot', '', $botName, 'I couldn’t find a confident answer to that in our FAQs, so I’m routing you to a real human agent now.');
    $fresh = nexa_livechat_get_chat((int) $chat['id']) ?? $chat;
    nexa_livechat_escalate($fresh, 'Bot could not answer: ' . mb_substr($clean, 0, 80));
}

/* ---------------- JSON API ---------------- */

function nexa_livechat_json(array $data, int $code = 200): void {
    // Never let a notice, warning or buffered theme fragment turn the API
    // response into HTML: the browser client expects JSON and nothing else.
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function nexa_livechat_input(): array {
    $in = $_POST;
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $j = json_decode($raw, true);
        if (is_array($j)) $in = array_merge($in, $j);
    }
    return $in;
}

function nexa_livechat_state_payload(array $chat, bool $forAgent = false): array {
    $msgs = [];
    foreach (nexa_livechat_messages((int) $chat['id'], 120) as $m) {
        $msgs[] = ['id' => (int) $m['id'], 'type' => (string) $m['sender_type'], 'sender' => (string) $m['sender_name'], 'body' => (string) $m['body'], 'at' => (string) $m['created_at']];
    }
    $payload = ['ok' => true, 'chat' => nexa_livechat_public_chat($chat), 'messages' => $msgs, 'suggestions' => nexa_livechat_visitor_suggestions($chat)];
    if ($forAgent) {
        $payload['chat']['visitor_email'] = (string) ($chat['visitor_email'] ?? '');
        $payload['chat']['source_page'] = (string) ($chat['source_page'] ?? '');
        $payload['chat']['assigned_agent'] = (int) ($chat['assigned_agent'] ?? 0);
        $payload['chat']['subject'] = (string) ($chat['subject'] ?? '');
        $payload['chat']['agent_unread'] = (int) ($chat['agent_unread'] ?? 0);
        $payload['agent_suggestions'] = nexa_livechat_agent_suggestions($chat);
    }
    return $payload;
}

function nexa_livechat_handle_api(): void {
    if (!isset($_GET['nexa_livechat'])) return;
    nexa_livechat_ensure();
    $scope = (string) $_GET['nexa_livechat'];
    $in = nexa_livechat_input();
    $action = preg_replace('/[^a-z_]/', '', strtolower((string) ($in['action'] ?? 'state')));

    if ($scope === 'visitor') {
        if (!nexa_livechat_enabled()) nexa_livechat_json(['ok' => false, 'error' => 'Live chat is currently disabled.'], 403);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') nexa_livechat_json(['ok' => false, 'error' => 'POST required.'], 405);

        if ($action === 'start') {
            $chat = null;
            $cid = (int) ($in['chat_id'] ?? 0); $token = (string) ($in['token'] ?? '');
            if ($cid > 0 && $token !== '') {
                $candidate = nexa_livechat_get_chat($cid);
                if ($candidate && hash_equals((string) $candidate['access_token'], $token) && ($candidate['status'] ?? '') !== 'closed') $chat = $candidate;
            }
            if ($chat === null) {
                $recent = query("SELECT COUNT(*) AS c FROM livechat_chats WHERE ip = ? AND created_at > ?", [(string) ($_SERVER['REMOTE_ADDR'] ?? ''), date('Y-m-d H:i:s', time() - 3600)]);
                if ((int) ($recent[0]['c'] ?? 0) > 30) nexa_livechat_json(['ok' => false, 'error' => 'Too many chats started. Please try again later.'], 429);
                $name = is_user_logged_in() ? (string) ($_SESSION['display-name'] ?? '') : nexa_livechat_clean_text((string) ($in['name'] ?? ''), 80);
                $email = nexa_livechat_clean_text((string) ($in['email'] ?? ''), 120);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
                $token = bin2hex(random_bytes(24));
                $page = nexa_livechat_clean_text((string) ($in['page'] ?? ''), 300);
                $id = update_table('livechat_chats', [
                    'access_token' => $token, 'visitor_name' => $name ?: 'Guest', 'visitor_email' => $email, 'visitor_user_id' => (string) get_current_user_id(),
                    'status' => 'bot', 'assigned_agent' => '', 'subject' => '', 'source_page' => $page, 'last_page' => $page,
                    'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
                    'bot_attempts' => '0', 'visitor_unread' => '0', 'agent_unread' => '0', 'released_agent_id' => '', 'escalated_at' => '',
                    'created_at' => nexa_livechat_now(), 'updated_at' => nexa_livechat_now(), 'last_message_at' => nexa_livechat_now(), 'closed_at' => '',
                ], 'insert');
                $chat = nexa_livechat_get_chat((int) $id);
                if ($chat) {
                    nexa_livechat_add_message((int) $chat['id'], 'bot', '', nexa_livechat_setting('bot_name', 'Nexa Assistant'), nexa_livechat_setting('welcome_message', 'Hi there! I can answer common questions or connect you with a human agent. How can I help?'));
                }
            }
            if (!$chat) nexa_livechat_json(['ok' => false, 'error' => 'Could not start chat.'], 500);
            $out = nexa_livechat_state_payload($chat);
            $out['token'] = (string) $chat['access_token'];
            nexa_livechat_json($out);
        }

        $cid = (int) ($in['chat_id'] ?? 0); $token = (string) ($in['token'] ?? '');
        $chat = nexa_livechat_get_chat($cid);
        if (!$chat || $token === '' || !hash_equals((string) $chat['access_token'], $token)) nexa_livechat_json(['ok' => false, 'error' => 'Chat session not found. Start a new chat.'], 404);

        if ($action === 'state') {
            $page = nexa_livechat_clean_text((string) ($in['page'] ?? ''), 300);
            if ($page !== '' && $page !== (string) ($chat['last_page'] ?? '')) nexa_livechat_update_chat($cid, ['last_page' => $page]);
            if (!empty($in['mark_read'])) nexa_livechat_update_chat($cid, ['visitor_unread' => '0']);
            nexa_livechat_dispatch_queue();
            $chat = nexa_livechat_get_chat($cid) ?? $chat;
            nexa_livechat_json(nexa_livechat_state_payload($chat));
        }
        if ($action === 'identity') {
            $name = nexa_livechat_clean_text((string) ($in['name'] ?? ''), 80);
            $email = nexa_livechat_clean_text((string) ($in['email'] ?? ''), 120);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) nexa_livechat_json(['ok' => false, 'error' => 'Please enter a valid email address.'], 422);
            nexa_livechat_update_chat($cid, ['visitor_name' => $name ?: (string) $chat['visitor_name'], 'visitor_email' => $email]);
            nexa_livechat_json(nexa_livechat_state_payload(nexa_livechat_get_chat($cid) ?? $chat));
        }
        if ($action === 'send') {
            if (($chat['status'] ?? '') === 'closed') nexa_livechat_json(['ok' => false, 'error' => 'This chat has ended. Start a new chat to continue.'], 410);
            $body = nexa_livechat_clean_text((string) ($in['message'] ?? ''), 2000);
            if ($body === '') nexa_livechat_json(['ok' => false, 'error' => 'Type a message first.'], 422);
            $recent = query("SELECT COUNT(*) AS c FROM livechat_messages WHERE chat_id = ? AND sender_type = ? AND created_at > ?", [(string) $cid, 'visitor', date('Y-m-d H:i:s', time() - 60)]);
            if ((int) ($recent[0]['c'] ?? 0) >= 30) nexa_livechat_json(['ok' => false, 'error' => 'Please slow down a moment.'], 429);
            nexa_livechat_add_message($cid, 'visitor', '', (string) ($chat['visitor_name'] ?? 'Guest'), $body);
            $chat = nexa_livechat_get_chat($cid) ?? $chat;
            if (($chat['status'] ?? '') === 'bot') nexa_livechat_bot_reply($chat, $body);
            elseif (($chat['status'] ?? '') === 'queued') nexa_livechat_dispatch_queue();
            nexa_livechat_json(nexa_livechat_state_payload(nexa_livechat_get_chat($cid) ?? $chat));
        }
        if ($action === 'human') {
            $chat = nexa_livechat_escalate($chat, 'Visitor clicked Talk to a human');
            nexa_livechat_json(nexa_livechat_state_payload($chat));
        }
        if ($action === 'close') {
            nexa_livechat_update_chat($cid, ['status' => 'closed', 'closed_at' => nexa_livechat_now()]);
            nexa_livechat_add_message($cid, 'system', '', 'System', 'This chat has ended. Thanks for talking with us.');
            nexa_livechat_json(nexa_livechat_state_payload(nexa_livechat_get_chat($cid) ?? $chat));
        }
        nexa_livechat_json(['ok' => false, 'error' => 'Unknown visitor action.'], 404);
    }

    if ($scope === 'agent') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') nexa_livechat_json(['ok' => false, 'error' => 'POST required.'], 405);
        if (!is_user_logged_in()) nexa_livechat_json(['ok' => false, 'error' => 'Please log in as an agent.'], 401);
        if (!nexa_verify_csrf((string) ($in['nexa_csrf_token'] ?? ''))) nexa_livechat_json(['ok' => false, 'error' => 'Security check failed. Reload the console.'], 403);
        $agent = nexa_livechat_current_agent(true);
        if ($agent === null) nexa_livechat_json(['ok' => false, 'error' => 'You are not enabled as a live-chat agent. Ask an admin to add you in Live Chat → Agents.'], 403);
        $manager = nexa_livechat_is_manager();

        if ($action === 'profile') {
            $status = in_array((string) ($in['status'] ?? ''), ['online', 'away', 'offline'], true) ? (string) $in['status'] : (string) $agent['status'];
            $max = max(1, (int) nexa_livechat_setting('max_capacity', '10'));
            $cap = max(1, min($max, (int) ($in['capacity'] ?? $agent['capacity'])));
            query("UPDATE livechat_agents SET status = ?, capacity = ?, last_seen = ? WHERE id = ?", [$status, (string) $cap, nexa_livechat_now(), (int) $agent['id']]);
            nexa_livechat_dispatch_queue();
            $action = 'state';
        }

        $chatId = (int) ($in['chat_id'] ?? 0);
        $chat = $chatId > 0 ? nexa_livechat_get_chat($chatId) : null;

        if ($action === 'claim') {
            if (!$chat) nexa_livechat_json(['ok' => false, 'error' => 'Chat not found.'], 404);
            if (($chat['status'] ?? '') === 'closed') nexa_livechat_json(['ok' => false, 'error' => 'That chat has ended.'], 410);
            if (($chat['status'] ?? '') === 'active' && (int) ($chat['assigned_agent'] ?? 0) !== (int) $agent['id'] && !$manager) nexa_livechat_json(['ok' => false, 'error' => 'That chat is already with another agent.'], 409);
            $freshAgent = nexa_livechat_agent_by_id((int) $agent['id']);
            if (!$freshAgent || ($freshAgent['status'] ?? '') !== 'online') nexa_livechat_json(['ok' => false, 'error' => 'Set yourself Online before claiming a chat.'], 422);
            if ((int) $freshAgent['load'] >= (int) $freshAgent['capacity'] && (int) ($chat['assigned_agent'] ?? 0) !== (int) $agent['id']) {
                nexa_livechat_json(['ok' => false, 'error' => 'You are at capacity (' . $freshAgent['load'] . '/' . $freshAgent['capacity'] . '). Close or release a chat before taking another.'], 422);
            }
            nexa_livechat_update_chat($chatId, ['status' => 'active', 'assigned_agent' => (string) $agent['id'], 'released_agent_id' => '', 'escalated_at' => ($chat['escalated_at'] ?? '') ?: nexa_livechat_now()]);
            nexa_livechat_add_message($chatId, 'system', '', 'System', $agent['display_name'] . ' joined the chat.');
            $action = 'state'; $chat = nexa_livechat_get_chat($chatId);
        }
        if ($action === 'send') {
            if (!$chat) nexa_livechat_json(['ok' => false, 'error' => 'Chat not found. It may have been deleted — reload the console.'], 404);
            if (($chat['status'] ?? '') === 'closed') nexa_livechat_json(['ok' => false, 'error' => 'This chat has ended, so the visitor will not see a reply. Ask them to start a new chat.'], 410);
            $assignedTo = (int) ($chat['assigned_agent'] ?? 0);
            if ($assignedTo > 0 && $assignedTo !== (int) $agent['id'] && !$manager) {
                $owner = nexa_livechat_agent_by_id($assignedTo);
                nexa_livechat_json(['ok' => false, 'error' => 'This chat is being handled by ' . ($owner['display_name'] ?? 'another agent') . '. Only a manager can take it over.'], 403);
            }
            $body = nexa_livechat_clean_text((string) ($in['message'] ?? ''), 3000);
            if ($body === '') nexa_livechat_json(['ok' => false, 'error' => 'Type a reply first.'], 422);
            if ($assignedTo !== (int) $agent['id']) {
                // Replying IS taking over: a queued or bot chat must never sit
                // unanswerable just because nobody pressed "Claim" first.
                $freshAgent = nexa_livechat_agent_by_id((int) $agent['id']) ?? $agent;
                if ((int) ($freshAgent['load'] ?? 0) >= (int) ($freshAgent['capacity'] ?? 1) && !$manager) {
                    nexa_livechat_json(['ok' => false, 'error' => 'You are at capacity (' . (int) ($freshAgent['load'] ?? 0) . '/' . (int) ($freshAgent['capacity'] ?? 1) . '). Close or release one of your chats before taking this one.'], 422);
                }
                nexa_livechat_update_chat($chatId, ['status' => 'active', 'assigned_agent' => (string) $agent['id'], 'released_agent_id' => '', 'escalated_at' => ($chat['escalated_at'] ?? '') ?: nexa_livechat_now()]);
                nexa_livechat_add_message($chatId, 'system', '', 'System', $agent['display_name'] . ' joined the chat.');
            } elseif (($chat['status'] ?? '') !== 'active') {
                nexa_livechat_update_chat($chatId, ['status' => 'active']);
            }
            nexa_livechat_add_message($chatId, 'agent', (string) $agent['id'], (string) $agent['display_name'], $body);
            nexa_livechat_update_chat($chatId, ['agent_unread' => '0']);
            $action = 'state'; $chat = nexa_livechat_get_chat($chatId);
        }
        if ($action === 'release') {
            if (!$chat) nexa_livechat_json(['ok' => false, 'error' => 'Chat not found.'], 404);
            if ((int) ($chat['assigned_agent'] ?? 0) !== (int) $agent['id'] && !$manager) nexa_livechat_json(['ok' => false, 'error' => 'You can only release your own chats.'], 403);
            nexa_livechat_update_chat($chatId, ['status' => 'queued', 'assigned_agent' => '', 'released_agent_id' => (string) ($chat['assigned_agent'] ?? $agent['id']), 'escalated_at' => ($chat['escalated_at'] ?? '') ?: nexa_livechat_now()]);
            nexa_livechat_add_message($chatId, 'system', '', 'System', 'The agent released this chat back to the queue.');
            nexa_livechat_dispatch_queue([(int) $chatId => (int) ($chat['assigned_agent'] ?? $agent['id'])]);
            $action = 'state'; $chat = nexa_livechat_get_chat($chatId);
        }
        if ($action === 'close') {
            if (!$chat) nexa_livechat_json(['ok' => false, 'error' => 'Chat not found.'], 404);
            nexa_livechat_update_chat($chatId, ['status' => 'closed', 'closed_at' => nexa_livechat_now()]);
            nexa_livechat_add_message($chatId, 'system', '', 'System', 'The agent ended this chat. Thanks for talking with us.');
            nexa_livechat_dispatch_queue();
            $action = 'state'; $chat = nexa_livechat_get_chat($chatId);
        }
        if ($action === 'assign') {
            if (!$manager) nexa_livechat_json(['ok' => false, 'error' => 'Only managers can assign chats.'], 403);
            if (!$chat) nexa_livechat_json(['ok' => false, 'error' => 'Chat not found.'], 404);
            $target = nexa_livechat_agent_by_id((int) ($in['agent_id'] ?? 0));
            if (!$target) nexa_livechat_json(['ok' => false, 'error' => 'Agent not found.'], 404);
            if ((int) $target['load'] >= (int) $target['capacity'] && (int) ($chat['assigned_agent'] ?? 0) !== (int) $target['id']) nexa_livechat_json(['ok' => false, 'error' => $target['display_name'] . ' is at capacity (' . $target['load'] . '/' . $target['capacity'] . ').'], 422);
            nexa_livechat_update_chat($chatId, ['status' => 'active', 'assigned_agent' => (string) $target['id'], 'released_agent_id' => '']);
            nexa_livechat_add_message($chatId, 'system', '', 'System', 'This chat was assigned to ' . $target['display_name'] . '.');
            $action = 'state'; $chat = nexa_livechat_get_chat($chatId);
        }

        if ($action === 'alerts') {
            nexa_livechat_dispatch_queue();
            $items = []; $waiting = 0; $unread = 0;
            foreach (query("SELECT * FROM livechat_chats WHERE status != ? ORDER BY updated_at DESC LIMIT 100", ['closed']) as $row) {
                $isQueued = ($row['status'] ?? '') === 'queued';
                $mineUnread = (int) ($row['assigned_agent'] ?? 0) === (int) $agent['id'] && !empty($row['agent_unread']);
                $anyUnread = $manager && ($row['status'] ?? '') === 'active' && !empty($row['agent_unread']);
                if ($isQueued) $waiting++;
                if ($mineUnread || ($anyUnread && (int) ($row['assigned_agent'] ?? 0) !== (int) $agent['id'])) $unread++;
                if (!$isQueued && !$mineUnread && !$anyUnread) continue;
                $last = query("SELECT sender_type, body, created_at FROM livechat_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 1", [(string) $row['id']]);
                $item = nexa_livechat_public_chat($row);
                $item['visitor_email'] = (string) ($row['visitor_email'] ?? '');
                $item['assigned_agent'] = (int) ($row['assigned_agent'] ?? 0);
                $item['agent_unread'] = (int) ($row['agent_unread'] ?? 0);
                $item['last_message'] = $last ? mb_substr((string) $last[0]['body'], 0, 140) : '';
                $item['last_message_type'] = $last ? (string) $last[0]['sender_type'] : '';
                $item['last_message_at'] = (string) ($row['last_message_at'] ?? ($last[0]['created_at'] ?? ''));
                $items[] = $item;
                if (count($items) >= 10) break;
            }
            $meA = nexa_livechat_agent_by_id((int) $agent['id']) ?? $agent;
            nexa_livechat_json(['ok' => true, 'waiting' => $waiting, 'unread' => $unread, 'attention' => count($items), 'items' => $items, 'me' => ['id' => (int) $meA['id'], 'name' => (string) $meA['display_name'], 'status' => (string) ($meA['status'] ?? ''), 'load' => (int) ($meA['load'] ?? 0), 'capacity' => (int) ($meA['capacity'] ?? 1)]]);
        }

        if ($action === 'state') {
            nexa_livechat_dispatch_queue();
            $me = nexa_livechat_agent_by_id((int) $agent['id']) ?? $agent;
            $queue = []; $mine = []; $all = []; $bot = [];
            foreach (query("SELECT * FROM livechat_chats WHERE status != ? ORDER BY updated_at DESC LIMIT 100", ['closed']) as $row) {
                $item = nexa_livechat_public_chat($row);
                $item['visitor_email'] = (string) ($row['visitor_email'] ?? ''); $item['assigned_agent'] = (int) ($row['assigned_agent'] ?? 0); $item['agent_unread'] = (int) ($row['agent_unread'] ?? 0); $item['last_message_at'] = (string) ($row['last_message_at'] ?? '');
                if (($row['status'] ?? '') === 'queued') $queue[] = $item;
                if ((int) ($row['assigned_agent'] ?? 0) === (int) $agent['id'] && ($row['status'] ?? '') === 'active') $mine[] = $item;
                if ($manager && ($row['status'] ?? '') === 'active') $all[] = $item;
                if (($row['status'] ?? '') === 'bot') $bot[] = $item;
            }
            usort($queue, static fn($a, $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));
            foreach ($queue as &$q) $q['queue_position'] = nexa_livechat_queue_position((int) $q['id']); unset($q);
            $agentsOut = [];
            foreach (nexa_livechat_agents() as $a) $agentsOut[] = ['id' => (int) $a['id'], 'name' => (string) $a['display_name'], 'status' => (string) $a['status'], 'load' => (int) $a['load'], 'capacity' => (int) $a['capacity']];
            $out = ['ok' => true, 'me' => ['id' => (int) $me['id'], 'name' => (string) $me['display_name'], 'status' => (string) $me['status'], 'load' => (int) ($me['load'] ?? 0), 'capacity' => (int) ($me['capacity'] ?? 1)], 'manager' => $manager, 'queue' => $queue, 'mine' => $mine, 'active' => $all, 'bot' => $bot, 'agents' => $agentsOut];
            if ($chatId > 0) {
                $selected = nexa_livechat_get_chat($chatId);
                if ($selected) {
                    if (!empty($in['mark_read'])) nexa_livechat_update_chat($chatId, ['agent_unread' => '0']);
                    $out['selected'] = nexa_livechat_state_payload(nexa_livechat_get_chat($chatId) ?? $selected, true);
                }
            }
            nexa_livechat_json($out);
        }
        nexa_livechat_json(['ok' => false, 'error' => 'Unknown agent action.'], 404);
    }

    nexa_livechat_json(['ok' => false, 'error' => 'Unknown live-chat endpoint.'], 404);
}

/* ---------------- Front-end persistent widget ---------------- */

function nexa_livechat_head(): void {
    if (!empty($GLOBALS['adminarea']) || !nexa_livechat_enabled()) return;
    $color = nexa_livechat_setting('color', '#6d28d9');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6d28d9';
    echo '<style id="nexa-livechat-css">'
        . '#nexa-livechat-root{--nlc:' . $color . ';position:fixed;right:20px;bottom:20px;z-index:999999;font-family:Arial,Helvetica,sans-serif;color:#172033}'
        . '#nexa-livechat-root *{box-sizing:border-box}'
        . '.nlc-bubble{width:60px;height:60px;border:0;border-radius:50%;background:var(--nlc);color:#fff;box-shadow:0 12px 32px rgba(0,0,0,.28);cursor:pointer;display:flex;align-items:center;justify-content:center;position:relative}'
        . '.nlc-bubble svg{width:29px;height:29px;fill:currentColor}'
        . '.nlc-badge{position:absolute;top:-4px;right:-3px;min-width:22px;height:22px;padding:2px 5px;border-radius:999px;background:#dc2626;color:#fff;font-size:12px;font-weight:700;display:none;align-items:center;justify-content:center;border:2px solid #fff}'
        . '.nlc-panel{position:absolute;right:0;bottom:74px;width:min(385px,calc(100vw - 32px));height:min(590px,calc(100vh - 110px));background:#fff;border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.35);overflow:hidden;display:none;flex-direction:column;border:1px solid rgba(15,23,42,.12)}'
        . '.nlc-panel.open{display:flex}'
        . '.nlc-head{background:var(--nlc);color:#fff;padding:14px 15px;display:flex;gap:11px;align-items:center}'
        . '.nlc-avatar{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex:0 0 auto}'
        . '.nlc-head h3{margin:0;font-size:16px;line-height:1.2}.nlc-head p{margin:2px 0 0;font-size:12px;opacity:.88}'
        . '.nlc-head-actions{margin-left:auto;display:flex;gap:5px}.nlc-icon-btn{border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:8px;width:31px;height:31px;cursor:pointer;font-size:16px}'
        . '.nlc-status{font-size:12px;background:#f5f3ff;color:#4c1d95;padding:8px 12px;border-bottom:1px solid #ede9fe;display:flex;justify-content:space-between;gap:8px;align-items:center}'
        . '.nlc-human{border:1px solid var(--nlc);color:var(--nlc);background:#fff;border-radius:999px;padding:4px 9px;cursor:pointer;font-weight:700;white-space:nowrap}'
        . '.nlc-identity{padding:9px 11px;background:#f8fafc;border-bottom:1px solid #e5e7eb}.nlc-identity summary{cursor:pointer;font-size:12px;font-weight:700;color:#475569}.nlc-identity input{width:100%;margin-top:7px;padding:8px;border:1px solid #cbd5e1;border-radius:8px}.nlc-identity button{margin-top:7px;background:var(--nlc);color:#fff;border:0;border-radius:8px;padding:7px 10px;cursor:pointer}'
        . '.nlc-messages{flex:1;overflow-y:auto;padding:14px;background:linear-gradient(#fbfcfe,#f5f7fb)}'
        . '.nlc-msg{max-width:84%;padding:9px 11px;margin:0 0 9px;border-radius:14px;line-height:1.45;font-size:14px;white-space:pre-wrap;word-wrap:break-word}.nlc-msg small{display:block;margin-top:4px;font-size:10px;opacity:.62}'
        . '.nlc-msg.visitor{margin-left:auto;background:var(--nlc);color:#fff;border-bottom-right-radius:4px}.nlc-msg.bot,.nlc-msg.agent{background:#fff;border:1px solid #e2e8f0;border-bottom-left-radius:4px}.nlc-msg.system{max-width:92%;margin-left:auto;margin-right:auto;background:#eef2ff;color:#3730a3;text-align:center;font-size:12px;border-radius:999px;padding:6px 10px}'
        . '.nlc-chips{display:flex;gap:6px;flex-wrap:wrap;padding:8px 10px;background:#fff;border-top:1px solid #eef2f7}.nlc-chips button{border:1px solid #ddd6fe;background:#f5f3ff;color:#5b21b6;border-radius:999px;padding:5px 9px;cursor:pointer;font-size:12px}'
        . '.nlc-form{display:flex;gap:8px;padding:10px;background:#fff;border-top:1px solid #e5e7eb}.nlc-form textarea{flex:1;resize:none;border:1px solid #cbd5e1;border-radius:12px;padding:10px;max-height:90px;font-family:inherit}.nlc-send{border:0;background:var(--nlc);color:#fff;border-radius:12px;padding:0 15px;font-weight:800;cursor:pointer}.nlc-send:disabled,.nlc-form textarea:disabled{opacity:.55;cursor:not-allowed}'
        . '.nlc-foot{font-size:10px;text-align:center;color:#94a3b8;padding:5px;background:#fff}'
        . '@media(max-width:640px){#nexa-livechat-root{right:14px;bottom:14px}.nlc-panel{bottom:70px;height:min(610px,calc(100vh - 92px))}}'
        . '</style>' . "\n";
}

function nexa_livechat_widget(): void {
    if (!empty($GLOBALS['adminarea']) || !nexa_livechat_enabled()) return;
    static $done = false; if ($done) return; $done = true;
    $brand = nexa_livechat_setting('brand_name', (string) get_setting('blogname', 'Support'));
    $bot = nexa_livechat_setting('bot_name', 'Nexa Assistant');
    $config = [
        'endpoint' => home_url('content/plugins/nexa-live-chat/api.php?nexa_livechat=visitor'), 'brand' => $brand, 'botName' => $bot,
        'requireEmail' => nexa_livechat_setting('require_email', '0') === '1',
    ];
    echo '<div id="nexa-livechat-root" aria-live="polite">'
        . '<section class="nlc-panel" id="nlc-panel" aria-label="Live chat">'
        . '<div class="nlc-head"><div class="nlc-avatar">' . esc_html(mb_substr($brand !== '' ? $brand : 'S', 0, 1)) . '</div><div><h3>' . esc_html($brand) . '</h3><p id="nlc-subtitle">' . esc_html($bot) . ' · usually replies instantly</p></div><div class="nlc-head-actions"><button class="nlc-icon-btn" id="nlc-close-chat" title="End chat" type="button">✓</button><button class="nlc-icon-btn" id="nlc-min" title="Minimise" type="button">—</button></div></div>'
        . '<div class="nlc-status"><span id="nlc-status-text">Starting chat…</span><button class="nlc-human" id="nlc-human" type="button">Talk to a human</button></div>'
        . '<details class="nlc-identity" id="nlc-identity"><summary>Your name & email (optional)</summary><input id="nlc-name" autocomplete="name" placeholder="Your name"><input id="nlc-email" type="email" autocomplete="email" placeholder="Email for follow-up"><button id="nlc-save-id" type="button">Save details</button></details>'
        . '<div class="nlc-messages" id="nlc-messages"></div>'
        . '<div class="nlc-chips" id="nlc-chips"><button type="button" data-nlc-chip="I need to speak to a human">Human agent</button><button type="button" data-nlc-chip="What are your opening hours?">Opening hours</button><button type="button" data-nlc-chip="How can I contact you?">Contact options</button></div>'
        . '<form class="nlc-form" id="nlc-form"><textarea id="nlc-input" rows="1" placeholder="Type your message…" aria-label="Type your message"></textarea><button class="nlc-send" id="nlc-send" type="submit">Send</button></form>'
        . '<div class="nlc-foot">FAQ/AI first · a human can take over at any time</div></section>'
        . '<button class="nlc-bubble" id="nlc-bubble" type="button" aria-label="Open live chat"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.14 2 11.25c0 2.92 1.48 5.53 3.8 7.24-.14 1.1-.62 2.6-1.55 3.72 0 0 2.9-.1 5.02-1.34.87.22 1.79.38 2.73.38 5.52 0 10-4.14 10-9.25S17.52 2 12 2Z"/></svg><span class="nlc-badge" id="nlc-badge">0</span></button>'
        . '</div>'
        . '<script>window.NEXA_LIVECHAT=' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
        . '<script>(function(){'
        . 'var cfg=window.NEXA_LIVECHAT||{},root=document.getElementById("nexa-livechat-root");if(!root)return;'
        . 'var LS="nexaLiveChat.v1",store={};try{store=JSON.parse(localStorage.getItem(LS)||"{}")}catch(e){store={}}'
        . 'var els={bubble:document.getElementById("nlc-bubble"),panel:document.getElementById("nlc-panel"),msgs:document.getElementById("nlc-messages"),input:document.getElementById("nlc-input"),form:document.getElementById("nlc-form"),send:document.getElementById("nlc-send"),status:document.getElementById("nlc-status-text"),subtitle:document.getElementById("nlc-subtitle"),chips:document.getElementById("nlc-chips"),badge:document.getElementById("nlc-badge"),name:document.getElementById("nlc-name"),email:document.getElementById("nlc-email")};'
        . 'var busy=false,pollTimer=null;store.seen=store.seen||0;'
        . 'function save(){try{localStorage.setItem(LS,JSON.stringify(store))}catch(e){}}'
        . 'function api(action,data){data=data||{};data.action=action;if(store.chatId){data.chat_id=store.chatId;data.token=store.token||""}var body=new URLSearchParams();Object.keys(data).forEach(function(k){body.append(k,data[k]==null?"":data[k])});return fetch(cfg.endpoint,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:body.toString(),credentials:"same-origin"}).then(function(r){return r.text().then(function(txt){var j;try{j=JSON.parse(txt)}catch(e){throw new Error("Live chat server returned an invalid response (HTTP "+r.status+"). Please reload and try again.")}if(!r.ok||!j.ok)throw new Error(j.error||"Chat request failed");return j})})}'
        . 'function esc(s){var d=document.createElement("div");d.textContent=s||"";return d.innerHTML}'
        . 'function linkify(node,text){var parts=String(text||"").split(/(https?:\\/\\/[^\\s<]+)/g);parts.forEach(function(p){if(/^https?:\\/\\//.test(p)){var a=document.createElement("a");a.href=p;a.target="_blank";a.rel="noopener";a.textContent=p;node.appendChild(a)}else node.appendChild(document.createTextNode(p))})}'
        . 'function renderChips(list){if(!els.chips)return;els.chips.innerHTML="";if(!list||!list.length){els.chips.style.display="none";return}els.chips.style.display="flex";list.forEach(function(s){var b=document.createElement("button");b.type="button";b.textContent=s.label||s.message;b.title=s.message||s.label;b.addEventListener("click",function(){els.input.value=s.message||s.label;els.form.dispatchEvent(new Event("submit",{cancelable:true}))});els.chips.appendChild(b)})}'
        . 'function render(state){if(!state||!state.chat)return;renderChips(state.suggestions);var c=state.chat;store.chatId=c.id;save();var nearBottom=els.msgs.scrollHeight-els.msgs.scrollTop-els.msgs.clientHeight<90;els.msgs.innerHTML="";var maxId=store.seen||0;(state.messages||[]).forEach(function(m){var d=document.createElement("div");d.className="nlc-msg "+m.type;linkify(d,m.body);var s=document.createElement("small");s.textContent=(m.sender||"")+(m.at?" · "+m.at:"");d.appendChild(s);els.msgs.appendChild(d);if(m.id>maxId)maxId=m.id});var unread=0;(state.messages||[]).forEach(function(m){if(m.id>(store.seen||0)&&m.type!=="visitor")unread++});if(store.open){store.seen=maxId;unread=0;setTimeout(function(){api("state",{mark_read:1,page:location.href}).catch(function(){})},0)}els.badge.textContent=unread;els.badge.style.display=unread?"flex":"none";save();'
        . 'var status="";if(c.status==="bot")status=cfg.botName+" is helping you";else if(c.status==="queued")status="In the human queue"+(c.queue_position?" · position "+c.queue_position:"");else if(c.status==="active")status="Connected with "+(c.agent_name||"an agent");else if(c.status==="closed")status="This chat has ended";els.status.textContent=status;els.subtitle.textContent=c.status==="active"?(c.agent_name||"Support agent")+" · live agent":cfg.botName+" · FAQ/AI first contact";var closed=c.status==="closed";els.input.disabled=closed;els.send.disabled=closed;els.input.placeholder=closed?"Start a new chat to continue…":"Type your message…";if(nearBottom||store.open)els.msgs.scrollTop=els.msgs.scrollHeight}'
        . 'function refresh(mark){return api("state",{page:location.href,mark_read:mark&&store.open?1:0}).then(render).catch(function(e){els.status.textContent=e.message})}'
        . 'function start(){if(store.chatId&&store.token)return refresh(true);return api("start",{name:store.name||els.name.value||"",email:store.email||els.email.value||"",page:location.href}).then(function(j){store.chatId=j.chat.id;store.token=j.token;save();render(j)})}'
        . 'function setOpen(open){store.open=!!open;save();els.panel.classList.toggle("open",store.open);if(store.open){start().then(function(){store.seen=Math.max(store.seen||0);els.badge.style.display="none";setTimeout(function(){els.input.focus()},30)}).catch(function(e){els.status.textContent=e.message});if(!pollTimer)pollTimer=setInterval(function(){if(store.chatId)refresh(false)},4000)}else if(store.chatId)refresh(false)}'
        . 'els.bubble.addEventListener("click",function(){setOpen(!store.open)});document.getElementById("nlc-min").addEventListener("click",function(){setOpen(false)});'
        . 'document.getElementById("nlc-close-chat").addEventListener("click",function(){if(!store.chatId)return;if(!confirm("End this chat?"))return;api("close",{}).then(function(j){render(j);store.chatId=null;store.token="";store.seen=0;save()}).catch(function(e){alert(e.message)})});'
        . 'document.getElementById("nlc-human").addEventListener("click",function(){start().then(function(){return api("human",{})}).then(render).catch(function(e){alert(e.message)})});'
        . 'document.querySelectorAll("[data-nlc-chip]").forEach(function(b){b.addEventListener("click",function(){els.input.value=b.getAttribute("data-nlc-chip");els.form.dispatchEvent(new Event("submit",{cancelable:true}))})});'
        . 'document.getElementById("nlc-save-id").addEventListener("click",function(){store.name=els.name.value.trim();store.email=els.email.value.trim();save();if(store.chatId)api("identity",{name:store.name,email:store.email}).then(render).catch(function(e){alert(e.message)});else alert("Details saved. They will be attached when the chat starts.")});'
        . 'els.form.addEventListener("submit",function(e){e.preventDefault();var text=els.input.value.trim();if(!text||busy)return;if(!store.chatId){start().then(function(){els.form.dispatchEvent(new Event("submit",{cancelable:true}))});return}busy=true;els.send.disabled=true;var old=els.send.textContent;els.send.textContent="…";api("send",{message:text}).then(function(j){els.input.value="";render(j)}).catch(function(err){alert(err.message)}).finally(function(){busy=false;els.send.disabled=false;els.send.textContent=old;els.input.focus()})});'
        . 'els.input.addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();els.form.dispatchEvent(new Event("submit",{cancelable:true}))}});'
        . 'els.name.value=store.name||"";els.email.value=store.email||"";if(store.chatId&&store.token)refresh(false);if(store.open)setOpen(true);if(store.chatId&&!pollTimer)pollTimer=setInterval(function(){refresh(false)},5000);'
        . 'window.nexaLiveChatOpen=function(){setOpen(true)};window.nexaLiveChatClose=function(){setOpen(false)};'
        . '})();</script>' . "\n";
}

function nexa_livechat_shortcode(): string {
    if (!nexa_livechat_enabled()) return '<p>Live chat is currently unavailable.</p>';
    return '<button type="button" class="btn btn-primary" onclick="if(window.nexaLiveChatOpen)nexaLiveChatOpen();">Chat with us</button>';
}
if (function_exists('add_shortcode')) add_shortcode('live_chat', 'nexa_livechat_shortcode');
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Nexa Live Chat', 'plugin_slug' => 'nexa-live-chat', 'label' => 'Live chat button', 'tag' => 'live_chat',
        'description' => 'Insert a button that opens the persistent live-chat bubble.', 'shortcode' => '[live_chat]',
    ]);
}
