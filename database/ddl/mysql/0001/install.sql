-- GateTrail schema release 0001
-- Database driver: mysql
-- Direction: install
-- Generated from the canonical schema manifest; do not edit manually.

CREATE TABLE `acl_roles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `guard_name` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `description` LONGTEXT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    `deleted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `acl_role_name_guard_uq` UNIQUE (`name`, `guard_name`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `acl_permissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `guard_name` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `description` LONGTEXT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    `deleted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `acl_perm_name_guard_uq` UNIQUE (`name`, `guard_name`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `acl_assignment_locks` (
    `assignment_kind` VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
    `scope_hash` VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    `scope_key` LONGTEXT NOT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`assignment_kind`, `scope_hash`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `acl_role_assignments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_type` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `subject_id` BIGINT UNSIGNED NOT NULL,
    `role_id` BIGINT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL,
    `assigned_by_type` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `assigned_by_id` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `assignment_reason` LONGTEXT NULL,
    `starts_at` DATETIME NULL,
    `expires_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `revoked_by_type` VARCHAR(191) COLLATE utf8mb4_bin NULL,
    `revoked_by_id` VARCHAR(191) COLLATE utf8mb4_bin NULL,
    `revocation_reason` LONGTEXT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `acl_ra_role_fk` FOREIGN KEY (`role_id`) REFERENCES `acl_roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `acl_ra_period_ck` CHECK (expires_at IS NULL OR expires_at > COALESCE(starts_at, assigned_at)),
    CONSTRAINT `acl_ra_revoke_ck` CHECK ((revoked_at IS NULL AND revoked_by_type IS NULL AND revoked_by_id IS NULL) OR (revoked_at IS NOT NULL AND revoked_by_type IS NOT NULL AND revoked_by_id IS NOT NULL)),
    INDEX `acl_ra_subject_ix` (`subject_type`, `subject_id`, `revoked_at`, `starts_at`, `expires_at`),
    INDEX `acl_ra_scope_ix` (`subject_type`, `subject_id`, `role_id`, `revoked_at`, `starts_at`, `expires_at`),
    INDEX `acl_ra_role_ix` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `acl_permission_assignments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_type` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `subject_id` BIGINT UNSIGNED NOT NULL,
    `permission_id` BIGINT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL,
    `assigned_by_type` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `assigned_by_id` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `assignment_reason` LONGTEXT NULL,
    `starts_at` DATETIME NULL,
    `expires_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `revoked_by_type` VARCHAR(191) COLLATE utf8mb4_bin NULL,
    `revoked_by_id` VARCHAR(191) COLLATE utf8mb4_bin NULL,
    `revocation_reason` LONGTEXT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `acl_pa_perm_fk` FOREIGN KEY (`permission_id`) REFERENCES `acl_permissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `acl_pa_period_ck` CHECK (expires_at IS NULL OR expires_at > COALESCE(starts_at, assigned_at)),
    CONSTRAINT `acl_pa_revoke_ck` CHECK ((revoked_at IS NULL AND revoked_by_type IS NULL AND revoked_by_id IS NULL) OR (revoked_at IS NOT NULL AND revoked_by_type IS NOT NULL AND revoked_by_id IS NOT NULL)),
    INDEX `acl_pa_subject_ix` (`subject_type`, `subject_id`, `revoked_at`, `starts_at`, `expires_at`),
    INDEX `acl_pa_scope_ix` (`subject_type`, `subject_id`, `permission_id`, `revoked_at`, `starts_at`, `expires_at`),
    INDEX `acl_pa_perm_ix` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `acl_role_permissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id` BIGINT UNSIGNED NOT NULL,
    `permission_id` BIGINT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL,
    `assigned_by_type` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `assigned_by_id` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `assignment_reason` LONGTEXT NULL,
    `starts_at` DATETIME NULL,
    `expires_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `revoked_by_type` VARCHAR(191) COLLATE utf8mb4_bin NULL,
    `revoked_by_id` VARCHAR(191) COLLATE utf8mb4_bin NULL,
    `revocation_reason` LONGTEXT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `acl_rp_role_fk` FOREIGN KEY (`role_id`) REFERENCES `acl_roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `acl_rp_perm_fk` FOREIGN KEY (`permission_id`) REFERENCES `acl_permissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `acl_rp_period_ck` CHECK (expires_at IS NULL OR expires_at > COALESCE(starts_at, assigned_at)),
    CONSTRAINT `acl_rp_revoke_ck` CHECK ((revoked_at IS NULL AND revoked_by_type IS NULL AND revoked_by_id IS NULL) OR (revoked_at IS NOT NULL AND revoked_by_type IS NOT NULL AND revoked_by_id IS NOT NULL)),
    INDEX `acl_rp_role_ix` (`role_id`, `revoked_at`, `starts_at`, `expires_at`, `permission_id`),
    INDEX `acl_rp_scope_ix` (`role_id`, `permission_id`, `revoked_at`, `starts_at`, `expires_at`),
    INDEX `acl_rp_perm_ix` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `acl_access_audits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `actor_type` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `actor_id` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `subject_type` VARCHAR(191) COLLATE utf8mb4_bin NULL,
    `subject_id` BIGINT UNSIGNED NULL,
    `target_type` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    `target_id` BIGINT UNSIGNED NOT NULL,
    `before` LONGTEXT NULL,
    `after` LONGTEXT NULL,
    `reason` LONGTEXT NULL,
    `context` LONGTEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` LONGTEXT NULL,
    `request_id` VARCHAR(191) NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `acl_audit_subject_ck` CHECK ((subject_type IS NULL AND subject_id IS NULL) OR (subject_type IS NOT NULL AND subject_id IS NOT NULL)),
    INDEX `acl_audit_actor_ix` (`actor_type`, `actor_id`, `created_at`),
    INDEX `acl_audit_subject_ix` (`subject_type`, `subject_id`, `created_at`),
    INDEX `acl_audit_target_ix` (`target_type`, `target_id`, `created_at`),
    INDEX `acl_audit_event_ix` (`event`, `created_at`),
    INDEX `acl_audit_request_ix` (`request_id`),
    INDEX `acl_audit_created_ix` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
