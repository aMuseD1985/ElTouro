<?php
/**
 * All schema steps in ONE place. Idempotent: every step checks itself whether it is needed.
 * Called by migrate.php (by key) and admin/ops.php (by click).
 * Append new changes at the bottom, never rewrite existing steps.
 *
 * One deliberate exception: the block "0. English refactor" runs FIRST, because it renames
 * page slugs and setting keys before the steps below would create fresh defaults under the
 * new names. It only does something on databases created before October 2026.
 */
declare(strict_types=1);

function tableExists(string $t): bool
{
    return (int)dbOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])['n'] > 0;
}

function columnExists(string $t, string $c): bool
{
    return (int)dbOne('SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c])['n'] > 0;
}

function columnType(string $t, string $c): string
{
    return (string)(dbOne('SELECT COLUMN_TYPE AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c])['t'] ?? '');
}

/** @return string[] log lines */
function runMigrations(): array
{
    $log = [];
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $create = function (string $name, string $sql) use (&$log): void {
        if (tableExists($name)) {
            $log[] = "= $name vorhanden";
            return;
        }
        db()->exec($sql);
        $log[] = "+ $name angelegt";
    };

    /* ---------- 0. English refactor (2026-10): rename German identifiers in existing data ---------- */
    migrateLegacyDataDir($log);
    if (tableExists('settings')) {
        foreach (['registrierung_offen' => 'registration_open', 'betreiber_name' => 'operator_name', 'betreiber_anschrift' => 'operator_address',
                  'betreiber_email' => 'operator_email', 'betreiber_telefon' => 'operator_phone', 'aufsichtsbehoerde' => 'supervisory_authority',
                  'log_tage' => 'log_days'] as $old => $new) {
            if (dbExec('UPDATE IGNORE settings SET k = ? WHERE k = ?', [$new, $old])) {
                $log[] = "~ Einstellung $old → $new";
            }
            dbExec('DELETE FROM settings WHERE k = ?', [$old]);   // only left over if both existed
        }
    }
    if (tableExists('pages')) {
        foreach (['impressum' => 'imprint', 'datenschutz' => 'privacy', 'nutzungsbedingungen' => 'terms'] as $old => $new) {
            if (dbExec('UPDATE IGNORE pages SET slug = ? WHERE slug = ?', [$new, $old])) {
                $log[] = "~ Seite $old → $new";
            }
        }
        $replace = ['{{betreiber_name}}' => '{{operator_name}}', '{{betreiber_anschrift}}' => '{{operator_address}}',
                    '{{betreiber_email}}' => '{{operator_email}}', '{{betreiber_telefon}}' => '{{operator_phone}}',
                    '{{aufsichtsbehoerde}}' => '{{supervisory_authority}}', '{{log_tage}}' => '{{log_days}}',
                    '{{kartendienst_hinweis}}' => '{{map_service_note}}', '{{kartendienst}}' => '{{map_service}}', '{{stand}}' => '{{updated}}',
                    '](/impressum)' => '](/imprint)', '](/datenschutz)' => '](/privacy)', '](/nutzungsbedingungen)' => '](/terms)',
                    'et_zugang' => 'et_access'];
        $changed = 0;
        foreach ($replace as $old => $new) {
            $changed += dbExec('UPDATE pages SET body = REPLACE(body, ?, ?) WHERE body LIKE ?', [$old, $new, '%' . $old . '%']);
        }
        if ($changed) {
            $log[] = "~ Platzhalter und Links in Seiten umbenannt ($changed)";
        }
    }
    if (tableExists('tours') && ($n = dbExec("UPDATE tours SET geojson = REPLACE(geojson, '\"freihand\":', '\"freehand\":') WHERE geojson LIKE '%\"freihand\":%'"))) {
        $log[] = "~ Touren-Geometrie: freihand → freehand ($n)";
    }

    /* ---------- Accounts ---------- */
    $create('users', "CREATE TABLE users (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(254) NOT NULL, password_hash VARCHAR(255) NOT NULL,
      display_name VARCHAR(30) NOT NULL, birth_date DATE NOT NULL,
      locale ENUM('de','en') NOT NULL DEFAULT 'de', is_admin TINYINT(1) NOT NULL DEFAULT 0,
      status ENUM('active','blocked','deleted') NOT NULL DEFAULT 'active',
      email_verified_at DATETIME NULL, terms_accepted_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_users_email (email), UNIQUE KEY uq_users_name (display_name)
    ) $opt");

    $create('user_profiles', "CREATE TABLE user_profiles (
      user_id BIGINT UNSIGNED PRIMARY KEY, bio TEXT NULL, home_region VARCHAR(100) NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_prof_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    $create('auth_tokens', "CREATE TABLE auth_tokens (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
      purpose ENUM('verify','reset') NOT NULL, token_hash CHAR(64) NOT NULL,
      expires_at DATETIME NOT NULL, used_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_tok_hash (token_hash), KEY ix_tok_user (user_id, purpose),
      CONSTRAINT fk_tok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    $create('login_attempts', "CREATE TABLE login_attempts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY ix_la_ip_time (ip, created_at)
    ) $opt");

    /* ---------- Crews ---------- */
    $create('rider_groups', "CREATE TABLE rider_groups (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80) NOT NULL, name VARCHAR(60) NOT NULL,
      description TEXT NULL, region VARCHAR(100) NULL,
      discoverability ENUM('listed','secret') NOT NULL DEFAULT 'listed',
      join_policy ENUM('open','request','invite') NOT NULL DEFAULT 'request',
      invite_code CHAR(24) NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_groups_slug (slug), UNIQUE KEY uq_groups_invite (invite_code),
      KEY ix_groups_list (discoverability, deleted_at),
      CONSTRAINT fk_groups_creator FOREIGN KEY (created_by) REFERENCES users(id)
    ) $opt");

    $create('group_members', "CREATE TABLE group_members (
      group_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
      role ENUM('admin','member') NOT NULL DEFAULT 'member', status ENUM('active','pending') NOT NULL DEFAULT 'pending',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (group_id, user_id), KEY ix_gm_user (user_id, status),
      CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES rider_groups(id) ON DELETE CASCADE,
      CONSTRAINT fk_gm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    /* ---------- Content & settings ---------- */
    $create('pages', "CREATE TABLE pages (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(60) NOT NULL, locale ENUM('de','en') NOT NULL,
      title VARCHAR(120) NOT NULL, body MEDIUMTEXT NOT NULL, updated_by BIGINT UNSIGNED NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_pages (slug, locale)
    ) $opt");

    $create('settings', "CREATE TABLE settings (
      k VARCHAR(60) PRIMARY KEY, v TEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $opt");

    foreach ([['imprint', 'de', 'Impressum'], ['imprint', 'en', 'Legal notice'],
              ['privacy', 'de', 'Datenschutzerklärung'], ['privacy', 'en', 'Privacy policy'],
              ['terms', 'de', 'Nutzungsbedingungen'], ['terms', 'en', 'Terms of use']] as [$s, $l, $t]) {
        if (dbExec('INSERT IGNORE INTO pages (slug, locale, title, body) VALUES (?, ?, ?, ?)', [$s, $l, $t, ''])) {
            $log[] = "+ Seite $s/$l";
        }
    }
    dbExec("INSERT IGNORE INTO settings (k, v) VALUES ('registration_open', '1')");

    /* ---------- Tours (route planner) ---------- */
    $create('tours', "CREATE TABLE tours (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id BIGINT UNSIGNED NOT NULL, owner_group_id BIGINT UNSIGNED NULL,
      title VARCHAR(120) NOT NULL, description TEXT NULL, content_lang ENUM('de','en') NOT NULL DEFAULT 'de',
      visibility ENUM('private','group','public') NOT NULL DEFAULT 'private',
      source ENUM('planned','recorded','imported') NOT NULL DEFAULT 'planned',
      rule_set ENUM('ekfv','ekfv2027') NOT NULL DEFAULT 'ekfv',
      difficulty ENUM('easy','moderate','demanding') NOT NULL DEFAULT 'easy',
      style ENUM('relaxed','social','sporty') NOT NULL DEFAULT 'social',
      distance_m INT UNSIGNED NOT NULL DEFAULT 0, ascent_m SMALLINT UNSIGNED NULL,
      freehand_share_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
      waypoints_json JSON NOT NULL, geojson MEDIUMTEXT NOT NULL,
      start_lat DECIMAL(9,6) NOT NULL, start_lng DECIMAL(9,6) NOT NULL,
      bbox_min_lat DECIMAL(9,6) NOT NULL, bbox_min_lng DECIMAL(9,6) NOT NULL,
      bbox_max_lat DECIMAL(9,6) NOT NULL, bbox_max_lng DECIMAL(9,6) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      KEY ix_tours_owner (owner_user_id), KEY ix_tours_group (owner_group_id),
      KEY ix_tours_public (visibility, deleted_at, start_lat, start_lng),
      CONSTRAINT fk_tours_user FOREIGN KEY (owner_user_id) REFERENCES users(id),
      CONSTRAINT fk_tours_group FOREIGN KEY (owner_group_id) REFERENCES rider_groups(id) ON DELETE SET NULL
    ) $opt");

    /* ---------- Forum ---------- */
    $create('forum_categories', "CREATE TABLE forum_categories (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(60) NOT NULL,
      name_de VARCHAR(80) NOT NULL, name_en VARCHAR(80) NOT NULL,
      description_de VARCHAR(255) NULL, description_en VARCHAR(255) NULL,
      sort SMALLINT NOT NULL DEFAULT 0, UNIQUE KEY uq_fc_slug (slug)
    ) $opt");

    $create('forum_threads', "CREATE TABLE forum_threads (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id INT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL, title VARCHAR(150) NOT NULL,
      is_pinned TINYINT(1) NOT NULL DEFAULT 0, is_locked TINYINT(1) NOT NULL DEFAULT 0,
      post_count INT UNSIGNED NOT NULL DEFAULT 0, last_post_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
      KEY ix_ft_list (category_id, deleted_at, is_pinned, last_post_at),
      CONSTRAINT fk_ft_cat FOREIGN KEY (category_id) REFERENCES forum_categories(id),
      CONSTRAINT fk_ft_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) $opt");

    $create('forum_posts', "CREATE TABLE forum_posts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, thread_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL, body TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, edited_at DATETIME NULL, deleted_at DATETIME NULL,
      KEY ix_fp_thread (thread_id, created_at), KEY ix_fp_user_time (user_id, created_at),
      CONSTRAINT fk_fp_thread FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
      CONSTRAINT fk_fp_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) $opt");

    foreach ([
        ['vorstellung', 'Vorstellungsrunde', 'Introductions', 'Wer bist du, was fährst du?', 'Who are you, what do you ride?', 10],
        ['touren', 'Touren & Treffen', 'Rides & meetups', 'Streckentipps, Verabredungen, Berichte', 'Route tips, meetups, ride reports', 20],
        ['technik', 'Technik & Scooter', 'Tech & scooters', 'Modelle, Reifen, Akkus, Pflege', 'Models, tyres, batteries, care', 30],
        ['recht', 'Recht & Sicherheit', 'Rules & safety', 'Verkehrsregeln, Versicherung, Ausrüstung', 'Traffic rules, insurance, gear', 40],
        ['allgemein', 'Allgemeines & Feedback', 'General & feedback', 'Alles andere – und was ElTouro besser machen soll', 'Everything else – and how ElTouro can improve', 50],
    ] as [$slug, $nde, $nen, $dde, $den, $sort]) {
        if (dbExec('INSERT IGNORE INTO forum_categories (slug, name_de, name_en, description_de, description_en, sort) VALUES (?, ?, ?, ?, ?, ?)',
            [$slug, $nde, $nen, $dde, $den, $sort])) {
            $log[] = "+ Forumskategorie $slug";
        }
    }

    /* ---------- Reports (DSA) ---------- */
    $create('reports', "CREATE TABLE reports (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reporter_user_id BIGINT UNSIGNED NULL,
      target_type ENUM('post','thread','tour','group','user') NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
      reason TEXT NOT NULL, status ENUM('open','done') NOT NULL DEFAULT 'open',
      decision TEXT NULL, handled_by BIGINT UNSIGNED NULL, handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ix_rep_status (status, created_at), KEY ix_rep_target (target_type, target_id)
    ) $opt");

    /* ---------- Legal texts: first version into empty pages ---------- */
    $texts = require __DIR__ . '/legal_texts.php';
    foreach ($texts as $slug => $langs) {
        foreach ($langs as $loc => $body) {
            if (dbExec("UPDATE pages SET body = ? WHERE slug = ? AND locale = ? AND TRIM(body) = ''", [$body, $slug, $loc])) {
                $log[] = "+ Text für $slug/$loc eingesetzt";
            }
        }
    }
    foreach (['log_days' => '7'] as $k => $v) {
        dbExec('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
    }

    /* ---------- Crew talk: conversation inside a crew ---------- */
    $create('herd_topics', "CREATE TABLE herd_topics (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, group_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL, title VARCHAR(150) NOT NULL,
      is_pinned TINYINT(1) NOT NULL DEFAULT 0, is_locked TINYINT(1) NOT NULL DEFAULT 0,
      post_count INT UNSIGNED NOT NULL DEFAULT 0, last_post_id BIGINT UNSIGNED NULL,
      last_post_at DATETIME NOT NULL, last_post_user_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
      KEY ix_ht_list (group_id, deleted_at, is_pinned, last_post_at),
      CONSTRAINT fk_ht_group FOREIGN KEY (group_id) REFERENCES rider_groups(id) ON DELETE CASCADE,
      CONSTRAINT fk_ht_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) $opt");

    $create('herd_posts', "CREATE TABLE herd_posts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, topic_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL, reply_to_id BIGINT UNSIGNED NULL, body TEXT NOT NULL,
      like_count INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, edited_at DATETIME NULL, deleted_at DATETIME NULL,
      KEY ix_hp_topic (topic_id, id), KEY ix_hp_user_time (user_id, created_at),
      CONSTRAINT fk_hp_topic FOREIGN KEY (topic_id) REFERENCES herd_topics(id) ON DELETE CASCADE,
      CONSTRAINT fk_hp_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) $opt");

    $create('herd_reactions', "CREATE TABLE herd_reactions (
      post_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (post_id, user_id),
      CONSTRAINT fk_hr_post FOREIGN KEY (post_id) REFERENCES herd_posts(id) ON DELETE CASCADE,
      CONSTRAINT fk_hr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    $create('herd_reads', "CREATE TABLE herd_reads (
      topic_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
      last_read_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (topic_id, user_id),
      CONSTRAINT fk_hrd_topic FOREIGN KEY (topic_id) REFERENCES herd_topics(id) ON DELETE CASCADE,
      CONSTRAINT fk_hrd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    if (!str_contains(columnType('reports', 'target_type'), 'herdpost')) {
        db()->exec("ALTER TABLE reports MODIFY target_type ENUM('post','thread','tour','group','user','herdpost') NOT NULL");
        $log[] = '~ reports.target_type um herdpost erweitert';
    }

    /* ---------- Rides: a tour as a date with meeting point and sign-up (2026-10) ---------- */
    $create('rides', "CREATE TABLE rides (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      tour_id BIGINT UNSIGNED NOT NULL, organizer_user_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NULL,
      visibility ENUM('group','public') NOT NULL DEFAULT 'group',
      title VARCHAR(120) NOT NULL, description TEXT NULL, content_lang ENUM('de','en') NOT NULL DEFAULT 'de',
      starts_at DATETIME NOT NULL, meeting_point VARCHAR(200) NOT NULL,
      meeting_lat DECIMAL(9,6) NULL, meeting_lng DECIMAL(9,6) NULL,
      capacity SMALLINT UNSIGNED NOT NULL, waitlist_enabled TINYINT(1) NOT NULL DEFAULT 1,
      style ENUM('relaxed','social','sporty') NOT NULL DEFAULT 'social',
      stvo29_confirmed_at DATETIME NULL,
      status ENUM('planned','cancelled') NOT NULL DEFAULT 'planned', cancel_reason VARCHAR(500) NULL, cancelled_at DATETIME NULL,
      talk_topic_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      KEY ix_rides_upcoming (deleted_at, status, starts_at), KEY ix_rides_group (group_id, starts_at),
      KEY ix_rides_tour (tour_id), KEY ix_rides_organizer (organizer_user_id),
      CONSTRAINT fk_rides_tour FOREIGN KEY (tour_id) REFERENCES tours(id),
      CONSTRAINT fk_rides_user FOREIGN KEY (organizer_user_id) REFERENCES users(id),
      CONSTRAINT fk_rides_group FOREIGN KEY (group_id) REFERENCES rider_groups(id) ON DELETE SET NULL,
      CONSTRAINT fk_rides_topic FOREIGN KEY (talk_topic_id) REFERENCES herd_topics(id) ON DELETE SET NULL
    ) $opt");

    $create('ride_signups', "CREATE TABLE ride_signups (
      ride_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
      status ENUM('confirmed','waitlist','cancelled') NOT NULL,
      road_legal_confirmed_at DATETIME NOT NULL, photo_consent TINYINT(1) NOT NULL DEFAULT 0,
      queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (ride_id, user_id), KEY ix_rs_user (user_id, status), KEY ix_rs_queue (ride_id, status, queued_at),
      CONSTRAINT fk_rs_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE,
      CONSTRAINT fk_rs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    if (!str_contains(columnType('reports', 'target_type'), "'ride'")) {
        db()->exec("ALTER TABLE reports MODIFY target_type ENUM('post','thread','tour','group','user','herdpost','ride') NOT NULL");
        $log[] = '~ reports.target_type um ride erweitert';
    }

    // Privacy policy and terms got sections about rides. Replace the previous default text,
    // but only where it was never edited – edited texts must be completed by hand.
    $previousDefaults = [
        'privacy' => ['de' => '7c952c23971b8b52ff87c98ea3b80461e8f07ed7414a5edb70c7623b163db5eb', 'en' => '2fa2c76dc9f9b54a07d9203ca9f3de44512be3f5fcf79f0bcf075e6a3f01a43e'],
        'terms'   => ['de' => '76ea7fd9fb7ab40f39df1c82c834c477cc42e098e7858ed683007428b8ebe722', 'en' => '5026d5032e3612a60f0f15c427ca08694a27ed49bb7660dcdb3ddfbc10ca9d29'],
    ];
    foreach ($previousDefaults as $slug => $hashes) {
        foreach ($hashes as $loc => $hash) {
            $p = dbOne('SELECT body FROM pages WHERE slug = ? AND locale = ?', [$slug, $loc]);
            if ($p === null || $p['body'] === $texts[$slug][$loc]) {
                continue;
            }
            if (hash('sha256', $p['body']) === $hash) {
                dbExec('UPDATE pages SET body = ? WHERE slug = ? AND locale = ?', [$texts[$slug][$loc], $slug, $loc]);
                $log[] = "~ $slug/$loc: Standardtext um Ausfahrten ergänzt";
            }
        }
    }

    /* ---------- Tour share links and Google sign-in (2026-10) ---------- */
    if (!columnExists('tours', 'share_token')) {
        db()->exec('ALTER TABLE tours ADD share_token CHAR(24) NULL, ADD share_created_at DATETIME NULL, ADD UNIQUE KEY uq_tours_share (share_token)');
        $log[] = '~ tours.share_token angelegt';
    }
    $create('user_identities', "CREATE TABLE user_identities (
      provider ENUM('google') NOT NULL, subject VARCHAR(255) NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
      email VARCHAR(254) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login_at DATETIME NULL,
      PRIMARY KEY (provider, subject), KEY ix_ui_user (user_id),
      CONSTRAINT fk_ui_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    /* ---------- Reward system, stage 1: referral origin and activity events (2026-10, docs/rewards.md) ---------- */
    if (!columnExists('users', 'invite_code')) {
        db()->exec('ALTER TABLE users ADD invite_code CHAR(10) NULL, ADD UNIQUE KEY uq_users_invite (invite_code)');
        $log[] = '~ users.invite_code angelegt';
    }
    if (!columnExists('tours', 'share_created_by')) {
        db()->exec('ALTER TABLE tours ADD share_created_by BIGINT UNSIGNED NULL AFTER share_created_at');
        $log[] = '~ tours.share_created_by angelegt';
    }
    $create('referrals', "CREATE TABLE referrals (
      user_id BIGINT UNSIGNED PRIMARY KEY, referrer_user_id BIGINT UNSIGNED NULL,
      source ENUM('invite_link','share_link','crew_invite','ride_share') NOT NULL,
      channel ENUM('whatsapp','telegram','facebook','x','email','copy','native','unknown') NOT NULL DEFAULT 'unknown',
      ref_type VARCHAR(20) NOT NULL, ref_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ix_ref_referrer (referrer_user_id), KEY ix_ref_source (source, channel),
      CONSTRAINT fk_ref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ref_referrer FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) $opt");
    $create('activity_events', "CREATE TABLE activity_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
      type VARCHAR(40) NOT NULL, subject_type VARCHAR(20) NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
      related_user_id BIGINT UNSIGNED NULL, value DECIMAL(10,2) NULL, meta JSON NULL,
      occurred_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      voided_at DATETIME NULL, voided_by BIGINT UNSIGNED NULL, void_reason VARCHAR(500) NULL,
      UNIQUE KEY uq_event (type, user_id, subject_type, subject_id),
      KEY ix_event_user (user_id, occurred_at), KEY ix_event_type (type, occurred_at),
      CONSTRAINT fk_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    // Legal pages: default texts of earlier versions that were never edited get the current default.
    $earlierDefaults = [
        'privacy' => ['de' => ['7f5b63b6bc91c170f4b48617d86799fdc5143a9258b9bfce9c12160e66174814', '1d37ffa904f2ff69495cbf1838231f22c9d4671e0cf074a93923c214018d2787'],
                      'en' => ['d626e9ea9f8f8e548f714271166a27b85c5a3d4b89e7b87bfff553de5fb4e074', 'bcffa513cf8aa47f15aa4ef45af61c239757410a248262de9e43e2cda0968024']],
        'terms'   => ['de' => ['f67bd7aca3213e8da07881163f4f0844e9892c8d8e49717ae92766a59b86a2ab'],
                      'en' => ['dd40215568b28ad2acdb14ff89336d92e22c091fd4e8e40e5fe08c8d60de8f2d']],
    ];
    foreach ($earlierDefaults as $slug => $byLang) {
        foreach ($byLang as $loc => $hashes) {
            $p = dbOne('SELECT body FROM pages WHERE slug = ? AND locale = ?', [$slug, $loc]);
            if ($p !== null && in_array(hash('sha256', $p['body']), $hashes, true)) {
                dbExec('UPDATE pages SET body = ? WHERE slug = ? AND locale = ?', [$texts[$slug][$loc], $slug, $loc]);
                $log[] = "~ $slug/$loc: Standardtext aktualisiert";
            }
        }
    }
    foreach (legalPagesNeedingUpdate() as $page => $sections) {
        $log[] = "! $page wurde von Hand angepasst – bitte aus legal_texts.php ergänzen: " . implode(', ', $sections);
    }

    return $log;
}

/**
 * Legal pages edited by hand that still lack a section the app needs (rides, sharing, Google sign-in).
 * Checked by phrases that only those sections contain. Also shown on the admin start page.
 * @return array<string, string[]> e.g. ["privacy/de" => ["Teilen", "Google-Anmeldung"]]
 */
function legalPagesNeedingUpdate(): array
{
    $markers = [
        'privacy' => [
            'de' => ['Ausfahrten' => 'Fotos und Videos:', 'Teilen' => 'Touren teilen:', 'Google-Anmeldung' => 'Anmeldung mit Google:', 'Einladungen' => 'Einladungen:'],
            'en' => ['Ausfahrten' => 'Photos and videos:', 'Teilen' => 'Sharing routes:', 'Google-Anmeldung' => 'Sign in with Google:', 'Einladungen' => 'Invitations:'],
        ],
        'terms' => [
            'de' => ['Ausfahrten' => 'legt eine Teilnehmergrenze fest', 'Teilen' => 'per Link teilst'],
            'en' => ['Ausfahrten' => 'sets a participant limit', 'Teilen' => 'share a route via link'],
        ],
    ];
    $missing = [];
    foreach ($markers as $slug => $byLang) {
        foreach ($byLang as $loc => $sections) {
            $p = dbOne('SELECT body FROM pages WHERE slug = ? AND locale = ?', [$slug, $loc]);
            if ($p === null || trim($p['body']) === '') {
                continue;
            }
            foreach ($sections as $section => $marker) {
                if (!str_contains($p['body'], $marker)) {
                    $missing["$slug/$loc"][] = $section;
                }
            }
        }
    }
    return $missing;
}

/**
 * Before the English refactor runtime data lived in daten/ (sessions in daten/sitzungen).
 * Backups move to data/backups so they stay listed and restorable; old sessions are simply
 * dropped (everybody logs in once more). Nothing is deleted – daten/ stays blocked by its .htaccess.
 */
function migrateLegacyDataDir(array &$log): void
{
    $old = __DIR__ . '/daten/backups';
    if (!is_dir($old)) {
        return;
    }
    $new = dataDir('backups');
    $moved = 0;
    foreach (glob($old . '/*') ?: [] as $file) {
        if (is_file($file) && !file_exists($new . '/' . basename($file)) && @rename($file, $new . '/' . basename($file))) {
            $moved++;
        }
    }
    if ($moved) {
        $log[] = "~ $moved Backup-Dateien von daten/backups nach data/backups verschoben";
    }
}
