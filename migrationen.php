<?php
/**
 * Alle Schema-Schritte an EINER Stelle. Idempotent: Jeder Schritt prüft selbst, ob er nötig ist.
 * Aufgerufen von migrate.php (per Schlüssel) und admin/datenbank.php (per Klick).
 * Neue Änderungen immer unten anhängen, nie bestehende Schritte umschreiben.
 */
declare(strict_types=1);

function gibtTabelle(string $t): bool
{
    return (int)einzeln('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])['n'] > 0;
}

function gibtSpalte(string $t, string $s): bool
{
    return (int)einzeln('SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $s])['n'] > 0;
}

/** @return string[] Protokollzeilen */
function fuehreMigrationenAus(): array
{
    $log = [];
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $lege = function (string $name, string $sql) use (&$log): void {
        if (gibtTabelle($name)) {
            $log[] = "= $name vorhanden";
            return;
        }
        db()->exec($sql);
        $log[] = "+ $name angelegt";
    };

    /* ---------- Konten ---------- */
    $lege('users', "CREATE TABLE users (
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

    $lege('user_profiles', "CREATE TABLE user_profiles (
      user_id BIGINT UNSIGNED PRIMARY KEY, bio TEXT NULL, home_region VARCHAR(100) NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_prof_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    $lege('auth_tokens', "CREATE TABLE auth_tokens (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
      purpose ENUM('verify','reset') NOT NULL, token_hash CHAR(64) NOT NULL,
      expires_at DATETIME NOT NULL, used_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_tok_hash (token_hash), KEY ix_tok_user (user_id, purpose),
      CONSTRAINT fk_tok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    $lege('login_attempts', "CREATE TABLE login_attempts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY ix_la_ip_time (ip, created_at)
    ) $opt");

    /* ---------- Herden ---------- */
    $lege('rider_groups', "CREATE TABLE rider_groups (
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

    $lege('group_members', "CREATE TABLE group_members (
      group_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
      role ENUM('admin','member') NOT NULL DEFAULT 'member', status ENUM('active','pending') NOT NULL DEFAULT 'pending',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (group_id, user_id), KEY ix_gm_user (user_id, status),
      CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES rider_groups(id) ON DELETE CASCADE,
      CONSTRAINT fk_gm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    /* ---------- Inhalte & Einstellungen ---------- */
    $lege('pages', "CREATE TABLE pages (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(60) NOT NULL, locale ENUM('de','en') NOT NULL,
      title VARCHAR(120) NOT NULL, body MEDIUMTEXT NOT NULL, updated_by BIGINT UNSIGNED NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_pages (slug, locale)
    ) $opt");

    $lege('settings', "CREATE TABLE settings (
      k VARCHAR(60) PRIMARY KEY, v TEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $opt");

    foreach ([['impressum', 'de', 'Impressum'], ['impressum', 'en', 'Legal notice'],
              ['datenschutz', 'de', 'Datenschutzerklärung'], ['datenschutz', 'en', 'Privacy policy'],
              ['nutzungsbedingungen', 'de', 'Nutzungsbedingungen'], ['nutzungsbedingungen', 'en', 'Terms of use']] as [$s, $l, $t]) {
        if (ausfuehren('INSERT IGNORE INTO pages (slug, locale, title, body) VALUES (?, ?, ?, ?)', [$s, $l, $t, ''])) {
            $log[] = "+ Seite $s/$l";
        }
    }
    ausfuehren("INSERT IGNORE INTO settings (k, v) VALUES ('registrierung_offen', '1')");

    /* ---------- Touren (Routenplaner) ---------- */
    $lege('tours', "CREATE TABLE tours (
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
    $lege('forum_categories', "CREATE TABLE forum_categories (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(60) NOT NULL,
      name_de VARCHAR(80) NOT NULL, name_en VARCHAR(80) NOT NULL,
      description_de VARCHAR(255) NULL, description_en VARCHAR(255) NULL,
      sort SMALLINT NOT NULL DEFAULT 0, UNIQUE KEY uq_fc_slug (slug)
    ) $opt");

    $lege('forum_threads', "CREATE TABLE forum_threads (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id INT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL, title VARCHAR(150) NOT NULL,
      is_pinned TINYINT(1) NOT NULL DEFAULT 0, is_locked TINYINT(1) NOT NULL DEFAULT 0,
      post_count INT UNSIGNED NOT NULL DEFAULT 0, last_post_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
      KEY ix_ft_list (category_id, deleted_at, is_pinned, last_post_at),
      CONSTRAINT fk_ft_cat FOREIGN KEY (category_id) REFERENCES forum_categories(id),
      CONSTRAINT fk_ft_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) $opt");

    $lege('forum_posts', "CREATE TABLE forum_posts (
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
        if (ausfuehren('INSERT IGNORE INTO forum_categories (slug, name_de, name_en, description_de, description_en, sort) VALUES (?, ?, ?, ?, ?, ?)',
            [$slug, $nde, $nen, $dde, $den, $sort])) {
            $log[] = "+ Forumskategorie $slug";
        }
    }

    /* ---------- Meldungen (DSA) ---------- */
    $lege('reports', "CREATE TABLE reports (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reporter_user_id BIGINT UNSIGNED NULL,
      target_type ENUM('post','thread','tour','group','user') NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
      reason TEXT NOT NULL, status ENUM('open','done') NOT NULL DEFAULT 'open',
      decision TEXT NULL, handled_by BIGINT UNSIGNED NULL, handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ix_rep_status (status, created_at), KEY ix_rep_target (target_type, target_id)
    ) $opt");

    /* ---------- Rechtstexte: Erstfassung in leere Seiten ---------- */
    $texte = require __DIR__ . '/rechtstexte.php';
    foreach ($texte as $slug => $sprachen) {
        foreach ($sprachen as $loc => $body) {
            if (ausfuehren("UPDATE pages SET body = ? WHERE slug = ? AND locale = ? AND TRIM(body) = ''", [$body, $slug, $loc])) {
                $log[] = "+ Text für $slug/$loc eingesetzt";
            }
        }
    }
    foreach (['log_tage' => '7'] as $k => $v) {
        ausfuehren('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
    }

    /* ---------- Tränke: Austausch innerhalb einer Herde ---------- */
    $lege('herd_topics', "CREATE TABLE herd_topics (
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

    $lege('herd_posts', "CREATE TABLE herd_posts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, topic_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL, reply_to_id BIGINT UNSIGNED NULL, body TEXT NOT NULL,
      like_count INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, edited_at DATETIME NULL, deleted_at DATETIME NULL,
      KEY ix_hp_topic (topic_id, id), KEY ix_hp_user_time (user_id, created_at),
      CONSTRAINT fk_hp_topic FOREIGN KEY (topic_id) REFERENCES herd_topics(id) ON DELETE CASCADE,
      CONSTRAINT fk_hp_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) $opt");

    $lege('herd_reactions', "CREATE TABLE herd_reactions (
      post_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (post_id, user_id),
      CONSTRAINT fk_hr_post FOREIGN KEY (post_id) REFERENCES herd_posts(id) ON DELETE CASCADE,
      CONSTRAINT fk_hr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    $lege('herd_reads', "CREATE TABLE herd_reads (
      topic_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
      last_read_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (topic_id, user_id),
      CONSTRAINT fk_hrd_topic FOREIGN KEY (topic_id) REFERENCES herd_topics(id) ON DELETE CASCADE,
      CONSTRAINT fk_hrd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opt");

    if (!str_contains((string)einzeln("SELECT COLUMN_TYPE AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'target_type'")['t'], 'herdpost')) {
        db()->exec("ALTER TABLE reports MODIFY target_type ENUM('post','thread','tour','group','user','herdpost') NOT NULL");
        $log[] = '~ reports.target_type um herdpost erweitert';
    }

    return $log;
}
