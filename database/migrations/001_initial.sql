CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL, password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','operator') NOT NULL DEFAULT 'operator', status ENUM('active','suspended') NOT NULL DEFAULT 'active',
 daily_limit INT UNSIGNED NOT NULL DEFAULT 1000, timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_users_email(email), KEY ix_users_status_role(status,role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS password_resets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY uq_reset_token(token_hash), KEY ix_reset_user(user_id,expires_at),
 CONSTRAINT fk_reset_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS access_codes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code_hash CHAR(64) NOT NULL, label VARCHAR(120) NOT NULL,
 expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY uq_access_code(code_hash), KEY ix_access_active(revoked_at,expires_at),
 CONSTRAINT fk_access_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS access_code_accounts (
 code_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL, used_at DATETIME NULL,
 PRIMARY KEY(code_id,account_id), KEY ix_code_account(account_id,used_at),
 CONSTRAINT fk_code_assignment FOREIGN KEY(code_id) REFERENCES access_codes(id) ON DELETE CASCADE,
 CONSTRAINT fk_code_user FOREIGN KEY(account_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS contacts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, phone_e164 VARCHAR(16) NOT NULL,
 name VARCHAR(160) NOT NULL DEFAULT '', email VARCHAR(190) NULL, consent_status ENUM('unknown','opted_in','unsubscribed') NOT NULL DEFAULT 'unknown',
 consent_source VARCHAR(255) NULL, consented_at DATETIME NULL, unsubscribed_at DATETIME NULL,
 last_inbound_at DATETIME NULL, notes TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_contact_phone(user_id,phone_e164), KEY ix_contact_search(user_id,name,phone_e164), KEY ix_contact_consent(user_id,consent_status),
 CONSTRAINT fk_contact_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS groups (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_group_name(user_id,name), KEY ix_group_user(user_id),
 CONSTRAINT fk_group_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS group_contacts (
 user_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NOT NULL, contact_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 PRIMARY KEY(group_id,contact_id), KEY ix_membership_contact(user_id,contact_id),
 CONSTRAINT fk_member_group FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE,
 CONSTRAINT fk_member_contact FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
 CONSTRAINT fk_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS import_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,filename VARCHAR(255) NOT NULL,total_rows INT UNSIGNED NOT NULL DEFAULT 0,
 created_count INT UNSIGNED NOT NULL DEFAULT 0,updated_count INT UNSIGNED NOT NULL DEFAULT 0,error_count INT UNSIGNED NOT NULL DEFAULT 0,errors_json MEDIUMTEXT NULL,created_at DATETIME NOT NULL,
 KEY ix_import_user(user_id,created_at),CONSTRAINT fk_import_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS media (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,storage_name VARCHAR(128) NOT NULL,
 original_name VARCHAR(255) NOT NULL,mime_type VARCHAR(120) NOT NULL,size_bytes BIGINT UNSIGNED NOT NULL,media_type ENUM('image','audio','document') NOT NULL,created_at DATETIME NOT NULL,
 UNIQUE KEY uq_media_name(storage_name),KEY ix_media_user(user_id),CONSTRAINT fk_media_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,name VARCHAR(140) NOT NULL,language VARCHAR(16) NOT NULL DEFAULT 'en',
 provider_template_name VARCHAR(120) NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_template_name(user_id,name),KEY ix_template_user(user_id),CONSTRAINT fk_template_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS template_parts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,template_id BIGINT UNSIGNED NOT NULL,sequence_no SMALLINT UNSIGNED NOT NULL,
 part_type ENUM('text','image','audio','document') NOT NULL,text_content TEXT NULL,media_id BIGINT UNSIGNED NULL,caption VARCHAR(1024) NULL,created_at DATETIME NOT NULL,
 UNIQUE KEY uq_template_sequence(template_id,sequence_no),KEY ix_part_owner(user_id,template_id),
 CONSTRAINT fk_part_template FOREIGN KEY(template_id) REFERENCES templates(id) ON DELETE CASCADE,
 CONSTRAINT fk_part_media FOREIGN KEY(media_id) REFERENCES media(id) ON DELETE SET NULL,
 CONSTRAINT fk_part_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS campaigns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,template_id BIGINT UNSIGNED NOT NULL,name VARCHAR(160) NOT NULL,
 status ENUM('draft','queued','processing','completed','cancelled','failed') NOT NULL DEFAULT 'draft',scheduled_for DATETIME NULL,
 recipient_count INT UNSIGNED NOT NULL DEFAULT 0,excluded_count INT UNSIGNED NOT NULL DEFAULT 0,part_count INT UNSIGNED NOT NULL DEFAULT 0,
 quota_day DATE NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,dispatched_at DATETIME NULL,
 KEY ix_campaign_user(user_id,created_at),KEY ix_campaign_status(status,updated_at),
 CONSTRAINT fk_campaign_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_campaign_template FOREIGN KEY(template_id) REFERENCES templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS campaign_targets (
 campaign_id BIGINT UNSIGNED NOT NULL,target_type ENUM('contact','group') NOT NULL,target_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(campaign_id,target_type,target_id),KEY ix_target_id(target_type,target_id),CONSTRAINT fk_target_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS campaign_recipients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NULL,
 phone_e164 VARCHAR(16) NOT NULL,display_name VARCHAR(160) NOT NULL DEFAULT '',status ENUM('queued','sending','accepted','delivered','failed','cancelled','suppressed') NOT NULL DEFAULT 'queued',
 failure_reason VARCHAR(255) NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_campaign_phone(campaign_id,phone_e164),KEY ix_recipient_user(user_id,campaign_id,status),
 CONSTRAINT fk_recipient_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
 CONSTRAINT fk_recipient_contact FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
 CONSTRAINT fk_recipient_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS campaign_parts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,template_part_id BIGINT UNSIGNED NULL,sequence_no SMALLINT UNSIGNED NOT NULL,
 part_type ENUM('text','image','audio','document') NOT NULL,text_content TEXT NULL,media_id BIGINT UNSIGNED NULL,caption VARCHAR(1024) NULL,
 UNIQUE KEY uq_campaign_sequence(campaign_id,sequence_no),CONSTRAINT fk_cpart_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
 CONSTRAINT fk_cpart_templatepart FOREIGN KEY(template_part_id) REFERENCES template_parts(id) ON DELETE SET NULL,
 CONSTRAINT fk_cpart_media FOREIGN KEY(media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quota_days (
 user_id BIGINT UNSIGNED NOT NULL,quota_day DATE NOT NULL,reserved_count INT UNSIGNED NOT NULL DEFAULT 0,limit_snapshot INT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id,quota_day),CONSTRAINT fk_quota_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quota_reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,quota_day DATE NOT NULL,campaign_id BIGINT UNSIGNED NOT NULL,
 recipient_id BIGINT UNSIGNED NOT NULL,campaign_part_id BIGINT UNSIGNED NOT NULL,state ENUM('reserved','consumed','released') NOT NULL DEFAULT 'reserved',
 released_at DATETIME NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_reservation(recipient_id,campaign_part_id),KEY ix_reserve_day(user_id,quota_day,state),
 CONSTRAINT fk_reserve_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_reserve_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
 CONSTRAINT fk_reserve_recipient FOREIGN KEY(recipient_id) REFERENCES campaign_recipients(id) ON DELETE CASCADE,
 CONSTRAINT fk_reserve_part FOREIGN KEY(campaign_part_id) REFERENCES campaign_parts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS delivery_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,recipient_id BIGINT UNSIGNED NOT NULL,campaign_part_id BIGINT UNSIGNED NOT NULL,
 reservation_id BIGINT UNSIGNED NOT NULL,status ENUM('queued','sending','accepted','retry_wait','delivered','failed','cancelled') NOT NULL DEFAULT 'queued',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 6,available_at DATETIME NOT NULL,locked_at DATETIME NULL,
 idempotency_key CHAR(64) NOT NULL,provider_message_id VARCHAR(190) NULL,last_error VARCHAR(255) NULL,accepted_at DATETIME NULL,delivered_at DATETIME NULL,
 created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uq_job_part(recipient_id,campaign_part_id),UNIQUE KEY uq_job_idempotency(idempotency_key),
 KEY ix_job_claim(status,available_at,id),KEY ix_job_order(recipient_id,status),KEY ix_job_campaign(campaign_id,status),
 CONSTRAINT fk_job_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
 CONSTRAINT fk_job_recipient FOREIGN KEY(recipient_id) REFERENCES campaign_recipients(id) ON DELETE CASCADE,
 CONSTRAINT fk_job_part FOREIGN KEY(campaign_part_id) REFERENCES campaign_parts(id) ON DELETE CASCADE,
 CONSTRAINT fk_job_reservation FOREIGN KEY(reservation_id) REFERENCES quota_reservations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS webhook_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id VARCHAR(190) NOT NULL,event_type VARCHAR(64) NOT NULL,job_id BIGINT UNSIGNED NULL,
 received_at DATETIME NOT NULL,payload_hash CHAR(64) NOT NULL,UNIQUE KEY uq_webhook_event(event_id),KEY ix_event_job(job_id,received_at),
 CONSTRAINT fk_event_job FOREIGN KEY(job_id) REFERENCES delivery_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_id BIGINT UNSIGNED NULL,action VARCHAR(100) NOT NULL,target_type VARCHAR(64) NOT NULL,target_id VARCHAR(80) NULL,
 metadata_json TEXT NULL,ip_hash CHAR(64) NULL,created_at DATETIME NOT NULL,KEY ix_audit_actor(actor_id,created_at),KEY ix_audit_target(target_type,target_id,created_at),
 CONSTRAINT fk_audit_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS rate_limits (
 bucket_key CHAR(64) PRIMARY KEY,attempts INT UNSIGNED NOT NULL,window_started DATETIME NOT NULL,blocked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS schema_migrations (
 version VARCHAR(100) PRIMARY KEY,applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
